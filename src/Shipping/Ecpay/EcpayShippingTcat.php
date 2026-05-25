<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

final class EcpayShippingTcat extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_tcat'; }
	public function get_title(): string { return '綠界黑貓宅配'; }
	public function get_type(): string { return 'home'; }
	public function get_logistics_subtype(): string { return 'TCAT'; }
	protected function settings_key(): string { return 'ship_tcat'; }
}
