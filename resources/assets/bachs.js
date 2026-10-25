(function () {
  'use strict';
  if (window.__bachsWhmcsLoaded) return;
  window.__bachsWhmcsLoaded = true;
  var sdkPromise, sdkInitialized = false, activeCheckout = null;
  function sdk() {
    if (sdkPromise) return sdkPromise;
    sdkPromise = new Promise(function (resolve, reject) {
      if (window.Bachs) { resolve(window.Bachs); return; }
      var script = document.createElement('script');
      script.src = 'https://checkout.bachs.io/bachs.js'; script.async = true;
      script.onload = function () { window.Bachs ? resolve(window.Bachs) : reject(new Error('sdk')); };
      script.onerror = function () { reject(new Error('sdk')); };
      document.head.appendChild(script);
      setTimeout(function () { reject(new Error('timeout')); }, 8000);
    });
    return sdkPromise;
  }
  function announce(root, message) {
    var status = root.querySelector('[data-bachs-status]');
    if (status && status.textContent !== message) status.textContent = message;
  }
  function safeCheckout(value) {
    try { var u = new URL(value); return u.protocol === 'https:' && u.hostname === 'checkout.bachs.io' && !u.port && !u.username && !u.password && !u.hash ? u.href : null; } catch (_) { return null; }
  }
  function poll(root, id) {
    var count = 0;
    function check() {
      if (count++ >= 12) { announce(root, 'Your payment is still being confirmed. You can return to this invoice later.'); root.removeAttribute('aria-busy'); return; }
      var url = new URL(root.getAttribute('data-status-url'), window.location.href); url.searchParams.set('attempt_id', id);
      fetch(url.href, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'})
        .then(function (r) { if (!r.ok) throw new Error('status'); return r.json(); })
        .then(function (data) {
          announce(root, data.message);
          if (data.state === 'posted' || data.state === 'setup_completed' || data.invoice_paid) { root.removeAttribute('aria-busy'); return; }
          if (['review','failed','expired_verified','cancelled_verified'].indexOf(data.state) >= 0) { root.removeAttribute('aria-busy'); return; }
          setTimeout(check, Math.min(15000, 1500 * Math.pow(1.5, count)));
        })
        .catch(function () { announce(root, 'We could not refresh payment status. Return to your invoice to recheck.'); root.removeAttribute('aria-busy'); });
    }
    check();
  }
  document.querySelectorAll('[data-bachs-payment]').forEach(function (root) {
    var form = root.querySelector('[data-bachs-form]'); var button = form.querySelector('button');
    form.addEventListener('submit', function (event) {
      if (!window.fetch || !window.FormData || !window.Promise) return;
      event.preventDefault(); if (button.disabled) return;
      button.disabled = true; root.setAttribute('aria-busy', 'true'); announce(root, 'Preparing secure checkout…');
      var abort = window.AbortController ? new AbortController() : null;
      var timeout = setTimeout(function () { if (abort) abort.abort(); }, 30000);
      fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{'Accept':'application/json'}, signal: abort ? abort.signal : undefined})
        .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Checkout could not be started.'); return data; }); })
        .then(function (data) {
          var url = safeCheckout(data.checkout_url); if (!url) throw new Error('Secure checkout could not be opened. Return to your invoice to recheck.');
          var fallback = root.querySelector('[data-bachs-fallback]'); fallback.href = url; fallback.hidden = false;
          announce(root, 'Opening Bachs…');
          if (data.mode !== 'overlay') { window.location.assign(url); return; }
          sdk().then(function (Bachs) {
            activeCheckout = {root:root, attempt:data.attempt_id, button:button};
            if (!sdkInitialized) {
              Bachs.Initialize({onEvent:function (event) {
                var current = activeCheckout; if (!current) return;
                if (event.type === 'checkout.completed') { announce(current.root, 'Confirming your payment…'); poll(current.root, current.attempt); }
                if (event.type === 'checkout.closed') { announce(current.root, 'Checkout closed. You can continue when ready.'); current.root.removeAttribute('aria-busy'); current.button.disabled = false; current.button.focus(); poll(current.root, current.attempt); }
                if (event.type === 'checkout.error') { announce(current.root, 'Use the secure checkout link below to continue.'); current.root.removeAttribute('aria-busy'); }
                if (event.type === 'checkout.failed' || event.type === 'checkout.expired') { announce(current.root, 'Rechecking your payment status…'); poll(current.root, current.attempt); }
              }}); sdkInitialized = true;
            }
            Bachs.Checkout.open({checkoutUrl:url}); announce(root, 'Complete your payment in Bachs.'); root.removeAttribute('aria-busy');
          }).catch(function () { announce(root, 'Continue using the secure checkout link below.'); root.removeAttribute('aria-busy'); fallback.focus(); });
        })
        .catch(function (error) {
          announce(root, error.name === 'AbortError' ? 'Checkout is taking longer than expected. Recheck this invoice before trying again.' : error.message);
          root.removeAttribute('aria-busy'); button.disabled = false;
        }).finally(function () { clearTimeout(timeout); });
    });
  });
  document.querySelectorAll('[data-bachs-return]').forEach(function (root) { poll(root, root.getAttribute('data-attempt')); });
})();
