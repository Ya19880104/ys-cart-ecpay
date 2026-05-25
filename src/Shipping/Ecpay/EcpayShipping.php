<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Shipping\YSShippingInterface;
use YangSheep\YSCartEcpay\Support\Settings;

abstract class EcpayShipping implements YSShippingInterface {
	abstract protected function settings_key(): string;
	abstract public function get_logistics_subtype(): string;

	public function get_provider(): string {
		return 'ecpay';
	}

	public function is_enabled(): bool {
		return Settings::shipping_enabled( $this->settings_key() ) && Settings::has_logistics_credentials();
	}

	public function is_available( array $order_data ): bool {
		unset( $order_data );
		return $this->is_enabled();
	}

	public function calculate_cost( array $cart_items, array $address = [] ): float {
		unset( $address );
		$threshold = $this->get_free_threshold();
		if ( $threshold > 0 ) {
			$total = 0.0;
			foreach ( $cart_items as $item ) {
				$total += (float) ( $item['line_total'] ?? $item['subtotal'] ?? 0 );
			}
			if ( $total >= $threshold ) {
				return 0.0;
			}
		}

		return Settings::shipping_cost( $this->settings_key() );
	}

	public function get_free_threshold(): float {
		return Settings::free_threshold( $this->settings_key() );
	}

	public function supports_cvs_selection(): bool {
		return 'cvs' === $this->get_type();
	}

	public function supports_cod(): bool {
		return false;
	}

	public function get_settings_fields(): array {
		return [];
	}

	public function get_supported_countries(): array {
		return [ 'TW' ];
	}
}

