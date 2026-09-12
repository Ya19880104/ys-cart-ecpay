<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSCreditCard;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Security\YSInboundPermission;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Ecpg\EcpgClient;
use YangSheep\YSCartEcpay\Ecpg\EcpgOrderContext;
use YangSheep\YSCartEcpay\Ecpg\EcpgSettlement;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\Settings;

/**
 * 站內付 2.0 的六條路（v0.5.0）
 *
 *   GET  /ecpay/ecpg/pay           託管付款頁（本站網域；order+key 能力網址，與核心感謝頁同級）
 *   POST /ecpay/ecpg/token         付款頁取 Token：訂閱→GetTokenbyBindingCard、一般→GetTokenbyTrade
 *   POST /ecpay/ecpg/confirm       SDK 給的 PayToken／BindCardPayToken → CreatePayment／CreateBindCard
 *   POST /ecpay/ecpg/charge-saved  登入顧客以已綁定卡片付這張訂單（CreatePaymentWithCardID，免輸卡號）
 *   POST /ecpay/ecpg/return        綠界幕後通知 ReturnURL（JSON；必回 1|OK）
 *   POST /ecpay/ecpg/result        3D 驗證後瀏覽器導回 OrderResultURL（表單 ResultData）
 *
 * 🔴 結果可能同時從 confirm／result／return 三條路進來，順序不定。狀態變更全部集中在
 * {@see EcpgSettlement::apply()}（冪等）；這裡只負責解碼、找訂單、決定回應。
 *
 * 🔴 對綠界的回應規則：解不開／不是我們的＝400（不 ACK：那不是給我們的）；已處理（含重複、
 * 業務拒絕）＝1|OK；**我們自己寫不進 DB**＝500 不 ACK，讓綠界依其機制重送（每天最多 4 次）。
 * 綁卡的 BindCardID 只在這份 payload 裡，ACK 之後就沒有第二次機會。
 */
final class EcpgPaymentController {
	public const NAMESPACE = 'ys-ecommerce/v1';

	public static function register_routes(): void {
		$controller = new self();

		register_rest_route( self::NAMESPACE, '/ecpay/ecpg/pay', [
			'methods'             => 'GET',
			'callback'            => [ $controller, 'pay_page' ],
			'permission_callback' => [ self::class, 'page_permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/ecpay/ecpg/token', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'token' ],
			'permission_callback' => [ self::class, 'browser_json_permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/ecpay/ecpg/confirm', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'confirm' ],
			'permission_callback' => [ self::class, 'browser_json_permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/ecpay/ecpg/charge-saved', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'charge_saved' ],
			'permission_callback' => [ self::class, 'browser_json_permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/ecpay/ecpg/return', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'return_notify' ],
			'permission_callback' => [ self::class, 'return_permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/ecpay/ecpg/result', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'order_result' ],
			'permission_callback' => [ self::class, 'result_permission' ],
		] );
	}

	// ── 權限（核心 YSInboundPermission：body 上限、content-type、速率、IP allowlist）─────

	public static function page_permission( \WP_REST_Request $request ) {
		return self::inbound_permission( 'ecpay_ecpg_page', [
			'body_max_bytes' => 4096,
			'rate_limit'     => [ 600, 60 ],
			'allowed_types'  => [],
			'verify_ip'      => false,
		], $request );
	}

	public static function browser_json_permission( \WP_REST_Request $request ) {
		return self::inbound_permission( 'ecpay_ecpg_browser', [
			'body_max_bytes' => 16384,
			'rate_limit'     => [ 120, 60 ],
			'allowed_types'  => [ 'application/json' ],
			'verify_ip'      => false,
		], $request );
	}

	public static function return_permission( \WP_REST_Request $request ) {
		return self::inbound_permission( 'ecpay_ecpg_return', [
			'body_max_bytes' => 65536,
			'rate_limit'     => [ 300, 60 ],
			'allowed_types'  => [ 'application/json' ],
		], $request );
	}

	public static function result_permission( \WP_REST_Request $request ) {
		return self::inbound_permission( 'ecpay_ecpg_result', [
			'body_max_bytes' => 65536,
			'rate_limit'     => [ 600, 60 ],
			'allowed_types'  => [ 'application/x-www-form-urlencoded', 'multipart/form-data' ],
			'verify_ip'      => false,
		], $request );
	}

	private static function inbound_permission( string $context, array $opts, \WP_REST_Request $request ) {
		if ( ! class_exists( YSInboundPermission::class ) ) {
			return true;
		}
		$callback = YSInboundPermission::build( $context, $opts );
		return $callback( $request );
	}

	// ── 託管付款頁 ─────────────────────────────────────────────────────────────

	public function pay_page( \WP_REST_Request $request ): void {
		$order = $this->resolve_order( $request, true );
		if ( ! $order ) {
			$this->render_notice( __( '找不到訂單', 'ys-cart-ecpay' ), __( '連結無效或已過期，請回到網站重新結帳。', 'ys-cart-ecpay' ), home_url( '/' ), __( '回到首頁', 'ys-cart-ecpay' ) );
		}
		$status = (string) ( $order->status ?? '' );
		if ( in_array( $status, [ 'processing', 'completed', 'shipped', 'delivered' ], true ) ) {
			wp_safe_redirect( EcpgOrderContext::thank_you_url( $order ) );
			exit;
		}
		if ( 'pending' !== $status ) {
			$repayable = method_exists( YSOrder::class, 'is_repayable' ) && YSOrder::is_repayable( $status );
			$this->render_notice(
				__( '這張訂單目前無法在此付款', 'ys-cart-ecpay' ),
				$repayable
					? __( '上一次付款沒有完成。請從「重新付款」開始新的付款流程。', 'ys-cart-ecpay' )
					: __( '訂單狀態不允許付款，如有疑問請聯繫客服。', 'ys-cart-ecpay' ),
				$repayable ? EcpgOrderContext::repay_url( $order ) : home_url( '/' ),
				$repayable ? __( '重新付款', 'ys-cart-ecpay' ) : __( '回到首頁', 'ys-cart-ecpay' )
			);
		}

		// 🔴 REST 對「有 cookie 但沒有 nonce」的請求一律視為訪客（WordPress 的 CSRF 防線）。
		// 付款頁是瀏覽器直接導航進來的，因此這裡要自己驗 logged_in cookie 才知道顧客是誰；
		// 之後頁面上的 JS 帶著綁定該使用者的 wp_rest nonce 呼叫 charge-saved，走的就是 WP 標準機制。
		$this->restore_cookie_user();
		$detail = OrderPaymentDetail::read( (int) $order->id ) ?? [];
		$flow   = (string) ( $detail['ecpay_ecpg_flow'] ?? EcpgOrderContext::FLOW_PAY );
		$amount = (int) ( $detail['ecpay_charged_amount'] ?? 0 );
		$saved  = $this->saved_cards_for( $order );
		$creds  = Settings::payment_credentials();

		$context = [
			'site_name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'order_number' => (string) ( $order->order_number ?? $order->id ),
			'amount'       => $amount,
			'flow'         => $flow,
			'saved_cards'  => $saved,
			'test_mode'    => ! empty( $creds['test_mode'] ),
			'sdk_url'      => EcpgClient::sdk_url( ! empty( $creds['test_mode'] ) ),
			'script_url'   => YS_CART_ECPAY_URL . 'assets/js/ys-cart-ecpay-ecpg-pay.js?ver=' . rawurlencode( YS_CART_ECPAY_VERSION ),
			'config'       => [
				'order'       => (int) $order->id,
				'key'         => (string) $request->get_param( 'key' ),
				'env'         => ! empty( $creds['test_mode'] ) ? 'Stage' : 'Prod',
				'flow'        => $flow,
				'tokenUrl'    => rest_url( self::NAMESPACE . '/ecpay/ecpg/token' ),
				'confirmUrl'  => rest_url( self::NAMESPACE . '/ecpay/ecpg/confirm' ),
				'savedUrl'    => rest_url( self::NAMESPACE . '/ecpay/ecpg/charge-saved' ),
				'hasSaved'    => [] !== $saved,
				'nonce'       => function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
				'i18n'        => [
					'loading'      => __( '正在載入綠界付款元件…', 'ys-cart-ecpay' ),
					'submitting'   => __( '處理中，請勿關閉視窗…', 'ys-cart-ecpay' ),
					'redirecting'  => __( '前往銀行 3D 驗證…', 'ys-cart-ecpay' ),
					'paid'         => __( '付款成功，正在前往訂單頁…', 'ys-cart-ecpay' ),
					'pending'      => __( '付款結果待綠界確認，請稍候於訂單頁查看，請勿重複付款。', 'ys-cart-ecpay' ),
					'sdk_failed'   => __( '綠界付款元件載入失敗，請重新整理再試。', 'ys-cart-ecpay' ),
					'token_failed' => __( '無法取得付款代碼，請重新整理再試。', 'ys-cart-ecpay' ),
					'network'      => __( '連線失敗，請稍後再試。', 'ys-cart-ecpay' ),
				],
			],
			'thank_you_url' => EcpgOrderContext::thank_you_url( $order ),
			'repay_url'     => EcpgOrderContext::repay_url( $order ),
		];

		$this->render_template( 'ecpg-pay', $context );
	}

	// ── 付款頁 API ─────────────────────────────────────────────────────────────

	public function token( \WP_REST_Request $request ): void {
		$order = $this->resolve_pending_order( $request );
		if ( ! $order ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '訂單不存在或不在可付款狀態。', 'ys-cart-ecpay' ) ], 404 );
		}
		$detail = OrderPaymentDetail::read( (int) $order->id ) ?? [];
		$mtn    = (string) ( $detail['ecpay_merchant_trade_no'] ?? $detail['mer_trade_no'] ?? '' );
		$amount = (int) ( $detail['ecpay_charged_amount'] ?? 0 );
		$flow   = (string) ( $detail['ecpay_ecpg_flow'] ?? EcpgOrderContext::FLOW_PAY );
		if ( '' === $mtn || $amount <= 0 ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '付款資料不完整，請回到結帳頁重新開始。', 'ys-cart-ecpay' ) ], 409 );
		}

		$member_id = (string) ( $detail['ecpay_ecpg_member_id'] ?? EcpgOrderContext::member_id( (int) ( $order->customer_id ?? 0 ) ) );
		$consumer  = EcpgOrderContext::consumer_info( $order, EcpgOrderContext::FLOW_BIND === $flow ? $member_id : '' );
		if ( ! EcpgOrderContext::has_contact( $consumer ) ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '訂單缺少有效的 Email 或手機，無法建立綠界付款。', 'ys-cart-ecpay' ) ], 409 );
		}

		$client = new EcpgClient();
		if ( EcpgOrderContext::FLOW_BIND === $flow && '' !== $member_id ) {
			$result = $client->get_token_by_binding_card( [
				'ConsumerInfo'   => $consumer,
				'OrderInfo'      => EcpgOrderContext::order_info( $order, $mtn, $amount, EcpgOrderContext::return_url() ),
				'OrderResultURL' => EcpgOrderContext::order_result_url(),
				'CustomField'    => '',
			] );
		} else {
			$flow   = EcpgOrderContext::FLOW_PAY;
			$result = $client->get_token_by_trade( [
				'RememberCard'      => 0,
				'PaymentUIType'     => 2,
				'ChoosePaymentList' => '1',
				'OrderInfo'         => EcpgOrderContext::order_info( $order, $mtn, $amount, EcpgOrderContext::return_url() ),
				'CardInfo'          => [ 'OrderResultURL' => EcpgOrderContext::order_result_url() ],
				'ConsumerInfo'      => $consumer,
			] );
		}

		$token = is_array( $result['data'] ?? null ) ? trim( (string) ( $result['data']['Token'] ?? '' ) ) : '';
		if ( EcpgClient::OUTCOME_SUCCESS !== $result['outcome'] || '' === $token ) {
			YSLogger::warning( 'ecpay', 'ECPG 取得 Token 失敗', [
				'order_id' => (int) $order->id,
				'flow'     => $flow,
				'outcome'  => $result['outcome'],
				'rtn_code' => $result['rtn_code'],
				'message'  => $result['message'],
			] );
			$this->respond_json( [ 'ok' => false, 'status' => 'token_failed', 'message' => $this->public_message( $result ) ], 502 );
		}

		$this->respond_json( [
			'ok'     => true,
			'flow'   => $flow,
			'token'  => $token,
			'expire' => (string) ( $result['data']['TokenExpireDate'] ?? '' ),
		] );
	}

	public function confirm( \WP_REST_Request $request ): void {
		$order = $this->resolve_pending_order( $request );
		if ( ! $order ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '訂單不存在或不在可付款狀態。', 'ys-cart-ecpay' ) ], 404 );
		}
		$detail = OrderPaymentDetail::read( (int) $order->id ) ?? [];
		$mtn    = (string) ( $detail['ecpay_merchant_trade_no'] ?? $detail['mer_trade_no'] ?? '' );
		$flow   = (string) ( $detail['ecpay_ecpg_flow'] ?? EcpgOrderContext::FLOW_PAY );
		$member = (string) ( $detail['ecpay_ecpg_member_id'] ?? '' );

		$pay_token  = $this->string_param( $request, 'pay_token' );
		$bind_token = $this->string_param( $request, 'bind_card_pay_token' );
		$client     = new EcpgClient();
		if ( EcpgOrderContext::FLOW_BIND === $flow && '' !== $bind_token && '' !== $member ) {
			$result = $client->create_bind_card( $bind_token, $member );
		} elseif ( '' !== $pay_token ) {
			$result = $client->create_payment( $pay_token, $mtn );
		} else {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '缺少付款代碼。', 'ys-cart-ecpay' ) ], 400 );
		}

		$this->respond_json( ...$this->authorization_response( $order, $result, 'ecpg_confirm' ) );
	}

	public function charge_saved( \WP_REST_Request $request ): void {
		$order = $this->resolve_pending_order( $request );
		if ( ! $order ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '訂單不存在或不在可付款狀態。', 'ys-cart-ecpay' ) ], 404 );
		}
		// 已綁定卡片只有卡片擁有者本人（登入狀態）可以用——能力網址不足以授權扣一張存好的卡。
		$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$customer_id = (int) ( $order->customer_id ?? 0 );
		if ( $user_id <= 0 || $user_id !== (int) ( $order->user_id ?? 0 ) || $customer_id <= 0 ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'forbidden', 'message' => __( '請先登入訂單所屬帳號，才能使用已綁定的卡片。', 'ys-cart-ecpay' ) ], 403 );
		}
		$card_id   = (int) $request->get_param( 'card_id' );
		$authority = $card_id > 0 && class_exists( YSCreditCard::class )
			? YSCreditCard::get_token_charge_authority_for_owner( $card_id, $customer_id, $user_id, EcpgOrderContext::GATEWAY_ID )
			: null;
		if ( ! is_array( $authority ) || '' === (string) ( $authority['raw_token'] ?? '' ) ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '找不到這張已綁定的卡片。', 'ys-cart-ecpay' ) ], 404 );
		}

		$detail = OrderPaymentDetail::read( (int) $order->id ) ?? [];
		$mtn    = (string) ( $detail['ecpay_merchant_trade_no'] ?? $detail['mer_trade_no'] ?? '' );
		$amount = (int) ( $detail['ecpay_charged_amount'] ?? 0 );
		if ( '' === $mtn || $amount <= 0 ) {
			$this->respond_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( '付款資料不完整，請回到結帳頁重新開始。', 'ys-cart-ecpay' ) ], 409 );
		}
		$member_id = EcpgOrderContext::member_id( $customer_id );
		$result    = ( new EcpgClient() )->create_payment_with_card_id( [
			'BindCardID'   => (string) $authority['raw_token'],
			'OrderInfo'    => EcpgOrderContext::order_info( $order, $mtn, $amount, EcpgOrderContext::return_url() ),
			'ConsumerInfo' => EcpgOrderContext::consumer_info( $order, $member_id ),
			'Need3D'       => 0,
			'CustomField'  => '',
		] );

		$this->respond_json( ...$this->authorization_response( $order, $result, 'ecpg_charge_saved' ) );
	}

	// ── 綠界回呼 ───────────────────────────────────────────────────────────────

	public function return_notify( \WP_REST_Request $request ): void {
		$credentials = Settings::payment_credentials();
		$body        = $request->get_json_params();
		if ( ! is_array( $body ) || [] === $body ) {
			$body = json_decode( (string) $request->get_body(), true );
		}
		$data = EcpgClient::decode_callback( $body, $credentials['hash_key'], $credentials['hash_iv'] );
		if ( null === $data ) {
			$this->respond_text( '0|Invalid Payload', 400 );
		}
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$order      = EcpgSettlement::find_order_by_merchant_trade_no( (string) ( $order_info['MerchantTradeNo'] ?? '' ) );
		if ( ! $order ) {
			$this->respond_text( '0|Order Not Found', 404 );
		}

		$outcome = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		if ( EcpgSettlement::STATUS_PERSIST_FAILED === $outcome['status']
			|| EcpgSettlement::VAULT_FAILED === $outcome['vault'] ) {
			// 我們自己寫不進去：不 ACK，讓綠界重送。綁卡的 BindCardID 只在這份 payload 裡。
			$this->respond_text( '0|Persist Failed', 500 );
		}
		if ( EcpgSettlement::STATUS_REJECTED === $outcome['status'] ) {
			YSLogger::warning( 'ecpay', 'ECPG ReturnURL 被拒絕（已 ACK，不變更訂單）', [
				'order_id' => (int) $order->id,
				'message'  => $outcome['message'],
			] );
		}
		$this->respond_text( '1|OK' );
	}

	public function order_result( \WP_REST_Request $request ): void {
		$credentials = Settings::payment_credentials();
		$raw         = $request->get_param( 'ResultData' );
		$data        = is_string( $raw ) && '' !== $raw
			? EcpgClient::decode_callback( wp_unslash( $raw ), $credentials['hash_key'], $credentials['hash_iv'] )
			: null;
		if ( null === $data ) {
			$this->render_notice( __( '付款結果無法解讀', 'ys-cart-ecpay' ), __( '綠界回傳的資料無法驗證。若您已完成付款，訂單狀態會在幾分鐘內由綠界通知更新。', 'ys-cart-ecpay' ), home_url( '/' ), __( '回到首頁', 'ys-cart-ecpay' ) );
		}
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$order      = EcpgSettlement::find_order_by_merchant_trade_no( (string) ( $order_info['MerchantTradeNo'] ?? '' ) );
		if ( ! $order ) {
			$this->render_notice( __( '找不到對應的訂單', 'ys-cart-ecpay' ), __( '付款結果無法對應到本站訂單，請聯繫客服並提供綠界交易編號。', 'ys-cart-ecpay' ), home_url( '/' ), __( '回到首頁', 'ys-cart-ecpay' ) );
		}

		$outcome = EcpgSettlement::apply( $order, $data, 'ecpg_result' );
		switch ( $outcome['status'] ) {
			case EcpgSettlement::STATUS_PAID:
			case EcpgSettlement::STATUS_ALREADY_PAID:
				wp_safe_redirect( EcpgOrderContext::thank_you_url( $order ) );
				exit;
			case EcpgSettlement::STATUS_FAILED:
				$this->render_notice(
					__( '付款未完成', 'ys-cart-ecpay' ),
					sprintf( /* translators: %s: ECPay RtnMsg */ __( '綠界回報：%s。您可以重新付款再試一次。', 'ys-cart-ecpay' ), $outcome['message'] ),
					EcpgOrderContext::repay_url( $order ),
					__( '重新付款', 'ys-cart-ecpay' )
				);
				// render_notice exits.
			default:
				// pending／rejected／persist_failed：不下定論，顧客到訂單頁等綠界幕後通知。
				$this->render_notice(
					__( '付款結果確認中', 'ys-cart-ecpay' ),
					__( '綠界尚未確認這筆交易的最終結果。請稍後於訂單頁查看，請勿重複付款。', 'ys-cart-ecpay' ),
					EcpgOrderContext::thank_you_url( $order ),
					__( '查看訂單', 'ys-cart-ecpay' )
				);
		}
	}

	// ── 共用 ───────────────────────────────────────────────────────────────────

	/**
	 * 把 CreatePayment／CreateBindCard／CreatePaymentWithCardID 的結果翻成付款頁 JS 看得懂的回應。
	 *
	 * @return array{0:array<string,mixed>,1:int}
	 */
	private function authorization_response( object $order, array $result, string $source ): array {
		if ( EcpgClient::OUTCOME_REJECTED === $result['outcome'] ) {
			return [ [ 'ok' => false, 'status' => 'rejected', 'message' => $this->public_message( $result ) ], 409 ];
		}
		$data = is_array( $result['data'] ?? null ) ? $result['data'] : [];
		if ( EcpgClient::OUTCOME_INDETERMINATE === $result['outcome'] ) {
			YSLogger::warning( 'ecpay', 'ECPG 授權結果不明，等待綠界幕後通知', [ 'order_id' => (int) $order->id, 'source' => $source, 'message' => $result['message'] ] );
			return [ [ 'ok' => false, 'status' => 'pending', 'message' => __( '付款結果待綠界確認，請稍後於訂單頁查看，請勿重複付款。', 'ys-cart-ecpay' ), 'redirect' => EcpgOrderContext::thank_you_url( $order ) ], 202 ];
		}
		if ( EcpgClient::OUTCOME_PROVIDER_FAILED === $result['outcome'] ) {
			if ( [] !== $data && isset( $data['OrderInfo'] ) ) {
				EcpgSettlement::mark_failure( $order, $data, $source );
			}
			return [ [ 'ok' => false, 'status' => 'failed', 'message' => $this->public_message( $result ), 'repay' => EcpgOrderContext::repay_url( $order ) ], 402 ];
		}

		// success：先看要不要 3D。
		$three_d = EcpgClient::three_d_url( $data );
		if ( '' !== $three_d ) {
			return [ [ 'ok' => true, 'status' => 'redirect', 'url' => $three_d ], 200 ];
		}
		$outcome = EcpgSettlement::apply( $order, $data, $source );
		switch ( $outcome['status'] ) {
			case EcpgSettlement::STATUS_PAID:
			case EcpgSettlement::STATUS_ALREADY_PAID:
				return [ [ 'ok' => true, 'status' => 'paid', 'redirect' => EcpgOrderContext::thank_you_url( $order ) ], 200 ];
			case EcpgSettlement::STATUS_FAILED:
				return [ [ 'ok' => false, 'status' => 'failed', 'message' => $outcome['message'], 'repay' => EcpgOrderContext::repay_url( $order ) ], 402 ];
			default:
				return [ [ 'ok' => false, 'status' => 'pending', 'message' => __( '付款已受理，狀態將於綠界確認後更新，請勿重複付款。', 'ys-cart-ecpay' ), 'redirect' => EcpgOrderContext::thank_you_url( $order ) ], 202 ];
		}
	}

	/** 給顧客看的失敗原因：綠界的 RtnMsg 可以顯示，連線／金鑰層的細節不給。 */
	private function public_message( array $result ): string {
		if ( EcpgClient::OUTCOME_PROVIDER_FAILED === ( $result['outcome'] ?? '' ) && null !== ( $result['rtn_code'] ?? null ) && '' !== (string) ( $result['rtn_msg'] ?? '' ) ) {
			return sanitize_text_field( (string) $result['rtn_msg'] ) . '（' . (int) $result['rtn_code'] . '）';
		}
		if ( EcpgClient::OUTCOME_REJECTED === ( $result['outcome'] ?? '' ) ) {
			return __( '目前無法建立綠界付款，請稍後再試。', 'ys-cart-ecpay' );
		}
		return __( '綠界未接受這筆付款，請稍後再試或改用其他卡片。', 'ys-cart-ecpay' );
	}

	/** REST 路徑上把已驗證的 logged_in cookie 還原成當前使用者（只在尚未有使用者時）。 */
	private function restore_cookie_user(): void {
		if ( ! function_exists( 'get_current_user_id' ) || get_current_user_id() > 0
			|| ! function_exists( 'wp_validate_auth_cookie' ) || ! function_exists( 'wp_set_current_user' ) ) {
			return;
		}
		$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
	}

	/** @return list<array{id:int,last4:string,brand:string,expire:string}> */
	private function saved_cards_for( object $order ): array {
		$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$customer_id = (int) ( $order->customer_id ?? 0 );
		if ( $user_id <= 0 || $user_id !== (int) ( $order->user_id ?? 0 ) || $customer_id <= 0
			|| ! class_exists( YSCreditCard::class ) || ! method_exists( YSCreditCard::class, 'get_customer_cards' ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) YSCreditCard::get_customer_cards( $customer_id, EcpgOrderContext::GATEWAY_ID ) as $card ) {
			if ( ! is_object( $card ) || 'active' !== (string) ( $card->status ?? 'active' ) ) {
				continue;
			}
			$out[] = [
				'id'     => (int) ( $card->id ?? 0 ),
				'last4'  => (string) ( $card->card_last4 ?? '' ),
				'brand'  => (string) ( $card->card_brand ?? '' ),
				'expire' => (string) ( $card->expire_date ?? '' ),
			];
		}
		return $out;
	}

	/** order＋key 能力網址 → 屬於本閘道的訂單；任何一項不符回 null（不透露哪一項）。 */
	private function resolve_order( \WP_REST_Request $request, bool $allow_query ): ?object {
		$order_id = (int) $request->get_param( 'order' );
		$key      = $this->string_param( $request, 'key' );
		if ( $order_id <= 0 || '' === $key || ! YSOrder::verify_order_key( $order_id, $key ) ) {
			return null;
		}
		$order   = YSOrder::find( $order_id );
		$gateway = (string) ( $order->gateway_id ?? $order->payment_method ?? '' );
		return $order && EcpgOrderContext::GATEWAY_ID === $gateway ? $order : null;
	}

	private function resolve_pending_order( \WP_REST_Request $request ): ?object {
		$order = $this->resolve_order( $request, false );
		return $order && 'pending' === (string) ( $order->status ?? '' ) ? $order : null;
	}

	private function string_param( \WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function respond_json( array $payload, int $status = 200 ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( $status );
		header_remove( 'Content-Type' );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function respond_text( string $body, int $status = 200 ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( $status );
		header_remove( 'Content-Type' );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/** @param array<string,mixed> $context */
	private function render_template( string $name, array $context ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header_remove( 'Content-Type' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );
		$ecpg = $context; // 模板以 $ecpg 取值
		require YS_CART_ECPAY_DIR . 'templates/frontend/' . $name . '.php';
		exit;
	}

	private function render_notice( string $title, string $message, string $action_url, string $action_label ): void {
		$this->render_template( 'ecpg-notice', [
			'site_name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'title'        => $title,
			'message'      => $message,
			'action_url'   => $action_url,
			'action_label' => $action_label,
		] );
	}
}
