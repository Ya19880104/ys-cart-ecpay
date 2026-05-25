<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

final class EcpayBarcodeGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_barcode';
	}

	public function get_title(): string {
		return 'ECPay Barcode';
	}

	protected function gateway_key(): string {
		return 'barcode';
	}

	protected function choose_payment(): string {
		return 'BARCODE';
	}
}

