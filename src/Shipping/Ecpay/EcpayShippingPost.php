<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/** 中華郵政宅配（subtype POST）。官方明載必填 GoodsWeight，且不送宅配溫層／距離／規格／時段。 */
final class EcpayShippingPost extends EcpayShipping {
	public function get_id(): string {
		return 'ys_ec_ecpay_ship_post';
	}
}
