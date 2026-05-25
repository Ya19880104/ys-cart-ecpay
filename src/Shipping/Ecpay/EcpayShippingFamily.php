<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

final class EcpayShippingFamily extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_family'; }
	public function get_title(): string { return '綠界全家超商取貨'; }
	public function get_type(): string { return 'cvs'; }
	public function get_logistics_subtype(): string { return 'FAMI'; }
	protected function settings_key(): string { return 'ship_family'; }
}
