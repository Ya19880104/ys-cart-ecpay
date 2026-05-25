<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

final class EcpayCvsGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_cvs';
	}

	public function get_title(): string {
		return '綠界超商代碼';
	}

	protected function gateway_key(): string {
		return 'cvs';
	}

	protected function choose_payment(): string {
		return 'CVS';
	}
}
