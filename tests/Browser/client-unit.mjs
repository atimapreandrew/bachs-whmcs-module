// DOM-behavior unit tests. These do not replace browser/theme/layout acceptance.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../../resources/assets/bachs.js', import.meta.url), 'utf8');
let passed = 0;
const flush = async () => { for (let i=0;i<12;i++) await Promise.resolve(); };
function fixture({url='https://checkout.bachs.io/c/synthetic', sdk=false, fetchEnabled=true, delay=false}={}) {
  const status={textContent:''}, fallback={hidden:true,focus(){this.focused=true;}}, button={disabled:false,focus(){this.focused=true;}}, attrs={};
  const form={action:'https://billing.example.invalid/modules/gateways/bachs/checkout.php',querySelector(){return button;},addEventListener(type,cb){this.submit=cb;}};
  const root={querySelector(selector){return selector==='[data-bachs-status]'?status:selector==='[data-bachs-fallback]'?fallback:form;},setAttribute(k,v){attrs[k]=v;},removeAttribute(k){delete attrs[k];},getAttribute(){return 'https://billing.example.invalid/modules/gateways/bachs/status.php';}};
  let script, calls=[], resolveFetch, initialized=0, onEvent;
  const result={attempt_id:'synthetic',mode:'overlay',checkout_url:url};
  const window={location:{href:'https://billing.example.invalid/viewinvoice.php?id=42',assign(u){this.assigned=u;}},FormData:class {},Promise};
  if(fetchEnabled)window.fetch=true;
  if(sdk) window.Bachs={Initialize(options){initialized++;onEvent=options.onEvent;},Checkout:{open(options){this.url=options.checkoutUrl;}}};
  const fetch=(path,opts)=>{
    calls.push([path,opts]);
    if(delay && calls.length===1)return new Promise(resolve=>{resolveFetch=()=>resolve({ok:true,json:async()=>result});});
    return Promise.resolve({ok:true,json:async()=>opts.method==='POST'?result:{state:'pending',message:'Confirming your payment…',invoice_paid:false}});
  };
  const ctx={window,document:{querySelectorAll(s){return s==='[data-bachs-payment]'?[root]:[];},createElement(){return script={};},head:{appendChild(){}}},fetch,URL,Promise,FormData:class {},AbortController:class {constructor(){this.signal={};}abort(){}},setTimeout(){return 1;},clearTimeout(){}};
  vm.runInNewContext(source,ctx);
  return {form,root,status,fallback,button,calls,attrs,get script(){return script;},get initialized(){return initialized;},get onEvent(){return onEvent;},resolve(){resolveFetch();},window};
}
async function test(name,fn){await fn();passed++;console.log('PASS '+name);}
await test('Double-click is blocked while one checkout request is pending',async()=>{const f=fixture({delay:true});f.form.submit({preventDefault(){}});f.form.submit({preventDefault(){}});assert.equal(f.calls.length,1);assert.equal(f.button.disabled,true);f.resolve();await flush();});
await test('Blocked SDK preserves the original session as a redirect fallback',async()=>{const f=fixture();f.form.submit({preventDefault(){}});await flush();assert.equal(f.fallback.hidden,false);assert.equal(f.fallback.href,'https://checkout.bachs.io/c/synthetic');f.script.onerror();await flush();assert.equal(f.fallback.focused,true);assert.match(f.status.textContent,/secure checkout link/);assert.equal(f.calls.filter(c=>c[1].method==='POST').length,1);});
await test('Browser completion stays pending until the server confirms recording',async()=>{const f=fixture({sdk:true});f.form.submit({preventDefault(){}});await flush();f.onEvent({type:'checkout.completed'});await flush();assert.equal(f.status.textContent,'Confirming your payment…');assert.equal(f.calls.length,2);assert.equal(f.window.location.assigned,undefined);});
await test('Overlay initializes once and restores keyboard focus on close',async()=>{const f=fixture({sdk:true});f.form.submit({preventDefault(){}});await flush();f.onEvent({type:'checkout.closed'});await flush();assert.equal(f.button.focused,true);assert.equal(f.button.disabled,false);f.form.submit({preventDefault(){}});await flush();assert.equal(f.initialized,1);});
await test('Unsafe checkout origin cannot be exposed or opened',async()=>{const f=fixture({url:'https://checkout.bachs.io.evil.invalid/c/token'});f.form.submit({preventDefault(){}});await flush();assert.equal(f.fallback.hidden,true);assert.match(f.status.textContent,/could not be opened/);});
await test('Without fetch the native POST form remains the payment path',async()=>{const f=fixture({fetchEnabled:false});let prevented=false;f.form.submit({preventDefault(){prevented=true;}});assert.equal(prevented,false);assert.equal(f.calls.length,0);});
console.log(`${passed} client behavior unit tests passed. Browser rendering remains a separate gate.`);
