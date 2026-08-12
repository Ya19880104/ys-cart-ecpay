<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Shipping\YSShippingInterface;
use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Support\Settings;

abstract class EcpayShipping implements YSShippingInterface {
	abstract protected function settings_key(): string;
	abstract public function get_logistics_subtype(): string;

	/** 綠界宅配溫層代碼：常溫。 */
	public const TEMP_ROOM = '0001';

	/** 綠界宅配溫層代碼：冷藏。 */
	public const TEMP_CHILLED = '0002';

	/** 綠界宅配溫層代碼：冷凍。 */
	public const TEMP_FROZEN = '0003';

	public function get_provider(): string {
		return 'ecpay';
	}

	/**
	 * 這個物流方式的溫層代碼（v0.3.0）
	 *
	 * 🔴 舊版把溫層讀成 `$order_data['temperature_code']`，而那個 key **全 repo
	 * 只出現在讀取的那一行、沒有任何寫入點**——換句話說宅配永遠送常溫（`0001`）。
	 * 客戶賣的是冷藏／冷凍商品，送單溫層錯了就是貨到時已經退冰。
	 *
	 * 溫層是**物流方式的屬性**，不是訂單的臨時欄位：業主在後台開「黑貓冷凍」
	 * 這個方式，它就永遠送 `0003`。由方式自己回答，沒有中間人可以搞丟。
	 */
	public function get_temperature_code(): string {
		return self::TEMP_ROOM;
	}

	/**
	 * 是否為 C2C（店到店）(v0.3.0)
	 *
	 * 🔴 B2C 與 C2C 是**兩種不同的合約**，綠界以不同的服務金鑰開通，subtype 也
	 * 不同。用 B2C 的 subtype 打 C2C 商店代號，電子地圖會回「找不到加密金鑰，
	 * 請確認是否有申請開通此物流方式」，送單則直接失敗。
	 *
	 * 兩者各自註冊成獨立的物流方式（與核心 PayUni provider 的做法一致），由業主
	 * 依自己的合約決定開哪一個——而不是靠一個容易設錯的全域開關。
	 */
	public function is_c2c(): bool {
		return false;
	}

	/**
	 * C2C 退貨門市代號（C2C 建立訂單必填）。
	 *
	 * 綠界規定 C2C 必須指定退貨門市；沒有它，送單會被拒。取自後台設定，
	 * 缺值時由 requester fail-closed（不猜一個門市）。
	 */
	public function get_return_store_id(): string {
		return $this->is_c2c() ? Settings::get( 'ship_c2c_return_store_id', '' ) : '';
	}

	public function is_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' )
			&& ! \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( 'shipping', $this->get_id(), Plugin::manifest() ) ) {
			return false;
		}

		return Settings::shipping_enabled( $this->settings_key() ) && Settings::has_logistics_credentials();
	}

	public function is_available( array $order_data ): bool {
		unset( $order_data );
		return $this->is_enabled();
	}

	public function calculate_cost( array $cart_items, array $address = [] ): float {
		unset( $address );
		$threshold = $this->get_free_threshold();
		if ( $threshold > 0 ) {
			$total = 0.0;
			foreach ( $cart_items as $item ) {
				$total += (float) ( $item['line_total'] ?? $item['subtotal'] ?? 0 );
			}
			if ( $total >= $threshold ) {
				return 0.0;
			}
		}

		return Settings::shipping_base_fee( $this->get_id() );
	}

	public function get_free_threshold(): float {
		return Settings::shipping_free_threshold( $this->get_id() );
	}

	public function supports_cvs_selection(): bool {
		return 'cvs' === $this->get_type();
	}

	/**
	 * 是否支援貨到付款（v0.3.0）
	 *
	 * 🔴 舊版 `IsCollection` 在 requester 與電子地圖兩處都寫死 `'N'`，因此就算
	 * 業主在後台開了貨到付款，送出去的仍然是「不代收」——貨送到了，錢沒收。
	 *
	 * 現在由方式自己回答，並由後台設定控制；requester 與電子地圖共用同一個答案。
	 */
	public function supports_cod(): bool {
		return Settings::shipping_cod_enabled( $this->get_id() );
	}

	public function get_settings_fields(): array {
		return [];
	}

	public function get_supported_countries(): array {
		return [ 'TW' ];
	}
}
