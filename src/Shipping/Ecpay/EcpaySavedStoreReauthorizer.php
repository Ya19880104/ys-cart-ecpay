<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
use YangSheep\Ecommerce\Models\YSCustomer;
use YangSheep\YSCartEcpay\Support\ShippingMethodOperability;

/**
 * Reauthorize an owned saved CVS address for the current checkout.
 *
 * The saved row supplies only the store id and exact method identity.  Store
 * name/address always come from the canonical directory, and the newly issued
 * authority lives only in the short-lived one-use selection transient.
 */
final class EcpaySavedStoreReauthorizer {
	private const PROVIDER = 'ecpay';

	/**
	 * @param array<string,mixed> $params
	 * @return array{success:bool,code:string,message:string,status:int,data:array<string,mixed>}
	 */
	public static function reauthorize( array $params ): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! is_user_logged_in() ) {
			return self::failure( 'authentication_required', '請先登入再使用已儲存的取貨門市。', 401 );
		}

		if ( ! class_exists( YSCustomer::class ) || ! method_exists( YSCustomer::class, 'find_by_user_id' ) ) {
			return self::failure( 'saved_store_not_found', '找不到可用的收件地址。', 404 );
		}

		$customer    = YSCustomer::find_by_user_id( $user_id );
		$customer_id = is_object( $customer ) ? (int) ( $customer->id ?? 0 ) : 0;
		if ( $customer_id <= 0 ) {
			return self::failure( 'saved_store_not_found', '找不到可用的收件地址。', 404 );
		}

		foreach ( [ 'address_id', 'shipping_id', 'payment_method', 'cart_scope' ] as $field ) {
			if ( isset( $params[ $field ] ) && ! is_scalar( $params[ $field ] ) ) {
				return self::failure( 'invalid_saved_store_request', '已儲存門市的重新授權資料格式錯誤。', 400 );
			}
		}

		$address_id    = absint( $params['address_id'] ?? 0 );
		$shipping_id  = sanitize_text_field( wp_unslash( (string) ( $params['shipping_id'] ?? '' ) ) );
		$payment      = sanitize_text_field( wp_unslash( (string) ( $params['payment_method'] ?? '' ) ) );
		$cart_scope   = self::sanitize_cart_scope( (string) ( $params['cart_scope'] ?? 'default' ) );
		$descriptor   = EcpayShippingCatalog::get( $shipping_id );

		if ( $address_id <= 0 || '' === $shipping_id || '' === $payment ) {
			return self::failure( 'invalid_saved_store_request', '缺少已儲存門市的重新授權資料。', 400 );
		}

		if ( null === $descriptor
			|| empty( $descriptor['requires_store'] )
			|| 'CVS' !== (string) ( $descriptor['logistics_type'] ?? '' )
			|| ! ShippingMethodOperability::is_operable( $shipping_id ) ) {
			return self::failure( 'saved_store_method_unavailable', '這個取貨方式目前無法使用，請重新選擇門市。', 409 );
		}

		if ( ! class_exists( YSGatewayRegistry::class )
			|| ! method_exists( YSGatewayRegistry::class, 'get' )
			|| null === YSGatewayRegistry::get( $payment ) ) {
			return self::failure( 'saved_store_payment_unavailable', '付款方式無效，請重新選擇付款方式。', 400 );
		}

		if ( EcpayStoreSelector::COD_GATEWAY_ID === $payment && empty( $descriptor['cod_capable'] ) ) {
			return self::failure( 'saved_store_cod_unavailable', '這個取貨方式不支援貨到付款。', 409 );
		}

		$row = self::owned_address( $address_id, $customer_id );
		if ( null === $row ) {
			return self::failure( 'saved_store_not_found', '找不到已儲存的取貨門市。', 404 );
		}

		$row_provider = trim( (string) ( $row['shipping_provider'] ?? '' ) );
		if ( 'cvs' !== (string) ( $row['shipping_type'] ?? '' )
			|| ! in_array( $row_provider, [ '', self::PROVIDER ], true )
			|| $shipping_id !== (string) ( $row['shipping_method_id'] ?? '' ) ) {
			return self::failure( 'saved_store_incompatible', '已儲存門市與目前物流方式不相符，請重新選擇。', 409 );
		}

		$store_id = trim( (string) ( $row['cvs_store_id'] ?? '' ) );
		$subtype  = trim( (string) ( $descriptor['logistics_subtype'] ?? '' ) );
		if ( '' === $store_id || '' === $subtype ) {
			return self::failure( 'saved_store_incompatible', '已儲存門市資料不完整，請重新選擇。', 409 );
		}

		// Cache-only lookup: never turn a checkout request into a synchronous
		// provider call.  A miss schedules the existing one-shot refresh instead.
		$canonical = EcpayStoreDirectory::lookup( $subtype, $store_id );
		if ( '' === trim( (string) ( $canonical['name'] ?? '' ) )
			|| '' === trim( (string) ( $canonical['address'] ?? '' ) ) ) {
			EcpayStoreDirectory::schedule_refresh_soon();
			return self::failure( 'saved_store_unavailable', '目前無法驗證這間門市，請稍後再試或重新選店。', 409 );
		}

		$principal = EcpayStoreSelector::current_principal( $cart_scope );
		if ( '' === $principal || 'u:' . $user_id !== $principal ) {
			return self::failure( 'authentication_required', '無法確認已儲存門市的擁有者。', 401 );
		}

		$token = EcpayStoreSelector::issue_canonical_saved_selection(
			$shipping_id,
			$subtype,
			$store_id,
			$canonical,
			$payment,
			$cart_scope,
			$principal
		);
		if ( '' === $token ) {
			return self::failure( 'saved_store_token_unavailable', '目前無法建立門市授權，請稍後再試。', 503 );
		}

		$collection = EcpayStoreSelector::COD_GATEWAY_ID === $payment ? 'Y' : 'N';

		return [
			'success' => true,
			'code'    => 'saved_store_reauthorized',
			'message' => '',
			'status'  => 200,
			'data'    => [
				'selection_token' => $token,
				'store_id'        => $store_id,
				'store_name'      => trim( (string) $canonical['name'] ),
				'store_address'   => trim( (string) $canonical['address'] ),
				'store_phone'     => trim( (string) ( $canonical['phone'] ?? '' ) ),
				'store_verified'  => 1,
				'cvs_type'        => $subtype,
				'cvs_store_id'    => $store_id,
				'cvs_store_name'  => trim( (string) $canonical['name'] ),
				'cvs_store_addr'  => trim( (string) $canonical['address'] ),
				'shipping_id'     => $shipping_id,
				'payment_method'  => $payment,
				'collection_mode' => $collection,
				'cart_scope'      => $cart_scope,
			],
		];
	}

	/** @return array<string,mixed>|null */
	private static function owned_address( int $address_id, int $customer_id ): ?array {
		global $wpdb;

		if ( ! defined( 'YS_ECOMMERCE_TABLE_PREFIX' )
			|| ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_row' ) ) {
			return null;
		}

		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'addresses';
		$sql   = $wpdb->prepare(
			"SELECT id, customer_id, shipping_type, shipping_provider, shipping_method_id, cvs_store_id
			FROM {$table}
			WHERE id = %d AND customer_id = %d
			LIMIT 1",
			$address_id,
			$customer_id
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private static function sanitize_cart_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		if ( '' === $scope || ! preg_match( '/^[a-z0-9_]{1,32}$/', $scope ) ) {
			return 'default';
		}

		return $scope;
	}

	/** @return array{success:bool,code:string,message:string,status:int,data:array<string,mixed>} */
	private static function failure( string $code, string $message, int $status ): array {
		return [
			'success' => false,
			'code'    => $code,
			'message' => $message,
			'status'  => $status,
			'data'    => [],
		];
	}
}
