<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 黑貓宅配冷藏（subtype TCAT，Temperature 0002）。 */
final class EcpayShippingTcatChilled extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_tcat_chilled';
	}
}
