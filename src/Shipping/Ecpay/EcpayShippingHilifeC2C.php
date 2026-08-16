<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 萊爾富店到店（C2C，subtype HILIFEC2C）。 */
final class EcpayShippingHilifeC2C extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_hilife_c2c';
	}
}
