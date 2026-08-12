<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Support\Settings;
use YangSheep\Ecommerce\Api\Storefront\YSRestAuth;
use YangSheep\Ecommerce\Services\Setup\YSPageResolver;

final class EcpayStoreSelector {
	private const MAP_PATH = '/Express/map';

	/** 核心的貨到付款金流 ID（與 requester 用同一個常數語意）。 */
	public const COD_GATEWAY_ID = 'ys_ec_cod';

	/**
	 * 物流方式 → 綠界 LogisticsSubType。
	 *
	 * 🔴 這裡**不再**自己維護一份清單，一律向 {@see EcpayShippingCatalog} 查。
	 * 舊版在這裡抄了第二份 subtype 表，於是「型錄加了方式、地圖沒加」就成了
	 * 一種可能——症狀是後台開得起來、按下選擇門市卻回 false，看起來像設定沒存好。
	 *
	 * @return array<string,string>
	 */
	private static function subtypes(): array {
		return EcpayShippingCatalog::map_subtypes();
	}

	/**
	 * 電子地圖表單資料。
	 *
	 * @param string $shipping_id    物流方式 ID。
	 * @param string $payment_method 顧客**當下選定的付款方式**；決定 IsCollection。
	 *
	 * @return array{action_url:string,fields:array<string,string>,temp_id:string,collection_mode:string}|false
	 */
	public static function build_map_form_data(
		string $shipping_id,
		string $context = 'checkout',
		int $order_id = 0,
		string $cart_scope = 'default',
		string $return_url = '',
		string $payment_method = ''
	) {
		$descriptor = EcpayShippingCatalog::get( $shipping_id );
		$subtypes   = self::subtypes();

		if ( null === $descriptor
			|| ! isset( $subtypes[ $shipping_id ] )
			|| ! self::is_method_enabled( $shipping_id )
			|| ! Settings::has_logistics_credentials() ) {
			return false;
		}

		// C2C 沒填退貨門市時連地圖都不要開：顧客選完門市、結帳完成，送單那一刻
		// 才失敗，善後成本高得多。
		$method = self::instantiate( $shipping_id );
		if ( null === $method || ! $method->is_configured() ) {
			return false;
		}

		$is_collection = self::resolve_collection_mode( $method, $payment_method );
		$cart_scope    = self::sanitize_cart_scope( $cart_scope );

		// 🔴 身分要在**這裡**算，不能等到回呼。
		//
		// 這個請求是顧客的瀏覽器打給我們自己的（同源），cookie 一定在。
		// 選店回呼則是綠界從**它們的**伺服器 POST 過來的跨站請求，而購物車
		// cookie 是 SameSite=Lax——跨站 POST 不會帶。在回呼那一刻重算身分，
		// 訪客一律算出空字串，於是簽出一張「無法識別擁有者」的憑證：顧客帶著
		// 它回到結帳頁，反而被自己的守門擋下來（正常流程 100% 失敗）。
		//
		// 因此身分在同源這一側算好、存進 map session，回呼只能**複製**它。
		$actor = self::current_principal( $cart_scope );
		if ( '' === $actor ) {
			// 算不出身分就不要開地圖。讓顧客在選完門市之後才失敗，比在這裡
			// 直接說「請重新整理」糟得多。
			return false;
		}

		$return_url  = self::sanitize_return_url( $return_url, $cart_scope );
		$credentials = Settings::logistics_credentials();
		$temp_id     = wp_generate_uuid4();
		// 地圖用的交易編號只用於這一次選店的來回比對，與送單的 MerchantTradeNo
		// 無關，因此可以是隨機值；重點是回呼必須**帶回同一個**。
		$merchant_trade_no = substr(
			(string) preg_replace( '/[^A-Za-z0-9]/', '', 'YSMAP' . wp_generate_password( 20, false, false ) ),
			0,
			20
		);

		set_transient( 'ys_ec_ecpay_map_' . $temp_id, [
			'shipping_id'       => $shipping_id,
			'logistics_subtype' => $subtypes[ $shipping_id ],
			'user_id'           => get_current_user_id(),
			// 🔴 憑證的擁有者。在這個同源請求裡算好，回呼只准複製、不准重算。
			'actor'             => $actor,
			'context'           => $context,
			'order_id'          => $order_id,
			'cart_scope'        => $cart_scope,
			'return_url'        => $return_url,
			'merchant_trade_no' => $merchant_trade_no,
			// 🔴 這次選店是在「要不要代收」的哪一種前提下做的。綠界會依此篩門市，
			// 因此它必須跟著這次 session 走，之後才驗得出「選店與送單前提不同」。
			'payment_method'    => $payment_method,
			'is_collection'     => $is_collection,
			'created_at'        => current_time( 'timestamp' ),
		], 30 * MINUTE_IN_SECONDS );

		$fields = [
			'MerchantID'        => $credentials['merchant_id'],
			'MerchantTradeNo'   => $merchant_trade_no,
			'LogisticsType'     => 'CVS',
			'LogisticsSubType'  => $subtypes[ $shipping_id ],
			// 🔴 代收與否要與送單一致。地圖送 'N'、送單送 'Y' 時，綠界會依
			// 「不代收」篩門市——顧客選得了的門市卻可能不支援代收，建單就失敗。
			'IsCollection'      => $is_collection ? 'Y' : 'N',
			'ServerReplyURL'    => rest_url( 'ys-ecommerce/v1/ecpay/store-callback' ),
			'ExtraData'         => $temp_id,
			'Device'            => wp_is_mobile() ? '1' : '0',
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
		];

		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );

		return [
			'action_url'      => Settings::logistics_endpoint( self::MAP_PATH ),
			'fields'          => $fields,
			'temp_id'         => $temp_id,
			'collection_mode' => $is_collection ? 'Y' : 'N',
		];
	}

	/**
	 * 這次選店是不是在「代收」前提下進行。
	 *
	 * 與 requester 的 `resolve_is_collection()` 是同一條規則：只認訂單／購物車
	 * **實際的付款方式**，不看任何後台開關。兩邊用同一條規則，才不會出現
	 * map=N、create=Y。
	 */
	private static function resolve_collection_mode( EcpayShipping $method, string $payment_method ): bool {
		if ( self::COD_GATEWAY_ID !== trim( $payment_method ) ) {
			return false;
		}

		return $method->supports_cod();
	}

	/** 門市選擇 token 的 transient 前綴。 */
	private const SELECTION_PREFIX = 'ys_ec_ecpay_sel_';

	/** 門市選擇的有效期（秒）。與地圖 session 一致。 */
	private const SELECTION_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * 發出一張不透明的門市選擇 token，並把**權威資料留在伺服器**。
	 *
	 * 🔴 前端拿到的只是一個沒有意義的字串。門市代號、subtype、代收前提、擁有者、
	 * 購物車 scope 全部存在伺服器這一側——前端改不了，也偽造不出來。
	 *
	 * 舊做法是把整包門市資料丟進 localStorage 再由結帳送回來，於是
	 * 「這個門市是在哪一種前提下選的」變成一個可竄改的欄位，等於沒有守門。
	 *
	 * @param array<string,mixed> $selection
	 * @param string              $principal 由 map session **複製**而來的擁有者識別
	 */
	private static function issue_selection_token( array $selection, string $principal ): string {
		$token = wp_generate_password( 32, false, false );

		set_transient( self::SELECTION_PREFIX . $token, [
			'shipping_id'       => (string) ( $selection['shipping_id'] ?? '' ),
			'logistics_subtype' => (string) ( $selection['cvs_type'] ?? '' ),
			'store_id'          => (string) ( $selection['store_id'] ?? '' ),
			'store_name'        => (string) ( $selection['store_name'] ?? '' ),
			'store_address'     => (string) ( $selection['store_address'] ?? '' ),
			'collection_mode'   => (string) ( $selection['collection_mode'] ?? 'N' ),
			// 🔴 存**精確的**付款方式，不是只存 N/Y。
			// 只比代收模式的話，信用卡選的門市可以拿去給另一個非代收金流用——
			// 兩者的門市限制未必相同，而且那本來就是「另一次結帳」。
			'payment_method'    => (string) ( $selection['payment_method'] ?? '' ),
			'cart_scope'        => (string) ( $selection['cart_scope'] ?? 'default' ),
			// 🔴 訪客也要綁。登入者綁 user id；訪客綁購物車 session——沒有它，
			// 一張 token 誰撿到都能用。
			//
			// 這個值是**傳進來的**，不是在這裡重算的：這支函式跑在綠界的跨站
			// 回呼裡，SameSite=Lax 的購物車 cookie 根本不會出現。重算的結果是
			// 每一次正常的訪客選店都簽出一張擁有者為空的憑證。
			'principal'         => $principal,
			'issued_at'         => current_time( 'timestamp' ),
		], self::SELECTION_TTL );

		return $token;
	}

	/**
	 * 目前這個請求的身分識別（登入者或購物車 session）。
	 *
	 * 回空字串＝**無法識別**。那不是「訪客」，而是「證明不了是誰」——
	 * consume 端據此拒絕（正常結帳一定有購物車 session）。
	 */
	private static function current_principal( string $cart_scope ): string {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'u:' . $user_id;
		}

		$cookie = 'default' === $cart_scope ? 'ys_ec_session' : 'ys_ec_session_' . $cart_scope;
		$value  = isset( $_COOKIE[ $cookie ] ) ? (string) $_COOKIE[ $cookie ] : '';
		if ( '' !== $value ) {
			return 's:' . hash( 'sha256', $value );
		}

		// headless 的訪客沒有我方 cookie（前端可能在另一個 origin），身分來自
		// 核心既有的 `X-YS-Guest-Token`。少了這一條，headless 站的訪客一律算不出
		// 身分——地圖根本開不起來。
		if ( class_exists( YSRestAuth::class ) && method_exists( YSRestAuth::class, 'get_guest_token' ) ) {
			$guest = (string) ( YSRestAuth::get_guest_token() ?? '' );
			if ( '' !== $guest ) {
				return 'g:' . hash( 'sha256', $guest );
			}
		}

		return '';
	}

	/**
	 * 驗證一張門市選擇 token（**只讀，不消耗**）。
	 *
	 * 回 `null` 代表通過；回字串代表拒絕的理由，呼叫端據此要求重選。
	 *
	 * 逐項比對：擁有者、購物車 scope、物流方式、subtype、門市代號，以及**最終的
	 * 付款方式**是否與當初選店的代收前提一致。任何一項對不上就重選——包含 token
	 * 不存在（過期或偽造）。
	 *
	 * 🔴 這裡刻意不刪。結帳驗證會因為**其他欄位**（電話格式、地址沒填）整批失敗，
	 * 而顧客會照著訊息補好再送一次——上一版在這裡就把憑證刪掉了，於是那第二次
	 * 送出必然收到「請重新選擇門市」，而他從頭到尾沒動過門市。
	 * 真正的一次性消耗在 {@see self::claim_selection()}，時機是訂單成立那一刻。
	 *
	 * @param array<string,mixed> $data 結帳送出的資料
	 */
	public static function verify_selection( array $data, string $shipping_id, string $payment_method ): ?string {
		$token = trim( (string) ( $data['ecpay_store_token'] ?? '' ) );
		if ( '' === $token ) {
			return '請重新選擇取貨門市（缺少門市選擇憑證）。';
		}

		$record = get_transient( self::SELECTION_PREFIX . $token );
		if ( ! is_array( $record ) ) {
			return '取貨門市的選擇已逾時或無效，請重新選擇門市。';
		}

		$method = self::instantiate( $shipping_id );
		if ( null === $method ) {
			return '所選的物流方式無效，請重新選擇。';
		}

		$scope = self::sanitize_cart_scope( (string) ( $data['cart_scope'] ?? 'default' ) );

		// 🔴 擁有者：登入者綁 user id、訪客綁購物車 session。
		// 兩邊都必須算得出身分——算不出來就是「證明不了是誰」，一律拒絕。
		$issued_principal  = (string) ( $record['principal'] ?? '' );
		$current_principal = self::current_principal( $scope );
		if ( '' === $issued_principal || '' === $current_principal || $issued_principal !== $current_principal ) {
			return '取貨門市的選擇無效，請重新選擇門市。';
		}

		if ( (string) ( $record['shipping_id'] ?? '' ) !== $shipping_id ) {
			return '取貨門市與所選的物流方式不符，請重新選擇門市。';
		}

		if ( (string) ( $record['logistics_subtype'] ?? '' ) !== $method->get_logistics_subtype() ) {
			return '取貨門市與所選的物流方式不符，請重新選擇門市。';
		}

		$submitted_store = trim( (string) ( $data['cvs_store_id'] ?? '' ) );
		if ( '' === $submitted_store || $submitted_store !== (string) ( $record['store_id'] ?? '' ) ) {
			return '取貨門市資料已變更，請重新選擇門市。';
		}

		if ( (string) ( $record['cart_scope'] ?? 'default' ) !== $scope ) {
			return '取貨門市與目前的購物車不符，請重新選擇門市。';
		}

		// 🔴 比**精確的付款方式**，不是只比代收模式。
		//
		// 只比 N/Y 的話，用信用卡選的門市可以拿去給另一個非代收金流用——那是另一次
		// 結帳的條件，而不同金流對超商取貨的限制未必相同。付款方式一改就重選，
		// 這一條同時涵蓋 N→Y 與 Y→N。
		if ( (string) ( $record['payment_method'] ?? '' ) !== trim( $payment_method ) ) {
			return '付款方式已變更，原先選擇的取貨門市可能不支援，請重新選擇門市。';
		}

		// 代收模式仍然獨立再驗一次：即使付款方式字串相同，方式的代收能力也可能
		// 因設定變動而改變。
		$was = 'Y' === (string) ( $record['collection_mode'] ?? 'N' );
		$now = self::resolve_collection_mode( $method, $payment_method );
		if ( $was !== $now ) {
			return '付款方式已變更，原先選擇的取貨門市可能不支援，請重新選擇門市。';
		}

		return null;
	}

	/**
	 * 認領（消耗）一張門市選擇 token。
	 *
	 * 回 `null` 代表認領成功；回字串代表拒絕的理由。
	 *
	 * 呼叫時機是**訂單成立那一刻**，不是欄位驗證的時候——驗證會因為別的欄位失敗，
	 * 那時候把憑證用掉等於懲罰一個什麼都沒做錯的顧客。
	 *
	 * 認領前會再驗一次：從驗證到成立之間，付款方式可能被改、設定可能被動過。
	 *
	 * @param array<string,mixed> $data 結帳送出的資料
	 */
	public static function claim_selection( array $data, string $shipping_id, string $payment_method ): ?string {
		$rejection = self::verify_selection( $data, $shipping_id, $payment_method );
		if ( null !== $rejection ) {
			return $rejection;
		}

		$token = trim( (string) ( $data['ecpay_store_token'] ?? '' ) );

		// 🔴 一次性消耗必須是**原子的**。
		//
		// 先 `get_transient()` 驗、再 `delete_transient()` 刪的話，兩個併發的結帳
		// 會同時通過驗證、同時刪除，兩張訂單共用一次選店。
		// `delete_transient()` 只有在真的刪掉一列時才回 true——把它當成「認領」，
		// 搶不到的那一個就是慢的那一個。
		//
		// 這一條在真實的 object cache（Redis／Memcached）與多 process 之下同樣成立：
		// WordPress 的 `delete_transient()` 最終走到 `wp_cache_delete()` 或
		// `delete_option()`，兩者對「那一列本來就不在」都回 false。
		if ( ! delete_transient( self::SELECTION_PREFIX . $token ) ) {
			return '這次的取貨門市選擇已被使用，請重新選擇門市。';
		}

		return null;
	}

	/**
	 * 既有的門市選擇，在付款方式變成 $payment_method 之後還算不算數。
	 *
	 * 這是給**前端**用的提示述詞（讓使用者在按下結帳之前就看到要重選），
	 * 不是守門本身——守門是 {@see self::consume_selection()}，在伺服器端。
	 *
	 * @param array<string,mixed> $selection 門市選擇（store callback 的 payload）。
	 */
	public static function selection_requires_reselect( array $selection, string $payment_method ): bool {
		$shipping_id = (string) ( $selection['shipping_id'] ?? '' );
		$method      = self::instantiate( $shipping_id );
		if ( null === $method ) {
			return true;
		}

		// 缺少當初的前提就是無法證明它相符——重選比猜安全。
		if ( ! array_key_exists( 'collection_mode', $selection ) ) {
			return true;
		}

		$was = 'Y' === (string) $selection['collection_mode'];
		$now = self::resolve_collection_mode( $method, $payment_method );

		return $was !== $now;
	}

	public static function handle_store_callback( \WP_REST_Request $request ): void {
		$params   = self::params( $request );
		$temp_id  = (string) ( $params['ExtraData'] ?? $params['TempId'] ?? '' );
		$map_data = '' !== $temp_id ? get_transient( 'ys_ec_ecpay_map_' . $temp_id ) : false;

		if ( ! is_array( $map_data ) ) {
			wp_die( 'Invalid map session.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		if ( ! self::validate_map_owner( $map_data ) ) {
			wp_die( 'Invalid map session owner.', 'ECPay Store Callback', [ 'response' => 403 ] );
		}

		// 🔴 擁有者只能從 map session **複製**。這個請求是綠界跨站 POST 過來的，
		// 沒有我方 cookie；在這裡重算的話訪客一律算出空字串。
		// map session 一定有它（開圖時就 gate 掉了），沒有代表這份 session 不是
		// 本版寫的——拒絕比簽出一張不能用的憑證好。
		$actor = (string) ( $map_data['actor'] ?? '' );
		if ( '' === $actor ) {
			wp_die( 'Map session missing actor.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		$shipping_id = (string) ( $map_data['shipping_id'] ?? '' );
		if ( '' === $shipping_id || ! isset( self::subtypes()[ $shipping_id ] ) || ! self::is_method_enabled( $shipping_id ) ) {
			wp_die( 'Shipping method disabled.', 'ECPay Store Callback', [ 'response' => 403 ] );
		}

		if ( ! self::verify_map_payload( $params, $map_data ) ) {
			wp_die( 'Invalid CheckMacValue.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		$store_id = trim( (string) ( $params['CVSStoreID'] ?? '' ) );
		if ( '' === $store_id ) {
			wp_die( 'Missing store id.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		$store_info = [
			'store_id'        => $store_id,
			'store_name'      => (string) ( $params['CVSStoreName'] ?? '' ),
			'store_address'   => (string) ( $params['CVSAddress'] ?? '' ),
			'store_phone'     => (string) ( $params['CVSTelephone'] ?? '' ),
			'cvs_type'        => (string) ( $map_data['logistics_subtype'] ?? '' ),
			'cvs_store_id'    => $store_id,
			'cvs_store_name'  => (string) ( $params['CVSStoreName'] ?? '' ),
			'cvs_store_addr'  => (string) ( $params['CVSAddress'] ?? '' ),
			'shipping_id'     => $shipping_id,
			// 🔴 這次選店的前提跟著結果一起回去。前端據此判斷「顧客改了付款方式
			// 之後這個門市還能不能用」（見 selection_requires_reselect()）。
			'collection_mode' => ( ! empty( $map_data['is_collection'] ) ) ? 'Y' : 'N',
			'payment_method'  => (string) ( $map_data['payment_method'] ?? '' ),
			'context'         => (string) ( $map_data['context'] ?? 'checkout' ),
			'order_id'        => (int) ( $map_data['order_id'] ?? 0 ),
			'cart_scope'      => self::sanitize_cart_scope( (string) ( $map_data['cart_scope'] ?? 'default' ) ),
			'return_url'      => self::sanitize_return_url(
				(string) ( $map_data['return_url'] ?? '' ),
				(string) ( $map_data['cart_scope'] ?? 'default' )
			),
			'selected_at'     => current_time( 'mysql' ),
		];

		// 🔴 發 token，權威資料留伺服器。前端只帶這個字串回結帳。
		// 擁有者識別不放進 $store_info——那份資料會被 JSON 印進回呼頁面。
		$store_info['selection_token'] = self::issue_selection_token( $store_info, $actor );

		set_transient( 'ys_ec_ecpay_store_' . $temp_id, $store_info, self::SELECTION_TTL );
		delete_transient( 'ys_ec_ecpay_map_' . $temp_id );

		self::render_callback_page( $store_info );
	}

	/**
	 * 依 method_id 取得物流方式實例（只認型錄裡的類別）。
	 */
	private static function instantiate( string $shipping_id ): ?EcpayShipping {
		$descriptor = EcpayShippingCatalog::get( $shipping_id );
		if ( null === $descriptor ) {
			return null;
		}

		$class = (string) $descriptor['class'];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$method = new $class();

		return $method instanceof EcpayShipping ? $method : null;
	}

	/**
	 * @return array<string,string>
	 */
	private static function params( \WP_REST_Request $request ): array {
		$out = [];
		foreach ( $request->get_params() as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$out[ (string) $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}
		return $out;
	}

	private static function is_method_enabled( string $shipping_id ): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( 'shipping', $shipping_id, Plugin::manifest() );
		}

		$alias = EcpayShippingCatalog::id_to_alias()[ $shipping_id ] ?? '';

		return '' !== $alias && Settings::shipping_enabled( $alias );
	}

	/**
	 * @param array<string,mixed> $map_data
	 */
	private static function validate_map_owner( array $map_data ): bool {
		$stored_user_id  = (int) ( $map_data['user_id'] ?? 0 );
		$current_user_id = get_current_user_id();

		if ( $stored_user_id <= 0 || $current_user_id <= 0 ) {
			return true;
		}

		return $stored_user_id === $current_user_id;
	}

	/**
	 * 驗證選店回呼。
	 *
	 * 🔴 每一項都是**必須帶回**，不是「有帶才比」。
	 *
	 * 舊版寫成 `isset($params['MerchantTradeNo']) && $params['MerchantTradeNo'] !== ...`
	 * ——攻擊者（或任何一個壞掉的中介）只要**不送**那個欄位，這一關就自動通過。
	 * subtype 尤其致命：不送 subtype 就能把 B2C 的選店結果掛到 C2C 的 session 上。
	 *
	 * @param array<string,string> $params
	 * @param array<string,mixed>  $map_data
	 */
	private static function verify_map_payload( array $params, array $map_data ): bool {
		$credentials = Settings::logistics_credentials();
		if ( '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv']
			|| empty( $params['CheckMacValue'] ) ) {
			return false;
		}

		$expected = [
			'MerchantID'       => $credentials['merchant_id'],
			'MerchantTradeNo'  => (string) ( $map_data['merchant_trade_no'] ?? '' ),
			'LogisticsSubType' => (string) ( $map_data['logistics_subtype'] ?? '' ),
		];

		foreach ( $expected as $field => $value ) {
			if ( '' === $value ) {
				return false;
			}
			if ( ! array_key_exists( $field, $params ) ) {
				return false;
			}
			if ( (string) $params[ $field ] !== $value ) {
				return false;
			}
		}

		return CheckMacValue::verify( $params, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );
	}

	private static function sanitize_cart_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		if ( '' === $scope || ! preg_match( '/^[a-z0-9_]{1,32}$/', $scope ) ) {
			return 'default';
		}

		return $scope;
	}

	private static function sanitize_return_url( string $return_url, string $cart_scope = 'default' ): string {
		$fallback = self::checkout_url();
		if ( 'default' !== $cart_scope ) {
			$fallback = add_query_arg( [ 'cart_scope' => $cart_scope ], $fallback );
		}

		$return_url = trim( $return_url );
		if ( '' === $return_url ) {
			return $fallback;
		}

		$return_url = wp_validate_redirect( esc_url_raw( $return_url ), $fallback );
		if ( 'default' !== $cart_scope ) {
			$return_url = add_query_arg( [ 'cart_scope' => $cart_scope ], $return_url );
		}

		return $return_url ?: $fallback;
	}

	private static function checkout_url(): string {
		if ( class_exists( YSPageResolver::class ) ) {
			return YSPageResolver::checkout_url();
		}

		return home_url( '/checkout/' );
	}

	/**
	 * @param array<string,mixed> $store_info
	 */
	private static function render_callback_page( array $store_info ): void {
		$json_data    = wp_json_encode( $store_info );
		$origin       = esc_url( home_url() );
		$checkout_url = esc_url( $store_info['return_url'] ?? self::checkout_url() );
		$context      = (string) ( $store_info['context'] ?? 'checkout' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header_remove( 'Content-Type' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		nocache_headers();

		if ( in_array( $context, [ 'admin', 'frontend_change' ], true ) ) {
			?>
<!DOCTYPE html>
<html lang="zh-TW">
<head><meta charset="utf-8"><title>ECPay Store Selected</title></head>
<body>
<script>
try {
	if (window.opener) {
		window.opener.postMessage({
			action: 'ys_ec_store_selected',
			provider: 'ecpay',
			data: <?php echo $json_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		}, <?php echo wp_json_encode( $origin ); ?>);
	}
} catch (e) {}
setTimeout(function () { window.close(); }, 600);
</script>
</body>
</html>
			<?php
			exit;
		}

		?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
	<meta charset="utf-8">
	<title>ECPay Store Selected</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<noscript><meta http-equiv="refresh" content="0;url=<?php echo esc_attr( $checkout_url ); ?>"></noscript>
</head>
<body>
<script>
(function () {
	var payload = <?php echo $json_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> || {};
	payload._timestamp = Date.now();
	var serialized = JSON.stringify(payload);
	try {
		localStorage.setItem('ys_ec_selected_store', serialized);
	} catch (e) {
		try { sessionStorage.setItem('ys_ec_selected_store', serialized); } catch (e2) {}
	}
	window.location.replace(<?php echo wp_json_encode( $checkout_url ); ?>);
})();
</script>
</body>
</html>
		<?php
		exit;
	}
}
