<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 7-ELEVEN 交貨便（C2C，subtype UNIMARTC2C）。回單需寄貨編號＋驗證碼兩段。 */
final class EcpayShippingUnimartC2C extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_unimart_c2c';
	}
}
