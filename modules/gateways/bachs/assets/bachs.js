(function () {
  'use strict';
  if (window.bachsWhmcsLoaded) return;
  window.bachsWhmcsLoaded = true;
  if (window.__bachsWhmcsLoaded) return;
  window.__bachsWhmcsLoaded = true;
  var sdkPromise, sdkInitialized = false, activeCheckout = null;
  function sdk() {
    if (sdkPromise) return sdkPromise;
    sdkPromise = new Promise(function (resolve, reject) {
      if (window.Bachs) { resolve(window.Bachs); return; }
      var script = document.createElement('script');
      var timeout = setTimeout(function () { reject(new Error('timeout')); }, 8000);
      script.src = 'https://checkout.bachs.io/bachs.js'; script.async = true;
      script.onload = function () { clearTimeout(timeout); window.Bachs ? resolve(window.Bachs) : reject(new Error('sdk')); };
      script.onerror = function () { clearTimeout(timeout); reject(new Error('sdk')); };
      document.head.appendChild(script);
    });
    return sdkPromise;
  }
  function announce(root, message) {
    var status = root.querySelector('[data-bachs-status]');
    if (status && typeof message === 'string' && status.textContent !== message) status.textContent = message;
  }
  function safeCheckout(value) {
    try { var u = new URL(value); return u.protocol === 'https:' && u.hostname === 'checkout.bachs.io' && !u.port && !u.username && !u.password && !u.hash ? u.href : null; } catch (_) { return null; }
  }
  function checkoutError(message) {
    var error = new Error(message); error.bachsMessage = true; return error;
  }
  function confirmed(data) {
    return data && (data.state === 'posted' || data.state === 'setup_completed' || data.invoice_paid === true);
  }
  function poll(root, id, done) {
    if (!window.fetch || !window.URL || !window.Promise) return;
    var count = 0, version = root.bachsPollVersion = (root.bachsPollVersion || 0) + 1;
    function current() { return version === root.bachsPollVersion; }
    function finish(data) {
      if (!current()) return;
      root.removeAttribute('aria-busy');
      if (done) done(data);
    }
    function check() {
      if (!current()) return;
      if (count++ >= 12) { announce(root, 'Your payment is still being confirmed. You can return to this invoice later.'); finish(); return; }
      Promise.resolve().then(function () {
        var url = new URL(root.getAttribute('data-status-url'), window.location.href); url.searchParams.set('attempt_id', id);
        return fetch(url.href, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
      }).then(function (r) { if (!r.ok) throw new Error('status'); return r.json(); })
        .then(function (data) {
          if (!current()) return;
          if (!data || typeof data.state !== 'string' || typeof data.message !== 'string') throw new Error('status');
          announce(root, data.message);
          if (confirmed(data) || ['review','failed','expired_verified','cancelled_verified'].indexOf(data.state) >= 0) { finish(data); return; }
          setTimeout(check, Math.min(15000, 1500 * Math.pow(1.5, count)));
        })
        .catch(function () {
          if (!current()) return;
          announce(root, 'We could not refresh payment status. Return to your invoice to recheck.'); finish();
        });
    }
    check();
  }
  document.querySelectorAll('[data-bachs-payment]').forEach(function (root) {
    var form = root.querySelector('[data-bachs-form]');
    if (!form) return;
    var button = form.querySelector('button[type="submit"]'), fallback = root.querySelector('[data-bachs-fallback]');
    if (!button || !fallback) return;
    var fallbackContainer = root.querySelector('[data-bachs-fallback-container]');
    var originalHTML = button.innerHTML, submitting = false, generation = 0, requestAbort, requestTimer;
    function hideFallback() {
      fallback.hidden = true; fallback.removeAttribute('href');
      if (fallbackContainer) fallbackContainer.hidden = true;
    }
    function restore(data) {
      submitting = false;
      if (activeCheckout && activeCheckout.root === root) activeCheckout = null;
      // Restore the server-rendered label even if the theme replaced it with a spinner.
      button.innerHTML = originalHTML; button.disabled = false; button.classList.remove('disabled');
      root.removeAttribute('aria-busy');
      if (confirmed(data)) { form.hidden = true; hideFallback(); }
    }
    form.addEventListener('submit', function (event) {
      if (!window.fetch || !window.FormData || !window.Promise) return;
      event.preventDefault(); event.stopPropagation();
      // A theme may disable the button before this handler. Use our own request state.
      if (submitting || form.hidden) return;
      submitting = true;
      var current = ++generation;
      if (activeCheckout && activeCheckout.root === root) activeCheckout = null;
      root.bachsPollVersion = (root.bachsPollVersion || 0) + 1;
      hideFallback();
      button.textContent = 'Preparing…'; button.disabled = true;
      root.setAttribute('aria-busy', 'true'); announce(root, 'Preparing secure checkout…');
      requestAbort = window.AbortController ? new AbortController() : null;
      var deadline = new Promise(function (resolve, reject) {
        requestTimer = setTimeout(function () {
          if (requestAbort) requestAbort.abort();
          var error = new Error('timeout'); error.name = 'AbortError'; reject(error);
        }, 30000);
      });
      var request = Promise.resolve().then(function () {
        return fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{'Accept':'application/json'}, signal: requestAbort ? requestAbort.signal : undefined});
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) throw checkoutError(data && typeof data.message === 'string' ? data.message : 'Checkout could not be started. Return to your invoice to recheck.');
          return data;
        });
      });
      Promise.race([request, deadline]).then(function (data) {
        if (current !== generation) return;
        clearTimeout(requestTimer);
        var url = data && safeCheckout(data.checkout_url);
        if (!url) throw checkoutError('Secure checkout could not be opened. Return to your invoice to recheck.');
        fallback.href = url; fallback.hidden = false;
        if (fallbackContainer) fallbackContainer.hidden = false;
        announce(root, 'Opening Bachs…');
        if (data.mode !== 'overlay') { window.location.assign(url); return; }
        return sdk().then(function (Bachs) {
          if (current !== generation) return;
          activeCheckout = {root:root, attempt:data.attempt_id, restore:restore, button:button, fallback:fallback};
          if (!sdkInitialized) {
            Bachs.Initialize({onEvent:function (event) {
              var active = activeCheckout; if (!active || !event) return;
              if (event.type === 'checkout.completed') { announce(active.root, 'Confirming your payment…'); active.root.setAttribute('aria-busy', 'true'); poll(active.root, active.attempt, active.restore); }
              if (event.type === 'checkout.closed') { active.restore(); announce(active.root, 'Checkout closed. You can continue when ready.'); active.button.focus(); poll(active.root, active.attempt, active.restore); }
              if (event.type === 'checkout.error') { active.restore(); announce(active.root, 'Use the secure checkout link below to continue.'); active.fallback.focus(); }
              if (event.type === 'checkout.failed' || event.type === 'checkout.expired') { announce(active.root, 'Rechecking your payment status…'); poll(active.root, active.attempt, active.restore); }
            }}); sdkInitialized = true;
          }
          Bachs.Checkout.open({checkoutUrl:url}); button.innerHTML = originalHTML;
          announce(root, 'Complete your payment in Bachs.'); root.removeAttribute('aria-busy');
        }).catch(function () {
          if (current !== generation) return;
          restore(); announce(root, 'Continue using the secure checkout link below.'); fallback.focus();
        });
      }).catch(function (error) {
        if (current !== generation) return;
        clearTimeout(requestTimer); restore();
        announce(root, error.name === 'AbortError' ? 'Checkout is taking longer than expected. Recheck this invoice before trying again.'
          : error.bachsMessage ? error.message : 'Checkout could not be started. Return to your invoice to recheck or try again later.');
      });
    });
    window.addEventListener('pageshow', function (event) {
      if (!event.persisted) return;
      ++generation; root.bachsPollVersion = (root.bachsPollVersion || 0) + 1;
      clearTimeout(requestTimer); if (requestAbort) requestAbort.abort();
      restore();
      if (!form.hidden) announce(root, 'Recheck this invoice before trying again.');
    });
  });
  document.querySelectorAll('[data-bachs-return]').forEach(function (root) { poll(root, root.getAttribute('data-attempt')); });
})();
