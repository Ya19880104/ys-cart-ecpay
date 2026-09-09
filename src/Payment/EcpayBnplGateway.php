<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * BNPL 無卡分期：需先向綠界申請開通，且訂單須滿 3,000 元（下限寫在型錄，金額不足時結帳頁就不顯示）。
 *
 * 標題、ChoosePayment、alias、最低金額與額外欄位全部由
 * {@see EcpayPaymentCatalog} 導出——這個類別只負責「我是哪一個方式」。
 */
final class EcpayBnplGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_bnpl';
	}
}
