(function (global) {
  'use strict';

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
    requestMapForm: postJson,
    submitForm: submitForm
  };
})(window);

