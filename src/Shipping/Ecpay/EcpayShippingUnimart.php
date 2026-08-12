<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 7-ELEVEN 超商取貨（B2C，subtype UNIMART）。 */
final class EcpayShippingUnimart extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_unimart';
	}
}
