// DOM-behavior unit tests. Browser/theme/layout acceptance remains separate.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../../modules/gateways/bachs/assets/bachs.js', import.meta.url), 'utf8');
let passed = 0, failed = 0;
const flush = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
function fixture({url = 'https://checkout.bachs.io/c/synthetic', sdk = false, fetchEnabled = true, delay = false,
  returned = false, httpError = false, jsonError = false, networkError = false, abortSupported = true,
  malformedRoot = false, statusDelay = false, label = 'Pay with Bachs'} = {}) {
  const status = {textContent: ''}, container = {hidden: true}, classes = new Set();
  const fallback = {hidden: true, focus() { this.focused = true; }, removeAttribute(key) { delete this[key]; }};
  const normalHTML = '<span data-bachs-label>' + label + '</span>';
  const button = {innerHTML: normalHTML, disabled: false, classList: {add(k) { classes.add(k); }, remove(k) { classes.delete(k); }}, focus() { this.focused = true; }};
  const attrs = {}, listeners = {}, timers = new Map();
  const form = {hidden: false, action: 'https://billing.example.invalid/modules/gateways/bachs/checkout.php',
    querySelector() { return button; }, addEventListener(type, cb) { this.submit = cb; }};
  const root = {querySelector(selector) { return selector === '[data-bachs-status]' ? status : selector === '[data-bachs-fallback]' ? fallback : selector === '[data-bachs-fallback-container]' ? container : form; },
    setAttribute(k, v) { attrs[k] = v; }, removeAttribute(k) { delete attrs[k]; },
    getAttribute(name) { return name === 'data-attempt' ? 'synthetic' : 'https://billing.example.invalid/modules/gateways/bachs/status.php'; }};
  let script, calls = [], resolveFetch, initialized = 0, onEvent, timerId = 0;
  const statusRequests = [];
  const result = {attempt_id: 'synthetic', mode: 'overlay', checkout_url: url};
  const window = {location: {href: 'https://billing.example.invalid/viewinvoice.php?id=42', assign(u) { this.assigned = u; }},
    FormData: class {}, Promise, URL, addEventListener(type, callback) { listeners[type] = callback; }};
  if (fetchEnabled) window.fetch = true;
  if (sdk) window.Bachs = {Initialize(options) { initialized++; onEvent = options.onEvent; }, Checkout: {open(options) { this.url = options.checkoutUrl; }}};
  const response = () => ({ok: !httpError, json: async () => {
    if (jsonError) throw new SyntaxError('Unexpected token <, private HTML');
    return httpError ? {message: 'Bachs is temporarily unavailable.'} : result;
  }});
  const fetch = (path, opts) => {
    calls.push([path, opts]);
    if (opts.method !== 'POST') {
      if (statusDelay) return new Promise(resolve => statusRequests.push(data => resolve({ok: true, json: async () => data})));
      return Promise.resolve({ok: true, json: async () => ({state: 'pending', message: 'Confirming your payment…', invoice_paid: false})});
    }
    if (networkError) return Promise.reject(new TypeError('Failed to fetch'));
    if (delay && calls.filter(c => c[1].method === 'POST').length === 1) return new Promise(resolve => { resolveFetch = () => resolve(response()); });
    return Promise.resolve(response());
  };
  const ctx = {window, document: {querySelectorAll(s) { return s === '[data-bachs-payment]' ? (malformedRoot ? [{querySelector() { return null; }}, root] : [root]) : s === '[data-bachs-return]' && returned ? [root] : []; },
    createElement() { return script = {}; }, head: {appendChild() {}}}, fetch, URL, Promise, FormData: class {},
    setTimeout(callback, ms) { const id = ++timerId; timers.set(id, {callback, ms}); return id; }, clearTimeout(id) { timers.delete(id); }};
  if (abortSupported) window.AbortController = ctx.AbortController = class { constructor() { this.signal = {}; } abort() {} };
  vm.runInNewContext(source, ctx);
  return {form, root, status, fallback, container, button, classes, normalHTML, calls, attrs, result,
    get script() { return script; }, get initialized() { return initialized; }, get onEvent() { return onEvent; },
    resolve() { resolveFetch(); }, resolveStatus(index, data) { statusRequests[index](data); }, window,
    timer(ms) { const entry = [...timers].find(([, value]) => value.ms === ms); assert.ok(entry, 'Expected timer ' + ms); timers.delete(entry[0]); entry[1].callback(); },
    pageshow() { listeners.pageshow({persisted: true}); }};
}
function submit(f) { f.form.submit({preventDefault() {}, stopPropagation() {}}); }
function restored(f) { assert.equal(f.button.innerHTML, f.normalHTML); assert.equal(f.button.disabled, false); assert.equal(f.classes.has('disabled'), false); assert.equal(f.attrs['aria-busy'], undefined); }
async function test(name, fn) { try { await fn(); passed++; console.log('PASS ' + name); } catch (error) { failed++; console.error('FAIL ' + name + ': ' + error.message); } }

await test('Double-click is blocked while one checkout request is pending', async () => { const f = fixture({delay: true}); submit(f); submit(f); await flush(); assert.equal(f.calls.length, 1); assert.equal(f.button.disabled, true); f.resolve(); await flush(); });
await test('Blocked SDK preserves the original session as a redirect fallback', async () => { const f = fixture(); submit(f); await flush(); assert.equal(f.container.hidden, false); assert.equal(f.fallback.href, 'https://checkout.bachs.io/c/synthetic'); f.script.onerror(); await flush(); assert.equal(f.fallback.focused, true); assert.match(f.status.textContent, /secure checkout link/); assert.equal(f.calls.filter(c => c[1].method === 'POST').length, 1); restored(f); });
await test('Browser completion stays pending until the server confirms recording', async () => { const f = fixture({sdk: true}); submit(f); await flush(); f.onEvent({type: 'checkout.completed'}); await flush(); assert.equal(f.status.textContent, 'Confirming your payment…'); assert.equal(f.calls.length, 2); assert.equal(f.window.location.assigned, undefined); });
await test('Overlay initializes once and restores keyboard focus and label on close', async () => { const f = fixture({sdk: true}); submit(f); await flush(); f.button.innerHTML = ''; f.classes.add('disabled'); f.onEvent({type: 'checkout.closed'}); await flush(); assert.equal(f.button.focused, true); restored(f); submit(f); await flush(); assert.equal(f.initialized, 1); });
await test('Unsafe checkout origin cannot be exposed or opened', async () => { const f = fixture({url: 'https://checkout.bachs.io.evil.invalid/c/token'}); submit(f); await flush(); assert.equal(f.container.hidden, true); assert.match(f.status.textContent, /could not be opened/); restored(f); });
await test('Without fetch the native POST form remains the payment path', async () => { const f = fixture({fetchEnabled: false}); let prevented = false; f.form.submit({preventDefault() { prevented = true; }}); assert.equal(prevented, false); assert.equal(f.calls.length, 0); });
await test('Return status remains readable when fetch is unavailable', async () => { const f = fixture({fetchEnabled: false, returned: true}); await flush(); assert.equal(f.calls.length, 0); });
await test('Server error restores a theme-replaced custom button label', async () => { const f = fixture({delay: true, httpError: true, label: 'Pay this invoice'}); submit(f); await flush(); f.button.innerHTML = '<i class="spinner"></i>'; f.classes.add('disabled'); f.resolve(); await flush(); restored(f); assert.equal(f.status.textContent, 'Bachs is temporarily unavailable.'); });
await test('Theme-disabled buttons still submit once through the module handler', async () => { const f = fixture({httpError: true}); f.button.disabled = true; f.classes.add('disabled'); submit(f); await flush(); assert.equal(f.calls.length, 1); restored(f); });
await test('Network and non-JSON errors show a useful message and restore the button', async () => { for (const options of [{networkError: true}, {jsonError: true}]) { const f = fixture(options); submit(f); await flush(); restored(f); assert.match(f.status.textContent, /recheck|try again/i); assert.doesNotMatch(f.status.textContent, /Unexpected token|Failed to fetch|private HTML/); } });
await test('Checkout timeout restores the button even without AbortController', async () => { const f = fixture({delay: true, abortSupported: false}); submit(f); await flush(); f.timer(30000); await flush(); restored(f); assert.match(f.status.textContent, /taking longer/); f.resolve(); await flush(); assert.equal(f.container.hidden, true); });
await test('Overlay errors restore the button and focus the existing checkout link', async () => { const f = fixture({sdk: true}); submit(f); await flush(); f.onEvent({type: 'checkout.error'}); restored(f); assert.equal(f.fallback.focused, true); assert.equal(f.container.hidden, false); });
await test('A retry hides and clears the previous checkout link while it starts', async () => { const f = fixture({sdk: true}); submit(f); await flush(); f.onEvent({type: 'checkout.closed'}); await flush(); submit(f); assert.equal(f.container.hidden, true); assert.equal(f.fallback.href, undefined); await flush(); });
await test('One malformed payment container does not break other payment controls', async () => { const f = fixture({malformedRoot: true, httpError: true}); submit(f); await flush(); restored(f); assert.equal(f.calls.length, 1); });
await test('Only a current server-confirmed receipt hides the payment form', async () => { const f = fixture({sdk: true, statusDelay: true}); submit(f); await flush(); f.onEvent({type: 'checkout.completed'}); await flush(); f.onEvent({type: 'checkout.closed'}); await flush(); f.resolveStatus(1, {state: 'posted', message: 'Payment received.', invoice_paid: true}); await flush(); assert.equal(f.form.hidden, true); assert.equal(f.container.hidden, true); f.resolveStatus(0, {state: 'pending', message: 'Old pending response', invoice_paid: false}); await flush(); assert.equal(f.status.textContent, 'Payment received.'); });
await test('Browser Back restores checkout controls without creating a new request', async () => { const f = fixture({sdk: true}); f.result.mode = 'redirect'; submit(f); await flush(); assert.equal(f.button.disabled, true); f.button.innerHTML = ''; f.pageshow(); restored(f); assert.equal(f.calls.length, 1); });
console.log(`${passed} client behavior unit tests passed, ${failed} failed. Browser rendering remains a separate gate.`);
if (failed) process.exitCode = 1;
