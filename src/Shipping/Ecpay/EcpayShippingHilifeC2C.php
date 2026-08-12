<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/**
 * 綠界萊爾富超商取貨（C2C 店到店）（v0.3.0）
 *
 * 🔴 C2C 與 B2C 是兩份不同的綠界合約：subtype
 * 不同、服務金鑰不同。用 B2C 的 subtype 打 C2C
 * 商店代號，電子地圖會回「找不到加密金鑰」，送單直接失敗。
 */
final class EcpayShippingHilifeC2C extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_hilife_c2c'; }
	public function get_title(): string { return '綠界萊爾富超商取貨（C2C 店到店）'; }
	public function get_type(): string { return 'cvs'; }
	public function get_logistics_subtype(): string { return 'HILIFEC2C'; }
	protected function settings_key(): string { return 'ship_hilife_c2c'; }
	public function is_c2c(): bool { return true; }
}
