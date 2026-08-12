<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 7-ELEVEN 冷凍取貨（B2C，subtype UNIMARTFREEZE）。官方僅提供 B2C，無 C2C 版本。 */
final class EcpayShippingUnimartFreeze extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_unimart_freeze';
	}
}
