<?php
/**
 * v0.3.0 — 綠界物流：C2C（店到店）、低溫、貨到付款
 *
 * 三個客戶阻斷級缺口（光咖啡 guangcafe.com 實地發現）：
 *
 *   1. **只有 B2C subtype**。`UNIMART`／`FAMI`／`HILIFE` 寫死，完全沒有 C2C。
 *      客戶的綠界合約是 C2C（舊 WooCommerce `cvs_type=C2C`、同一組 MerchantID），
 *      因此站上開著的 7-11 取貨是「用 B2C 的 subtype 打 C2C 商店代號」——
 *      電子地圖回「找不到加密金鑰，請確認是否有申請開通**此物流方式**」，
 *      送單則直接失敗。注意是「此物流方式」而不是整個服務，那正是 subtype
 *      對不上的症狀。
 *
 *   2. **宅配溫層是半成品**。`Temperature` 欄位在，值卻取自
 *      `$order_data['temperature_code']`——那個 key 全 repo 只出現在讀取的那
 *      一行，沒有任何寫入點。因此宅配**永遠**送常溫 `0001`。客戶賣的是冷藏
 *      （NT$110）與冷凍（NT$160），貨到就已經退冰。
 *
 *   3. **貨到付款送不出去**。`IsCollection` 在送單與電子地圖兩處都寫死 `'N'`。
 *
 * 修法照核心 PayUni provider 已驗證的雙軌模板：B2C 與 C2C 各自是獨立的物流
 * 方式，繼承同一個 base、只覆寫差異，各自在後台啟用／停用。
 *
 * Run: php tests/regression/v023_shipping_c2c_and_temperature.php
 */

declare(strict_types=1);

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ );
    }

    function wp_strip_all_tags( $v ): string {
        return strip_tags( (string) $v );
    }

    function current_time( string $type ): string {
        return '2026/08/12 00:00:00';
    }

    function rest_url( string $path = '' ): string {
        return 'https://example.test/wp-json/' . ltrim( $path, '/' );
    }

    function wp_json_encode( $data ) {
        return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    function __( string $t, string $d = '' ): string {
        return $t;
    }
}

namespace YangSheep\Ecommerce\Shipping {
    interface YSShippingInterface {}
}

namespace YangSheep\Ecommerce\Utils {
    class YSLogger {
        public static function error( string $c, string $m, array $x = [] ): void {}
        public static function warning( string $c, string $m, array $x = [] ): void {}
        public static function info( string $c, string $m, array $x = [] ): void {}
    }
}

namespace YangSheep\YSCartEcpay {
    final class Plugin {
        public static function manifest(): array {
            return [];
        }
    }
}

namespace YangSheep\YSCartEcpay\Support {
    class Settings {
        /** 後台設定值（測試逐案覆寫）。 */
        public static array $options = [];

        public const SENDER_KEYS = [
            'name'    => 'shipping_ecpay_sender_name',
            'phone'   => 'shipping_ecpay_sender_phone',
            'zipcode' => 'shipping_ecpay_sender_zipcode',
            'address' => 'shipping_ecpay_sender_address',
        ];

        public static function get( string $key, mixed $default = '' ): mixed {
            return self::$options[ $key ] ?? $default;
        }

        public static function shipping_enabled( string $key ): bool {
            return true;
        }

        public static function has_logistics_credentials(): bool {
            return true;
        }

        public static function logistics_credentials(): array {
            return [
                'merchant_id' => '3507531',
                'hash_key'    => 'K',
                'hash_iv'     => 'I',
                'test_mode'   => false,
            ];
        }

        public static function shipping_base_fee( string $id ): float {
            return 0.0;
        }

        public static function shipping_free_threshold( string $id ): float {
            return 0.0;
        }

        public static function shipping_method_option( string $method_id, string $key, mixed $default = '' ): mixed {
            return self::$options[ 'shipping_' . $method_id . '_' . $key ] ?? $default;
        }

        public static function shipping_cod_enabled( string $method_id ): bool {
            return '1' === (string) self::shipping_method_option( $method_id, 'cod_enabled', '0' );
        }
    }

    class CheckMacValue {
        public static function generate( array $fields, string $key, string $iv, string $algo = 'md5' ): string {
            return 'MAC';
        }
    }
}

namespace {
    $root = dirname( __DIR__, 2 );

    require_once $root . '/src/Shipping/Ecpay/EcpayShipping.php';
    foreach ( glob( $root . '/src/Shipping/Ecpay/EcpayShipping*.php' ) as $file ) {
        if ( str_ends_with( $file, 'EcpayShipping.php' ) || str_ends_with( $file, 'EcpayShippingRequester.php' ) ) {
            continue;
        }
        require_once $file;
    }

    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShipping;
    use YangSheep\YSCartEcpay\Support\Settings;

    $pass   = 0;
    $fail   = 0;
    $assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
        if ( $ok ) {
            ++$pass;
            echo "  PASS  {$label}\n";
            return;
        }
        ++$fail;
        echo "  FAIL  {$label}\n";
    };

    $ns = 'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\';

    // ── (a) C2C subtype 完整且與 B2C 分開 ─────────────────────────────────
    $expected = [
        'EcpayShippingUnimart'          => [ 'UNIMART', false ],
        'EcpayShippingFamily'           => [ 'FAMI', false ],
        'EcpayShippingHilife'           => [ 'HILIFE', false ],
        'EcpayShippingUnimartC2C'       => [ 'UNIMARTC2C', true ],
        'EcpayShippingFamilyC2C'        => [ 'FAMIC2C', true ],
        'EcpayShippingHilifeC2C'        => [ 'HILIFEC2C', true ],
        'EcpayShippingUnimartFreeze'    => [ 'UNIMARTFREEZE', false ],
        'EcpayShippingUnimartFreezeC2C' => [ 'UNIMARTFREEZEC2C', true ],
    ];

    $problems = [];
    foreach ( $expected as $class => [ $subtype, $is_c2c ] ) {
        $fqcn = $ns . $class;
        if ( ! class_exists( $fqcn ) ) {
            $problems[] = $class . '（類別不存在）';
            continue;
        }

        $m = new $fqcn();
        if ( $m->get_logistics_subtype() !== $subtype ) {
            $problems[] = sprintf( '%s subtype=%s（應為 %s）', $class, $m->get_logistics_subtype(), $subtype );
        }
        if ( $m->is_c2c() !== $is_c2c ) {
            $problems[] = $class . ' is_c2c 不符';
        }
    }

    $assert(
        [] === $problems,
        '(a) 🔴 B2C／C2C／冷凍各自是獨立方式且 subtype 正確（問題：' . ( implode( '; ', $problems ) ?: '無' ) . '）'
    );

    // ── (b) 每個方式的 id 唯一，且 C2C 有 _c2c 後綴 ────────────────────────
    $ids = [];
    foreach ( array_keys( $expected ) as $class ) {
        $m           = new ( $ns . $class )();
        $ids[ $class ] = $m->get_id();
    }
    $assert(
        count( array_unique( $ids ) ) === count( $ids )
        && str_ends_with( $ids['EcpayShippingUnimartC2C'], '_c2c' )
        && str_ends_with( $ids['EcpayShippingFamilyC2C'], '_c2c' )
        && str_ends_with( $ids['EcpayShippingHilifeC2C'], '_c2c' ),
        '(b) 方式 id 互不重複，C2C 以 _c2c 後綴區分'
    );

    // ── (c) 🔴 宅配溫層由**物流方式**回答，不再是永遠常溫 ──────────────────
    $tcat    = new ( $ns . 'EcpayShippingTcat' )();
    $chilled = new ( $ns . 'EcpayShippingTcatChilled' )();
    $frozen  = new ( $ns . 'EcpayShippingTcatFrozen' )();

    $assert(
        EcpayShipping::TEMP_ROOM === $tcat->get_temperature_code()
        && EcpayShipping::TEMP_CHILLED === $chilled->get_temperature_code()
        && EcpayShipping::TEMP_FROZEN === $frozen->get_temperature_code()
        && '0001' === EcpayShipping::TEMP_ROOM
        && '0002' === EcpayShipping::TEMP_CHILLED
        && '0003' === EcpayShipping::TEMP_FROZEN,
        '(c) 🔴 黑貓常溫／冷藏／冷凍各自回自己的溫層碼（0001／0002／0003）'
    );

    // 三者的 subtype 都是 TCAT——溫層是 Temperature 欄位，不是 subtype
    $assert(
        'TCAT' === $tcat->get_logistics_subtype()
        && 'TCAT' === $chilled->get_logistics_subtype()
        && 'TCAT' === $frozen->get_logistics_subtype(),
        '(c2) 宅配溫層走 Temperature 欄位，subtype 仍是 TCAT'
    );

    // 超商冷凍則相反：它是**獨立 subtype**
    $assert(
        'UNIMARTFREEZE' === ( new ( $ns . 'EcpayShippingUnimartFreeze' )() )->get_logistics_subtype(),
        '(c3) 超商冷凍是獨立 subtype（不是常溫超商加溫層參數）'
    );

    // ── (d) 🔴 貨到付款由設定決定，不再寫死 N ──────────────────────────────
    Settings::$options = [];
    $unimart = new ( $ns . 'EcpayShippingUnimart' )();
    $assert( false === $unimart->supports_cod(), '(d) 未設定時預設不代收' );

    Settings::$options = [ 'shipping_ys_ec_ecpay_ship_unimart_cod_enabled' => '1' ];
    $assert( true === $unimart->supports_cod(), '(d2) 🔴 後台開啟後 supports_cod() 才回 true（舊版寫死 N）' );

    // ── (e) 🔴 C2C 必填退貨門市；缺值 fail-closed ──────────────────────────
    Settings::$options = [];
    $u_c2c = new ( $ns . 'EcpayShippingUnimartC2C' )();
    $assert(
        '' === $u_c2c->get_return_store_id() && '' === $unimart->get_return_store_id(),
        '(e) 未設定退貨門市時回空字串（由 requester fail-closed，不猜門市）'
    );

    Settings::$options = [ 'ship_c2c_return_store_id' => '991182' ];
    $assert(
        '991182' === $u_c2c->get_return_store_id()
        && '' === $unimart->get_return_store_id(),
        '(e2) C2C 讀得到退貨門市；B2C 不需要也不讀'
    );

    // ── (f) 契約：requester 的三個欄位都來自物流方式 ───────────────────────
    $req = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/src/Shipping/Ecpay/EcpayShippingRequester.php' ) );
    $code = implode( "\n", array_filter(
        explode( "\n", $req ),
        static function ( string $line ): bool {
            $t = ltrim( $line );
            return '' !== $t && ! str_starts_with( $t, '//' ) && ! str_starts_with( $t, '*' ) && ! str_starts_with( $t, '/*' );
        }
    ) );

    $assert(
        str_contains( $code, '$this->method->get_temperature_code()' )
        && str_contains( $code, '$this->method->supports_cod()' )
        && str_contains( $code, '$this->method->is_c2c()' )
        && str_contains( $code, "\$fields['ReturnStoreID']" )
        // 🔴 負向：三個寫死的值都不得再出現
        && ! str_contains( $code, "'IsCollection'      => 'N'" )
        && ! str_contains( $code, "\$order_data['temperature_code']" ),
        '(f) 🔴 Temperature／IsCollection／ReturnStoreID 都由物流方式決定，寫死的值已移除'
    );

    // ── (g) 契約：C2C 的寄件代碼必須帶回來（否則貨出不去）──────────────────
    $assert(
        str_contains( $code, "'cvs_payment_no'" )
        && str_contains( $code, "'cvs_validation_no'" )
        && str_contains( $code, "\$this->method->is_c2c() && '' === \$cvs_payment_no" ),
        '(g) 🔴 C2C 回單保留 CVSPaymentNo／CVSValidationNo，缺寄貨編號即失敗'
    );

    // ── (h) 契約：電子地圖的 subtype 表與方式一致 ──────────────────────────
    $sel = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php' ) );
    $missing = [];
    foreach ( $expected as $class => [ $subtype, $is_c2c ] ) {
        $id = ( new ( $ns . $class )() )->get_id();
        if ( ! str_contains( $sel, "'" . $id . "'" ) || ! str_contains( $sel, "'" . $subtype . "'" ) ) {
            $missing[] = $id;
        }
    }
    $assert(
        [] === $missing && str_contains( $sel, 'self::is_cod_enabled( $shipping_id )' ),
        '(h) 🔴 電子地圖涵蓋全部 subtype，且代收與送單一致（缺：' . ( implode( ', ', $missing ) ?: '無' ) . '）'
    );

    echo "\nshipping c2c + temperature: {$pass} PASS / {$fail} FAIL\n";
    exit( $fail > 0 ? 1 : 0 );
}
