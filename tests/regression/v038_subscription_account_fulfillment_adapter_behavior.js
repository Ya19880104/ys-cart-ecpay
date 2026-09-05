'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const sourcePath = path.join(root, 'assets', 'js', 'ys-cart-ecpay-account-fulfillment.js');
let pass = 0;
let fail = 0;
function check(label, ok) {
    if (ok) { pass += 1; process.stdout.write(`PASS ${label}\n`); return; }
    fail += 1; process.stdout.write(`FAIL ${label}\n`);
}

if (!fs.existsSync(sourcePath)) {
    check('ECPay account fulfillment adapter production asset exists', false);
    process.stdout.write(`\nv0.3.0 subscription account adapter: ${pass} PASS / ${fail} FAIL\n`);
    process.exit(1);
}

const source = fs.readFileSync(sourcePath, 'utf8');
let adapter = null;
const storageWrites = [];
const popups = [];
const forms = [];
const adapterTimers = new Set();
const nativeSetTimeout = setTimeout;

function fakeElement(tag) {
    const children = [];
    return {
        tagName: String(tag).toUpperCase(),
        method: '', action: '', target: '', type: '', name: '', value: '',
        appendChild(child) { children.push(child); return child; },
        remove() {},
        submit() {
            forms.push({ method: this.method, action: this.action, target: this.target, fields: children.map((item) => [item.name, item.value]) });
            const popup = popups.find((item) => item.name === this.target);
            if (popup) popup.location.href = popup.returnHref;
        },
    };
}

const document = {
    body: { appendChild() {} },
    createElement: fakeElement,
};
const window = {
    location: { href: 'https://account.test/dashboard?tab=subscriptions', origin: 'https://account.test' },
    YsEcAccountHandlers: {
        registerFulfillmentProviderAdapter(provider, value) {
            if (provider === 'ecpay') adapter = value;
            return true;
        },
    },
    open(url, name) {
        const popup = {
            name,
            closed: false,
            location: { href: url || 'about:blank' },
            returnHref: 'https://account.test/dashboard?tab=subscriptions&ys_ec_store_result=ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
            close() { this.closed = true; },
            focus() {},
        };
        popups.push(popup);
        return popup;
    },
};
window.setInterval = (fn) => nativeSetTimeout(fn, 0);
window.clearInterval = (id) => clearTimeout(id);
window.setTimeout = (fn, delay) => {
    let id = null;
    id = nativeSetTimeout(() => {
        adapterTimers.delete(id);
        fn();
    }, Number(delay) >= 1000 ? 0 : Number(delay) || 0);
    adapterTimers.add(id);
    return id;
};
window.clearTimeout = (id) => {
    adapterTimers.delete(id);
    clearTimeout(id);
};
window.localStorage = { setItem(key, value) { storageWrites.push([key, value]); } };
window.sessionStorage = { setItem(key, value) { storageWrites.push([key, value]); } };

const context = {
    window, document, URL, URLSearchParams, Promise, setTimeout, clearTimeout,
    console: { log() {}, warn() {}, error() {} },
};
context.globalThis = context;
vm.createContext(context);
vm.runInContext(source, context, { filename: 'ys-cart-ecpay-account-fulfillment.js' });

function requestClient(label, overrides = {}) {
    const calls = [];
    const client = (method, endpoint, body, options) => {
        calls.push({ label, method, endpoint, body, options });
        if (overrides.reject) return Promise.reject(new Error(overrides.reject));
        if (method === 'POST' && endpoint === '/stores/ecpay/map-url') {
            return Promise.resolve({ success: true, data: { action_url: 'https://logistics.ecpay.test/Express/map', fields: { MerchantID: '2000132', ExtraData: 'TEMP123' } } });
        }
        if (method === 'GET' && endpoint.startsWith('/ecpay/store-result?')) {
            return Promise.resolve({ success: true, data: Object.assign({
                selection_token: 'TOKEN-PROMISE-ONLY',
                cvs_store_id: '991234',
                cvs_store_name: '權威門市',
                cvs_store_addr: '台北市測試路1號',
                context: 'subscription',
                cart_scope: 'sub_41',
                shipping_id: 'ys_ec_ecpay_ship_unimart',
            }, overrides.result || {}) });
        }
        if (method === 'POST' && endpoint === '/stores/ecpay/reauthorize') {
            return Promise.resolve({ success: true, data: {
                selection_token: 'TOKEN-REAUTH-ONLY',
                cvs_store_id: '991234',
                cvs_store_name: '權威門市',
                cvs_store_addr: '台北市測試路1號',
                context: 'subscription',
                cart_scope: 'sub_41',
                shipping_id: 'ys_ec_ecpay_ship_unimart',
            } });
        }
        return Promise.reject(new Error(`unexpected ${method} ${endpoint}`));
    };
    return { calls, client };
}

function adapterContext(request, overrides = {}) {
    return Object.assign({
        subscription_id: 41,
        shipping_method_id: 'ys_ec_ecpay_ship_unimart',
        provider_id: 'ecpay',
        mode: 'select_store',
        request,
        return_url: 'https://account.test/dashboard?tab=subscriptions',
    }, overrides);
}

async function settleWithin(promise, milliseconds = 40) {
    return Promise.race([
        Promise.resolve(promise).then(
            (value) => ({ kind: 'fulfilled', value }),
            (error) => ({ kind: 'rejected', error })
        ),
        new Promise((resolve) => nativeSetTimeout(() => resolve({ kind: 'hung' }), milliseconds)),
    ]);
}

(async () => {
    check('adapter registers only through the Core provider-neutral registry', !!adapter && typeof adapter.selectStore === 'function' && typeof adapter.reauthorizeSavedStore === 'function');
    if (!adapter) process.exit(1);

    const a = requestClient('root-a');
    const selected = await adapter.selectStore(adapterContext(a.client));
    check(
        'fresh selection uses one root-bound client for map and one-time result claim',
        a.calls.length === 2
            && a.calls[0].method === 'POST'
            && a.calls[0].body.context === 'subscription'
            && a.calls[0].body.subscription_id === 41
            && a.calls[0].body.shipping_id === 'ys_ec_ecpay_ship_unimart'
            && a.calls[1].method === 'GET'
            && a.calls[1].endpoint.includes('cart_scope=sub_41')
            && selected.selection_token === 'TOKEN-PROMISE-ONLY'
            && selected.cvs_store_address === '台北市測試路1號'
    );
    check(
        'map is submitted to the isolated popup without placing token bytes in DOM fields',
        forms.length === 1
            && forms[0].action === 'https://logistics.ecpay.test/Express/map'
            && forms[0].fields.some(([key]) => key === 'MerchantID')
            && !JSON.stringify(forms[0]).includes('TOKEN-PROMISE-ONLY')
    );

    const b = requestClient('root-b');
    const reauthorized = await adapter.reauthorizeSavedStore(adapterContext(b.client, {
        mode: 'reauthorize_saved_store',
        saved_address: { id: 77, cvs_store_id: '991234' },
    }));
    check(
        'saved-store reauthorization stays on the second root and sends server-owned subscription context',
        b.calls.length === 1
            && b.calls[0].method === 'POST'
            && b.calls[0].endpoint === '/stores/ecpay/reauthorize'
            && b.calls[0].body.address_id === 77
            && b.calls[0].body.subscription_id === 41
            && reauthorized.selection_token === 'TOKEN-REAUTH-ONLY'
            && a.calls.every((call) => call.label === 'root-a')
    );

    const wrong = requestClient('wrong', { result: { context: 'checkout' } });
    let wrongRejected = false;
    try { await adapter.selectStore(adapterContext(wrong.client)); } catch (error) { wrongRejected = true; }
    check('wrong result context fails closed after claim', wrongRejected);

    let noClientRejected = false;
    try { await adapter.selectStore(adapterContext(null)); } catch (error) { noClientRejected = true; }
    check('missing root-bound request never falls back to a global client', noClientRejected);

    const syncPopupIndex = popups.length;
    let syncThrew = false;
    let syncPromise = null;
    try {
        syncPromise = adapter.selectStore(adapterContext(() => { throw new Error('sync request failure'); }));
    } catch (error) {
        syncThrew = true;
    }
    const syncOutcome = syncPromise ? await settleWithin(syncPromise) : { kind: 'missing' };
    check(
        'synchronous root request failures become Promise rejections and close the popup',
        !syncThrew
            && syncOutcome.kind === 'rejected'
            && /sync request failure/.test(String(syncOutcome.error && syncOutcome.error.message))
            && popups[syncPopupIndex]
            && popups[syncPopupIndex].closed === true
    );

    const mapPopupIndex = popups.length;
    const mapTimeout = await settleWithin(adapter.selectStore(adapterContext(() => new Promise(() => {}))));
    check(
        'map-url request has a bounded timeout, clears its timer and closes the popup',
        mapTimeout.kind === 'rejected'
            && /逾時/.test(String(mapTimeout.error && mapTimeout.error.message))
            && popups[mapPopupIndex]
            && popups[mapPopupIndex].closed === true
            && adapterTimers.size === 0
    );

    const claimPopupIndex = popups.length;
    const claimTimeout = await settleWithin(adapter.selectStore(adapterContext((method, endpoint) => {
        if (method === 'POST' && endpoint === '/stores/ecpay/map-url') {
            return Promise.resolve({ success: true, data: { action_url: 'https://logistics.ecpay.test/Express/map', fields: { MerchantID: '2000132' } } });
        }
        if (method === 'GET' && endpoint.startsWith('/ecpay/store-result?')) return new Promise(() => {});
        return Promise.reject(new Error(`unexpected ${method} ${endpoint}`));
    })));
    check(
        'one-time result claim has a bounded timeout, clears its timer and closes the popup',
        claimTimeout.kind === 'rejected'
            && /逾時/.test(String(claimTimeout.error && claimTimeout.error.message))
            && popups[claimPopupIndex]
            && popups[claimPopupIndex].closed === true
            && adapterTimers.size === 0
    );

    const reauthorizeTimeout = await settleWithin(adapter.reauthorizeSavedStore(adapterContext(
        () => new Promise(() => {}),
        { mode: 'reauthorize_saved_store', saved_address: { id: 77, cvs_store_id: '991234' } }
    )));
    check(
        'saved-store reauthorization has a bounded timeout and clears its timer',
        reauthorizeTimeout.kind === 'rejected'
            && /逾時/.test(String(reauthorizeTimeout.error && reauthorizeTimeout.error.message))
            && adapterTimers.size === 0
    );

    check(
        'raw selection tokens never enter storage, URL, custom events or adapter source persistence code',
        storageWrites.length === 0
            && !source.includes('localStorage')
            && !source.includes('sessionStorage')
            && !source.includes('CustomEvent')
            && !popups.some((popup) => String(popup.location.href).includes('TOKEN-'))
    );

    process.stdout.write(`\nv0.3.0 subscription account adapter: ${pass} PASS / ${fail} FAIL\n`);
    process.exit(fail > 0 || pass === 0 ? 1 : 0);
})().catch((error) => {
    process.stdout.write(`FAIL unexpected adapter error: ${error && error.stack || error}\n`);
    process.exit(1);
});
