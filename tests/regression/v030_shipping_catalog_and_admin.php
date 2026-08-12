<?php
/**
 * 行為回歸：物流方式型錄 × 後台儲存往返（v0.2.12）
 *
 * 這一支**不做原始碼字串斷言**。它把真的類別載進來、真的呼叫後台的存檔流程、
 * 真的讀回設定，然後問：使用者實際會遇到的行為對不對。
 *
 * 涵蓋 CODEX #2I Section 4 的：
 *   (1) 每個方式都能獨立啟用／停用，method_id 不重複
 *   (2) 後台 save → DB 設定 → reload 往返
 *   (3) 各 C2C 的退貨門市完全獨立，不會跨通路取值
 *   (6) 7-ELEVEN 冷凍與常溫是各自獨立的方式（不同 subtype、不同開關）
 *   ＋型錄與 manifest／Settings／admin 清單的一致性（單一 SOT 的實際證明）
 *
 * Run: php tests/regression/v030_shipping_catalog_and_admin.php
 */

declare(strict_types=1);

namespace YangSheep\Ecommerce\Shipping {
    interface YSShippingInterface {
        public function get_id(): string;
        public function get_title(): string;
        public function get_provider(): string;
        public function get_type(): string;
        public function is_enabled(): bool;
        public function is_available( array $order_data ): bool;
        public function calculate_cost( array $cart_items, array $address = [] ): float;
        public function get_free_threshold(): float;
        public function supports_cvs_selection(): bool;
        public function supports_cod(): bool;
        public function get_settings_fields(): array;
        public function get_supported_countries(): array;
    }
}

namespace YangSheep\Ecommerce {
    final class YSEcommerce {
        /** @var array<string,mixed> */
        public static array $settings = [];
        private static ?self $instance = null;

        public static function get_instance(): self {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function get_setting(string $key, mixed $default = ''): mixed {
            return self::$settings[$key] ?? $default;
        }

        public function update_setting(string $key, mixed $value): bool {
            self::$settings[$key] = $value;
            return true;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_CART_ECPAY_DIR', dirname(__DIR__, 2) . '/');
    define('YS_CART_ECPAY_VERSION', '0.2.12');
    define('YS_CART_ECPAY_BASENAME', 'ys-cart-ecpay/ys-cart-ecpay.php');

    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? ''; }
    function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
    function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
    function esc_html__(string $text, string $domain = ''): string { return $text; }
    function wp_json_encode($data, int $flags = 0) { return json_encode($data, $flags); }

    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
    foreach (glob($root . '/src/Shipping/Ecpay/EcpayShipping*.php') ?: [] as $file) {
        require_once $file;
    }
    require_once $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php';
    require_once $root . '/src/Support/Settings.php';
    require_once $root . '/src/Admin/EcpaySettings.php';

    use YangSheep\Ecommerce\YSEcommerce;
    use YangSheep\YSCartEcpay\Admin\EcpaySettings;
    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShipping;
    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
    use YangSheep\YSCartEcpay\Support\Settings;

    $pass = 0;
    $fail = 0;
    $bad  = [];

    function check(string $label, bool $ok): void {
        global $pass, $fail, $bad;
        if ($ok) {
            echo "  PASS  {$label}\n";
            $pass++;
            return;
        }
        echo "  FAIL  {$label}\n";
        $fail++;
        $bad[] = $label;
    }

    /** 讓後台以「這些欄位被勾選／填寫」的狀態跑一次儲存。 */
    function admin_save(array $post): void {
        $_POST = $post;
        $method = new ReflectionMethod(EcpaySettings::class, 'save_shipping_method_options');
        $method->setAccessible(true);
        $method->invoke(null);

        $ids      = (new ReflectionMethod(EcpaySettings::class, 'shipping_method_ids'));
        $ids->setAccessible(true);
        $aliases  = array_keys($ids->invoke(null));

        $selectable = new ReflectionMethod(EcpaySettings::class, 'selectable_aliases');
        $selectable->setAccessible(true);
        $allowed = $selectable->invoke(null, $aliases);

        $switches = new ReflectionMethod(EcpaySettings::class, 'save_method_switches');
        $switches->setAccessible(true);
        $switches->invoke(null, $aliases, $allowed);

        $_POST = [];
    }

    echo "## v030 物流方式型錄 × 後台儲存往返（v0.2.12）\n";

    // ── (1) 每個方式都是獨立的 ────────────────────────────────────────────
    $catalog = EcpayShippingCatalog::all();
    $ids     = EcpayShippingCatalog::ids();

    check(
        '(1a) method_id 不重複，且與型錄 key 一一對應',
        count($ids) === count(array_unique($ids)) && count($ids) === count($catalog)
    );

    $aliases = array_column($catalog, 'alias');
    check('(1b) alias 不重複', count($aliases) === count(array_unique($aliases)));

    $enabled_options = array_column($catalog, 'enabled_option');
    check('(1c) 每個方式有自己的啟用開關 key，不共用', count($enabled_options) === count(array_unique($enabled_options)));

    // 同一個通路的 B2C 與 C2C 必須是不同的 subtype——這正是「電子地圖回找不到
    // 加密金鑰」的根因。
    $subtypes = array_column($catalog, 'logistics_subtype');
    $cvs_subtypes = [];
    foreach ($catalog as $descriptor) {
        if ('CVS' === $descriptor['logistics_type']) {
            $cvs_subtypes[] = $descriptor['logistics_subtype'];
        }
    }
    check('(1d) 超商各方式的 subtype 互不相同', count($cvs_subtypes) === count(array_unique($cvs_subtypes)));

    // 黑貓三個溫層刻意共用 subtype TCAT，靠 Temperature 區分——這是綠界的合約。
    $tcat = array_filter($catalog, static fn (array $d): bool => 'TCAT' === $d['logistics_subtype']);
    $tcat_temps = array_column($tcat, 'temperature');
    check(
        '(1e) 黑貓三個方式共用 subtype TCAT 但溫層互不相同（0001/0002/0003）',
        3 === count($tcat) && ['0001', '0002', '0003'] === array_values(array_unique($tcat_temps))
    );

    // ── (6) 7-ELEVEN 冷凍與常溫是兩個方式 ─────────────────────────────────
    $unimart = EcpayShippingCatalog::get('ys_ec_ecpay_ship_unimart');
    $freeze  = EcpayShippingCatalog::get('ys_ec_ecpay_ship_unimart_freeze');
    check(
        '(6a) 7-ELEVEN 冷凍是獨立 subtype UNIMARTFREEZE，不是常溫加一個溫層參數',
        null !== $unimart && null !== $freeze
            && 'UNIMART' === $unimart['logistics_subtype']
            && 'UNIMARTFREEZE' === $freeze['logistics_subtype']
            && $unimart['enabled_option'] !== $freeze['enabled_option']
            && '0003' === $freeze['temperature']
    );

    // 🔴 綠界官方（官方 API skill V3.2 物流商支援表＋官方 PHP SDK）列出的 C2C
    // subtype 只有 FAMIC2C／UNIMARTC2C／HILIFEC2C／OKMARTC2C——沒有冷凍 C2C。
    // 自創一個 subtype 的後果是送單被綠界拒絕，而錯誤訊息與「B2C/C2C 用錯」一模一樣。
    check(
        '(6b) 不得出現官方未載明的冷凍 C2C subtype',
        !in_array('UNIMARTFREEZEC2C', $subtypes, true)
    );

    // ── 單一 SOT：manifest／admin 清單／Settings 都要從型錄長出來 ──────────
    $manifest_ids = array_column(EcpayShippingCatalog::manifest_methods(), 'id');
    check('(SOT-a) manifest 的方式清單 = 型錄', $manifest_ids === $ids);

    $admin_rows = EcpayShippingCatalog::admin_rows();
    check('(SOT-b) 後台清單 = 型錄（以 alias 為 key）', array_keys($admin_rows) === $aliases);

    $method_keys = Settings::method_keys();
    $missing_keys = [];
    foreach ($catalog as $descriptor) {
        if (($method_keys[$descriptor['alias']] ?? null) !== $descriptor['enabled_option']) {
            $missing_keys[] = $descriptor['alias'];
        }
    }
    check('(SOT-c) Settings 的開關 key = 型錄', [] === $missing_keys);

    // manifest 內每個超商方式都必須帶電子地圖選店器，宅配則不帶。
    $selector_ok = true;
    foreach (EcpayShippingCatalog::manifest_methods() as $entry) {
        $descriptor = EcpayShippingCatalog::get($entry['id']);
        $has        = isset($entry['store_selector']);
        if ($has !== ('CVS' === $descriptor['logistics_type'])) {
            $selector_ok = false;
        }
    }
    check('(SOT-d) 只有超商方式帶 store_selector', $selector_ok);

    // ── (2) 後台儲存往返 ──────────────────────────────────────────────────
    YSEcommerce::$settings = [ 'ys_ec_ecpay_enabled' => '1' ];

    // 全部勾選，但一個退貨門市／重量都不填。
    $post = [];
    foreach ($aliases as $alias) {
        $post['ys_ec_ecpay_' . $alias . '_enabled'] = '1';
    }
    admin_save($post);

    $blocked = [];
    $opened  = [];
    foreach ($catalog as $method_id => $descriptor) {
        $on = '1' === (string) YSEcommerce::$settings[$descriptor['enabled_option']];
        if ($descriptor['requires_return_store'] || $descriptor['requires_goods_weight']) {
            if ($on) {
                $blocked[] = $method_id;
            }
        } elseif (!$on) {
            $opened[] = $method_id;
        }
    }

    check(
        '(2a) 缺退貨門市的 C2C／缺重量的郵局：勾了也不會被啟用（fail-closed）',
        [] === $blocked
    );
    check('(2b) 設定完整的方式照勾選啟用', [] === $opened);

    // 補上每個 C2C 的退貨門市（各自不同）＋郵局重量，再存一次。
    $post = [];
    foreach ($aliases as $alias) {
        $post['ys_ec_ecpay_' . $alias . '_enabled'] = '1';
    }
    $expected_return_stores = [];
    $i = 0;
    foreach ($catalog as $method_id => $descriptor) {
        if ($descriptor['requires_return_store']) {
            $value = 'RS' . str_pad((string) (++$i), 4, '0', STR_PAD_LEFT);
            $post['ys_ec_ecpay_' . $descriptor['alias'] . '_return_store_id'] = $value;
            $expected_return_stores[$method_id] = $value;
        }
        if ($descriptor['requires_goods_weight']) {
            $post['ys_ec_ecpay_' . $descriptor['alias'] . '_goods_weight'] = '1.25';
        }
    }
    admin_save($post);

    $still_off = [];
    foreach ($catalog as $method_id => $descriptor) {
        if ('1' !== (string) YSEcommerce::$settings[$descriptor['enabled_option']]) {
            $still_off[] = $method_id;
        }
    }
    check('(2c) 補齊必填後全部方式都能啟用', [] === $still_off);

    // reload：後台渲染資料必須讀得回剛剛存的值，否則「存了等於沒存」。
    $_GET = [ 'tab' => 'shipping' ];
    $rendered = EcpaySettings::settings_for_render();
    $_GET = [];

    $reload_ok = true;
    foreach ($catalog as $method_id => $descriptor) {
        $row = $rendered['shipping_methods'][$descriptor['alias']] ?? [];
        if ($descriptor['requires_return_store']
            && ($row['return_store_id'] ?? null) !== $expected_return_stores[$method_id]) {
            $reload_ok = false;
        }
        if ($descriptor['requires_goods_weight'] && '' === (string) ($row['goods_weight'] ?? '')) {
            $reload_ok = false;
        }
        if (true !== ($rendered[$descriptor['alias'] . '_enabled'] ?? false)) {
            $reload_ok = false;
        }
    }
    check('(2d) reload 讀得回每個方式的開關與專屬設定', $reload_ok);

    // 單獨關掉一個方式，其他不受影響。
    $post_single = $post;
    unset($post_single['ys_ec_ecpay_ship_unimart_c2c_enabled']);
    admin_save($post_single);

    check(
        '(2e) 關掉 7-ELEVEN 交貨便不會動到其他任何方式',
        '0' === (string) YSEcommerce::$settings['ys_ec_ecpay_ship_unimart_c2c_enabled']
            && '1' === (string) YSEcommerce::$settings['ys_ec_ecpay_ship_family_c2c_enabled']
            && '1' === (string) YSEcommerce::$settings['ys_ec_ecpay_ship_unimart_enabled']
            && '1' === (string) YSEcommerce::$settings['ys_ec_ecpay_ship_hilife_c2c_enabled']
    );

    // ── (3) 各 C2C 的退貨門市完全獨立 ─────────────────────────────────────
    admin_save($post);

    $isolation_ok = true;
    $seen_values  = [];
    foreach ($catalog as $method_id => $descriptor) {
        if (!$descriptor['requires_return_store']) {
            continue;
        }
        /** @var EcpayShipping $method */
        $method = new $descriptor['class']();
        $value  = $method->get_return_store_id();
        if ($value !== $expected_return_stores[$method_id]) {
            $isolation_ok = false;
        }
        $seen_values[] = $value;
    }
    check(
        '(3a) 每個 C2C 方式讀到的是自己那一把退貨門市，且互不相同',
        $isolation_ok && count($seen_values) === count(array_unique($seen_values))
    );

    // 只清掉全家的退貨門市——7-ELEVEN 與萊爾富必須完全不受影響。
    YSEcommerce::$settings['ys_ec_ecpay_ship_family_c2c_return_store_id'] = '';

    $family = new \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingFamilyC2C();
    $seven  = new \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingUnimartC2C();
    $hilife = new \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingHilifeC2C();

    check(
        '(3b) 清掉全家的退貨門市，只有全家失效；7-ELEVEN／萊爾富仍可用',
        '' === $family->get_return_store_id()
            && false === $family->is_configured()
            && '' !== $seven->get_return_store_id()
            && true === $seven->is_configured()
            && true === $hilife->is_configured()
    );

    check(
        '(3c) 退貨門市缺值時該方式直接停用（不是等到送單才失敗）',
        false === $family->is_enabled()
    );

    // ── 能力 vs. 這張訂單：supports_cod() 只回答「這個通路能不能代收」 ─────
    $cod_matches = true;
    foreach ($catalog as $method_id => $descriptor) {
        /** @var EcpayShipping $method */
        $method = new $descriptor['class']();
        if ($method->supports_cod() !== (bool) $descriptor['cod_capable']) {
            $cod_matches = false;
        }
    }
    check('(COD-a) supports_cod() 一律等於型錄的通路能力，不讀任何後台開關', $cod_matches);

    check(
        '(COD-b) 已移除舊的「貨到付款」後台開關（它會讓已付款訂單也送出代收）',
        !method_exists(Settings::class, 'shipping_cod_enabled')
    );

    echo "\nREGRESSION v030_shipping_catalog_and_admin PASS={$pass} FAIL={$fail}\n";
    if ($fail > 0) {
        echo "Failed:\n";
        foreach ($bad as $name) {
            echo "  - {$name}\n";
        }
        exit(1);
    }
}
