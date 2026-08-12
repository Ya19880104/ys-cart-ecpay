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

	/** 啟用自動退款的設定鍵（預設關閉）。 */
	public const AUTO_REFUND_OPTION = 'ys_ec_ecpay_auto_refund_enabled';

	/**
	 * 宣告具自動金流退款能力（core v2.56.4 `YSGatewayRegistry::supports_auto_refund`
	 * 的可選方法協定）——後台退款 UI 據此不顯示「訂單退款≠金流退款」警示。
	 *
	 * 🔴 v0.3.0（#2H）：**預設關閉，0.3.0 維持 record-only／manual-only。**
	 *
	 * 舊版無條件回 true，於是核心真的會呼叫 DoAction——而
	 * `docs/credit-refund-sandbox-gate.md` 明訂：在受控正式商店把
	 * `query_credit_close_status` 的狀態對映、`E→N` 與 `R` 的實際回應碼、分期／
	 * 紅利／銀聯的官方規則實測完成之前，不得宣稱支援自動退款。
	 *
	 * 「程式碼寫好了」與「可以拿真錢跑」是兩件事。gate 沒過就讓核心把退款交給
	 * 這條路徑，等於用客戶的錢做第一次驗證。
	 *
	 * 站方在 gate 完成後可以明確開啟；沒有這個 option 就是關的。
	 */
	public function supports_gateway_refund(): bool {
		return '1' === (string) get_option( self::AUTO_REFUND_OPTION, '0' );
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

		// ── 步驟 1：QueryTradeInfo（唯讀）——卡別判定的**唯一證據集合**由此固定 ──
		//
		// 🔴 v0.3.0：這次查詢**一律執行**，不再只在缺 gwsr 時才查。
		//
		// 舊版是「gwsr 已快取 → 跳過查詢 → 卡別只看 payment_detail」。於是同一筆
		// 交易會因為一個**與卡別無關的快取**是否命中，而得到不同的證據集合：
		// 快取未命中時 QueryTradeInfo 回報 `stage=3` 會擋下分期；快取命中時只看到
		// 持久化的 `stage=0`，同一筆交易就被判成一般信用卡放行。
		//
		// 判定依據不能取決於快取狀態。查詢失敗＝證據不完整＝不可判定（fail-closed）。
		$query = $client->query_trade( $merchant_trade_no );

		// 🔴 v0.3.0：採信這份回應需要**四件事同時成立**。
		//
		// 舊版只看 `is_array( $query['data'] )`：非 2xx、CheckMacValue 不符、
		// 「查無此交易」的錯誤回應——這些全都帶著 populated data，於是一份我們
		// 無法證明來源、甚至根本不是這筆交易的回應，被拿來當退款依據。
		$query_problem = self::query_problem( $query, $merchant_trade_no, $trade_no );
		if ( null !== $query_problem ) {
			return self::reject( sprintf(
				'無法採信綠界交易查詢結果（%s），卡別與關帳狀態都無法證明，已中止退款；請稍後重試或於綠界後台人工處理。',
				sanitize_text_field( $query_problem )
			) );
		}

		$query_data = $query['data'];

		$gwsr = (string) ( $query_data['gwsr'] ?? '' );
		if ( '' === $gwsr ) {
			// 查詢成功但沒有 gwsr → 退回已持久化的值（webhook 當時寫下的同一筆事實）。
			$gwsr = (string) ( $payment_detail['gwsr'] ?? $payment_detail['ecpay_gwsr'] ?? '' );
		} else {
			// 🔴 v0.3.0（#2G）：gwsr 落盤是**必要條件**，不再是 best-effort 快取。
			//
			// 舊版把它當快取：寫不進去只記一筆 warning 就繼續。但 gwsr 同時是身分
			// 指紋的一部分，而 `identity_drift()` 現在要求每一次 CAS 都能在 detail
			// 裡看到它——沒落盤就代表之後每一次 arm 都會失敗，而失敗的時機會落在
			// 「已經送出第一步」之後。與其讓它在中途炸開，不如在還沒動任何錢的
			// 現在就停下來。
			$cached = OrderPaymentDetail::mutate(
				$order_id,
				static function ( array $detail ) use ( $gwsr ): array {
					$detail['gwsr'] = $gwsr;
					return $detail;
				}
			);
			if ( ! $cached->is_persisted() ) {
				YSLogger::error( 'ecpay', 'CRITICAL: gwsr 無法落盤，已中止退款（未送出任何金流動作）', array_merge(
					[ 'order_id' => $order_id ],
					$cached->to_log_context()
				) );

				return self::reject( '無法保存綠界授權單號（gwsr），退款身分無法在後續每一步重新證明，已中止；未執行任何金流操作，請重試。' );
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
		// 🔴 v0.3.0（#2G）：**禁止 string-cast alias**。
		//
		// 舊版是 `(int) $payment_detail['ecpay_charged_amount']`。`(int)` 會把
		// `'1000abc'` 變成 1000、把 `'1e3'` 變成 1、把超界字串**飽和**成
		// PHP_INT_MAX——每一種都是「看起來合理」的金額，而它是退款上界的依據，
		// 也是指紋的一部分。同一個值用不同轉型算出不同結果，指紋就不再證明任何事。
		$charged_amount = CoreRefundAuthorization::canonical_int( $payment_detail['ecpay_charged_amount'] ?? null );
		if ( null === $charged_amount || $charged_amount <= 0 ) {
			return self::reject( '此訂單未記錄可解讀的實際請款金額，無法判定全額／部分退款；請人工處理。' );
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
			// #2F：請款金額也是身分的一部分（退款上界的依據）。
			'charged_amount'    => $charged_amount,
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
				// 🔴 v0.3.0：核心的**狀態**才是授權，光有 entry 不夠。
				// 一筆已經 finalized、已經由 provider 完成、或根本是 record-only
				// （人工記帳、未經金流）的請求，其 entry 一樣存在——只驗存在就等於
				// 允許對這些請求再送一次 DoAction。
				//
				// 判定收斂在 CoreRefundAuthorization：reservation、送出前 arm、
				// 每一次 CAS retry 共用同一份，不會再各自寬鬆。
				$schema = CoreRefundAuthorization::problem_in( $detail, $request_id, $amount_twd );
				if ( null !== $schema ) {
					$decision = $schema;
					return null;
				}

				// 🔴 交易身分以**這一次 CAS 讀到的** detail 重建並比對。
				$drift = self::identity_drift( $detail, $fingerprint );
				if ( null !== $drift ) {
					$decision = [ 'action' => 'identity_drift', 'field' => $drift ];
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

				// (a) 同一請求已成功 → 冪等重放。
				//
				// 🔴 v0.3.0（#2G）：「status 是 done」遠遠不足以宣告冪等成功。
				//
				// 冪等的意義是「這一次要做的事，先前**完全**做完了」。一筆
				// `status=done` 卻只執行了 E（漏了 N）、或缺 operations 歷史、或沒有
				// 綠界確認的交易編號的 entry，代表的是「有人把它標成 done」，不是
				// 「這筆退款完成了」。把它當冪等回報成功，核心就會結案，而那筆錢
				// 可能根本沒退。
				if ( $existing && 'done' === ( $existing['status'] ?? '' ) ) {
					if ( ! self::fingerprint_matches( $existing, $fingerprint ) ) {
						$decision = [ 'action' => 'fingerprint_mismatch', 'entry' => $existing ];
						return null;
					}

					$incomplete = self::done_replay_problem( $existing, $plan );
					if ( null !== $incomplete ) {
						$decision = [ 'action' => 'done_incomplete', 'problem' => $incomplete ];
						return null;
					}

					$decision = [ 'action' => 'idempotent_replay', 'entry' => $existing ];
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

			// 🔴 v0.3.0（#2G）：**禁止 fallback**。
			//
			// 舊版是 `'' !== $replay ? $replay : ( $entry['trade_no'] ?? $trade_no )`。
			// 那個 fallback 把兩個語意完全不同的值混成一個：`response_trade_no` 是
			// **綠界回應確認的退款交易編號**，`trade_no` 是我們送出去的原始交易編號。
			// 靜默退回後者，呼叫端拿到一個「看起來像是退款憑據」的值，而它其實只是
			// 原始交易——對帳時無從發現。
			//
			// `done_replay_problem()` 已經在 CAS 內保證它是非空字串；這裡再驗一次是
			// 因為這個回傳值會被核心當成退款憑據存進帳本。
			$replay_trade_no = $entry['response_trade_no'] ?? null;
			if ( ! is_string( $replay_trade_no ) || '' === $replay_trade_no ) {
				return self::reject( '既有的退款紀錄缺少綠界確認的交易編號，無法作為冪等重放的依據；請人工核對後處理。' );
			}

			return [
				'success'        => true,
				'transaction_id' => $replay_trade_no,
				'message'        => '（冪等重放：此退刷請求先前已成功）',
			];
		}

		if ( 'done_incomplete' === $action ) {
			$problem = is_array( $decision ) ? (string) ( $decision['problem'] ?? '' ) : '';

			YSLogger::error( 'ecpay', 'CRITICAL: 既有 done 紀錄不完整，不得當成冪等重放', [
				'order_id'   => $order_id,
				'request_id' => $request_id,
				'problem'    => $problem,
			] );

			return [
				'success' => false,
				'outcome' => 'indeterminate',
				'message' => sprintf(
					'既有的退款紀錄標為完成，但內容不完整（%s）：無法證明這筆退款真的執行完畢，'
						. '已拒絕回報成功。請於綠界後台確認實際狀態後人工核定。',
					sanitize_text_field( $problem )
				),
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
		if ( 'core_not_submitting' === $action ) {
			$status = is_array( $decision ) ? (string) ( $decision['status'] ?? '?' ) : '?';
			return self::reject( sprintf(
				'核心退款請求目前為「%s」而非 submitting，已拒絕操作——只有正在送出中的請求可以觸發金流動作。',
				sanitize_text_field( $status )
			) );
		}
		if ( 'identity_drift' === $action ) {
			$field = is_array( $decision ) ? (string) ( $decision['field'] ?? '' ) : '';
			return self::reject( sprintf(
				'訂單的綠界交易身分在退款準備期間被改動（%s），已中止；未送出任何金流動作，請人工核對後重試。',
				sanitize_text_field( $field )
			) );
		}
		if ( 'core_flag_unreadable' === $action ) {
			$flag = is_array( $decision ) ? (string) ( $decision['flag'] ?? '?' ) : '?';
			return self::reject( sprintf(
				'核心退款紀錄的旗標「%s」無法解讀（非 bool／0／1），已拒絕操作；請人工核對後處理。',
				sanitize_text_field( $flag )
			) );
		}
		if ( 'core_amount_unreadable' === $action ) {
			return self::reject( '核心退款紀錄的金額欄位無法解讀為整數，已拒絕操作；請人工核對後處理。' );
		}
		if ( 'core_finalized' === $action ) {
			return self::reject( '核心退款請求已核定完成，不得再送出金流動作。' );
		}
		if ( 'core_provider_done' === $action ) {
			return self::reject( '核心已標記此請求由 provider 完成，不得重複送出。' );
		}
		if ( 'core_record_only' === $action ) {
			return self::reject( '此為人工記錄型退款（record-only，未經金流），不得送出 DoAction。' );
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
			// 🔴 v0.3.0：**送出之前**先落盤一筆 operation token。
			//
			// reservation 證明「這一筆退款是我們仲裁通過的」，但它不區分「還沒送」
			// 與「送了但沒收到回應」。process 在 do_action_refund 中途被 kill（PHP
			// timeout、worker 重啟）時，ledger 停在 reserve 當下的樣子——人工核定
			// 時看不出綠界那邊到底有沒有收到請求。
			//
			// 寫不進去就**不送**：沒有 durable 的送出前紀錄，等於沒有事後可查證的
			// 依據，而退款是不可逆的。
			$op_token = self::operation_token( $request_id, $step, count( $executed ) );
			$sent_at  = current_time( 'mysql' );

			// 🔴 #2F：每一步都 append 到 `operations`，而不是覆寫單一 token。
			//
			// E→N 這類多步流程覆寫之後，人工核定時只看得到最後一步——「E 到底送
			// 出去了沒」變成無法回答的問題，而那正是決定要不要補 N 的依據。
			$armed = self::mark_attempt( $order_id, $request_id, [
				'operation_token' => $op_token,
				'pending_step'    => $step,
				'sent_at'         => $sent_at,
			], $fingerprint, null, static function ( array $entry ) use ( $op_token, $step, $sent_at ): array {
				$ops = is_array( $entry['operations'] ?? null ) && array_is_list( $entry['operations'] )
					? $entry['operations']
					: [];

				$ops[] = [
					'step'    => $step,
					'token'   => $op_token,
					'sent_at' => $sent_at,
					'result'  => 'sent',
				];

				$entry['operations'] = $ops;

				return $entry;
			} );

			if ( ! $armed ) {
				YSLogger::error( 'ecpay', 'CRITICAL: 送出前紀錄寫入失敗，已中止（未送出本步）', [
					'order_id'   => $order_id,
					'request_id' => $request_id,
					'step'       => $step,
					'executed'   => implode( ',', $executed ),
				] );

				if ( $executed ) {
					// 已經有步驟生效了 → 全單凍結，等人工核定。
					return [
						'success' => false,
						'outcome' => 'indeterminate',
						'message' => sprintf(
							'已執行 %s，但下一步（%s）的送出前紀錄寫不進去，已中止並凍結本單退款；'
								. '請於綠界後台確認實際狀態後人工核定。',
							implode( ',', $executed ),
							$step
						),
					];
				}

				return self::reject( '退款送出前的紀錄無法持久化，已中止；未執行任何金流操作，請重試。' );
			}

			// 🔴 v0.3.0：`executed` 只記**成功**的步驟。舊版在取得結果之前就把 step
			// 推進陣列，於是 E 成功、N 失敗會被記成 `executed=E,N`——人工核定時看到
			// 的是「兩步都做了」，實際上 N 從未生效。事實要分三種：已完成、嘗試過、
			// 失敗於哪一步。
			$result           = $client->do_action_refund( $merchant_trade_no, $trade_no, (float) $amount_twd, $step );
			$attempted        = array_merge( $executed, [ $step ] );

			// 🔴 v0.3.0（#2G）：每一步的**結果**也 append 一筆不可變事件。
			//
			// #2F 只寫 `sent`。回應是什麼、是被明確拒絕還是傳輸中斷、綠界回了哪個
			// RtnCode——全都只在 log 檔裡，而人工核定看的是訂單。
			//
			// 三個分支共用同一次寫入，因此無論走哪一條路都留得下同一組事實。
			self::append_operation_result( $order_id, $request_id, $fingerprint, [
				'step'      => $step,
				'token'     => $op_token,
				'result'    => 'result',
				// 傳輸分類：三態，不是 bool。indeterminate 與 rejected 對「可不可以
				// 重送」的答案完全相反。
				'transport' => ! empty( $result['indeterminate'] )
					? 'indeterminate'
					: ( ! empty( $result['success'] ) ? 'ok' : 'rejected' ),
				'attempted' => implode( ',', $attempted ),
				'executed'  => implode( ',', $executed ),
				'rtn_code'  => (string) ( $result['data']['RtnCode'] ?? '' ),
				'rtn_msg'   => mb_substr( (string) ( $result['message'] ?? '' ), 0, 300 ),
				'response_trade_no' => is_string( $result['data']['TradeNo'] ?? null )
					? (string) $result['data']['TradeNo']
					: '',
				'sent_at'   => $sent_at,
			] );

			if ( ! empty( $result['indeterminate'] ) ) {
				// 傳輸不確定：綠界端可能已生效 → attempt 維持 pending，等人工核定。
				// executed 不含本步（我們不知道它有沒有生效），另記 attempted。
				self::note_attempt( $order_id, $request_id, [
					'executed'       => implode( ',', $executed ),
					'attempted_step' => $step,
					'note'           => '結果未明（' . (string) ( $result['message'] ?? '' ) . '）',
				], $fingerprint, '結果未明註記' );

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
				$prior_steps = $executed; // 已成功的步驟（不含本步）

				if ( $prior_steps ) {
					self::note_attempt( $order_id, $request_id, [
						'executed'       => implode( ',', $prior_steps ),
						'attempted_step' => $step,
						'failed_step'    => $step,
						'rtn_code'       => (string) ( $result['data']['RtnCode'] ?? '' ),
						'rtn_msg'        => (string) ( $result['message'] ?? '' ),
						'note'           => sprintf(
							'部分完成：%s 成功、%s 失敗（%s）',
							implode( ',', $prior_steps ),
							$step,
							(string) ( $result['message'] ?? '' )
						),
					], $fingerprint, '部分完成註記' );

					YSLogger::error( 'ecpay', 'CRITICAL: 多步退款部分完成（前置步驟已改變綠界端狀態）', [
						'order_id'    => $order_id,
						'request_id'  => $request_id,
						'executed'    => implode( ',', $prior_steps ),
						'failed_step' => $step,
					] );

					// attempt 維持 pending ＝ 全單凍結，等人工核定。
					return [
						'success' => false,
						'outcome' => 'indeterminate',
						'message' => sprintf(
							'退款部分完成（已執行 %s，%s 失敗）：前置步驟已改變綠界端交易狀態，'
								. '不得重送完整流程。本單退款已凍結，請於綠界後台確認實際狀態後以 '
								. 'wp ys-cart refund-finalization resolve 人工核定。',
							implode( ',', $prior_steps ),
							$step
						),
					];
				}

				// 第一步就被明確拒絕（RtnCode≠1，金流未動）→ 可安全重試。
				$marked = self::mark_attempt( $order_id, $request_id, [
					'status'         => 'failed',
					'executed'       => implode( ',', $executed ), // 仍是空字串：第一步就被拒
					'attempted_step' => $step,
					'failed_step'    => $step,
					'rtn_code'       => (string) ( $result['data']['RtnCode'] ?? '' ),
					'rtn_msg'        => (string) ( $result['message'] ?? '' ),
				], $fingerprint, 'failed' );

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
			// 本步成功 → 才計入 executed。
			$executed = $attempted;

			if ( $step !== $plan[ array_key_last( $plan ) ] ) {
				$stepped = self::mark_attempt( $order_id, $request_id, [
					'executed' => implode( ',', $executed ),
					'note'     => '已完成動作 ' . $step . '，準備執行下一步',
				], $fingerprint, null );

				if ( ! $stepped ) {
					return self::indeterminate_persist_failure( $order_id, $request_id, 'step:' . $step );
				}
			}
		}

		// 🔴 v0.3.0：回應的 TradeNo 必須是**非空字串**，而且存進獨立欄位。
		//
		// 舊版是 `(string) ( $result['data']['TradeNo'] ?? $trade_no )`：綠界回了
		// `null`／`''`／數字 0 時，會靜默退回成我們自己送出去的 trade_no，於是
		// 「綠界確認的交易編號」與「我們以為的交易編號」再也分不出來；而且它被寫
		// 回 `trade_no`——指紋欄位——把比對基準一起改掉。
		$raw_trade_no  = $result['data']['TradeNo'] ?? null;
		$done_trade_no = is_string( $raw_trade_no ) ? trim( $raw_trade_no ) : '';

		if ( '' === $done_trade_no ) {
			// 金流已經動了，但回應沒有可驗證的交易編號 → 不得宣告成功。
			YSLogger::error( 'ecpay', 'CRITICAL: 退款成功回應缺少 TradeNo，無法驗證', [
				'order_id'   => $order_id,
				'request_id' => $request_id,
				'raw'        => is_scalar( $raw_trade_no ) ? (string) $raw_trade_no : gettype( $raw_trade_no ),
			] );

			self::note_attempt( $order_id, $request_id, [
				'note' => '綠界回應缺少 TradeNo，無法驗證，已凍結待人工核定',
			], $fingerprint, '缺 TradeNo 註記' );

			return [
				'success' => false,
				'outcome' => 'indeterminate',
				'message' => '綠界回應成功但未帶交易編號（TradeNo），無法驗證這筆退款；本單退款已凍結，請於綠界後台確認後人工核定。',
			];
		}

		$marked = self::mark_attempt( $order_id, $request_id, [
			'status'            => 'done',
			'executed'          => implode( ',', $executed ),
			'response_trade_no' => $done_trade_no,
		], $fingerprint, 'done' );

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
	 * 一筆 `status=done` 的 entry 是否真的「做完了」？（v0.3.0，#2G）
	 *
	 * 🔴 冪等重放會讓核心**結案**：它會把訂單標成已退款、把金額寫進帳、不再有任何
	 * 機制回來核對。所以「先前已成功」這個結論必須有完整證據，而不是一個狀態字串。
	 *
	 * 四項全部要過，任何一項不成立都代表這筆紀錄講不清楚它做了什麼：
	 *
	 *   1. `plan` 逐字元等於這一次算出來的計畫——關帳狀態可能已經改變，
	 *      當時做的是 `N`、現在需要的是 `E,N`，那不是同一件事
	 *   2. `executed` 逐字元等於 `plan`——`plan=E,N` 而 `executed=E` 代表只做了一半
	 *   3. `operations` 是完整的 append-only 歷史：計畫裡的每一步都有一筆，
	 *      而且每一筆都帶得出 token 與送出時間
	 *   4. `response_trade_no` 是**非空字串**——綠界確認的退款交易編號，
	 *      沒有它就沒有任何東西可以拿去對帳
	 *
	 * @param array<string,mixed> $entry
	 * @param list<string>        $plan
	 * @return string|null null＝完整；否則是可回報的原因
	 */
	private static function done_replay_problem( array $entry, array $plan ): ?string {
		$expected_plan = implode( ',', $plan );

		$entry_plan = $entry['plan'] ?? null;
		if ( ! is_string( $entry_plan ) || $entry_plan !== $expected_plan ) {
			return sprintf(
				'plan 不符（紀錄 %s／本次 %s）',
				is_scalar( $entry_plan ) ? (string) $entry_plan : gettype( $entry_plan ),
				$expected_plan
			);
		}

		$executed = $entry['executed'] ?? null;
		if ( ! is_string( $executed ) || $executed !== $expected_plan ) {
			return sprintf(
				'executed 與 plan 不一致（executed %s／plan %s）',
				is_scalar( $executed ) ? (string) $executed : gettype( $executed ),
				$expected_plan
			);
		}

		$operations = $entry['operations'] ?? null;
		if ( ! is_array( $operations ) || ! array_is_list( $operations ) ) {
			return 'operations 歷史缺失或格式異常';
		}

		foreach ( $plan as $index => $step ) {
			$op = $operations[ $index ] ?? null;
			if ( ! is_array( $op ) ) {
				return sprintf( 'operations 缺少第 %d 步（%s）', $index + 1, $step );
			}
			if ( ( $op['step'] ?? null ) !== $step ) {
				return sprintf(
					'operations 第 %d 步不符（紀錄 %s／計畫 %s）',
					$index + 1,
					is_scalar( $op['step'] ?? null ) ? (string) $op['step'] : gettype( $op['step'] ?? null ),
					$step
				);
			}
			foreach ( [ 'token', 'sent_at' ] as $field ) {
				$value = $op[ $field ] ?? null;
				if ( ! is_string( $value ) || '' === $value ) {
					return sprintf( 'operations 第 %d 步缺少 %s', $index + 1, $field );
				}
			}
		}

		$response_trade_no = $entry['response_trade_no'] ?? null;
		if ( ! is_string( $response_trade_no ) || '' === $response_trade_no ) {
			return '缺少綠界確認的交易編號（response_trade_no）';
		}

		return null;
	}

	/**
	 * 這一筆 attempt 的交易身分是否仍與 detail 的**當下值**一致？（v0.3.0，#2F）
	 *
	 * 🔴 交易證據（trade_no、mer_trade_no、gwsr、商店、環境、請款金額）是在
	 * `process_refund()` 開頭讀一次的。CAS 重試時只重驗核心 ledger，這些**完全
	 * 沒有再看**——期間 detail 被改寫（webhook 寫入另一筆交易、資料修復、
	 * 換商店設定）之後，我們仍會以 A 的身分呼叫 DoAction。
	 *
	 * 這個檢查放進每一個 CAS closure：任何 identity drift 一律 abort，DoAction
	 * 呼叫次數為 0。不相關欄位的變動（其他 provider 的 ledger）不受影響——那些
	 * 只會讓 CAS 落敗重試，重試時這裡仍然通過。
	 *
	 * @param array<string,mixed> $detail      當下的 payment_detail
	 * @param array<string,mixed> $fingerprint reservation 當時的交易指紋
	 * @return string|null null＝一致；否則是漂移的欄位說明
	 */
	private static function identity_drift( array $detail, array $fingerprint ): ?string {
		// 🔴 v0.3.0（#2G）：每一個身分欄位都必須 **typed-present**。
		//
		// 舊版的兩條寬鬆規則各自製造一個洞：
		//   1. `(string)` 轉型——`null`、`0`、`false` 都會變成看得下去的值
		//   2. 「當下缺值不算漂移」（gwsr 專屬）——期間有人把 gwsr 刪掉、或
		//      資料修復把欄位清空，比對就自動跳過，DoAction 照送
		//
		// 身分是不可逆動作的依據：**缺欄位不是「沒變」，是「無法證明沒變」**。
		$checks = [
			'trade_no'          => $detail['trade_no'] ?? null,
			'merchant_trade_no' => $detail['mer_trade_no'] ?? null,
			'merchant_id'       => $detail['ecpay_merchant_id'] ?? null,
			'environment'       => $detail['ecpay_environment'] ?? null,
			// gwsr 兩個歷史鍵名都接受，但**必須有一個存在**。
			'gwsr'              => $detail['gwsr'] ?? $detail['ecpay_gwsr'] ?? null,
		];

		foreach ( $checks as $key => $current ) {
			$expected = $fingerprint[ $key ] ?? null;

			// 期望值本身不是非空字串 → 指紋在建立時就不合格，不得放行。
			if ( ! is_string( $expected ) || '' === $expected ) {
				return sprintf( '%s（指紋缺值）', $key );
			}

			if ( ! is_string( $current ) || '' === $current ) {
				return sprintf(
					'%s（指紋 %s／當下 %s）',
					$key,
					$expected,
					null === $current ? '缺' : gettype( $current )
				);
			}

			if ( ! hash_equals( $expected, $current ) ) {
				return sprintf( '%s（指紋 %s／當下 %s）', $key, $expected, $current );
			}
		}

		// 請款金額：退款金額的上界依據，改變了就不能沿用原本的判定。
		//
		// 🔴 #2G：指紋端也必須 typed-present。舊版的 `null !== $charged_exp` 讓
		// 「指紋根本沒有這個欄位」變成跳過檢查——而缺欄位的指紋正是最該擋下的那種。
		$charged_exp = $fingerprint['charged_amount'] ?? null;
		if ( ! is_int( $charged_exp ) || $charged_exp <= 0 ) {
			return sprintf(
				'charged_amount（指紋 %s）',
				null === $charged_exp ? '缺' : gettype( $charged_exp )
			);
		}

		$charged_now = CoreRefundAuthorization::canonical_int( $detail['ecpay_charged_amount'] ?? null );
		if ( $charged_now !== $charged_exp ) {
			return sprintf(
				'charged_amount（指紋 %d／當下 %s）',
				$charged_exp,
				null === $charged_now ? '無法解讀' : (string) $charged_now
			);
		}

		return null;
	}

	/**
	 * 這份 QueryTradeInfo 回應可以拿來當退款依據嗎？（v0.3.0）
	 *
	 * 四道全部要過：
	 *   1. `success === true`（嚴格布林，不接受 truthy）
	 *   2. `mac_verified === true` —— 沒有 CheckMacValue 的回應無法證明來源
	 *   3. `data` 是陣列
	 *   4. 交易識別逐字元相符：MerchantTradeNo 必須是我們送出的那一筆；
	 *      回應若帶 TradeNo，也必須與訂單記錄的相符
	 *
	 * @param array<string,mixed> $query
	 * @return string|null null＝可採信；否則是可回報的原因
	 */
	private static function query_problem( array $query, string $merchant_trade_no, string $trade_no ): ?string {
		if ( true !== ( $query['success'] ?? null ) ) {
			return (string) ( $query['message'] ?? '查詢未成功' );
		}

		if ( true !== ( $query['mac_verified'] ?? null ) ) {
			return 'CheckMacValue 未驗證通過（無法證明回應來源）';
		}

		$data = $query['data'] ?? null;
		if ( ! is_array( $data ) ) {
			return '回應內容格式異常';
		}

		// 🔴 v0.3.0（#2G）：**授權身分用 raw bytes 逐位元比較，不做任何正規化。**
		//
		// CheckMacValue 的計算允許——而且必須——依綠界的正規化規則（URL encode 的
		// 大小寫、特定字元的替換）。那是**驗簽**的需要。
		//
		// 但「這份回應講的是不是我送出去的那一筆交易」是另一個問題，它的答案不能
		// 靠正規化拉近。舊版對兩個識別碼都先 `trim()`：`" YS123"` 與 `"YS123"` 於
		// 是相等。綠界的 MerchantTradeNo 不含空白，會出現空白只有兩種可能——回應
		// 不是我們以為的那一筆，或中間有東西改過它。兩種都不該放行。
		$resp_mtn = $data['MerchantTradeNo'] ?? null;
		if ( ! is_string( $resp_mtn ) || '' === $resp_mtn || ! hash_equals( $merchant_trade_no, $resp_mtn ) ) {
			return sprintf(
				'MerchantTradeNo 不符（回應 %s）',
				is_scalar( $resp_mtn ) ? sanitize_text_field( (string) $resp_mtn ) : gettype( $resp_mtn )
			);
		}

		// 🔴 #2F：TradeNo 必須**存在**且相符。
		//
		// 舊版只在「有值時」比對：MAC 正確、MerchantTradeNo 正確、但 TradeNo 缺
		// 失的回應仍可授權退款——而 TradeNo 正是我們要送去 DoAction 的識別碼。
		$resp_tn = $data['TradeNo'] ?? null;
		if ( ! is_string( $resp_tn ) || '' === $resp_tn ) {
			return '回應未帶 TradeNo（無法證明是同一筆交易）';
		}
		if ( '' === $trade_no || ! hash_equals( $trade_no, $resp_tn ) ) {
			return sprintf( 'TradeNo 不符（回應 %s）', sanitize_text_field( $resp_tn ) );
		}

		return null;
	}

	/**
	 * 業務拒絕（金流確定未動）——core 以 rejected_terminal 表示「可安全重試」。
	 *
	 * @return array{success:false, outcome:string, message:string}
	 */
	/**
	 * 這一次金流動作的 durable 識別（v0.3.0）
	 *
	 * 由 request_id、步驟與序號決定——**不是隨機值**：同一筆請求的同一步驟重試時
	 * 必須產生同一個 token，否則 token 本身就無法用來判斷「這是不是同一次動作」。
	 */
	private static function operation_token( string $request_id, string $step, int $sequence ): string {
		return substr( hash( 'sha256', $request_id . '|' . $step . '|' . $sequence ), 0, 32 );
	}

	/**
	 * 交易指紋的摘要（v0.3.0，#2G）
	 *
	 * 每一筆 forensic event 都帶著它。人工核定時只要比對這個值，就能判斷兩筆
	 * 紀錄講的是不是同一筆交易身分——不必逐欄比對，也不必把完整指紋重複塞進
	 * 每一筆事件裡。
	 *
	 * @param array<string,mixed> $fingerprint
	 */
	private static function fingerprint_digest( array $fingerprint ): string {
		$canonical = $fingerprint;
		ksort( $canonical );

		return substr( hash( 'sha256', (string) wp_json_encode( $canonical ) ), 0, 32 );
	}

	/**
	 * 把一筆**不可變**的 result event 追加到 operations 歷史（v0.3.0，#2G）
	 *
	 * 🔴 #2F 只 append `sent`：送出去之前寫一筆，然後就沒有了。回應是什麼、
	 * 是被明確拒絕還是傳輸中斷、綠界回了哪個 RtnCode——全都只在 log 檔裡，而
	 * 人工核定看的是訂單，不是 log。
	 *
	 * 這裡補上另一半：每一步的結果也 append 一筆，內容涵蓋核定需要的全部事實：
	 * token、步驟、attempted／executed、傳輸分類、RtnCode／RtnMsg、回應交易編號、
	 * 指紋摘要、時間戳。
	 *
	 * append 是**冪等**的：同一個 (token, phase) 已存在就不重複追加——CAS 重試與
	 * 供方重送都不會讓歷史長出重複的事件。既有事件一律不修改（immutable）。
	 *
	 * @param array<string,mixed> $fingerprint
	 * @param array<string,mixed> $event
	 */
	private static function append_operation_result(
		int $order_id,
		string $request_id,
		array $fingerprint,
		array $event
	): bool {
		$event['digest']      = self::fingerprint_digest( $fingerprint );
		$event['recorded_at'] = current_time( 'mysql' );

		$appended = self::mark_attempt(
			$order_id,
			$request_id,
			[],
			$fingerprint,
			null,
			static function ( array $entry ) use ( $event ): array {
				$ops = is_array( $entry['operations'] ?? null ) && array_is_list( $entry['operations'] )
					? $entry['operations']
					: [];

				foreach ( $ops as $existing ) {
					if ( ! is_array( $existing ) ) {
						continue;
					}
					// 已經有同一個動作、同一個階段的事件 → 不重複追加，也不覆寫。
					if ( ( $existing['token'] ?? null ) === ( $event['token'] ?? null )
						&& ( $existing['result'] ?? null ) === ( $event['result'] ?? null ) ) {
						return $entry;
					}
				}

				$ops[]               = $event;
				$entry['operations'] = $ops;

				return $entry;
			}
		);

		if ( $appended ) {
			return true;
		}

		// 🔴 ledger 寫不進去時，事件本身仍必須保存下來——它是「綠界那邊發生了
		// 什麼」的唯一紀錄，而人工核定只能依據它。
		self::record_orphan_facts( $order_id, $request_id, array_merge( $event, [ 'phase' => 'operation_result' ] ) );

		return false;
	}

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
	 * 由 `wp ys-cart refund-finalization resolve` 人工核定（本外掛的凍結會由核心核定同步解除）。
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

		// 金流已經動了。就算 attempt 寫不進去（或我們已失去授權），provider 的
		// 事實仍必須保存下來——人工核定唯一能依據的就是它。
		self::record_orphan_facts( $order_id, $request_id, [
			'phase'    => $phase,
			'trade_no' => $trade_no,
		] );

		return [
			'success' => false,
			'outcome' => 'indeterminate',
			'message' => '退款已送出但紀錄寫入失敗，本單退款已凍結：請先於綠界後台確認實際狀態，'
				. '再以 wp ys-cart refund-finalization resolve 人工核定（請勿另開新退款）。',
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
	 *   4. `executed` 只能往前，不能倒退
	 *
	 * 🔴 第 3 點先前只寫在註解裡：方法既沒有收指紋參數，實作也完全沒有比對。
	 * 沒有它，任何一次 step／terminal 寫入都可能落在**另一筆交易**的 attempt 上
	 * （request_id 撞號、attempt 被搬移、訂單授權資訊被改寫）。
	 *
	 * @param array<string,mixed> $fingerprint     reservation 當時的交易指紋
	 * @param string|null         $expect_terminal 這次要寫入的終態（'done'／'failed'／null＝非終態）
	 */
	private static function mark_attempt(
		int $order_id,
		string $request_id,
		array $patch,
		array $fingerprint,
		?string $expect_terminal = null,
		?callable $append = null
	): bool {
		$result = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $attempt, &$decision ) use ( $request_id, $patch, $fingerprint, $append ): ?array {
				// 🔴 指紋欄位不可變。少了這道檢查，終態寫入時的
				// `'trade_no' => $done_trade_no` 會把指紋裡的 trade_no 換成綠界回應
				// 的值——之後任何一次 fingerprint_matches 都是在跟被改過的值比對。
				$forbidden = array_intersect( array_keys( $patch ), self::FINGERPRINT_KEYS );
				if ( $forbidden ) {
					$decision = [ 'action' => 'fingerprint_write_attempt', 'keys' => array_values( $forbidden ) ];
					return null;
				}

				// 🔴 核心授權必須用**這一次 CAS 讀到的**值完整重驗。
				//
				// 舊版只檢查「entry 存在且 gateway 不同」：entry 消失、status 改成
				// 別的、金額被改、旗標被設起來——全都通過，於是 arm token 照寫、
				// DoAction 照送、終態照落。用的是同一份 validator，不會再漂移。
				//
				// $expect_terminal 為 null（送出前 arm／中途註記）時必須通過；終態
				// 寫入時允許核心已經前進（下方 authority_drift 分流）。
				$core_problem = CoreRefundAuthorization::problem_in( $detail, $request_id, (int) $fingerprint['amount'] );
				if ( null !== $core_problem ) {
					$decision = [
						'action'  => 'core_authority_lost',
						'problem' => $core_problem,
					];
					return null;
				}

				// 交易身分同樣以這一次讀到的值重建並比對（見 identity_drift）。
				$drift = self::identity_drift( $detail, $fingerprint );
				if ( null !== $drift ) {
					$decision = [ 'action' => 'identity_drift', 'field' => $drift ];
					return null;
				}

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

				// 指紋必須仍相符——這一筆 attempt 必須確實是我們 reserve 的那一筆。
				if ( ! self::fingerprint_matches( $current, $fingerprint ) ) {
					$decision = [ 'action' => 'fingerprint_mismatch' ];
					return null;
				}

				// executed 只能往前，不能倒退。
				$old_executed = (string) ( $current['executed'] ?? '' );
				$new_executed = array_key_exists( 'executed', $patch ) ? (string) $patch['executed'] : $old_executed;
				if ( '' !== $old_executed && ! str_starts_with( $new_executed, $old_executed ) ) {
					$decision = [ 'action' => 'executed_regression', 'old' => $old_executed, 'new' => $new_executed ];
					return null;
				}

				$next = array_merge( $current, $patch );

				// append-only 的部分（每步 operation 歷史）由 callback 在**最新**的
				// entry 上追加——直接放進 patch 會在 CAS 重試時重複追加。
				if ( null !== $append ) {
					$appended = $append( $next );
					if ( is_array( $appended ) ) {
						$next = $appended;
					}
				}

				$ledger[ $request_id ]       = $next;
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
		//
		// 🔴 但「名稱相同」不夠。兩層都要對得上：
		//   1. 指紋 —— 一筆屬於**別的交易**的 attempt 也可能剛好是 done
		//   2. 這次要寫的每一個欄位 —— 既有 entry 必須**逐欄相符**。
		//      舊版只比 status：一筆 `executed=E`（只做了第一步）的 done，會讓
		//      我們把 `executed=E,N` 的請求當成「已經落盤過」而回報成功。
		if ( 'terminal' === $action && null !== $expect_terminal
			&& $expect_terminal === ( is_array( $decision ) ? (string) ( $decision['status'] ?? '' ) : '' ) ) {
			$existing = OrderPaymentDetail::read( $order_id );
			$entry    = is_array( $existing['_ys_ecpay_refunds'][ $request_id ] ?? null )
				? $existing['_ys_ecpay_refunds'][ $request_id ]
				: null;

			if ( null !== $entry
				&& self::fingerprint_matches( $entry, $fingerprint )
				&& self::patch_readback_matches( $entry, $patch ) ) {
				return true;
			}

			YSLogger::error( 'ecpay', 'CRITICAL: 既有終態與本次要寫的內容不一致（不得當成冪等）', [
				'order_id'   => $order_id,
				'request_id' => $request_id,
				'expected'   => $expect_terminal,
			] );
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

	/**
	 * 既有 entry 是否**逐欄**等於這次要寫的 patch（v0.3.0）
	 *
	 * 冪等的意義是「這次要做的事先前已經做完了」。只比 status 不足以支撐那個結論
	 * ——`executed`、`response_trade_no` 這些才是「做完了什麼」的內容。
	 *
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $patch
	 */
	private static function patch_readback_matches( array $entry, array $patch ): bool {
		foreach ( $patch as $key => $expected ) {
			if ( ! array_key_exists( $key, $entry ) ) {
				return false;
			}

			// 型別敏感：`'1'` 與 `1`、`''` 與 `null` 都不算相符。
			if ( $entry[ $key ] !== $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 金流已動、但我們失去了寫入權（核心把請求改派／核定）時，把 provider 的事實
	 * 保存在一個**不需要核心授權**的地方（v0.3.0）
	 *
	 * 這些事實是「綠界那邊到底發生了什麼」的唯一紀錄。因為失去授權就丟掉它們，
	 * 人工核定時將無從判斷該不該補退款。
	 *
	 * @param array<string,mixed> $facts
	 */
	/** orphan 寫入成功。 */
	public const ORPHAN_WRITTEN = 'written';

	/** 核心 CAS 服務缺席（舊核心）——寫不進去，但原因與 DB 故障不同。 */
	public const ORPHAN_CORE_UNAVAILABLE = 'core_unavailable';

	/** 寫入失敗（DB 故障、欄位損壞、CAS 耗盡）。 */
	public const ORPHAN_FAILED = 'failed';

	/**
	 * @param array<string,mixed> $facts
	 * @return string self::ORPHAN_* 之一
	 */
	private static function record_orphan_facts( int $order_id, string $request_id, array $facts ): string {
		$written = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail ) use ( $request_id, $facts ): array {
				$orphans = is_array( $detail['_ys_ecpay_orphan_facts'] ?? null ) ? $detail['_ys_ecpay_orphan_facts'] : [];

				$existing = is_array( $orphans[ $request_id ] ?? null ) ? $orphans[ $request_id ] : [];
				$existing[] = array_merge( $facts, [ 'recorded_at' => current_time( 'mysql' ) ] );

				$orphans[ $request_id ]              = $existing;
				$detail['_ys_ecpay_orphan_facts']    = $orphans;

				return $detail;
			}
		);

		if ( $written->is_persisted() ) {
			return self::ORPHAN_WRITTEN;
		}

		// 🔴 v0.3.0（#2G）：資料庫全失敗時，log 是**唯一**的載體——因此它必須帶著
		// 與 orphan 紀錄**完全相同**的那一份完整事實，而不是一個摘要。
		//
		// 舊版把 `$facts` 攤平 merge 進 context：鍵名撞到 `order_id`／`request_id`
		// 時會被覆蓋，而且沒有 outcome、沒有時間戳、沒有指紋摘要——人工核定從 log
		// 撈出來的東西與從訂單撈出來的形狀不一樣，對不起來。
		$outcome = \YangSheep\YSCartEcpay\Support\DetailWriteOutcome::CORE_UNAVAILABLE === $written->get_outcome()
			? self::ORPHAN_CORE_UNAVAILABLE
			: self::ORPHAN_FAILED;

		YSLogger::error( 'ecpay', 'CRITICAL: provider 事實無法落盤（只剩這一筆 log）', [
			'order_id'    => $order_id,
			'request_id'  => $request_id,
			'outcome'     => $outcome,
			'write_state' => $written->get_outcome(),
			'recorded_at' => current_time( 'mysql' ),
			// 同一份 facts，原樣保留（不攤平、不截斷）。
			'facts'       => $facts,
		] );

		return $outcome;
	}

	/** 非終態註記；寫不進去不改變結論（凍結由送出前的 pending 保證），僅記錄。 */
	private static function note_attempt( int $order_id, string $request_id, array $patch, array $fingerprint, string $what ): void {
		if ( self::mark_attempt( $order_id, $request_id, $patch, $fingerprint, null ) ) {
			return;
		}

		YSLogger::error( 'ecpay', 'CRITICAL: ' . $what . '寫入失敗（凍結仍由送出前 pending 保證）', [
			'order_id'   => $order_id,
			'request_id' => $request_id,
		] );

		// 🔴 #2F：金流已經動過了，這些是 provider 端**唯一**的事實。
		//
		// 舊版只記 log 就算了：ledger 寫不進去（或授權在期間改變）時，operation
		// token、步驟、RtnCode／RtnMsg 全部只存在於 log 檔——人工核定看不到。
		self::record_orphan_facts( $order_id, $request_id, array_merge( $patch, [ 'phase' => $what ] ) );
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
	/**
	 * 構成交易指紋的欄位（v0.3.0）
	 *
	 * 這些鍵一旦由 reservation 寫下就**不可變**：它們是「這一筆 attempt 指向哪一筆
	 * 綠界交易」的唯一依據。任何後續寫入（step 註記、終態、CLI 核定、core 同步）
	 * 都不得改動它們——改得動，指紋就不再證明任何事。
	 *
	 * 綠界回應帶回來的交易編號另存 `response_trade_no`，不覆蓋指紋內的 `trade_no`。
	 */
	private const FINGERPRINT_KEYS = [
		'amount',
		'charged_amount',
		'trade_no',
		'merchant_trade_no',
		'gwsr',
		'merchant_id',
		'environment',
	];

	/**
	 * 核心 ledger entry 的嚴格 schema 檢查（v0.3.0）
	 *
	 * 舊版用 `! empty( $core_entry['finalized'] )` 與 `(float) $core_entry['amount']`：
	 *   - `empty()` 把 `'0'`、`0`、`'false'`、`[]` 全部視為「沒有 finalized」——一個
	 *     以字串 `'0'` 寫入的旗標會被當成未完成而放行；反過來，任何非空字串（含
	 *     `'no'`）都會被當成已完成而擋下。旗標的真假不該由 truthiness 決定。
	 *   - `(float)` 轉型讓 `'1000abc'`、`'1e3'`、`true` 都變成看似合理的金額。
	 *
	 * 這裡改成型別敏感：旗標必須是真正的 bool（或明確的 0/1），金額必須是
	 * canonical 整數。任何無法解讀的值一律 fail-closed。
	 *
	 * @param array<string,mixed> $core_entry
	 * @return array<string,mixed>|null null＝通過
	 */
	private static function core_entry_problem( array $core_entry, int $amount_twd ): ?array {
		$status = $core_entry['status'] ?? null;
		if ( ! is_string( $status ) || 'submitting' !== $status ) {
			return [
				'action' => 'core_not_submitting',
				'status' => is_scalar( $status ) ? (string) $status : gettype( $status ),
			];
		}

		foreach ( [ 'finalized' => 'core_finalized', 'provider_done' => 'core_provider_done', 'record_only' => 'core_record_only' ] as $flag => $action ) {
			if ( ! array_key_exists( $flag, $core_entry ) ) {
				continue; // 沒有這個旗標＝沒有被設定過
			}

			$raw = $core_entry[ $flag ];
			if ( is_bool( $raw ) ) {
				if ( $raw ) {
					return [ 'action' => $action ];
				}
				continue;
			}
			if ( 0 === $raw || '0' === $raw ) {
				continue; // 明確的 false
			}
			if ( 1 === $raw || '1' === $raw ) {
				return [ 'action' => $action ];
			}

			// 無法解讀的旗標值：不得猜。
			return [
				'action' => 'core_flag_unreadable',
				'flag'   => $flag,
				'value'  => is_scalar( $raw ) ? (string) $raw : gettype( $raw ),
			];
		}

		$amount = $core_entry['amount'] ?? null;
		if ( is_int( $amount ) ) {
			$core_amount = $amount;
		} elseif ( is_string( $amount ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/', $amount ) ) {
			$core_amount = (int) $amount;
		} elseif ( is_float( $amount ) && floor( $amount ) === $amount && $amount >= 0 && $amount <= PHP_INT_MAX ) {
			$core_amount = (int) $amount; // 整數值的 float（JSON 解碼常見）
		} else {
			return [
				'action'      => 'core_amount_unreadable',
				'core_amount' => is_scalar( $amount ) ? (string) $amount : gettype( $amount ),
			];
		}

		if ( $core_amount !== $amount_twd ) {
			return [ 'action' => 'core_amount_mismatch', 'core_amount' => $core_amount ];
		}

		return null;
	}

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
			// 金額類欄位是整數：`'1000'` 與 `1000` 不算相符。
			if ( in_array( $key, [ 'amount', 'charged_amount' ], true ) ) {
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

		/**
		 * 蒐集某個 evidence group 的全部值。
		 *
		 * 🔴 每一個值都必須是 **canonical 非負整數**。舊版直接 `(int)` 轉型：
		 * `'abc'` 變 0、`'-1'` 變 -1、`'3.9'` 變 3——一個壞掉或帶負號的欄位會被
		 * 讀成「沒有分期」而放行。無法解讀的證據不是「沒有證據」，是**不可判定**。
		 *
		 * @return array{values:array<int,array{origin:string,key:string,value:int}>, malformed:array<int,string>}
		 */
		$collect = static function ( array $keys ) use ( $sources ): array {
			$values    = [];
			$malformed = [];

			foreach ( $sources as $origin => $source ) {
				foreach ( $keys as $key ) {
					if ( ! array_key_exists( $key, $source ) ) {
						continue;
					}

					$raw = $source[ $key ];
					if ( '' === $raw || null === $raw ) {
						continue; // 明確的空值＝這個來源沒有提供
					}

					if ( is_int( $raw ) ) {
						$number = $raw;
					} elseif ( is_string( $raw ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/', $raw ) ) {
						$number = (int) $raw;
					} else {
						$malformed[] = sprintf( '%s.%s=%s', $origin, $key, is_scalar( $raw ) ? (string) $raw : gettype( $raw ) );
						continue;
					}

					if ( $number < 0 ) {
						$malformed[] = sprintf( '%s.%s=%d（負值）', $origin, $key, $number );
						continue;
					}

					$values[] = [ 'origin' => $origin, 'key' => $key, 'value' => $number ];
				}
			}

			return [ 'values' => $values, 'malformed' => $malformed ];
		};

		$stage_keys  = [ 'ecpay_stage', 'stage', 'Stage', 'installment_count' ];
		$redeem_keys = [ 'ecpay_red_dan', 'red_dan', 'ecpay_red_de_amt', 'red_de_amt', 'ecpay_red_ok_amt', 'red_ok_amt' ];

		$stage  = $collect( $stage_keys );
		$redeem = $collect( $redeem_keys );

		// 任何一個 group 有無法解讀的值 → 不可判定（不得當成「沒有分期／沒有紅利」）。
		$malformed = array_merge( $stage['malformed'], $redeem['malformed'] );
		if ( $malformed ) {
			return [
				'type'   => 'unknown',
				'label'  => '卡別證據無法解讀的交易',
				'reason' => '非 canonical 非負整數：' . implode( '、', array_map( 'sanitize_text_field', $malformed ) ),
			];
		}

		// 分期：任何來源回報正的期數即成立（positive evidence wins）。
		foreach ( $stage['values'] as $entry ) {
			if ( $entry['value'] > 0 ) {
				return [
					'type'   => 'installment',
					'label'  => '分期付款',
					'reason' => sprintf( '%s=%d（來源：%s）', $entry['key'], $entry['value'], $entry['origin'] ),
				];
			}
		}

		// 紅利折抵：同上。
		foreach ( $redeem['values'] as $entry ) {
			if ( $entry['value'] > 0 ) {
				return [
					'type'   => 'redeem',
					'label'  => '紅利折抵交易',
					'reason' => sprintf( '%s=%d（來源：%s）', $entry['key'], $entry['value'], $entry['origin'] ),
				];
			}
		}

		// 🔴 一般信用卡需要**兩個**證明：非分期、且無紅利。舊版只要 `stage=0` 就放行，
		// 完全沒有紅利的證據也算過——那是「沒看到」而不是「證明沒有」。
		if ( ! $stage['values'] ) {
			return [
				'type'   => 'unknown',
				'label'  => '無法證明是否為分期的交易',
				'reason' => '訂單付款紀錄與 QueryTradeInfo 皆未提供 stage 欄位（v0.3.0 之前建立）',
			];
		}
		if ( ! $redeem['values'] ) {
			return [
				'type'   => 'unknown',
				'label'  => '無法證明是否有紅利折抵的交易',
				'reason' => '訂單付款紀錄與 QueryTradeInfo 皆未提供 red_* 欄位（v0.3.0 之前建立）',
			];
		}

		// 付款方式：必須有值，且所有來源一致。
		$types = [];
		foreach ( $sources as $origin => $source ) {
			foreach ( [ 'ecpay_payment_type', 'payment_type', 'PaymentType' ] as $key ) {
				if ( array_key_exists( $key, $source ) && '' !== (string) $source[ $key ] ) {
					$types[] = (string) $source[ $key ];
				}
			}
		}

		if ( ! $types ) {
			return [
				'type'   => 'unknown',
				'label'  => '無法證明付款方式的交易',
				'reason' => '訂單付款紀錄與 QueryTradeInfo 皆未提供 PaymentType',
			];
		}

		$distinct = array_values( array_unique( $types ) );
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

		return [ 'type' => 'plain_credit', 'label' => '一般信用卡', 'reason' => '' ];
	}
}
