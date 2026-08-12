(function (global) {
  'use strict';

  var ROUTES = {
    storeMapUrl: '/wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url',
    storeCallback: '/wp-json/ys-ecommerce/v1/ecpay/store-callback'
  };

  /** 核心既有的訪客身分 header（headless 前端可能在另一個 origin，沒有 cookie）。 */
  var GUEST_TOKEN_HEADER = 'X-YS-Guest-Token';

  var guestToken = '';

  /**
   * 設定訪客身分。
   *
   * 🔴 headless 一定要設。門市選擇憑證是**綁擁有者**的：伺服器在開地圖那一刻
   * 就把身分記下來，結帳時比對。前端在另一個 origin 時我方 cookie 送不出去，
   * 身分只能靠這個 header——沒有它，伺服器算不出身分，地圖根本不會開。
   *
   * @param {string} token 核心發的 guest cart token
   */
  function setGuestToken(token) {
    guestToken = typeof token === 'string' ? token : '';
  }

  function postJson(url, payload) {
    var headers = { 'Content-Type': 'application/json' };
    if (guestToken) {
      headers[GUEST_TOKEN_HEADER] = guestToken;
    }

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      return res.json();
    });
  }

  /**
   * 取得電子地圖表單。
   *
   * 🔴 `paymentMethod` 是**必填**：綠界的電子地圖會依「要不要代收貨款」篩出
   * 不同的門市清單，因此選店當下就必須知道顧客選的付款方式。伺服器分不出
   * 「沒送這個欄位」與「顧客還沒選」，缺欄位一律拒絕（缺欄位＝無法證明，
   * 不是預設不代收）。
   *
   * 🔴 因此顧客**還沒選付款方式**時不要呼叫這一支，也不要送空字串——那只是把
   * 一次必然失敗的往返推給伺服器，而使用者看到的是一個沒頭沒尾的錯誤。
   * 正確的做法是先讓他選付款方式，選了才讓「選擇門市」可以按。
   *
   * @param {string} apiBase       站台 API 基底 URL
   * @param {string} shippingId    物流方式 ID
   * @param {string} paymentMethod 顧客當下選定的付款方式（必填、不得為空）
   * @param {object} [options]     其他選項：cart_scope、return_url
   * @returns {Promise} 缺付款方式時直接 reject，不發出請求
   */
  function requestStoreMapForm(apiBase, shippingId, paymentMethod, options) {
    if (typeof paymentMethod !== 'string' || paymentMethod === '') {
      return Promise.reject(new Error(
        'requestStoreMapForm() 需要付款方式：綠界的電子地圖依「要不要代收」篩門市，' +
        '請先讓顧客選好付款方式再開地圖。'
      ));
    }

    var payload = {
      shipping_id: shippingId,
      payment_method: paymentMethod
    };

    if (options && options.cart_scope) {
      payload.cart_scope = options.cart_scope;
    }
    if (options && options.return_url) {
      payload.return_url = options.return_url;
    }

    return postJson(apiBase.replace(/\/$/, '') + ROUTES.storeMapUrl, payload);
  }

  function submitForm(actionUrl, fields, target) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = actionUrl;
    form.target = target || '_self';
    Object.keys(fields || {}).forEach(function (key) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = fields[key];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  /**
   * 從選店結果取出**門市選擇憑證**，供結帳送出時一併帶上。
   *
   * 🔴 結帳時必須以 `ecpay_store_token` 這個欄位把它送回伺服器。門市代號本身
   * 是可竄改的字串；伺服器認的是這張憑證——它把擁有者、購物車 scope、物流方式、
   * subtype、門市與代收前提全部綁在伺服器那一側，而且**只能用一次**。
   *
   * 顧客在選完門市之後改了付款方式，憑證與新前提對不上，結帳會被拒絕並要求重選。
   *
   * @param {object} selection store-callback 回傳的門市資料
   * @returns {string} 憑證；取不到時回空字串（伺服器會要求重新選店）
   */
  function selectionToken(selection) {
    return (selection && selection.selection_token) || '';
  }

  global.YsCartEcpay = {
    routes: ROUTES,
    /** 結帳送出時攜帶憑證的欄位名稱。 */
    selectionTokenField: 'ecpay_store_token',
    /** 訪客身分 header 名稱（與核心一致）。 */
    guestTokenHeader: GUEST_TOKEN_HEADER,
    setGuestToken: setGuestToken,
    requestStoreMapForm: requestStoreMapForm,
    requestMapForm: postJson,
    selectionToken: selectionToken,
    submitForm: submitForm
  };
})(window);
