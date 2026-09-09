<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Apple Pay：需先在綠界申請開通並完成網域驗證，否則綠界付款頁不會出現這個按鈕。
 *
 * 標題、ChoosePayment、alias、最低金額與額外欄位全部由
 * {@see EcpayPaymentCatalog} 導出——這個類別只負責「我是哪一個方式」。
 */
final class EcpayApplePayGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_applepay';
	}
}
