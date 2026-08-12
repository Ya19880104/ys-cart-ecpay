<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/**
 * 綠界黑貓宅配（冷藏）（v0.3.0）
 *
 * 🔴 宅配的溫層是 `Temperature` 欄位，subtype 仍是
 * TCAT。舊版把它讀成
 * $order_data['temperature_code']，而那個 key 全 repo
 * 沒有任何寫入點——因此永遠送常溫。
 */
final class EcpayShippingTcatChilled extends EcpayShipping {
	public function get_id(): string { return 'ys_ec_ecpay_ship_tcat_chilled'; }
	public function get_title(): string { return '綠界黑貓宅配（冷藏）'; }
	public function get_type(): string { return 'home'; }
	public function get_logistics_subtype(): string { return 'TCAT'; }
	protected function settings_key(): string { return 'ship_tcat_chilled'; }
	public function get_temperature_code(): string { return self::TEMP_CHILLED; }
}
