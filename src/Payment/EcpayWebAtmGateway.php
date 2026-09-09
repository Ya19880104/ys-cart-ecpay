<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * WebATM：消費者導向自己的網銀完成即時轉帳，付款結果即時回來。
 *
 * 標題、ChoosePayment、alias、最低金額與額外欄位全部由
 * {@see EcpayPaymentCatalog} 導出——這個類別只負責「我是哪一個方式」。
 */
final class EcpayWebAtmGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_webatm';
	}
}
