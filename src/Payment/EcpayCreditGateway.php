<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayCreditGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_credit';
	}

	public function get_title(): string {
		return '綠界信用卡';
	}

	protected function gateway_key(): string {
		return 'credit';
	}

	protected function choose_payment(): string {
		return 'Credit';
	}

	/**
	 * 宣告具自動金流退款能力（core v2.56.4 `YSGatewayRegistry::supports_auto_refund`
	 * 的可選方法協定）——後台退款 UI 據此不顯示「訂單退款≠金流退款」警示。
	 */
	public function supports_gateway_refund(): bool {
		return true;
	}

	/**
	 * 信用卡退款（query-first 狀態機，CreditDetail/QueryTrade → DoAction）
	 *
	 * 僅信用卡 gateway 支援；ATM／超商依產品決策走人工退款（base 維持不支援）。
	 * 設計要點（CODEX 終審 F2/F4 修正——依綠界官方流程）：
	 *   1. **query-first**：先取 gwsr（付款紀錄，缺則 QueryTradeInfo 補查）→
	 *      CreditDetail/QueryTrade 查關帳狀態 → 依官方表選動作：
	 *        已授權（authorized）→ N（僅全額；部分明確拒絕）
	 *        要關帳（to_close）＋全額 → E 之後接 N
	 *        要關帳（to_close）＋部分 → R
	 *        已關帳（closed）→ R
	 *        unknown → 拒絕操作（fail-closed，不猜）
	 *   2. **crash-safe 冪等**：以 core `context['refund_request_id']` 為 key；
	 *      done→冪等重放、pending→拒絕盲重送、failed→允許重試。
	 *   3. **傳輸不確定 ≠ 失敗**：DoAction 回 indeterminate（timeout／非 2xx／無 RtnCode）
	 *      → attempt **維持 pending**（綠界端可能已生效，重試可能重複退款）；
	 *      只有 provider 明確拒絕（RtnCode≠1）才標 failed 開放重試。
	 *   4. 識別碼一律取自訂單付款紀錄；金額上限＝total－已退。
	 *
	 * @deferred-live-verification 驗證一律走受控正式商店小額實測（stage DoAction
	 * 官方明載不可用）；gate 清單見 docs/credit-refund-sandbox-gate.md。
	 */
	public function process_refund( int $order_id, float $amount, string $reason = '', array $context = [] ): array {
		unset( $reason );

		$order = YSOrder::find( $order_id );
		if ( ! $order ) {
			return self::reject( '訂單不存在。' );
		}

		// ── 硬性 gate（全部在任何 network call 之前）─────────────────────────────
		//
		// v0.3.0：這一段的每一條都是「不能證明就不做」。退款是不可逆的金流動作，
		// 猜錯的代價是真的把錢送出去，而我們無從撤回。

		// stage 環境：綠界官方明載 DoAction 於測試環境不可用。先前只有註解說明，
		// 實際上仍會把請求送到 stage endpoint 並把回應當真。
		$credentials = Settings::payment_credentials();
		if ( ! empty( $credentials['test_mode'] ) ) {
			return self::reject( '測試模式（stage）不支援信用卡退刷——綠界官方明載測試環境無實際授權，DoAction 不可用。請於正式環境操作或改走人工退款。' );
		}

		// 冪等鍵。core YSRefundHandler 各路徑皆提供；外部直呼缺 key 一律拒絕。
		$request_id = (string) ( $context['refund_request_id'] ?? '' );
		if ( '' === $request_id ) {
			return self::reject( '缺少 refund_request_id（冪等鍵），已拒絕退款操作。' );
		}

		// 金額：canonical TWD 正整數，且不得超過可退餘額。
		$total      = (float) ( $order->total ?? 0 );
		$refunded   = (float) ( $order->refunded_amount ?? 0 );
		$refundable = $total - $refunded;
		if ( ! EcpayPaymentClient::is_canonical_twd( $amount ) ) {
			return self::reject( '退刷金額必須為正整數新台幣（綠界信用卡不接受小數），已拒絕操作。' );
		}
		if ( round( $amount, 2 ) > round( $refundable, 2 ) ) {
			return self::reject( sprintf( '退刷金額不正確（可退餘額 %s）。', number_format( max( 0, $refundable ), 2 ) ) );
		}
		$amount_twd = (int) $amount;

		// payment_detail 讀取失敗（欄位損壞／訂單消失）不得當成空陣列——那會讓
		// 「有沒有進行中的退款」這個問題得到錯誤的否定答案。
		$payment_detail = OrderPaymentDetail::read( $order_id );
		if ( null === $payment_detail ) {
			return self::reject( '無法讀取訂單付款紀錄（欄位損壞或核心版本不符），已拒絕退款操作；請人工處理。' );
		}

		$trade_no          = (string) ( $payment_detail['trade_no'] ?? $order->gateway_trade_no ?? '' );
		$merchant_trade_no = (string) ( $payment_detail['mer_trade_no'] ?? '' );
		if ( '' === $trade_no || '' === $merchant_trade_no ) {
			return self::reject( '找不到綠界交易識別碼，無法退刷（訂單可能非綠界信用卡付款）。' );
		}

		$client = new EcpayPaymentClient();

		// ── 步驟 1：取得授權單號 gwsr（關帳狀態查詢的 key；唯讀，可在 reserve 前執行）──
		$gwsr        = (string) ( $payment_detail['gwsr'] ?? $payment_detail['ecpay_gwsr'] ?? '' );
		$query_data  = null;
		if ( '' === $gwsr ) {
			$query      = $client->query_trade( $merchant_trade_no );
			$query_data = is_array( $query['data'] ?? null ) ? $query['data'] : null;
			$gwsr       = (string) ( $query_data['gwsr'] ?? '' );
			if ( '' !== $gwsr ) {
				// gwsr 回寫只是快取（下次不必再查），寫不進去不影響本次判定，
				// 但仍要留下紀錄——它同時是「CAS 是否健康」的早期訊號。
				$cached = OrderPaymentDetail::mutate(
					$order_id,
					static function ( array $detail ) use ( $gwsr ): array {
						$detail['gwsr'] = $gwsr;
						return $detail;
					}
				);
				if ( ! $cached->is_persisted() ) {
					YSLogger::warning( 'ecpay', 'gwsr 快取回寫失敗（不影響本次退款判定）', array_merge(
						[ 'order_id' => $order_id ],
						$cached->to_log_context()
					) );
				}
			}
		}
		if ( '' === $gwsr ) {
			return self::reject( '無法取得綠界授權單號（gwsr），無法判定關帳狀態；請於綠界後台人工處理退款。' );
		}

		// ── 步驟 2：卡別 gate（分期／紅利／銀聯／無法證明 → 人工）─────────────
		//
		// 綠界對分期、紅利折抵、銀聯的退款規則與一般信用卡不同（例如分期只能全額）。
		// 我們沒有這些規則的權威來源，因此**不猜**：只有能明確證明是一般信用卡交易
		// 才自動退款，其餘一律導向人工。無法證明也算不通過——「沒有標記」不等於
		// 「不是分期」，舊訂單根本沒寫過這些欄位。
		$program = self::classify_card_program( $payment_detail, $query_data );
		if ( 'plain_credit' !== $program['type'] ) {
			return self::reject( sprintf(
				'此交易為 %s，自動退刷未涵蓋其官方規則，已導向人工處理（%s）。',
				$program['label'],
				$program['reason']
			) );
		}

		// ── 步驟 3：查關帳狀態（唯讀；查詢失敗＝未動錢，可安全重試）──────────
		$close = $client->query_credit_close_status( $gwsr, (int) round( $total ) );
		if ( 'unknown' === ( $close['state'] ?? 'unknown' ) ) {
			return self::reject( '無法確認交易關帳狀態（' . (string) ( $close['message'] ?? '' ) . '），已中止退款操作；請稍後重試或於綠界後台人工處理。' );
		}

		$is_full = $amount_twd >= (int) round( $total );

		// ── 步驟 4：依官方狀態機決定動作序列 ────────────────────────────────
		switch ( $close['state'] ) {
			case 'authorized':
			case 'cancelled': // 操作取消 → 依官方流程執行 N（僅全額）
				if ( ! $is_full ) {
					return self::reject( '此交易尚未請款（已授權／前次操作已取消），僅支援全額取消授權；請以全額退款處理。' );
				}
				$plan = [ 'N' ];
				break;
			case 'to_close':
				$plan = $is_full ? [ 'E', 'N' ] : [ 'R' ];
				break;
			case 'closed':
			default:
				$plan = [ 'R' ];
				break;
		}

		$fingerprint = [
			'amount'            => $amount_twd,
			'trade_no'          => $trade_no,
			'merchant_trade_no' => $merchant_trade_no,
			'gwsr'              => $gwsr,
		];

		// ── 步驟 5：原子式 reservation ──────────────────────────────────────
		//
		// 🔴 仲裁與寫入必須在**同一個 CAS closure** 內。舊版是「方法開頭讀一次 ledger →
		// 檢查有沒有 pending → 很久以後才寫入 pending」：兩個併發請求各自拿著自己的舊
		// 快照，都判定「沒有 pending」，於是都走到 DoAction ＝ 退兩次款。CAS 落敗時
		// mutator 會拿**當下最新**的 ledger 重跑整段仲裁，因此只有一個能 reserve 成功。
		$reservation = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $attempt, &$decision ) use ( $request_id, $fingerprint, $close, $plan ): ?array {
				$ledger   = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
				$existing = is_array( $ledger[ $request_id ] ?? null ) ? $ledger[ $request_id ] : null;

				// (a) 同一請求已成功 → 冪等重放；但 fingerprint 必須相符，否則代表
				//     request_id 撞號（不同交易共用同一個鍵），絕不能回報成功。
				if ( $existing && 'done' === ( $existing['status'] ?? '' ) ) {
					$decision = self::fingerprint_matches( $existing, $fingerprint )
						? [ 'action' => 'idempotent_replay', 'entry' => $existing ]
						: [ 'action' => 'fingerprint_mismatch', 'entry' => $existing ];
					return null;
				}

				// (b) 本訂單存在**任何**結果未明的 attempt（不分 request_id）→ 全單凍結。
				//     涵蓋 UI 逾時換新 UUID、core pending TTL 過期換號等所有路徑。
				foreach ( $ledger as $frozen_id => $frozen ) {
					if ( 'pending' === ( $frozen['status'] ?? '' ) ) {
						$decision = [ 'action' => 'frozen', 'frozen_id' => (string) $frozen_id ];
						return null;
					}
				}

				// (c) 同一請求先前明確失敗 → 可重試，但 fingerprint 必須仍然相符。
				if ( $existing && ! self::fingerprint_matches( $existing, $fingerprint ) ) {
					$decision = [ 'action' => 'fingerprint_mismatch', 'entry' => $existing ];
					return null;
				}

				// (d) reserve：只有走到這裡的那一個併發請求可以送金流。
				$ledger[ $request_id ] = array_merge( $fingerprint, [
					'status'   => 'pending',
					'state'    => (string) ( $close['state'] ?? '' ),
					'plan'     => implode( ',', $plan ),
					'executed' => '',
					'time'     => current_time( 'mysql' ),
				] );

				$detail['_ys_ecpay_refunds'] = $ledger;
				$decision                    = [ 'action' => 'reserved' ];

				return $detail;
			}
		);

		$decision = $reservation->get_decision();
		$action   = is_array( $decision ) ? (string) ( $decision['action'] ?? '' ) : '';

		if ( 'idempotent_replay' === $action ) {
			$entry = is_array( $decision['entry'] ?? null ) ? $decision['entry'] : [];
			return [
				'success'        => true,
				'transaction_id' => (string) ( $entry['trade_no'] ?? $trade_no ),
				'message'        => '（冪等重放：此退刷請求先前已成功）',
			];
		}

		if ( 'fingerprint_mismatch' === $action ) {
			return self::reject( '退款請求的交易指紋與既有紀錄不符（request_id 可能重複使用），已拒絕操作；請人工核對後處理。' );
		}

		if ( 'frozen' === $action ) {
			$frozen_id = is_array( $decision ) ? (string) ( $decision['frozen_id'] ?? '' ) : '';
			return [
				'success' => false,
				'outcome' => 'indeterminate',
				'message' => '此訂單有一筆結果未明的退款請求（' . sanitize_text_field( $frozen_id ) . '）凍結中：'
					. '為避免重複退款，已拒絕所有新的退款操作；請先於綠界後台確認該筆實際狀態並人工核定後再試。',
			];
		}

		if ( ! $reservation->is_persisted() || 'reserved' !== $action ) {
			// reserve 沒有落盤就**不得**送金流：冪等防線不存在，任何重試都可能重複退款。
			YSLogger::error( 'ecpay', 'CRITICAL: 退款 reservation 寫入失敗，未送出任何金流動作', array_merge(
				[
					'order_id'   => $order_id,
					'request_id' => $request_id,
				],
				$reservation->to_log_context()
			) );

			return self::reject( '退款請求無法持久化（冪等防線寫入失敗），已中止；未執行任何金流操作，請重試。' );
		}

		// ── 步驟 6：依序執行；每一步成功後**先落盤再送下一步** ──────────────
		$executed = [];
		$result   = [ 'success' => false, 'indeterminate' => false, 'data' => null, 'message' => '' ];

		foreach ( $plan as $step ) {
			$result     = $client->do_action_refund( $merchant_trade_no, $trade_no, (float) $amount_twd, $step );
			$executed[] = $step;

			if ( ! empty( $result['indeterminate'] ) ) {
				// 傳輸不確定：綠界端可能已生效 → attempt 維持 pending，等人工核定。
				self::note_attempt( $order_id, $request_id, [
					'executed' => implode( ',', $executed ),
					'note'     => '結果未明（' . (string) ( $result['message'] ?? '' ) . '）',
				], '結果未明註記' );

				return [
					'success' => false,
					'outcome' => 'indeterminate',
					'message' => '退款請求結果未明（傳輸中斷），為避免重複退款已凍結此請求；請先於綠界後台確認實際狀態，再人工核定。',
				];
			}

			if ( empty( $result['success'] ) ) {
				// provider 明確拒絕（RtnCode≠1，金流未動）→ 可安全重試。
				$marked = self::mark_attempt( $order_id, $request_id, [
					'status'   => 'failed',
					'executed' => implode( ',', $executed ),
					'rtn_code' => (string) ( $result['data']['RtnCode'] ?? '' ),
					'rtn_msg'  => (string) ( $result['message'] ?? '' ),
				] );

				if ( ! $marked ) {
					// 終態沒落盤 → attempt 停留 pending＝全單凍結。此時我們無法保證
					// 後續讀到的是哪一種狀態，一律回 indeterminate。
					return self::indeterminate_persist_failure( $order_id, $request_id, 'failed' );
				}

				return self::reject( '綠界退款失敗（動作 ' . $step . '）：' . (string) ( $result['message'] ?? '未知錯誤' ) );
			}

			// 🔴 E→N 這類多步流程：本步成功後必須**先 durable 記錄**才可送下一步。
			// 若不記錄就送 N，中途 crash 會留下「E 已執行但沒人知道」的狀態，人工
			// 核定時無從判斷該補 N 還是重來。
			if ( $step !== $plan[ array_key_last( $plan ) ] ) {
				$stepped = self::mark_attempt( $order_id, $request_id, [
					'executed' => implode( ',', $executed ),
					'note'     => '已完成動作 ' . $step . '，準備執行下一步',
				] );

				if ( ! $stepped ) {
					return self::indeterminate_persist_failure( $order_id, $request_id, 'step:' . $step );
				}
			}
		}

		$done_trade_no = (string) ( $result['data']['TradeNo'] ?? $trade_no );
		$marked        = self::mark_attempt( $order_id, $request_id, [
			'status'   => 'done',
			'executed' => implode( ',', $executed ),
			'trade_no' => $done_trade_no,
		] );

		if ( ! $marked ) {
			// v0.3.0：先前這裡回 success 並附註記。那是錯的——金流已經動了，而我們
			// 的紀錄沒落盤；對呼叫端宣告 success 會讓核心把訂單標記為已退款，之後
			// 沒有任何機制會回來核對。一律回 indeterminate，由人工核定。
			return self::indeterminate_persist_failure( $order_id, $request_id, 'done', $done_trade_no );
		}

		return [
			'success'        => true,
			'transaction_id' => $done_trade_no,
			'message'        => ( 'authorized' === $close['state']
				? '（未請款交易，以取消授權方式全額退款）'
				: ( [ 'E', 'N' ] === $plan ? '（要關帳交易，已取消關帳並取消授權全額退款）' : '' ) ),
		];
	}

	/**
	 * 業務拒絕（金流確定未動）——core 以 rejected_terminal 表示「可安全重試」。
	 *
	 * @return array{success:false, outcome:string, message:string}
	 */
	private static function reject( string $message ): array {
		return [
			'success' => false,
			'outcome' => 'rejected_terminal',
			'message' => $message,
		];
	}

	/**
	 * 終態寫入失敗 → indeterminate。
	 *
	 * 金流已經動了、我們的 ledger 卻沒落盤：既不能說成功（核心會就此結案），
	 * 也不能說失敗（會開放重送 ＝ 重複退款）。attempt 停留 pending＝全單凍結，
	 * 由 `wp ys-ecpay refund-attempts resolve` 人工核定。
	 *
	 * @return array{success:false, outcome:string, message:string}
	 */
	private static function indeterminate_persist_failure( int $order_id, string $request_id, string $phase, string $trade_no = '' ): array {
		YSLogger::error( 'ecpay', 'CRITICAL: 退款 attempt 終態寫入失敗（本單退款凍結，需 CLI 核定）', [
			'order_id'   => $order_id,
			'request_id' => $request_id,
			'phase'      => $phase,
			'trade_no'   => $trade_no,
		] );

		return [
			'success' => false,
			'outcome' => 'indeterminate',
			'message' => '退款已送出但紀錄寫入失敗，本單退款已凍結：請先於綠界後台確認實際狀態，'
				. '再以 wp ys-ecpay refund-attempts resolve 人工核定（請勿另開新退款）。',
		];
	}

	/**
	 * 以 CAS 併入本次 attempt 的欄位（只動自己那一筆，不整包覆蓋）。
	 */
	private static function mark_attempt( int $order_id, string $request_id, array $patch ): bool {
		$result = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail ) use ( $request_id, $patch ): array {
				$ledger  = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
				$current = is_array( $ledger[ $request_id ] ?? null ) ? $ledger[ $request_id ] : [];

				$ledger[ $request_id ]       = array_merge( $current, $patch );
				$detail['_ys_ecpay_refunds'] = $ledger;

				return $detail;
			}
		);

		return $result->is_persisted();
	}

	/** 非終態註記；寫不進去不改變結論（凍結由送出前的 pending 保證），僅記錄。 */
	private static function note_attempt( int $order_id, string $request_id, array $patch, string $what ): void {
		if ( self::mark_attempt( $order_id, $request_id, $patch ) ) {
			return;
		}

		YSLogger::error( 'ecpay', 'CRITICAL: ' . $what . '寫入失敗（凍結仍由送出前 pending 保證）', [
			'order_id'   => $order_id,
			'request_id' => $request_id,
		] );
	}

	/**
	 * 既有 attempt 是否與本次請求指向同一筆交易。
	 *
	 * 缺任何一個欄位都算不符——舊紀錄沒有指紋時，我們無法證明是同一筆，
	 * 而「無法證明」在退款這件事上必須等同於「不是」。
	 *
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $fingerprint
	 */
	private static function fingerprint_matches( array $entry, array $fingerprint ): bool {
		foreach ( $fingerprint as $key => $expected ) {
			if ( ! array_key_exists( $key, $entry ) ) {
				return false;
			}
			if ( 'amount' === $key ) {
				if ( (int) $entry[ $key ] !== (int) $expected ) {
					return false;
				}
				continue;
			}
			if ( (string) $entry[ $key ] !== (string) $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 判定信用卡交易的方案別（v0.3.0）
	 *
	 * 只有**明確證明**為一般信用卡（非分期、非紅利折抵、非銀聯）才允許自動退刷。
	 * 分期／紅利／銀聯各有不同的官方退款規則（例如分期僅能全額），我們沒有這些
	 * 規則的權威來源，猜錯就是把錢退錯——因此一律導向人工。
	 *
	 * 證據來源依序：訂單付款紀錄（webhook 持久化）→ QueryTradeInfo 回應。
	 * 兩者都無法證明時回 unknown（fail-closed）。
	 *
	 * @param array<string,mixed>      $payment_detail
	 * @param array<string,mixed>|null $query_data QueryTradeInfo 的原始回應（可為 null）
	 * @return array{type:string, label:string, reason:string}
	 */
	private static function classify_card_program( array $payment_detail, ?array $query_data ): array {
		$sources = [ $payment_detail ];
		if ( is_array( $query_data ) ) {
			$sources[] = $query_data;
		}

		$pick = static function ( array $keys ) use ( $sources ) {
			foreach ( $sources as $source ) {
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $source ) && '' !== (string) $source[ $key ] ) {
						return (string) $source[ $key ];
					}
				}
			}
			return null;
		};

		// 分期：stage（期數）為正整數即為分期。
		$stage = $pick( [ 'stage', 'Stage', 'installment_count' ] );
		if ( null !== $stage && (int) $stage > 0 ) {
			return [ 'type' => 'installment', 'label' => '分期付款', 'reason' => '期數 ' . (int) $stage ];
		}

		// 紅利折抵：red_dan（折抵點數）／red_de_amt（折抵金額）任一為正。
		foreach ( [ 'red_dan', 'red_de_amt', 'red_ok_amt' ] as $redeem_key ) {
			$redeem = $pick( [ $redeem_key ] );
			if ( null !== $redeem && (float) $redeem > 0 ) {
				return [ 'type' => 'redeem', 'label' => '紅利折抵交易', 'reason' => $redeem_key . '=' . $redeem ];
			}
		}

        // 付款方式必須明確是信用卡。ECPay 的 PaymentType 對銀聯等另有值，
        // 我們只認確定知道的那一個；其餘（含空值）都走人工。
		$payment_type = $pick( [ 'payment_type', 'PaymentType' ] );
		if ( null === $payment_type ) {
			return [
				'type'   => 'unknown',
				'label'  => '無法證明付款方式的交易',
				'reason' => '訂單付款紀錄與 QueryTradeInfo 皆未提供 PaymentType',
			];
		}
		if ( 'Credit_CreditCard' !== $payment_type ) {
			return [
				'type'   => 'unsupported',
				'label'  => '非一般信用卡交易（' . sanitize_text_field( $payment_type ) . '）',
				'reason' => 'PaymentType=' . sanitize_text_field( $payment_type ),
			];
		}

		return [ 'type' => 'plain_credit', 'label' => '一般信用卡', 'reason' => '' ];
	}
}
