<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

final class EcpayShippingPost extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_post'; }
	public function get_title(): string { return 'ECPay Post'; }
	public function get_type(): string { return 'home'; }
	public function get_logistics_subtype(): string { return 'POST'; }
	protected function settings_key(): string { return 'ship_post'; }
}

