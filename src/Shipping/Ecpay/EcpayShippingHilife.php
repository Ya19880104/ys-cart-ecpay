<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 萊爾富超商取貨（B2C，subtype HILIFE）。 */
final class EcpayShippingHilife extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_hilife';
	}
}
