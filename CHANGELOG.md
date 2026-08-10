# Changelog

## [Unreleased]

### Fixed

- 超商電子地圖（`/stores/ecpay/map-url`）補上購物車商品「允許的物流方式」交集驗證。先前僅檢查 provider 與該物流方式的**全域**啟用狀態，因此購物車商品只允許萊爾富時，前端仍可取得**已簽章**的 7-11 電子地圖表單；使用者選完門市、callback 也會寫入門市 session 與 `ys_ec_selected_store`，直到送單才被核心擋下。現以核心 `YSShippingRegistry::is_method_allowed_for_cart()` 這一份共用守門驗證（fail-closed，回 `shipping_method_not_allowed`）。
- 此端點不再接受 `order_id`。該參數直接取自請求且未驗證訂單存在、擁有者或品項，任何非零值都能整段跳過上述物流限制；現直接拒收（`order_id_not_supported`）。核心 `assets/js/ys-ec-store-selector.js` 與 `sdk/ys-cart-ecpay-headless.js` 兩個呼叫端皆未使用此參數。
- 購物車讀取失敗不再被當成空購物車。核心把空車視為「不限物流」，因此先前把 handler 缺失／非陣列／例外一律轉成空陣列，會讓讀取失敗反而簽發地圖表單；現以 `null` 表示讀取失敗並 fail-closed，空陣列僅代表確定為空。購物車讀取為純讀（`get_items_raw()` + `ys_ec_cart_key_scope` 綁定），訪客無 session cookie 時直接短路以避免 `setcookie()` 副作用。

## [0.2.10] - 2026-07-28

### Fixed

- Stop the bundled YS Hub Client library from registering an invalid
  WooCommerce HPOS declaration from its vendor path.
