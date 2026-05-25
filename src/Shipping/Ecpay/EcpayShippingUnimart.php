<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

final class EcpayShippingUnimart extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_unimart'; }
	public function get_title(): string { return 'ECPay 7-ELEVEN'; }
	public function get_type(): string { return 'cvs'; }
	public function get_logistics_subtype(): string { return 'UNIMART'; }
	protected function settings_key(): string { return 'ship_unimart'; }
}

