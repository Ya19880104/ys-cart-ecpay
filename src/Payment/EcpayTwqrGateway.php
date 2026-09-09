<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * TWQR 行動支付：需先向綠界申請開通。
 *
 * 標題、ChoosePayment、alias、最低金額與額外欄位全部由
 * {@see EcpayPaymentCatalog} 導出——這個類別只負責「我是哪一個方式」。
 */
final class EcpayTwqrGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_twqr';
	}
}
