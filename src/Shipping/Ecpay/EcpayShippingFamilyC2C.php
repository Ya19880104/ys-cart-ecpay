<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 全家店到店（C2C，subtype FAMIC2C）。與 B2C 是兩份合約，需另填退貨門市。 */
final class EcpayShippingFamilyC2C extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_family_c2c';
	}
}
