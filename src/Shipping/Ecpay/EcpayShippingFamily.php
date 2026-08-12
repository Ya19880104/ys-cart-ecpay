<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 全家超商取貨（B2C，subtype FAMI）。 */
final class EcpayShippingFamily extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_family';
	}
}
