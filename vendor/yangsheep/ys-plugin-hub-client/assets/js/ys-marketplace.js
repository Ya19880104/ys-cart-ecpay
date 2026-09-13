/**
 * YS Plugin Hub Client - 市集頁面前端邏輯
 * v2.0.2
 *
 * 所有操作走 AJAX，禁止 form POST。
 */
(function ($) {
    'use strict';

    var config = window.ysHubClient || {};
    var i18n = config.i18n || {};

    /**
     * 市集模組
     */
    var Marketplace = {

        /** 目前篩選的分類 */
        currentCategory: 'all',

        currentPlatform: 'all',

        /** 目前搜尋關鍵字 */
        searchKeyword: '',

        /** 外掛原始資料 */
        pluginsData: [],

        /** 分類資料 */
        categoriesData: [],

        platformsData: [],

        /** 公告資料 */
        announcementsData: [],

        /**
         * 初始化
         */
        init: function () {
            this.bindEvents();
            this.loadPlugins();
        },

        /**
         * 字串欄位：非字串一律退回預設值（拒絕該值，不做型別強轉）。
         */
        safeString: function (value, fallback) {
            return typeof value === 'string' ? value : (fallback || '');
        },

        /**
         * class token 只接受允許字元集。HTML escaping 不是 token 的正確約束：
         * 它不處理空白，遠端值就能夾帶額外的 class。
         */
        safeClassToken: function (raw, fallback) {
            return (typeof raw === 'string' && /^[A-Za-z0-9_-]{1,64}$/.test(raw)) ? raw : (fallback || '');
        },

        /**
         * 封閉域：不在允許集合內一律退回預設值。
         */
        safeEnum: function (value, allowed, fallback) {
            return (typeof value === 'string' && allowed.indexOf(value) !== -1) ? value : fallback;
        },

        /**
         * 以精確屬性值標記／移除節點，永遠不把資料內插進選擇器。
         *
         * 讀寫兩側必須用同一個表示法：寫入端經 HTML 解析後屬性存的是「解碼後」的值，
         * 而選擇器不做 HTML 解碼，因此把 HTML 逃逸字串放進選擇器會少一次解碼而永不相符；
         * 逃逸也不涵蓋 CSS 元字元，反斜線或換行會讓 jQuery 直接丟出語法錯誤。
         */
        attrFilter: function ($scope, selector, attribute, value) {
            var needle = String(value);
            return $scope.find(selector).filter(function () {
                return this.getAttribute(attribute) === needle;
            });
        },

        normalizePlatforms: function (platforms, plugins) {
            var self = this;
            var defaults = [
                {slug: 'ys-cart', name: 'YS CART'},
                {slug: 'woocommerce', name: 'WooCommerce'},
                {slug: 'wordpress', name: 'WordPress'}
            ];
            var declared = Array.isArray(platforms) ? platforms : [];
            var source = declared.length ? declared : defaults;
            // 以無原型物件作為 seen：一般 {} 會讓 'constructor' 這類鍵看起來已存在
            // （於是被靜默丟棄），而 '__proto__' 則永遠寫不進去（於是重複渲染）。
            var seen = Object.create(null);
            var result = [];

            $.each(source, function (i, platform) {
                if (!platform || typeof platform !== 'object') return;
                var slug = self.safeString(platform.slug);
                if (!slug || seen[slug]) return;
                seen[slug] = true;
                result.push({
                    slug: slug,
                    name: self.safeString(platform.name, slug)
                });
            });

            $.each(Array.isArray(plugins) ? plugins : [], function (i, plugin) {
                var slug = Marketplace.getPluginPlatform(plugin);
                if (!slug || seen[slug]) return;
                seen[slug] = true;
                result.push({
                    slug: slug,
                    name: self.safeString(plugin && plugin.platform_label, slug)
                });
            });

            return result;
        },

        getPluginPlatform: function (plugin) {
            if (!plugin || typeof plugin !== 'object') return '';
            var platform = this.safeString(plugin.platform);
            if (platform) return platform;
            if (plugin.slug === 'ys-cart') return 'ys-cart';
            if (plugin.category === 'payment' || plugin.category === 'shipping' || plugin.category === 'checkout' || plugin.category === 'cart') {
                return 'woocommerce';
            }
            if (this.safeString(plugin.name).toLowerCase().indexOf('woocommerce') !== -1) return 'woocommerce';
            return 'wordpress';
        },

        findCategoryLabel: function (category) {
            var slug = this.safeString(category);
            if (!slug) return '';
            for (var i = 0; i < this.categoriesData.length; i++) {
                var row = this.categoriesData[i];
                if (row && typeof row === 'object' && row.slug === slug) {
                    return this.safeString(row.name, slug);
                }
            }
            return slug;
        },

        findPlatformLabel: function (platform) {
            var slug = this.safeString(platform);
            if (!slug) return '';
            for (var i = 0; i < this.platformsData.length; i++) {
                var row = this.platformsData[i];
                if (row && typeof row === 'object' && row.slug === slug) {
                    return this.safeString(row.name, slug);
                }
            }
            if (slug === 'ys-cart') return 'YS CART';
            if (slug === 'woocommerce') return 'WooCommerce';
            if (slug === 'wordpress') return 'WordPress';
            return slug;
        },

        renderPlatformTabs: function () {
            var self = this;
            var $tabs = $('#ys-platform-tabs');
            if (!$tabs.length) return;

            $tabs.find('.ys-platform-tab').filter(function () {
                return this.getAttribute('data-platform') !== 'all';
            }).remove();

            $.each(self.platformsData, function (i, platform) {
                $tabs.append(self.renderDescriptor(self.node('button', {
                    'type': 'button',
                    'class': 'ys-filter-tab ys-platform-tab',
                    'data-platform': platform.slug
                }, platform.name)));
            });

            $tabs.find('.ys-platform-tab').removeClass('active');
            self.attrFilter($tabs, '.ys-platform-tab', 'data-platform', self.currentPlatform).addClass('active');
        },

        /**
         * 綁定事件
         */
        bindEvents: function () {
            var self = this;

            $(document).on('click', '.ys-platform-tab', function (e) {
                e.preventDefault();
                self.currentPlatform = $(this).attr('data-platform') || 'all';
                self.currentCategory = 'all';
                $('.ys-platform-tab').removeClass('active');
                $(this).addClass('active');
                self.renderCategoryTabs();
                self.renderPlugins();
            });

            // 分類篩選
            $(document).on('click', '.ys-filter-tab:not(.ys-platform-tab)', function (e) {
                e.preventDefault();
                var category = $(this).attr('data-category') || 'all';
                self.currentCategory = category;
                $('.ys-filter-tabs .ys-filter-tab').removeClass('active');
                $(this).addClass('active');
                self.renderPlugins();
            });

            // 搜尋
            $(document).on('input', '#ys-search-input', function () {
                self.searchKeyword = $(this).val().trim().toLowerCase();
                self.renderPlugins();
            });

            // 外部連結（付費外掛）：不使用行內事件處理器，點擊時再次套用封閉政策
            $(document).on('click', '.ys-external-btn', function (e) {
                e.preventDefault();
                var url = self.safeExternalUrl($(this).attr('data-external-url'));
                if (url) {
                    window.open(url, '_blank', 'noopener');
                }
            });

            // 安裝外掛
            $(document).on('click', '.ys-install-btn', function (e) {
                e.preventDefault();
                if (!confirm(i18n.confirmInstall)) return;
                var btn = $(this);
                var slug = btn.attr('data-slug') || '';
                var version = btn.attr('data-version') || '';
                self.installPlugin(btn, slug, version);
            });

            // 更新外掛
            $(document).on('click', '.ys-update-btn', function (e) {
                e.preventDefault();
                if (!confirm(i18n.confirmUpdate)) return;
                var btn = $(this);
                var slug = btn.attr('data-slug') || '';
                var version = btn.attr('data-version') || '';
                self.updatePlugin(btn, slug, version);
            });

            // 啟用外掛
            $(document).on('click', '.ys-activate-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var slug = btn.attr('data-slug') || '';
                self.activatePlugin(btn, slug);
            });

            // 停用外掛
            $(document).on('click', '.ys-deactivate-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var slug = btn.attr('data-slug') || '';
                self.deactivatePlugin(btn, slug);
            });

            // 刪除外掛
            $(document).on('click', '.ys-delete-btn', function (e) {
                e.preventDefault();
                var btn = $(this);
                var slug = btn.attr('data-slug') || '';
                if (confirm(i18n.confirmDelete || '確定要刪除此外掛？此操作無法復原。')) {
                    self.deletePlugin(btn, slug);
                }
            });

            // 刷新市集
            $(document).on('click', '#ys-refresh-btn', function (e) {
                e.preventDefault();
                self.refreshMarketplace($(this));
            });

            // 儲存設定
            $(document).on('click', '#ys-save-settings', function (e) {
                e.preventDefault();
                Settings.save($(this));
            });

            // 自動檢查更新 checkbox 變更時自動儲存
            $(document).on('change', '#ys-auto-check', function () {
                Settings.save($('#ys-save-settings'));
            });

            // 產生 Site Key
            $(document).on('click', '#ys-generate-key', function (e) {
                e.preventDefault();
                Settings.generateSiteKey($(this));
            });

            // 關閉公告
            $(document).on('click', '.ys-announcement-close', function () {
                var $ann = $(this).closest('.ys-announcement');
                var id = $ann.attr('data-id') || '';
                self.dismissAnnouncement(id);
                $ann.slideUp(200, function () {
                    $(this).remove();
                    // 若全部公告已關閉，隱藏容器
                    if ($('#ys-announcements .ys-announcement').length === 0) {
                        $('#ys-announcements').hide();
                    }
                });
            });
        },

        /**
         * 載入外掛列表（AJAX）
         */
        loadPlugins: function () {
            var self = this;

            self.showSkeleton();
            self.setHubStatus('checking', i18n.connecting || '連線中...');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_get_marketplace',
                    nonce: config.nonce
                },
                success: function (response) {
                    if (response.success && response.data) {
                        // 連線成功
                        self.setHubStatus('ok', i18n.connected || '已連線');

                        // 處理兩種格式：直接陣列或 {plugins: [...]} 物件
                        var plugins = self.normalizePlugins(response.data.plugins);
                        self.pluginsData = plugins;

                        self.platformsData = self.normalizePlatforms(self.normalizeTaxonomy(response.data.platforms), plugins);
                        self.renderPlatformTabs();

                        // 處理分類資料
                        self.categoriesData = self.normalizeTaxonomy(response.data.categories);
                        self.renderCategoryTabs();

                        // 處理公告資料
                        self.announcementsData = self.normalizeAnnouncements(response.data.announcements);
                        self.renderAnnouncements();

                        self.renderPlugins();
                    } else {
                        // API 回傳錯誤
                        var msg = (response.data && response.data.message)
                            ? response.data.message
                            : i18n.connectionFail;
                        var code = (response.data && response.data.code) || '';

                        self.setHubStatus('error', i18n.disconnected || '連線異常');
                        self.showConnectionError(msg, code);
                        self.showError(msg);
                    }
                },
                error: function (xhr) {
                    var reason = '';
                    if (xhr.status === 0) {
                        reason = i18n.networkError || 'Hub 伺服器無法連線（網路逾時或伺服器關閉）';
                    } else if (xhr.status >= 500) {
                        reason = (i18n.serverError || 'Hub 伺服器錯誤') + ' (HTTP ' + xhr.status + ')';
                    } else if (xhr.status === 403) {
                        reason = i18n.blocked || '此站台已被封鎖';
                    } else {
                        reason = (i18n.connectionFail || '連線失敗') + ' (HTTP ' + xhr.status + ')';
                    }

                    self.setHubStatus('error', i18n.disconnected || '連線異常');
                    self.showConnectionError(reason, 'http_' + xhr.status);
                    self.showError(reason);
                }
            });
        },

        /**
         * 設定 Hub 連線狀態燈號
         *
         * @param {string} status  'checking' | 'ok' | 'error' | 'breaker'
         * @param {string} text    狀態文字
         */
        setHubStatus: function (status, text) {
            // 封閉狀態集合同時決定要移除與要加入的 token，兩者不會各自漂移。
            var STATES = ['checking', 'ok', 'error', 'breaker'];
            var state = this.safeEnum(status, STATES, 'error');
            var $el = $('#ys-hub-status');
            $el.removeClass($.map(STATES, function (s) { return 'ys-hub-status-' + s; }).join(' '))
               .addClass('ys-hub-status-' + state);
            $el.find('.ys-hub-status-text').text(this.safeString(text));
            $el.attr('title', this.safeString(text));
        },

        /**
         * 顯示連線錯誤為公告
         *
         * @param {string} message 錯誤訊息
         * @param {string} code    錯誤代碼
         */
        showConnectionError: function (message, code) {
            var $wrap = $('#ys-announcements');
            var errorId = 'ys-conn-error';

            // 移除舊的連線錯誤公告
            $('#' + errorId).remove();

            var html = '<div id="' + errorId + '" class="ys-announcement ys-announcement-warning">' +
                '<div class="ys-announcement-content">' +
                '<strong><span class="dashicons dashicons-warning"></span> ' +
                this.escHtml(i18n.hubConnectionIssue || 'Hub 連線異常') + '</strong>' +
                '<p>' + this.escHtml(message) + '</p>';

            // 如果是熔斷，說明自動恢復時間
            if (code === 'circuit_breaker') {
                html += '<p style="font-size:12px;opacity:0.8;">' +
                    this.escHtml(i18n.circuitBreakerNote || '系統將在 30 分鐘後自動重新嘗試連線。') +
                    '</p>';
            }

            html += '</div></div>';

            $wrap.prepend(html).show();
        },

        /**
         * 渲染外掛卡片
         */
        renderPlugins: function () {
            var self = this;
            var plugins = self.filterPlugins();
            var $grid = $('#ys-plugin-grid');

            $grid.empty();

            if (!plugins || plugins.length === 0) {
                $grid.html(
                    '<div class="ys-empty-state">' +
                    '<span class="dashicons dashicons-plugins-checked"></span>' +
                    '<p>' + self.escHtml(i18n.noPlugins) + '</p>' +
                    '</div>'
                );
                return;
            }

            $.each(plugins, function (i, plugin) {
                $grid.append(self.buildCard(plugin));
            });
        },

        /**
         * 篩選外掛
         */
        filterPlugins: function () {
            var self = this;
            var result = Array.isArray(self.pluginsData) ? self.pluginsData : [];

            if (self.currentPlatform !== 'all') {
                result = $.grep(result, function (p) {
                    return self.getPluginPlatform(p) === self.currentPlatform;
                });
            }

            // 分類篩選
            if (self.currentCategory !== 'all') {
                result = $.grep(result, function (p) {
                    return !!p && typeof p === 'object' && p.category === self.currentCategory;
                });
            }

            // 搜尋篩選
            if (self.searchKeyword) {
                var kw = self.searchKeyword;
                result = $.grep(result, function (p) {
                    if (!p || typeof p !== 'object') return false;
                    // `(p.name || '')` 只擋得住 falsy 值：非字串的真值會讓
                    // toLowerCase() 直接丟 TypeError，一列壞資料就毀掉整個搜尋。
                    var name = self.safeString(p.name).toLowerCase();
                    var desc = self.safeString(p.description).toLowerCase();
                    var slug = self.safeString(p.slug).toLowerCase();
                    return name.indexOf(kw) !== -1
                        || desc.indexOf(kw) !== -1
                        || slug.indexOf(kw) !== -1;
                });
            }

            return result;
        },

        /**
         * 外掛列表 ingress：非陣列、非物件列、無 slug 的列一律安全丟棄。
         *
         * 前端與 PHP 端各自正規化是刻意的縱深防禦：快取或第三方過濾器都可能讓
         * 未正規化的資料抵達瀏覽器。
         */
        normalizePlugins: function (plugins) {
            var self = this;
            var source = plugins;
            if (source && typeof source === 'object' && !Array.isArray(source) && Array.isArray(source.plugins)) {
                source = source.plugins;
            }
            if (!Array.isArray(source)) return [];

            return $.grep($.map(source, function (row) {
                if (!row || typeof row !== 'object' || Array.isArray(row)) return null;
                if (!self.safeString(row.slug)) return null;
                return row;
            }), function (row) {
                return row !== null;
            });
        },

        /** 分類／平台 ingress：只保留具字串 slug 的物件列。 */
        normalizeTaxonomy: function (rows) {
            var self = this;
            if (!Array.isArray(rows)) return [];
            return $.grep($.map(rows, function (row) {
                if (!row || typeof row !== 'object' || Array.isArray(row)) return null;
                var slug = self.safeString(row.slug);
                if (!slug) return null;
                return {slug: slug, name: self.safeString(row.name, slug), icon: self.safeClassToken(row.icon, '')};
            }), function (row) {
                return row !== null;
            });
        },

        /** 公告 ingress：只保留具可辨識 id 的物件列。 */
        normalizeAnnouncements: function (rows) {
            var self = this;
            if (!Array.isArray(rows)) return [];
            return $.grep($.map(rows, function (row) {
                if (!row || typeof row !== 'object' || Array.isArray(row)) return null;
                if (!self.announcementId(row)) return null;
                return row;
            }), function (row) {
                return row !== null;
            });
        },

        /**
         * 外部連結政策（封閉式）
         *
         * 只接受絕對 https: 連結；其餘（http:、相對路徑、其他 scheme、非字串、
         * 空值）一律拒絕並回傳空字串，呼叫端據此決定不渲染該連結。
         *
         * @param {*} raw Hub 提供的原始連結
         * @return {string} 通過政策的連結，否則空字串
         */
        safeExternalUrl: function (raw) {
            if (typeof raw !== 'string' || raw === '') {
                return '';
            }

            var parsed;
            try {
                parsed = new URL(raw);
            } catch (e) {
                return '';
            }

            return parsed.protocol === 'https:' ? parsed.href : '';
        },

        /**
         * dashicons class 只接受單一 token，避免 Hub 值污染 class 屬性
         *
         * @param {*}      raw      Hub 提供的圖示名稱
         * @param {string} fallback 不合法時採用的預設圖示
         * @return {string}
         */
        safeIconClass: function (raw, fallback) {
            return (typeof raw === 'string' && /^[A-Za-z0-9_-]+$/.test(raw)) ? raw : fallback;
        },

        /**
         * 建立節點描述
         *
         * 描述是純資料：文字放 `text`、屬性放 `attrs`，永遠不含 HTML 字串。
         * 因此遠端值不會經過任何字串拼接進入可執行內容。
         *
         * @param {string}                 tag      標籤名稱
         * @param {Object}                 attrs    屬性（原始值，渲染時以 setAttribute 套用）
         * @param {string|Array|undefined} children 文字內容或子節點描述陣列
         * @return {Object}
         */
        node: function (tag, attrs, children) {
            var descriptor = {tag: tag, attrs: attrs || {}};
            if (typeof children === 'string') {
                descriptor.text = children;
            } else {
                descriptor.children = children || [];
            }
            return descriptor;
        },

        /**
         * 建構外掛卡片的結構描述（不產生 HTML 字串）
         *
         * @param {Object} plugin Hub 提供的外掛資料
         * @return {Object} 卡片節點描述
         */
        describeCard: function (plugin) {
            var self = this;
            var slug = typeof plugin.slug === 'string' ? plugin.slug : '';
            var name = (typeof plugin.name === 'string' && plugin.name !== '') ? plugin.name : slug;
            var version = typeof plugin.version === 'string' ? plugin.version : '';
            var description = typeof plugin.description === 'string' ? plugin.description : '';
            var iconClass = self.safeIconClass(plugin.icon, 'dashicons-admin-plugins');
            var status = plugin.status || plugin.local_status || 'not_installed';
            var localVersion = typeof plugin.local_version === 'string' ? plugin.local_version : '';
            var priceType = plugin.price_type || 'free';
            var priceAmount = typeof plugin.price_amount === 'string' ? plugin.price_amount : '';
            var externalUrl = self.safeExternalUrl(plugin.external_url);
            var infoUrl = self.safeExternalUrl(plugin.info_url);
            var platformSlug = self.getPluginPlatform(plugin);
            var platformLabel = plugin.platform_label || self.findPlatformLabel(platformSlug);
            var categoryLabel = plugin.category_label || self.findCategoryLabel(plugin.category || '');

            // 價格徽章
            var priceBadge = priceType === 'paid'
                ? self.node('span', {'class': 'ys-price-badge-paid'}, String(priceAmount || '付費'))
                : self.node('span', {'class': 'ys-price-badge-free'}, String(i18n.free || '免費'));

            var badges = [];
            var actions = [];

            // 付費外掛不顯示安裝/更新按鈕，改顯示「查看外掛」
            if (priceType === 'paid') {
                badges.push(priceBadge);
                if (externalUrl) {
                    actions.push(self.node('button', {
                        'type': 'button',
                        'class': 'ys-btn ys-btn-external ys-btn-sm ys-external-btn',
                        'data-external-url': externalUrl
                    }, [
                        self.node('span', {
                            'class': 'dashicons dashicons-external',
                            'style': 'font-size:14px;width:14px;height:14px;'
                        }, []),
                        self.node('span', {'class': 'ys-btn-label'}, ' ' + String(i18n.viewPlugin || '查看外掛'))
                    ]));
                }
            } else if (status === 'active') {
                // 已啟用
                badges.push(priceBadge);
                badges.push(self.node('span', {'class': 'ys-badge ys-badge-active'}, String(i18n.active || '已啟用')));
                if (plugin.update_available) {
                    // 已啟用且有更新
                    actions.push(self.node('button', {
                        'type': 'button',
                        'class': 'ys-btn ys-btn-primary ys-btn-sm ys-update-btn',
                        'data-slug': slug,
                        'data-version': version
                    }, String(i18n.update || '更新') + ' v' + version));
                } else {
                    // 已啟用無更新 → 停用按鈕
                    actions.push(self.node('button', {
                        'type': 'button',
                        'class': 'ys-btn ys-btn-muted ys-btn-sm ys-deactivate-btn',
                        'data-slug': slug
                    }, String(i18n.deactivate || '停用')));
                }
            } else if (status === 'installed' && plugin.update_available) {
                // 已安裝但有更新（未啟用）
                badges.push(priceBadge);
                badges.push(self.node('span', {'class': 'ys-badge ys-badge-installed'}, String(i18n.installed || '已安裝')));
                actions.push(self.node('button', {
                    'type': 'button',
                    'class': 'ys-btn ys-btn-primary ys-btn-sm ys-update-btn',
                    'data-slug': slug,
                    'data-version': version
                }, String(i18n.update || '更新') + ' v' + version));
            } else if (status === 'installed') {
                // 已安裝未啟用 → 啟用 + 刪除按鈕
                badges.push(priceBadge);
                badges.push(self.node('span', {'class': 'ys-badge ys-badge-installed'}, String(i18n.installed || '已安裝')));
                actions.push(self.node('button', {
                    'type': 'button',
                    'class': 'ys-btn ys-btn-success ys-btn-sm ys-activate-btn',
                    'data-slug': slug
                }, String(i18n.activate || '啟用')));
                actions.push(self.node('button', {
                    'type': 'button',
                    'class': 'ys-btn ys-btn-danger-text ys-btn-sm ys-delete-btn',
                    'data-slug': slug
                }, String(i18n.deletePlugin || '刪除')));
            } else {
                // 未安裝
                badges.push(priceBadge);
                actions.push(self.node('button', {
                    'type': 'button',
                    'class': 'ys-btn ys-btn-outline ys-btn-sm ys-install-btn',
                    'data-slug': slug,
                    'data-version': version
                }, String(i18n.install || '安裝')));
            }

            var versionText = 'v' + (localVersion !== '' ? localVersion : version);
            var taxonomyBadges = [];
            if (platformLabel) {
                taxonomyBadges.push(
                    self.node('span', {'class': 'ys-plugin-taxonomy-badge ys-plugin-platform-badge'}, String(platformLabel))
                );
            }
            if (categoryLabel && (!platformLabel || String(categoryLabel).toLowerCase() !== String(platformLabel).toLowerCase())) {
                taxonomyBadges.push(
                    self.node('span', {'class': 'ys-plugin-taxonomy-badge ys-plugin-category-badge'}, String(categoryLabel))
                );
            }
            var versionNode = taxonomyBadges.length
                ? self.node('span', {'class': 'ys-plugin-version'}, [
                    self.node('span', {'class': 'ys-plugin-taxonomy-tags'}, taxonomyBadges),
                    self.node('span', {'class': 'ys-plugin-version-number'}, versionText)
                ])
                : self.node('span', {'class': 'ys-plugin-version'}, versionText);

            // 連結未通過封閉政策時，名稱只以純文字呈現，不輸出 href。
            var nameNode = infoUrl
                ? self.node('h3', {'class': 'ys-plugin-name'}, [
                    self.node('a', {'href': infoUrl, 'target': '_blank', 'rel': 'noopener noreferrer'}, name)
                ])
                : self.node('h3', {'class': 'ys-plugin-name'}, name);

            return self.node('div', {'class': 'ys-plugin-card', 'data-slug': slug}, [
                self.node('div', {'class': 'ys-plugin-card-header'}, [
                    self.node('div', {'class': 'ys-plugin-icon'}, [
                        self.node('span', {'class': 'dashicons ' + iconClass}, [])
                    ]),
                    self.node('div', {'class': 'ys-plugin-meta'}, [nameNode, versionNode])
                ]),
                self.node('div', {'class': 'ys-plugin-description'}, description),
                self.node('div', {'class': 'ys-plugin-card-footer'}, [
                    self.node('div', {'class': 'ys-card-footer-left'}, badges),
                    self.node('div', {'class': 'ys-card-footer-right'}, actions)
                ])
            ]);
        },

        /**
         * 以 DOM API 渲染節點描述
         *
         * 文字一律走 textContent、屬性一律走 setAttribute，且封閉地拒絕任何
         * 事件處理器屬性，因此描述內容不可能被當成腳本執行。
         *
         * @param {Object} descriptor 節點描述
         * @return {Element}
         */
        renderDescriptor: function (descriptor) {
            if (!descriptor || typeof descriptor !== 'object' || typeof descriptor.tag !== 'string') {
                throw new Error('Invalid marketplace card descriptor');
            }

            var element = document.createElement(descriptor.tag);
            var attrs = descriptor.attrs || {};

            for (var name in attrs) {
                if (!Object.prototype.hasOwnProperty.call(attrs, name)) {
                    continue;
                }
                if (/^on/i.test(name)) {
                    throw new Error('Event handler attributes are not renderable');
                }
                element.setAttribute(name, String(attrs[name]));
            }

            if (typeof descriptor.text === 'string') {
                element.textContent = descriptor.text;
                return element;
            }

            var children = descriptor.children || [];
            for (var i = 0; i < children.length; i++) {
                element.appendChild(Marketplace.renderDescriptor(children[i]));
            }

            return element;
        },

        /**
         * 建構外掛卡片節點
         *
         * @param {Object} plugin Hub 提供的外掛資料
         * @return {Element}
         */
        buildCard: function (plugin) {
            return Marketplace.renderDescriptor(Marketplace.describeCard(plugin));
        },

        /**
         * 以精確屬性值尋找卡片，避免把 slug 當成選擇器語法解析
         *
         * @param {string} slug 外掛 slug
         * @return {Object} jQuery 集合
         */
        findCardElement: function (slug) {
            return $('.ys-plugin-card').filter(function () {
                return this.getAttribute('data-slug') === slug;
            });
        },

        /**
         * 整張卡片重新渲染（安裝/更新/啟用後使用）
         */
        replaceCard: function (slug, pluginData) {
            var $oldCard = Marketplace.findCardElement(slug);
            if ($oldCard.length && pluginData) {
                $oldCard.replaceWith(Marketplace.buildCard(pluginData));
            }
        },

        /**
         * 安裝外掛
         */
        installPlugin: function (btn, slug, version) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + this.escHtml(i18n.installing || '安裝中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_install_plugin',
                    nonce: config.nonce,
                    slug: slug,
                    version: version
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || i18n.success, 'success');
                        // 整張卡片用回傳的 plugin data 重新渲染
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(origText);
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(origText);
                }
            });
        },

        /**
         * 更新外掛
         */
        updatePlugin: function (btn, slug, version) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + this.escHtml(i18n.updating));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_update_plugin',
                    nonce: config.nonce,
                    slug: slug,
                    version: version
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || i18n.success, 'success');
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(origText);
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(origText);
                }
            });
        },

        /**
         * 啟用外掛
         */
        activatePlugin: function (btn, slug) {
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + (i18n.activating || '啟用中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_activate_plugin',
                    nonce: config.nonce,
                    slug: slug
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || (i18n.activated || '已啟用'), 'success');
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(i18n.activate || '啟用');
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(i18n.activate || '啟用');
                }
            });
        },

        /**
         * 停用外掛
         */
        deactivatePlugin: function (btn, slug) {
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + (i18n.deactivating || '停用中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_deactivate_plugin',
                    nonce: config.nonce,
                    slug: slug
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || '已停用', 'success');
                        // 如果是最後一個 YS 外掛 → 跳轉到外掛頁面
                        if (response.data.is_last && response.data.redirect) {
                            Toast.show(i18n.lastPluginWarning || '最後一個 YS 外掛已停用，即將跳轉到外掛管理頁面...', 'warning');
                            setTimeout(function () {
                                window.location.href = response.data.redirect;
                            }, 1500);
                            return;
                        }
                        if (response.data.plugin) {
                            Marketplace.replaceCard(slug, response.data.plugin);
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(i18n.deactivate || '停用');
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(i18n.deactivate || '停用');
                }
            });
        },

        /**
         * 刪除外掛
         */
        deletePlugin: function (btn, slug) {
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + (i18n.deleting || '刪除中...'));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_delete_plugin',
                    nonce: config.nonce,
                    slug: slug
                },
                success: function (response) {
                    if (response.success) {
                        Toast.show(response.data.message || '已刪除', 'success');
                        // 如果沒有剩餘 YS 外掛 → 跳轉
                        if (response.data.redirect) {
                            setTimeout(function () {
                                window.location.href = response.data.redirect;
                            }, 1500);
                            return;
                        }
                        // 從市集重新載入（刪除後卡片回到「安裝」狀態）
                        // 更新本地資料中的狀態
                        for (var i = 0; i < Marketplace.pluginsData.length; i++) {
                            if (Marketplace.pluginsData[i].slug === slug) {
                                Marketplace.pluginsData[i].status = 'not_installed';
                                Marketplace.pluginsData[i].local_status = 'not_installed';
                                Marketplace.pluginsData[i].local_version = '';
                                Marketplace.pluginsData[i].update_available = false;
                                Marketplace.replaceCard(slug, Marketplace.pluginsData[i]);
                                break;
                            }
                        }
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                        btn.prop('disabled', false).text(i18n.deletePlugin || '刪除');
                    }
                },
                error: function () {
                    Toast.show(i18n.failed, 'error');
                    btn.prop('disabled', false).text(i18n.deletePlugin || '刪除');
                }
            });
        },

        /**
         * 強制刷新市集
         */
        refreshMarketplace: function (btn) {
            var self = this;
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner ys-spinner-dark"></span> ' + this.escHtml(i18n.refreshing));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_refresh_marketplace',
                    nonce: config.nonce
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success && response.data && response.data.plugins) {
                        // 與 loadPlugins 走同一條 ingress：兩條路徑不得對「合法回應」有不同定義。
                        self.pluginsData = self.normalizePlugins(response.data.plugins);
                        self.platformsData = self.normalizePlatforms(self.normalizeTaxonomy(response.data.platforms), self.pluginsData);
                        self.renderPlatformTabs();

                        // 更新分類
                        self.categoriesData = self.normalizeTaxonomy(response.data.categories);
                        self.renderCategoryTabs();

                        // 更新公告
                        self.announcementsData = self.normalizeAnnouncements(response.data.announcements);
                        self.renderAnnouncements();

                        self.renderPlugins();
                        Toast.show(response.data.message || i18n.success, 'success');
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : i18n.failed;
                        Toast.show(msg, 'error');
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.failed, 'error');
                }
            });
        },

        /**
         * 顯示 Skeleton loading
         */
        showSkeleton: function () {
            var $grid = $('#ys-plugin-grid');
            $grid.empty();
            for (var i = 0; i < 6; i++) {
                $grid.append(
                    '<div class="ys-skeleton-card">' +
                    '<div class="ys-skeleton-header">' +
                    '<div class="ys-skeleton ys-skeleton-icon"></div>' +
                    '<div style="flex:1">' +
                    '<div class="ys-skeleton ys-skeleton-title"></div>' +
                    '<div class="ys-skeleton ys-skeleton-text"></div>' +
                    '</div></div>' +
                    '<div class="ys-skeleton-body">' +
                    '<div class="ys-skeleton ys-skeleton-desc"></div>' +
                    '<div class="ys-skeleton ys-skeleton-desc"></div>' +
                    '<div class="ys-skeleton ys-skeleton-desc"></div>' +
                    '</div>' +
                    '<div class="ys-skeleton-footer">' +
                    '<div class="ys-skeleton ys-skeleton-btn"></div>' +
                    '<div class="ys-skeleton ys-skeleton-btn"></div>' +
                    '</div></div>'
                );
            }
        },

        /**
         * 顯示錯誤訊息
         */
        showError: function (message) {
            var $grid = $('#ys-plugin-grid');
            $grid.html(
                '<div class="ys-notice ys-notice-warning" style="grid-column: 1 / -1;">' +
                '<span class="dashicons dashicons-warning"></span>' +
                '<span>' + this.escHtml(message) + '</span>' +
                '</div>'
            );
        },

        /**
         * 渲染動態分類 tabs
         */
        renderCategoryTabs: function () {
            var self = this;
            var $tabs = $('#ys-filter-tabs');
            if (!$tabs.length) return;
            // 兩個 map 都必須無原型：普通 {} 對 '__proto__' 的寫入是靜默 no-op（分類
            // 從畫面上消失而無任何錯誤），對 'constructor' 的讀取會撞到繼承屬性。
            var visibleCategories = Object.create(null);
            var renderedCategories = Object.create(null);
            var categoryStillVisible = self.currentCategory === 'all';

            $.each(self.pluginsData, function (i, plugin) {
                if (self.currentPlatform !== 'all' && self.getPluginPlatform(plugin) !== self.currentPlatform) {
                    return;
                }
                var category = self.safeString(plugin && plugin.category);
                if (category) {
                    visibleCategories[category] = true;
                }
            });

            // 保留「全部」按鈕，移除其他動態 tab
            $tabs.find('.ys-filter-tab').filter(function () {
                return this.getAttribute('data-category') !== 'all';
            }).remove();

            $.each(self.categoriesData, function (i, cat) {
                if (!cat || typeof cat !== 'object') return;
                var slug = self.safeString(cat.slug);
                // null-prototype map：直接查找即 own-property 語意。
                if (!slug || !visibleCategories[slug]) return;
                // 重複宣告的分類只渲染第一筆（Hub 資料是外部輸入，不保證唯一）。
                if (renderedCategories[slug]) return;
                renderedCategories[slug] = true;
                if (slug === self.currentCategory) {
                    categoryStillVisible = true;
                }

                var children = [];
                var iconToken = self.safeClassToken(cat.icon, '');
                if (iconToken) {
                    children.push(self.node('span', {
                        'class': 'dashicons ' + iconToken,
                        'style': 'font-size:14px;width:14px;height:14px;margin-right:4px;'
                    }, []));
                }
                children.push(self.node('span', {'class': 'ys-filter-tab-label'}, self.safeString(cat.name, slug)));

                $tabs.append(self.renderDescriptor(self.node('button', {
                    'type': 'button',
                    'class': 'ys-filter-tab',
                    'data-category': slug
                }, children)));
            });

            // 重新標記 active 狀態
            if (!categoryStillVisible) {
                self.currentCategory = 'all';
            }

            $tabs.find('.ys-filter-tab').removeClass('active');
            self.attrFilter($tabs, '.ys-filter-tab', 'data-category', self.currentCategory).addClass('active');
        },

        /**
         * 渲染公告
         */
        renderAnnouncements: function () {
            var self = this;
            var $wrap = $('#ys-announcements');
            if (!$wrap.length) return;

            $wrap.empty();

            // 取得已關閉的公告 ID
            var dismissedIds = self.getDismissedAnnouncements();

            // 過濾掉已關閉的公告
            var visibleAnns = $.grep(self.announcementsData, function (ann) {
                return dismissedIds.indexOf(self.announcementId(ann)) === -1;
            });

            if (visibleAnns.length === 0) {
                $wrap.hide();
                return;
            }

            // 公告類型是封閉域。用普通物件查表時，'constructor' 這類鍵會取回繼承的
            // 函式而不是 undefined，`|| 預設值` 於是永遠不會生效，遠端值就決定了
            // 元素的 class。因此先把 type 收斂到允許集合，再對映固定常數。
            var ANNOUNCEMENT_TYPES = ['info', 'warning', 'success', 'danger'];
            var typeClasses = {
                info: 'ys-announcement-info',
                warning: 'ys-announcement-warning',
                success: 'ys-announcement-success',
                danger: 'ys-announcement-danger'
            };
            var typeIcons = {
                info: 'dashicons-info',
                warning: 'dashicons-warning',
                success: 'dashicons-yes-alt',
                danger: 'dashicons-dismiss'
            };

            $.each(visibleAnns, function (i, ann) {
                if (!ann || typeof ann !== 'object') return;
                var type = self.safeEnum(ann.type, ANNOUNCEMENT_TYPES, 'info');
                var classes = 'ys-announcement ' + typeClasses[type] + (ann.is_pinned ? ' ys-announcement-pinned' : '');

                var body = [self.node('div', {'class': 'ys-announcement-title'}, self.safeString(ann.title))];
                var content = self.safeString(ann.content);
                if (content) {
                    body.push(self.node('div', {'class': 'ys-announcement-content'}, content));
                }

                $wrap.append(self.renderDescriptor(self.node('div', {
                    'class': classes,
                    'data-id': self.announcementId(ann)
                }, [
                    self.node('span', {
                        'class': 'dashicons ' + typeIcons[type],
                        'style': 'flex-shrink:0;margin-top:1px;'
                    }, []),
                    self.node('div', {'class': 'ys-announcement-body'}, body),
                    self.node('span', {
                        'class': 'ys-announcement-close dashicons dashicons-no-alt',
                        'title': self.safeString(i18n.dismiss, '關閉')
                    }, [])
                ])));
            });

            $wrap.show();
        },

        /**
         * 公告識別字：只接受字串或數字，其餘視為無識別字。
         */
        announcementId: function (ann) {
            if (!ann || typeof ann !== 'object') return '';
            if (typeof ann.id === 'string') return ann.id;
            return typeof ann.id === 'number' && isFinite(ann.id) ? String(ann.id) : '';
        },

        /**
         * 取得已關閉的公告 ID 列表
         */
        getDismissedAnnouncements: function () {
            try {
                var stored = localStorage.getItem('ys_hub_dismissed_announcements');
                if (stored) {
                    var parsed = JSON.parse(stored);
                    // 儲存內容可能被使用者或其他腳本改寫；只接受字串陣列。
                    if (Array.isArray(parsed)) {
                        return $.grep(parsed, function (id) {
                            return typeof id === 'string';
                        });
                    }
                }
            } catch (e) {
                // ignore
            }
            return [];
        },

        /**
         * 關閉公告
         */
        dismissAnnouncement: function (id) {
            var dismissed = this.getDismissedAnnouncements();
            if (dismissed.indexOf(String(id)) === -1) {
                dismissed.push(String(id));
            }
            try {
                localStorage.setItem('ys_hub_dismissed_announcements', JSON.stringify(dismissed));
            } catch (e) {
                // ignore
            }
        },

        /**
         * HTML escape
         */
        escHtml: function (str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        /**
         * Attribute escape
         */
        escAttr: function (str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
    };

    /**
     * 設定模組
     */
    var Settings = {

        /**
         * 儲存設定
         */
        save: function (btn) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner"></span> ' + Marketplace.escHtml(i18n.loading));

            var siteKey = $('#ys-site-key').val();
            var autoCheck = $('#ys-auto-check').is(':checked') ? 'yes' : 'no';

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_save_settings',
                    nonce: config.nonce,
                    site_key: siteKey,
                    auto_check: autoCheck
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success) {
                        Toast.show(i18n.saved, 'success');
                    } else {
                        Toast.show(response.data.message || i18n.failed, 'error');
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.failed, 'error');
                }
            });
        },

        /**
         * 測試連線
         */
        testConnection: function (btn) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner ys-spinner-dark"></span> ' + Marketplace.escHtml(i18n.testingConn));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_test_connection',
                    nonce: config.nonce
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success) {
                        Toast.show(i18n.connSuccess, 'success');
                        if (response.data && response.data.state) {
                            Settings.updateStatusDisplay(response.data.state, response.data.label);
                        }
                    } else {
                        Toast.show((response.data && response.data.message) || i18n.connFailed, 'error');
                        if (response.data && response.data.state) {
                            Settings.updateStatusDisplay(response.data.state, response.data.label);
                        }
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.connFailed, 'error');
                }
            });
        },

        /**
         * 產生 Site Key
         */
        generateSiteKey: function (btn) {
            var origText = btn.text();
            btn.prop('disabled', true).html('<span class="ys-spinner ys-spinner-dark"></span> ' + Marketplace.escHtml(i18n.generating));

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ys_hub_client_generate_site_key',
                    nonce: config.nonce
                },
                success: function (response) {
                    btn.prop('disabled', false).text(origText);
                    if (response.success && response.data && response.data.site_key) {
                        $('#ys-site-key').val(response.data.site_key);
                        Toast.show(response.data.message || i18n.success, 'success');
                    } else {
                        Toast.show((response.data && response.data.message) || i18n.failed, 'error');
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text(origText);
                    Toast.show(i18n.failed, 'error');
                }
            });
        },

        /**
         * 更新連線狀態顯示
         */
        updateStatusDisplay: function (state, label) {
            // 封閉集合；未知狀態退到最保守的 'open'（降級中），不產生未知 token。
            // HTML escaping 不是 class token 的正確約束：這裡沒有任何 HTML 解析，
            // 逃逸後的字元參照會原樣變成 class 名稱。
            var STATES = ['closed', 'open', 'half_open'];
            var safeState = Marketplace.safeEnum(state, STATES, 'open');
            var $status = $('#ys-connection-status');
            $status.removeClass($.map(STATES, function (s) { return 'ys-status-' + s; }).join(' '))
                .addClass('ys-status-' + safeState);
            $status.find('.ys-status-label').text(Marketplace.safeString(label));
        }
    };

    /**
     * Toast 提示模組
     */
    var Toast = {
        timer: null,

        show: function (message, type) {
            var $existing = $('.ys-toast');
            if ($existing.length) {
                $existing.remove();
            }

            var safeType = Marketplace.safeEnum(type, ['info', 'success', 'error', 'warning'], 'info');
            var $toast = $(Marketplace.renderDescriptor(Marketplace.node('div', {
                'class': 'ys-toast ys-toast-' + safeType
            }, Marketplace.safeString(message))));

            $('body').append($toast);

            // 觸發動畫
            setTimeout(function () {
                $toast.addClass('show');
            }, 10);

            // 自動隱藏
            if (Toast.timer) {
                clearTimeout(Toast.timer);
            }
            Toast.timer = setTimeout(function () {
                $toast.removeClass('show');
                setTimeout(function () {
                    $toast.remove();
                }, 300);
            }, 3000);
        }
    };

    // DOM Ready
    $(function () {
        if ($('#ys-plugin-grid').length) {
            Marketplace.init();
        }
    });

    // 測試接縫：瀏覽器沒有 CommonJS `module`，此區塊在正式環境不會執行。
    // 匯出的是「活的」單例本身而非包裝函式，離線契約因此驅動的是瀏覽器所驅動的
    // 同一組物件，不可能被一份重新實作的副本蒙混過去。
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            Marketplace: Marketplace,
            Settings: Settings,
            Toast: Toast
        };
    }

})(jQuery);
