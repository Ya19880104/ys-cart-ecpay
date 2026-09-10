/**
 * YS CART × 綠界站內付 2.0 託管付款頁（v0.5.0）
 *
 * 依賴（由模板依序載入）：jQuery → node-forge → 綠界 JS SDK（sdk-1.0.0.js）→ 本檔。
 * SDK 把卡號欄位渲染到固定 id `ECPayPayment` 的 div（官方：請勿更動 id）。
 *
 * 兩種流程（由後端 token 端點決定）：
 *   bind：ECPay.addBindingCard(token, lang, cb) → ECPay.getBindCardPayToken(cb) → POST confirm {bind_card_pay_token}
 *   pay ：ECPay.createPayment(token, lang, cb, 'V2') → ECPay.getPayToken(cb) → POST confirm {pay_token}
 *
 * 🔴 回呼第一個參數是物件，要取 .BindCardPayToken／.PayToken；errMsg 要用 `!= null` 判斷
 *（空字串也是錯誤）。3D 驗證用 window.location 整頁導轉，不可 iframe。
 */
(function () {
	'use strict';

	var cfg = window.ysEcpayEcpg || null;
	if (!cfg) {
		return;
	}

	var $ = window.jQuery;
	var statusEl = document.getElementById('ys-ecpg-status');
	var payButton = document.getElementById('ys-ecpg-pay');
	var newCardPanel = document.getElementById('ys-ecpg-new-card');
	var chooseNew = document.getElementById('ys-ecpg-choose-new');
	var sdkReady = false;
	var busy = false;
	var flow = cfg.flow;

	function setStatus(text, kind) {
		if (!statusEl) {
			return;
		}
		statusEl.textContent = text || '';
		statusEl.className = 'ys-ecpg-status' + (kind ? ' ys-ecpg-status--' + kind : '');
	}

	function setBusy(on) {
		busy = on;
		if (payButton) {
			payButton.disabled = on || !sdkReady;
		}
		var savedButtons = document.querySelectorAll('.ys-ecpg-saved-pay');
		for (var i = 0; i < savedButtons.length; i++) {
			savedButtons[i].disabled = on;
		}
	}

	function postJson(url, body) {
		var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce; // 登入顧客：WP REST cookie 驗證需要 nonce，否則一律視為訪客
		}
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.json().then(function (json) {
				return json || {};
			}, function () {
				return { ok: false, status: 'network', message: cfg.i18n.network };
			});
		}, function () {
			return { ok: false, status: 'network', message: cfg.i18n.network };
		});
	}

	function handleAuthorization(result) {
		if (result.status === 'redirect' && result.url) {
			setStatus(cfg.i18n.redirecting, 'info');
			window.location.href = result.url; // 3D 驗證：整頁導轉
			return;
		}
		if (result.status === 'paid') {
			setStatus(cfg.i18n.paid, 'ok');
			window.location.href = result.redirect;
			return;
		}
		if (result.status === 'pending') {
			setStatus(result.message || cfg.i18n.pending, 'info');
			if (result.redirect) {
				window.setTimeout(function () { window.location.href = result.redirect; }, 4000);
			}
			return;
		}
		setBusy(false);
		setStatus(result.message || cfg.i18n.network, 'error');
		if (result.status === 'failed' && result.repay) {
			var link = document.getElementById('ys-ecpg-repay');
			if (link) {
				link.href = result.repay;
				link.hidden = false;
			}
			if (payButton) {
				payButton.hidden = true;
			}
		}
	}

	function submitNewCard() {
		if (busy || !sdkReady || !window.ECPay) {
			return;
		}
		setBusy(true);
		setStatus(cfg.i18n.submitting, 'info');
		try {
			if (flow === 'bind') {
				window.ECPay.getBindCardPayToken(function (info, errMsg) {
					if (errMsg != null || !info || typeof info.BindCardPayToken !== 'string' || info.BindCardPayToken === '') {
						setBusy(false);
						setStatus(errMsg || cfg.i18n.token_failed, 'error');
						return;
					}
					postJson(cfg.confirmUrl, { order: cfg.order, key: cfg.key, bind_card_pay_token: info.BindCardPayToken }).then(handleAuthorization);
				});
			} else {
				window.ECPay.getPayToken(function (info, errMsg) {
					if (errMsg != null || !info || typeof info.PayToken !== 'string' || info.PayToken === '') {
						setBusy(false);
						setStatus(errMsg || cfg.i18n.token_failed, 'error');
						return;
					}
					postJson(cfg.confirmUrl, { order: cfg.order, key: cfg.key, pay_token: info.PayToken }).then(handleAuthorization);
				});
			}
		} catch (err) {
			setBusy(false);
			setStatus(String(err && err.message ? err.message : err), 'error');
		}
	}

	function mountSdk(token) {
		if (!window.ECPay) {
			setStatus(cfg.i18n.sdk_failed, 'error');
			return;
		}
		var lang = (window.ECPay.Language && window.ECPay.Language.zhTW) ? window.ECPay.Language.zhTW : 'zh-TW';
		// createPayment／addBindingCard 必須在 initialize 的 callback 內呼叫（官方範例），否則競態。
		window.ECPay.initialize(cfg.env, 1, function (errMsg) {
			if (errMsg != null) {
				setStatus(cfg.i18n.sdk_failed + ' ' + errMsg, 'error');
				return;
			}
			var done = function (err) {
				if (err != null) {
					setStatus(cfg.i18n.sdk_failed + ' ' + err, 'error');
					return;
				}
				sdkReady = true;
				setBusy(false);
				setStatus('', '');
			};
			try {
				if (flow === 'bind') {
					window.ECPay.addBindingCard(token, lang, done);
				} else {
					window.ECPay.createPayment(token, lang, done, 'V2');
				}
			} catch (err) {
				setStatus(cfg.i18n.sdk_failed + ' ' + String(err && err.message ? err.message : err), 'error');
			}
		});
	}

	function startNewCard() {
		if (newCardPanel) {
			newCardPanel.hidden = false;
		}
		if (chooseNew) {
			chooseNew.hidden = true;
		}
		setStatus(cfg.i18n.loading, 'info');
		postJson(cfg.tokenUrl, { order: cfg.order, key: cfg.key }).then(function (result) {
			if (!result.ok || !result.token) {
				setStatus(result.message || cfg.i18n.token_failed, 'error');
				return;
			}
			flow = result.flow || flow;
			mountSdk(result.token);
		});
	}

	function bindSavedCards() {
		var buttons = document.querySelectorAll('.ys-ecpg-saved-pay');
		for (var i = 0; i < buttons.length; i++) {
			buttons[i].addEventListener('click', function (event) {
				if (busy) {
					return;
				}
				var cardId = parseInt(event.currentTarget.getAttribute('data-card-id'), 10);
				if (!cardId) {
					return;
				}
				setBusy(true);
				setStatus(cfg.i18n.submitting, 'info');
				postJson(cfg.savedUrl, { order: cfg.order, key: cfg.key, card_id: cardId }).then(handleAuthorization);
			});
		}
	}

	if (payButton) {
		payButton.disabled = true;
		payButton.addEventListener('click', function (event) {
			event.preventDefault();
			submitNewCard();
		});
	}
	if (chooseNew) {
		chooseNew.addEventListener('click', function (event) {
			event.preventDefault();
			startNewCard();
		});
	}
	bindSavedCards();

	// 沒有已綁定卡片＝直接進新卡流程；有的話先讓顧客選。
	if (!cfg.hasSaved) {
		startNewCard();
	}
})();
