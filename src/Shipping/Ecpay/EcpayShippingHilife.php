<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

final class EcpayShippingHilife extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_hilife'; }
	public function get_title(): string { return 'ECPay Hi-Life'; }
	public function get_type(): string { return 'cvs'; }
	public function get_logistics_subtype(): string { return 'HILIFE'; }
	protected function settings_key(): string { return 'ship_hilife'; }
}

