<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;

/**
 * Single source of truth for ECPay shipping-method operability.
 */
final class ShippingMethodOperability {
	private const PROVIDER_ID = 'ys_ecpay';

	private const CORE_LIFECYCLE_CLASS = '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState';

	public static function is_operable( string $method_id ): bool {
		$method_id = trim( $method_id );
		if ( null === EcpayShippingCatalog::get( $method_id ) ) {
			return false;
		}

		try {
			return self::lifecycle_allows( $method_id )
				&& Settings::has_logistics_credentials_for_method( $method_id )
				&& self::is_configured( $method_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function is_configured( string $method_id ): bool {
		$method_id  = trim( $method_id );
		$descriptor = EcpayShippingCatalog::get( $method_id );
		if ( null === $descriptor ) {
			return false;
		}

		if ( ! empty( $descriptor['requires_goods_weight'] )
			&& (float) Settings::shipping_method_option( $method_id, 'goods_weight', '0' ) <= 0.0 ) {
			return false;
		}

		return true;
	}

	public static function has_operable_method(): bool {
		foreach ( EcpayShippingCatalog::ids() as $method_id ) {
			if ( self::is_operable( $method_id ) ) {
				return true;
			}
		}

		return false;
	}

	public static function has_operable_store_method( string $subtype = '' ): bool {
		$requested_subtype = strtoupper( trim( $subtype ) );
		if ( '' !== $requested_subtype && '' === self::store_channel( $requested_subtype ) ) {
			return false;
		}

		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			if ( empty( $descriptor['requires_store'] ) ) {
				continue;
			}

			$method_subtype = strtoupper( (string) ( $descriptor['logistics_subtype'] ?? '' ) );
			if ( '' !== $requested_subtype && $requested_subtype !== $method_subtype ) {
				continue;
			}

			if ( self::is_operable( (string) $method_id ) ) {
				return true;
			}
		}

		return false;
	}

	public static function store_channel( string $subtype ): string {
		$map = [
			'FAMI'          => 'FAMI',
			'FAMIC2C'       => 'FAMI',
			'UNIMART'       => 'UNIMART',
			'UNIMARTC2C'    => 'UNIMART',
			'UNIMARTFREEZE' => 'UNIMARTFREEZE',
			'HILIFE'        => 'HILIFE',
			'HILIFEC2C'     => 'HILIFE',
		];

		return (string) ( $map[ strtoupper( trim( $subtype ) ) ] ?? '' );
	}

	private static function lifecycle_allows( string $method_id ): bool {
		$core_lifecycle_api = self::has_core_lifecycle_api();
		if ( null !== $core_lifecycle_api ) {
			if ( ! $core_lifecycle_api ) {
				return false;
			}

			$class    = self::CORE_LIFECYCLE_CLASS;
			$manifest = Plugin::manifest();

			return $class::is_provider_enabled( self::PROVIDER_ID, $manifest )
				&& $class::is_capability_enabled( self::PROVIDER_ID, 'shipping', $manifest )
				&& $class::is_method_enabled( 'shipping', $method_id, $manifest );
		}

		$alias = EcpayShippingCatalog::id_to_alias()[ $method_id ] ?? '';

		return '' !== $alias && Settings::shipping_enabled( $alias );
	}

	/**
	 * @return bool|null True when complete, false when present but incomplete,
	 *                   null only when the lifecycle class is absent.
	 */
	private static function has_core_lifecycle_api(): ?bool {
		$class = self::CORE_LIFECYCLE_CLASS;
		if ( ! class_exists( $class ) ) {
			return null;
		}

		return method_exists( $class, 'is_provider_enabled' )
			&& method_exists( $class, 'is_capability_enabled' )
			&& method_exists( $class, 'is_method_enabled' );
	}
}
