<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

final class EcpayShippingUnimart extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_unimart'; }
	public function get_title(): string { return '綠界 7-ELEVEN 超商取貨'; }
	public function get_type(): string { return 'cvs'; }
	public function get_logistics_subtype(): string { return 'UNIMART'; }
	protected function settings_key(): string { return 'ship_unimart'; }
}
