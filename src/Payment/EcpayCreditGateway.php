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

		// v0.3.0：環境與商店身分必須與**建單當時**一致。設定被切換之後（stage↔live、
		// 換商店代號），我們手上的憑證屬於另一個環境／另一家商店——拿它去操作這筆
		// 交易，最好的情況是被綠界拒絕，最壞的情況是動到別家商店的同號交易。
		$charge_env      = (string) ( $payment_detail['ecpay_environment'] ?? '' );
		$charge_merchant = (string) ( $payment_detail['ecpay_merchant_id'] ?? '' );
		$now_env         = ! empty( $credentials['test_mode'] ) ? 'stage' : 'live';
		$now_merchant    = (string) ( $credentials['merchant_id'] ?? '' );

		if ( '' === $charge_env || '' === $charge_merchant ) {
			return self::reject( '此訂單未記錄建單時的綠界環境／商店代號（v0.3.0 之前建立），無法確認退款會送往同一個商店；請人工處理。' );
		}
		if ( $charge_env !== $now_env || $charge_merchant !== $now_merchant ) {
			return self::reject( sprintf(
				'綠界設定已與建單時不同（建單：%s／%s，目前：%s／%s），拒絕跨環境或跨商店退款；請切回原設定或人工處理。',
				$charge_env,
				$charge_merchant,
				$now_env,
				$now_merchant
			) );
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

		// ── 步驟 3：實際請款金額（關帳查詢與全額判定都以它為基準）──────────
		//
		// v0.3.0：基準是**建單時實際送出的金額**，不是 $order->total。total 可能在
		// 建單之後被改動，而且 `(int) round( total )` 會讓 1000.5 的訂單把 1001 元的
		// 退款判成「全額」——那不是同一筆錢。
		$charged_amount = isset( $payment_detail['ecpay_charged_amount'] )
			? (int) $payment_detail['ecpay_charged_amount']
			: null;
		if ( null === $charged_amount || $charged_amount <= 0 ) {
			return self::reject( '此訂單未記錄實際請款金額（v0.3.0 之前建立），無法判定全額／部分退款；請人工處理。' );
		}
		if ( $amount_twd > $charged_amount ) {
			return self::reject( sprintf( '退刷金額超過實際請款金額（請款 %d 元）。', $charged_amount ) );
		}
		$is_full = $amount_twd === $charged_amount;

		// ── 步驟 4：查關帳狀態（唯讀；查詢失敗＝未動錢，可安全重試）──────────
		$close = $client->query_credit_close_status( $gwsr, $charged_amount );
		if ( 'unknown' === ( $close['state'] ?? 'unknown' ) ) {
			return self::reject( '無法確認交易關帳狀態（' . (string) ( $close['message'] ?? '' ) . '），已中止退款操作；請稍後重試或於綠界後台人工處理。' );
		}

		// ── 步驟 5：依官方狀態機決定動作序列 ────────────────────────────────
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
			// v0.3.0：綁定建單身分——同一筆 request_id 若在設定切換後重放，指紋就對不上。
			'merchant_id'       => $charge_merchant,
			'environment'       => $charge_env,
		];

		// ── 步驟 6：原子式 reservation ──────────────────────────────────────
		//
		// 🔴 仲裁與寫入必須在**同一個 CAS closure** 內。舊版是「方法開頭讀一次 ledger →
		// 檢查有沒有 pending → 很久以後才寫入 pending」：兩個併發請求各自拿著自己的舊
		// 快照，都判定「沒有 pending」，於是都走到 DoAction ＝ 退兩次款。CAS 落敗時
		// mutator 會拿**當下最新**的 ledger 重跑整段仲裁，因此只有一個能 reserve 成功。
		$reservation = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $attempt, &$decision ) use ( $request_id, $fingerprint, $close, $plan, $amount_twd, $charged_amount ): ?array {
				$ledger   = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
				$existing = is_array( $ledger[ $request_id ] ?? null ) ? $ledger[ $request_id ] : null;

				// 🔴 v0.3.0：與核心的退款帳本綁定，並在**同一個 CAS 快照**內判定。
				// 核心 `_ys_refund_finalization` 才是「這筆退款請求存不存在、金額多少、
				// 由哪個 gateway 執行」的權威來源；provider 端只憑自己的 ledger 仲裁，
				// 等於允許任何人以任意 request_id 直接呼叫 gateway 退款。
				$core_ledger = is_array( $detail['_ys_refund_finalization'] ?? null )
					? $detail['_ys_refund_finalization']
					: [];
				$core_entry  = is_array( $core_ledger[ $request_id ] ?? null ) ? $core_ledger[ $request_id ] : null;

				if ( null === $core_entry ) {
					$decision = [ 'action' => 'no_core_request' ];
					return null;
				}
				if ( 'ys_ec_ecpay_credit' !== (string) ( $core_entry['gateway_id'] ?? '' ) ) {
					$decision = [ 'action' => 'core_gateway_mismatch', 'gateway' => (string) ( $core_entry['gateway_id'] ?? '' ) ];
					return null;
				}
				if ( ! isset( $core_entry['amount'] ) || abs( (float) $core_entry['amount'] - (float) $amount_twd ) > 0.005 ) {
					$decision = [ 'action' => 'core_amount_mismatch', 'core_amount' => $core_entry['amount'] ?? null ];
					return null;
				}

				// 剩餘可退額度：已在本 ledger 落成 done 的金額不得重複計入。
				$already = 0;
				foreach ( $ledger as $done_id => $done ) {
					if ( (string) $done_id === $request_id ) {
						continue;
					}
					if ( 'done' === ( $done['status'] ?? '' ) ) {
						$already += (int) ( $done['amount'] ?? 0 );
					}
				}
				if ( $already + $amount_twd > $charged_amount ) {
					$decision = [ 'action' => 'exceeds_remaining', 'already' => $already, 'charged' => $charged_amount ];
					return null;
				}

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

		if ( 'no_core_request' === $action ) {
			return self::reject( '核心沒有這筆退款請求的紀錄（_ys_refund_finalization），已拒絕操作——退款必須由核心退款流程發起。' );
		}
		if ( 'core_gateway_mismatch' === $action ) {
			return self::reject( '核心紀錄的 gateway 與本外掛不符，已拒絕操作；請人工核對（可能是 request_id 撞號）。' );
		}
		if ( 'core_amount_mismatch' === $action ) {
			return self::reject( '退款金額與核心紀錄不符，已拒絕操作；請人工核對後處理。' );
		}
		if ( 'exceeds_remaining' === $action ) {
			$already = is_array( $decision ) ? (int) ( $decision['already'] ?? 0 ) : 0;
			return self::reject( sprintf( '退款金額超過剩餘可退額度（已退 %d 元／請款 %d 元）。', $already, $charged_amount ) );
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

		// ── 步驟 7：依序執行；每一步成功後**先落盤再送下一步** ──────────────
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
				// 🔴 v0.3.0：只有「這一次流程完全沒有動到綠界端狀態」才是可安全重試。
				// E→N 的第二步失敗**不是**那種情況：E（取消關帳）已經成功執行，交易
				// 在綠界端已經改變狀態。把它標成 failed／rejected_terminal 等於告訴
				// 核心「可以再送一次完整的 E→N」——第二次的 E 會作用在一筆已經被取消
				// 關帳的交易上，結果無法預期。
				$prior_steps = array_slice( $executed, 0, -1 );

				if ( $prior_steps ) {
					self::note_attempt( $order_id, $request_id, [
						'executed' => implode( ',', $executed ),
						'note'     => sprintf(
							'部分完成：%s 成功、%s 失敗（%s）',
							implode( ',', $prior_steps ),
							$step,
							(string) ( $result['message'] ?? '' )
						),
					], '部分完成註記' );

					YSLogger::error( 'ecpay', 'CRITICAL: 多步退款部分完成（前置步驟已改變綠界端狀態）', [
						'order_id'    => $order_id,
						'request_id'  => $request_id,
						'executed'    => implode( ',', $executed ),
						'failed_step' => $step,
					] );

					// attempt 維持 pending ＝ 全單凍結，等人工核定。
					return [
						'success' => false,
						'outcome' => 'indeterminate',
						'message' => sprintf(
							'退款部分完成（已執行 %s，%s 失敗）：前置步驟已改變綠界端交易狀態，'
								. '不得重送完整流程。本單退款已凍結，請於綠界後台確認實際狀態後以 '
								. 'wp ys-ecpay refund-attempts resolve 人工核定。',
							implode( ',', $prior_steps ),
							$step
						),
					];
				}

				// 第一步就被明確拒絕（RtnCode≠1，金流未動）→ 可安全重試。
				$marked = self::mark_attempt( $order_id, $request_id, [
					'status'   => 'failed',
					'executed' => implode( ',', $executed ),
					'rtn_code' => (string) ( $result['data']['RtnCode'] ?? '' ),
					'rtn_msg'  => (string) ( $result['message'] ?? '' ),
				], 'failed' );

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
				], null );

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
		], 'done' );

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
	 * 以 CAS 併入本次 attempt 的欄位（v0.3.0：加上 terminal ownership）
	 *
	 * 舊版是無條件 `array_merge`：entry 不存在就**憑空建一筆**、已被 CLI 核定成
	 * done／failed 的 terminal 也照樣覆寫。兩者都很嚴重——前者讓任何呼叫端都能
	 * 偽造一筆退款紀錄，後者會把人工核定的結論抹掉並讓凍結解除。
	 *
	 * 現在 CAS closure 內要求：
	 *   1. entry 必須**已存在**（只有 reservation 能建立）
	 *   2. entry 必須仍是 `pending`（terminal 不可變）
	 *   3. 指紋必須**嚴格**相符（型別敏感，不做寬鬆轉換）
	 *   4. 若提供 expected executed prefix，落盤的 executed 必須是它的前綴
	 *      （防止把「已執行 E,N」倒退成「已執行 E」）
	 *
	 * @param string|null $expect_terminal 這次要寫入的終態（'done'／'failed'／null＝非終態）
	 */
	private static function mark_attempt( int $order_id, string $request_id, array $patch, ?string $expect_terminal = null ): bool {
		$result = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $attempt, &$decision ) use ( $request_id, $patch ): ?array {
				$ledger  = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
				$current = is_array( $ledger[ $request_id ] ?? null ) ? $ledger[ $request_id ] : null;

				if ( null === $current ) {
					$decision = [ 'action' => 'missing' ];
					return null; // 不得憑空建立 entry——那是 reservation 的專屬職責
				}
				if ( 'pending' !== ( $current['status'] ?? '' ) ) {
					$decision = [ 'action' => 'terminal', 'status' => (string) ( $current['status'] ?? '' ) ];
					return null; // terminal 不可變
				}

				// executed 只能往前，不能倒退。
				$old_executed = (string) ( $current['executed'] ?? '' );
				$new_executed = array_key_exists( 'executed', $patch ) ? (string) $patch['executed'] : $old_executed;
				if ( '' !== $old_executed && ! str_starts_with( $new_executed, $old_executed ) ) {
					$decision = [ 'action' => 'executed_regression', 'old' => $old_executed, 'new' => $new_executed ];
					return null;
				}

				$ledger[ $request_id ]       = array_merge( $current, $patch );
				$detail['_ys_ecpay_refunds'] = $ledger;
				$decision                    = [ 'action' => 'marked' ];

				return $detail;
			}
		);

		$decision = $result->get_decision();
		$action   = is_array( $decision ) ? (string) ( $decision['action'] ?? '' ) : '';

		if ( 'marked' === $action && $result->is_persisted() ) {
			return true;
		}

		// terminal 已存在且正好是我們想寫的終態＝先前已成功落盤，視為冪等成功。
		if ( 'terminal' === $action && null !== $expect_terminal
			&& $expect_terminal === ( is_array( $decision ) ? (string) ( $decision['status'] ?? '' ) : '' ) ) {
			return true;
		}

		YSLogger::error( 'ecpay', 'attempt 狀態寫入被拒或失敗', array_merge(
			[
				'order_id'   => $order_id,
				'request_id' => $request_id,
				'action'     => $action,
			],
			$result->to_log_context()
		) );

		return false;
	}

	/** 非終態註記；寫不進去不改變結論（凍結由送出前的 pending 保證），僅記錄。 */
	private static function note_attempt( int $order_id, string $request_id, array $patch, string $what ): void {
		if ( self::mark_attempt( $order_id, $request_id, $patch, null ) ) {
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

			$actual = $entry[ $key ];

			// 🔴 v0.3.0：嚴格比對，不做寬鬆型別轉換。舊版一律 `(string)`／`(int)`
			// 轉換後比較，於是 `"1000"` 與 `1000`、`"0"` 與 `false`、甚至 `null`
			// 與 `""` 都會被判成「相符」——指紋的用途正是要抓出「這不是同一筆」，
			// 用會抹平差異的比較方式等於沒有指紋。
			if ( 'amount' === $key ) {
				if ( ! is_int( $actual ) || $actual !== $expected ) {
					return false;
				}
				continue;
			}

			if ( ! is_string( $actual ) || ! is_string( $expected ) || $actual !== $expected ) {
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
		// 所有證據來源。**每一個來源都要看**，不能因為其中一個說「不是分期」就結案——
		// 舊版是「取第一個非空值」，於是持久化的 `stage=0` 會遮掉 QueryTradeInfo 回報的
		// `stage=3`：一筆分期交易被判成一般信用卡，然後以一般卡的規則退款。
		$sources = [ 'order' => $payment_detail ];
		if ( is_array( $query_data ) ) {
			$sources['query'] = $query_data;
		}

		/** 從所有來源蒐集某個欄位的全部值（含 0），保留來源標籤。 */
		$collect = static function ( array $keys ) use ( $sources ): array {
			$found = [];
			foreach ( $sources as $origin => $source ) {
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $source ) && '' !== (string) $source[ $key ] ) {
						$found[] = [ 'origin' => $origin, 'key' => $key, 'value' => (string) $source[ $key ] ];
					}
				}
			}
			return $found;
		};

		// 分期：任何來源回報正的期數即為分期（positive evidence wins）。
		$stages = $collect( [ 'ecpay_stage', 'stage', 'Stage', 'installment_count' ] );
		foreach ( $stages as $stage ) {
			if ( (int) $stage['value'] > 0 ) {
				return [
					'type'   => 'installment',
					'label'  => '分期付款',
					'reason' => sprintf( '%s=%s（來源：%s）', $stage['key'], $stage['value'], $stage['origin'] ),
				];
			}
		}

		// 紅利折抵：同上，任一來源為正即成立。
		$redeems = $collect( [ 'ecpay_red_dan', 'red_dan', 'ecpay_red_de_amt', 'red_de_amt', 'ecpay_red_ok_amt', 'red_ok_amt' ] );
		foreach ( $redeems as $redeem ) {
			if ( (float) $redeem['value'] > 0 ) {
				return [
					'type'   => 'redeem',
					'label'  => '紅利折抵交易',
					'reason' => sprintf( '%s=%s（來源：%s）', $redeem['key'], $redeem['value'], $redeem['origin'] ),
				];
			}
		}

		// 付款方式：必須有值，且所有來源必須一致。
		$types = $collect( [ 'ecpay_payment_type', 'payment_type', 'PaymentType' ] );
		if ( ! $types ) {
			return [
				'type'   => 'unknown',
				'label'  => '無法證明付款方式的交易',
				'reason' => '訂單付款紀錄與 QueryTradeInfo 皆未提供 PaymentType',
			];
		}

		$distinct = array_values( array_unique( array_map( static fn( array $t ): string => $t['value'], $types ) ) );
		if ( count( $distinct ) > 1 ) {
			return [
				'type'   => 'conflict',
				'label'  => '付款方式證據互相衝突的交易',
				'reason' => 'PaymentType 各來源不一致：' . implode( ' / ', array_map( 'sanitize_text_field', $distinct ) ),
			];
		}

		if ( 'Credit_CreditCard' !== $distinct[0] ) {
			return [
				'type'   => 'unsupported',
				'label'  => '非一般信用卡交易（' . sanitize_text_field( $distinct[0] ) . '）',
				'reason' => 'PaymentType=' . sanitize_text_field( $distinct[0] ),
			];
		}

		// 一般信用卡：另外要求**確實看過**分期／紅利欄位。完全沒有這些欄位代表
		// 這筆交易建立於 v0.3.0 之前（付款通知還沒持久化它們），我們無法證明它不是
		// 分期——「沒有標記」不等於「不是分期」。
		if ( ! $stages && ! $redeems ) {
			$has_zero_marker = false;
			foreach ( $sources as $source ) {
				foreach ( [ 'ecpay_stage', 'stage', 'ecpay_red_dan', 'red_dan' ] as $marker ) {
					if ( array_key_exists( $marker, $source ) ) {
						$has_zero_marker = true;
						break 2;
					}
				}
			}

			if ( ! $has_zero_marker ) {
				return [
					'type'   => 'unknown',
					'label'  => '無法證明是否為分期／紅利的交易',
					'reason' => '訂單付款紀錄與 QueryTradeInfo 皆未提供 stage／red_* 欄位（v0.3.0 之前建立）',
				];
			}
		}

		return [ 'type' => 'plain_credit', 'label' => '一般信用卡', 'reason' => '' ];
	}
}
