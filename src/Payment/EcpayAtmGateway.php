<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

final class EcpayAtmGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_atm';
	}

	public function get_title(): string {
		return '綠界 ATM 虛擬帳號';
	}

	protected function gateway_key(): string {
		return 'atm';
	}

	protected function choose_payment(): string {
		return 'ATM';
	}
}
