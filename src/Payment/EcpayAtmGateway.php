<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * 標題、ChoosePayment、alias、最低金額全部由 {@see EcpayPaymentCatalog} 導出——
 * 這個類別只負責「我是哪一個方式」。
 */
final class EcpayAtmGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_atm';
	}
}
