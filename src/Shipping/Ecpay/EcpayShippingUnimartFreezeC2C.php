<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/**
 * 綠界 7-ELEVEN 冷凍取貨（C2C 店到店）（v0.3.0）
 *
 * 🔴 超商冷凍是獨立的
 * subtype，不是常溫超商加一個溫層參數——綠界以
 * subtype 決定收退貨的門市與流程。
 */
final class EcpayShippingUnimartFreezeC2C extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_unimart_freeze_c2c'; }
	public function get_title(): string { return '綠界 7-ELEVEN 冷凍取貨（C2C 店到店）'; }
	public function get_type(): string { return 'cvs'; }
	public function get_logistics_subtype(): string { return 'UNIMARTFREEZEC2C'; }
	protected function settings_key(): string { return 'ship_unimart_freeze_c2c'; }
	public function is_c2c(): bool { return true; }
}
