<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Shipping\YSShippingInterface;
use YangSheep\YSCartEcpay\Support\Settings;
use YangSheep\YSCartEcpay\Support\ShippingMethodOperability;

/**
 * 綠界物流方式基底
 *
 * 🔴 這個類別**不再**要求子類別自己回答 subtype／型別／溫層。所有 wire 屬性一律
 * 由 {@see EcpayShippingCatalog} 依 `get_id()` 查表導出，子類別只宣告自己是誰。
 *
 * 理由：subtype 寫在子類別時，它就是第二份清單——電子地圖那張 SUBTYPES 表是第一份。
 * 兩份只要有一處手滑，症狀是「地圖開得起來、送單卻被綠界拒絕」，而且兩邊各自看起來
 * 都對。查表就沒有兩份可以不一致。
 */
abstract class EcpayShipping implements YSShippingInterface {
	/** 每個子類別只回答這一件事。 */
	abstract public function get_id(): string;

	/**
	 * 本方式的 descriptor；不在型錄內回 null（呼叫端一律 fail-closed）。
	 *
	 * @return array<string,mixed>|null
	 */
	final protected function descriptor(): ?array {
		return EcpayShippingCatalog::get( $this->get_id() );
	}

	/**
	 * wire 欄位取值：型錄裡沒有這個方式就直接中止。
	 *
	 * 缺 descriptor 代表這個類別根本不該被註冊；此時「猜一個預設值」會送出
	 * 錯的 subtype／溫層，那比噴錯嚴重得多。
	 *
	 * @return array<string,mixed>
	 */
	final protected function require_descriptor(): array {
		$descriptor = $this->descriptor();
		if ( null === $descriptor ) {
			throw new \RuntimeException(
				sprintf( '綠界物流方式 %s 不在型錄（EcpayShippingCatalog）內，已中止。', $this->get_id() )
			);
		}

		return $descriptor;
	}

	public function get_provider(): string {
		return 'ecpay';
	}

	public function get_title(): string {
		$descriptor = $this->descriptor();
		return '綠界' . (string) ( $descriptor['label'] ?? $this->get_id() );
	}

	/** 核心契約的粗分類：cvs／home。 */
	public function get_type(): string {
		$descriptor = $this->descriptor();
		return 'CVS' === ( $descriptor['logistics_type'] ?? '' ) ? 'cvs' : 'home';
	}

	/** 綠界 `LogisticsType`：CVS／HOME。 */
	public function get_logistics_type(): string {
		return (string) $this->require_descriptor()['logistics_type'];
	}

	/** 綠界 `LogisticsSubType`；電子地圖與送單必須送同一個。 */
	public function get_logistics_subtype(): string {
		return (string) $this->require_descriptor()['logistics_subtype'];
	}

	/**
	 * 這個物流方式的溫層代碼。
	 *
	 * 🔴 溫層是**物流方式的屬性**，不是訂單的臨時欄位。舊版讀
	 * `$order_data['temperature_code']`，而那個 key 全 repo 只出現在讀取的那一行、
	 * 沒有任何寫入點——換句話說宅配永遠送常溫（0001）。賣冷藏／冷凍商品時，
	 * 貨到就已經退冰了。
	 */
	public function get_temperature_code(): string {
		return (string) $this->require_descriptor()['temperature'];
	}

	/** b2c／c2c／home。 */
	public function get_channel(): string {
		$descriptor = $this->descriptor();
		return (string) ( $descriptor['channel'] ?? '' );
	}

	public function is_c2c(): bool {
		return EcpayShippingCatalog::CHANNEL_C2C === $this->get_channel();
	}

	/**
	 * 這個 subtype 吃不吃 `ReturnStoreID`。
	 *
	 * 🔴 官方規格：**選填**，且**僅 7-ELEVEN C2C（UNIMARTC2C）適用**，未設定時
	 * 退回原寄件門市。所以它既不是「C2C 都有」，也不是必填。
	 *
	 * 先前兩者都搞錯：對全家／萊爾富送出一個它們不吃的欄位，又因為沒填就不讓
	 * 方式啟用——業主明明照官方規格可以不填。
	 */
	public function supports_return_store(): bool {
		$descriptor = $this->descriptor();
		return (bool) ( $descriptor['supports_return_store'] ?? false );
	}

	/** 本方式**專屬**的退貨門市設定 key（不適用者為空字串）。 */
	public function get_return_store_option(): string {
		$descriptor = $this->descriptor();
		return $this->supports_return_store() ? (string) ( $descriptor['return_store_option'] ?? '' ) : '';
	}

	/**
	 * 退貨門市代號（選填）。
	 *
	 * 🔴 讀**自己的** option。共用一把 key 的後果是：業主填了一個通路的退貨門市，
	 * 另一個通路的退貨就寄到別人家去——而且送單當下不會有任何錯誤訊息。
	 *
	 * 空字串＝業主沒填，這是合法狀態（綠界會退回原寄件門市），送單時不帶這個欄位。
	 */
	public function get_return_store_id(): string {
		$option = $this->get_return_store_option();
		if ( '' === $option ) {
			return '';
		}

		return trim( (string) Settings::get( $option, '' ) );
	}

	/**
	 * 綠界載明本 subtype 需一併傳 `CollectionAmount`（值等於 `GoodsAmount`）。
	 *
	 * 適用 UNIMART／UNIMARTC2C／UNIMARTFREEZE；缺了會建單失敗。
	 */
	public function requires_collection_amount(): bool {
		$descriptor = $this->descriptor();
		return (bool) ( $descriptor['requires_collection_amount'] ?? false );
	}

	/**
	 * 這個方式送得出去的金額上限（null = 官方未設上限）
	 *
	 * 🔴 這是**契約**，不是可以裁切的參數。超過上限時正確的行為是「送不出去」，
	 * 不是「幫它改成上限」——舊版對所有方式一律 `min( 20000, $amount )`，
	 * 一張 25,000 元的黑貓代收訂單因此送出 20,000，貨照出、錢少收 5,000。
	 *
	 * 官方規則（`guides/06-logistics-domestic.md:403`、`:407`）：
	 *   - CVS 超商全系列：1 ~ 20,000
	 *   - 宅配（HOME）：無上限
	 *   - **但只要是貨到付款（`IsCollection=Y`），一律 20,000**
	 *
	 * 上限依「通路 × 是否代收」導出，不逐一寫進 11 個 descriptor：那 11 份值
	 * 只會是同兩個數字的複製，而複製出來的常數遲早會有一份沒跟著改。
	 */
	public function amount_max( bool $is_collection ): ?int {
		if ( $is_collection ) {
			return 20000;
		}

		return 'CVS' === $this->get_logistics_type() ? 20000 : null;
	}

	/**
	 * 綠界載明本 subtype 必填 `GoodsWeight`（目前只有中華郵政）。
	 */
	public function requires_goods_weight(): bool {
		$descriptor = $this->descriptor();
		return (bool) ( $descriptor['requires_goods_weight'] ?? false );
	}

	/**
	 * 是否送宅配專屬條件（溫層／距離／規格／指定時段）。
	 *
	 * 🔴 綠界官方明載中華郵政「請忽略」這些欄位；舊版對郵局照送，那是把
	 * 一份不屬於它的合約塞給它。
	 */
	public function sends_home_conditions(): bool {
		$descriptor = $this->descriptor();
		return (bool) ( $descriptor['sends_home_conditions'] ?? false );
	}

	/**
	 * 後台設定的包裹預設重量（公斤）；0 表示未設定。
	 *
	 * 只在訂單本身算不出重量時當後援；兩邊都沒有時由 requester fail-closed。
	 */
	public function get_default_goods_weight(): float {
		return max( 0.0, (float) Settings::shipping_method_option( $this->get_id(), 'goods_weight', '0' ) );
	}

	/**
	 * 建單回應中缺了就代表「貨出不去」的欄位。
	 *
	 * @return array<int,string>
	 */
	public function required_response_fields(): array {
		$descriptor = $this->descriptor();
		$fields     = $descriptor['required_response_fields'] ?? [];

		return is_array( $fields ) ? array_values( array_map( 'strval', $fields ) ) : [];
	}

	protected function settings_key(): string {
		$descriptor = $this->descriptor();
		return (string) ( $descriptor['alias'] ?? '' );
	}

	/**
	 * 這個方式**設定完整、可以送單**嗎？
	 *
	 * C2C 沒填退貨門市時回 false：綠界規定必填，沒有它建單一定被拒。與其讓顧客
	 * 選得到、結帳後才失敗，不如一開始就不要出現。
	 */
	public function is_configured(): bool {
		// 🔴 退貨門市**不在這裡**：官方規格是選填，沒填時綠界退回原寄件門市。
		// 把它當必填會讓一個完全合法的設定被判定成「未設定完成」。

		// 郵局的重量是綠界必填欄位。訂單本身算得出重量時優先用訂單的，但
		// 商品沒填重量的站台算出來會是 0——所以後台的預設重量必須先有值，
		// 否則這個方式就是「選得到、送不出」。
		return ShippingMethodOperability::is_configured( $this->get_id() );
	}

	public function is_enabled(): bool {
		return ShippingMethodOperability::is_operable( $this->get_id() );
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
	 * 這個通路**能不能**代收貨款。
	 *
	 * 🔴 這是**能力**，不是「這張訂單要代收」。核心以它決定貨到付款這個付款方式
	 * 在結帳頁要不要出現（`YSCheckoutAvailabilityService::is_cod_available()`）；
	 * 送單時 `IsCollection` 一律看**訂單實際的付款方式**，兩者不可混為一談。
	 *
	 * 混在一起的後果是：線上已刷卡的訂單也送出 `IsCollection=Y`，顧客在門市被
	 * 再收一次錢。
	 */
	public function supports_cod(): bool {
		$descriptor = $this->descriptor();
		return (bool) ( $descriptor['cod_capable'] ?? false );
	}

	public function get_settings_fields(): array {
		return [];
	}

	public function get_supported_countries(): array {
		return [ 'TW' ];
	}
}
