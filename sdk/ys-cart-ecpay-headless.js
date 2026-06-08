(function (global) {
  'use strict';

  var ROUTES = {
    storeMapUrl: '/wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url',
    storeCallback: '/wp-json/ys-ecommerce/v1/ecpay/store-callback'
  };

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      return res.json();
    });
  }

  function requestStoreMapForm(apiBase, shippingId) {
    return postJson(apiBase.replace(/\/$/, '') + ROUTES.storeMapUrl, {
      shipping_id: shippingId
    });
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

  global.YsCartEcpay = {
    routes: ROUTES,
    requestStoreMapForm: requestStoreMapForm,
    requestMapForm: postJson,
    submitForm: submitForm
  };
})(window);
