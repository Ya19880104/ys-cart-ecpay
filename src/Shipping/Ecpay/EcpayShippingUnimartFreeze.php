<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/**
 * 綠界 7-ELEVEN 冷凍取貨（B2C）（v0.3.0）
 *
 * 🔴 超商冷凍是獨立的
 * subtype，不是常溫超商加一個溫層參數——綠界以
 * subtype 決定收退貨的門市與流程。
 */
final class EcpayShippingUnimartFreeze extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_unimart_freeze'; }
	public function get_title(): string { return '綠界 7-ELEVEN 冷凍取貨（B2C）'; }
	public function get_type(): string { return 'cvs'; }
	public function get_logistics_subtype(): string { return 'UNIMARTFREEZE'; }
	protected function settings_key(): string { return 'ship_unimart_freeze'; }
}
