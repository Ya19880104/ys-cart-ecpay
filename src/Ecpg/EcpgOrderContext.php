<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Ecpg;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\YSCartEcpay\Payment\EcpayPaymentClient;
use YangSheep\YSCartEcpay\Support\Utf8Text;

/**
 * 把 YS CART 訂單翻成站內付 2.0 請求欄位（v0.5.0）
 *
 * 閘道（續扣）、託管付款頁（首刷＋綁卡）與回呼處理共用同一份翻譜，欄位長度與格式
 * 依官方文件 9040／35591／35630：
 *
 *   MerchantMemberID String(60)   Email String(100)   Phone String(60)   Name String(50)
 *   TradeDesc String(200)         ItemName String(400，官方建議 ≤200)   MerchantTradeNo String(20)
 *
 * 🔴 MerchantMemberID 是綁卡的**身分鍵**：綁定時用哪個值，日後 CreatePaymentWithCardID
 * 就必須帶同一個值，否則綠界拒絕扣款。因此它只由 customer_id 決定（`YSC<customer_id>`），
 * 不含任何會變動的東西（email、姓名、登入帳號）。
 */
final class EcpgOrderContext {
	/** 站內付 2.0 綁卡信用卡在 YS CART 的閘道 ID（也是 YSCreditCard 的 gateway_id）。 */
	public const GATEWAY_ID = 'ys_ec_ecpay_ecpg_credit';

	public const FLOW_BIND = 'bind';
	public const FLOW_PAY  = 'pay';

	/** 訂閱訂單且有會員身分＝交易且綁卡；其餘＝純交易（不留卡）。 */
	public static function flow_for( object $order ): string {
		$customer_id = (int) ( $order->customer_id ?? 0 );
		return $customer_id > 0 && self::is_subscription_order( (int) ( $order->id ?? 0 ) )
			? self::FLOW_BIND
			: self::FLOW_PAY;
	}

	/** 綁卡身分鍵；沒有會員（customer_id 0）回空字串＝不可綁卡。 */
	public static function member_id( int $customer_id ): string {
		return $customer_id > 0 ? 'YSC' . $customer_id : '';
	}

	/**
	 * ConsumerInfo。Email 與 Phone 擇一必填（9040／35591）；兩者都拿不到時回傳的陣列
	 * 不含任一者，呼叫端以 {@see self::has_contact()} 判定能否送出。
	 *
	 * @return array<string,string>
	 */
	public static function consumer_info( object $order, string $member_id ): array {
		$info = [];
		if ( '' !== $member_id ) {
			$info['MerchantMemberID'] = Utf8Text::truncate( $member_id, 60 );
		}

		$email = self::clean_email( (string) ( $order->billing_email ?? '' ) );
		if ( '' === $email && (int) ( $order->user_id ?? 0 ) > 0 && function_exists( 'get_userdata' ) ) {
			$user  = get_userdata( (int) $order->user_id );
			$email = $user ? self::clean_email( (string) ( $user->user_email ?? '' ) ) : '';
		}
		if ( '' !== $email ) {
			$info['Email'] = $email;
		}

		$phone = self::clean_phone( (string) ( $order->billing_phone ?? '' ) );
		if ( '' !== $phone ) {
			$info['Phone'] = $phone;
		}

		$name = self::clean_name( (string) ( $order->billing_name ?? '' ) );
		if ( '' !== $name ) {
			$info['Name'] = $name;
		}

		$country = strtoupper( trim( (string) ( $order->billing_country ?? 'TW' ) ) );
		if ( '' === $country || 'TW' === $country ) {
			$info['CountryCode'] = '158'; // ISO 3166-1 numeric：臺灣
		}

		return $info;
	}

	/** @param array<string,string> $consumer_info */
	public static function has_contact( array $consumer_info ): bool {
		return '' !== (string) ( $consumer_info['Email'] ?? '' ) || '' !== (string) ( $consumer_info['Phone'] ?? '' );
	}

	/**
	 * OrderInfo（GetTokenbyBindingCard／GetTokenbyTrade／CreatePaymentWithCardID 共用的子集）。
	 *
	 * @return array<string,mixed>
	 */
	public static function order_info( object $order, string $merchant_trade_no, int $amount, string $return_url ): array {
		return [
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
			'MerchantTradeNo'   => $merchant_trade_no,
			'TotalAmount'       => $amount,
			'ReturnURL'         => $return_url,
			'TradeDesc'         => Utf8Text::truncate( 'YS CART order ' . (string) ( $order->order_number ?? $order->id ?? '' ), 200 ),
			'ItemName'          => self::item_name( $order ),
		];
	}

	/** 商品名稱以 # 分隔（官方規格），控制字元與 HTML 去掉，總長壓在 190 內。 */
	public static function item_name( object $order ): string {
		$names = [];
		$items = (int) ( $order->id ?? 0 ) > 0 && class_exists( YSOrder::class ) && method_exists( YSOrder::class, 'get_items' )
			? YSOrder::get_items( (int) $order->id )
			: [];
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			$title = trim( (string) ( $item->product_title ?? '' ) );
			$label = trim( (string) ( $item->variant_label ?? '' ) );
			if ( '' !== $label ) {
				$title .= '（' . $label . '）';
			}
			$title = self::strip_control( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $title ) : strip_tags( $title ) );
			$title = str_replace( '#', '＃', $title ); // # 是綠界的分隔符，商品名裡的要換掉
			if ( '' !== $title ) {
				$names[] = $title;
			}
		}
		$joined = implode( '#', $names );
		if ( '' === trim( $joined ) ) {
			$joined = 'YS CART Order ' . (string) ( $order->order_number ?? $order->id ?? '' );
		}
		$joined = Utf8Text::truncate( $joined, 190 );
		return '' !== trim( $joined ) ? $joined : 'YS CART Order';
	}

	public static function is_subscription_order( int $order_id ): bool {
		if ( $order_id <= 0 || ! class_exists( YSOrder::class ) || ! method_exists( YSOrder::class, 'get_items' ) ) {
			return false;
		}
		foreach ( (array) YSOrder::get_items( $order_id ) as $item ) {
			if ( ! empty( $item->is_subscription ) ) {
				return true;
			}
		}
		return false;
	}

	/** 精確的正整數新台幣；非 canonical 一律 null（不四捨五入——那會扣出另一個金額）。 */
	public static function canonical_amount( mixed $total ): ?int {
		if ( is_string( $total ) && '' !== $total && is_numeric( $total ) ) {
			$total = 0.0 + $total;
		}
		return EcpayPaymentClient::is_canonical_twd( $total ) ? (int) $total : null;
	}

	/** 由卡號前六碼推卡別（只做 BIN 首碼，夠 YS CART 帳戶頁顯示用）。 */
	public static function card_brand( string $card6 ): string {
		$card6 = preg_replace( '/\D/', '', $card6 ) ?? '';
		if ( '' === $card6 ) {
			return 'card';
		}
		if ( '4' === $card6[0] ) {
			return 'visa';
		}
		if ( str_starts_with( $card6, '62' ) ) {
			return 'unionpay';
		}
		if ( '5' === $card6[0] || ( strlen( $card6 ) >= 4 && (int) substr( $card6, 0, 4 ) >= 2221 && (int) substr( $card6, 0, 4 ) <= 2720 ) ) {
			return 'mastercard';
		}
		if ( str_starts_with( $card6, '35' ) ) {
			return 'jcb';
		}
		if ( str_starts_with( $card6, '34' ) || str_starts_with( $card6, '37' ) ) {
			return 'amex';
		}
		return 'card';
	}

	/** CardInfo.CardValidMM／CardValidYY → `MM/YY`（YSCreditCard expire_date 上限 7 字元）；缺任一回空字串。 */
	public static function expire_date( array $card_info ): string {
		$mm = preg_replace( '/\D/', '', (string) ( $card_info['CardValidMM'] ?? '' ) ) ?? '';
		$yy = preg_replace( '/\D/', '', (string) ( $card_info['CardValidYY'] ?? '' ) ) ?? '';
		if ( '' === $mm || '' === $yy ) {
			return '';
		}
		$mm = str_pad( substr( $mm, -2 ), 2, '0', STR_PAD_LEFT );
		$yy = substr( $yy, -2 );
		return $mm . '/' . $yy;
	}

	// ── URL ────────────────────────────────────────────────────────────────────

	public static function order_key( object $order ): string {
		return class_exists( YSOrder::class ) && method_exists( YSOrder::class, 'generate_order_key' )
			? (string) YSOrder::generate_order_key( (int) ( $order->id ?? 0 ), (string) ( $order->order_number ?? '' ) )
			: '';
	}

	/** 託管付款頁（本站）：process_payment 交給結帳頁跳轉的目標。 */
	public static function pay_page_url( object $order, string $merchant_trade_no = '', string $fingerprint = '' ): string {
		$query = [ 'order' => (int) ( $order->id ?? 0 ), 'key' => self::order_key( $order ) ];
		if ( '' !== $merchant_trade_no && '' !== $fingerprint ) {
			$query['attempt'] = $merchant_trade_no;
			$query['afp']     = $fingerprint;
		}
		return add_query_arg(
			$query,
			rest_url( 'ys-ecommerce/v1/ecpay/ecpg/pay' )
		);
	}

	/** 綠界幕後通知（ReturnURL）——所有 ECPG 交易共用。 */
	public static function return_url(): string {
		return rest_url( 'ys-ecommerce/v1/ecpay/ecpg/return' );
	}

	/** 3D 驗證完成後瀏覽器導回（OrderResultURL）。 */
	public static function order_result_url(): string {
		return rest_url( 'ys-ecommerce/v1/ecpay/ecpg/result' );
	}

	public static function thank_you_url( object $order ): string {
		$query = [ 'order' => (int) ( $order->id ?? 0 ), 'key' => self::order_key( $order ) ];
		$resolver = '\YangSheep\Ecommerce\Services\Setup\YSPageResolver';
		$base     = class_exists( $resolver ) && method_exists( $resolver, 'url' )
			? (string) $resolver::url( 'thankyou', 'thankyou/' )
			: home_url( '/thankyou/' );
		return add_query_arg( $query, $base );
	}

	/** 核心結帳頁的重新付款入口（訪客以 key 驗證、會員以登入驗證）。 */
	public static function repay_url( object $order ): string {
		$resolver = '\YangSheep\Ecommerce\Services\Setup\YSPageResolver';
		$base     = class_exists( $resolver ) && method_exists( $resolver, 'url' )
			? (string) $resolver::url( 'checkout', '/checkout/' )
			: home_url( '/checkout/' );
		return add_query_arg( [ 'repay_order' => (int) ( $order->id ?? 0 ), 'key' => self::order_key( $order ) ], $base );
	}

	// ── 清洗 ───────────────────────────────────────────────────────────────────

	private static function clean_email( string $email ): string {
		$email = trim( $email );
		if ( '' === $email || strlen( $email ) > 100 || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return '';
		}
		return $email;
	}

	/** 綠界：可帶國碼但不可帶 +；國內 `0[89]\d{8}`、海外 `\d{10,20}`。其餘形狀不送（Email 仍可單獨成立）。 */
	private static function clean_phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone ) ?? '';
		if ( 1 === preg_match( '/^0[89][0-9]{8}$/', $digits ) || 1 === preg_match( '/^[0-9]{10,20}$/', $digits ) ) {
			return $digits;
		}
		return '';
	}

	/** 官方：中文、英文與 `: , . () / –`；其餘字元拿掉，上限 50 字。 */
	private static function clean_name( string $name ): string {
		$name = self::strip_control( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $name ) : strip_tags( $name ) );
		$name = preg_replace( '/[^\p{L}\p{M} ,.()\/\-–:]/u', '', $name ) ?? '';
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) ?? '' );
		return Utf8Text::truncate( $name, 50 );
	}

	private static function strip_control( string $value ): string {
		return trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', $value ) ?? $value );
	}
}
