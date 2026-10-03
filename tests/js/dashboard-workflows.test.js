import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { parse, compileScript } from '@vue/compiler-sfc';
import { transformSync } from 'esbuild';
import { JSDOM } from 'jsdom';
import { searchableSelect } from '../../resources/js/directives/searchableSelect.js';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/' });
for (const key of ['window', 'document', 'Document', 'HTMLElement', 'SVGElement', 'Element', 'Node', 'CustomEvent']) globalThis[key] = dom.window[key];
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: dom.window.navigator });
const require = createRequire(import.meta.url);
const Vue = require('vue');
const liveRefresh = { exports: {} };
new Function('require', 'module', 'exports', transformSync(readFileSync(new URL('../../resources/js/composables/useLiveRefresh.js', import.meta.url), 'utf8'), { format: 'cjs' }).code)(require, liveRefresh, liveRefresh.exports);

const locations = [
    { id: 0, name: 'Կենտրոնական պահեստ' },
    { id: 2, name: 'Էրեբունի' },
    { id: 3, name: 'Շենգավիթ' },
];
const centralUser = () => ({
    id: 1, name: 'Auditor', location_id: 0, branch: { id: 1, name: 'Administrative office' },
    permissions: Object.fromEntries(['dashboard.view', 'branches.view', 'stock.view', 'purchases.view', 'expiry.view', 'requests.view', 'receipts.view', 'movements.view', 'returns.view', 'transfers.view'].map((permission) => [permission, true])),
});

function snapshot(locationId = 0, values = {}, selectable = true) {
    return { data: { data: {
        products: 3, units: 125, stock_value: 1200, low_stock_products: 1, expiring_lots: 2, open_requests: 4,
        zero_stock_products: 1, expired_lots: 0, unapproved_requests: 2, awaiting_receipt_requests: 1,
        today: { receipts: 3, issues: 5, returns: 1, transfers: 2 }, branches: [],
        selected_location: locations.find((location) => location.id === locationId),
        location_options: selectable ? locations : [], can_select_location: selectable,
        ...values,
    } } };
}

function deferred() {
    let resolve; let reject;
    const promise = new Promise((complete, fail) => { resolve = complete; reject = fail; });
    return { promise, resolve, reject };
}

function mountDashboard(api, user = centralUser()) {
    const filename = new URL('../../resources/js/views/dashboard/Index.vue', import.meta.url);
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname });
    const script = compileScript(descriptor, { id: 'dashboard-workflow', inlineTemplate: true });
    const { code } = transformSync(script.content, { format: 'cjs' });
    const module = { exports: {} };
    const dependencies = {
        '@/services/api': api,
        '@/router': { currentUser: () => user },
        '@/composables/useLiveRefresh': liveRefresh.exports,
        'vue-router': { RouterLink: { props: ['to'], render() { return Vue.h('a', { href: this.to }, this.$slots.default?.()); } } },
    };
    new Function('require', 'module', 'exports', code)((name) => dependencies[name] || require(name), module, module.exports);
    const root = document.createElement('div');
    document.body.append(root);
    const app = Vue.createApp(module.exports.default);
    app.component('AppIcon', { render: () => null });
    app.directive('searchable-select', searchableSelect);
    app.mount(root);
    return { root, unmount() { app.unmount(); root.remove(); } };
}

async function settle() { await new Promise((resolve) => setImmediate(resolve)); await Vue.nextTick(); }
function choose(root, id) {
    const select = root.querySelector('#dashboard-location');
    select.value = String(id);
    select.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
}
function heroLocation(root) { return root.querySelector('.dashboard-hero-meta span:last-child').textContent; }
function heroUnits(root) { return root.querySelector('.dashboard-hero-panel strong')?.textContent; }

test('central dashboard selection replaces all metrics and activity without showing old totals under the new location', async () => {
    const selected = deferred(); const calls = []; const user = centralUser();
    const view = mountDashboard({ get(endpoint, { params }) {
        assert.equal(endpoint, 'dashboard'); calls.push(structuredClone(params));
        return calls.length === 1 ? Promise.resolve(snapshot()) : selected.promise;
    } }, user);
    try {
        await settle();
        assert.equal(view.root.querySelector('label[for="dashboard-location"]').textContent, 'Պահեստ / մասնաճյուղ');
        assert.equal(view.root.querySelector('select').value, '0');
        assert.equal(view.root.querySelectorAll('option').length, 3);
        assert.equal(heroLocation(view.root), 'Կենտրոնական պահեստ');
        assert.equal(heroUnits(view.root), '125');
        choose(view.root, 2); await settle();
        assert.deepEqual(calls, [{}, { branch_id: 2 }]);
        assert.equal(heroLocation(view.root), 'Էրեբունի');
        assert.equal(heroUnits(view.root), undefined);
        assert.equal(view.root.querySelectorAll('.dashboard-metric-card, .dashboard-activity-row, .dashboard-secondary-card').length, 0);
        assert.ok(view.root.querySelector('[role="status"]'));
        assert.equal(view.root.querySelector('select').disabled, false, 'a pending load still permits a newer location choice');
        selected.resolve(snapshot(2, { units: 42, products: 4, today: { receipts: 8, issues: 6, returns: 2, transfers: 1 } })); await settle();
        assert.equal(heroUnits(view.root), '42');
        assert.equal(view.root.querySelectorAll('.dashboard-metric-card').length, 6);
        assert.deepEqual([...view.root.querySelectorAll('.dashboard-activity-row strong')].map((element) => element.textContent), ['8', '6', '2', '1']);
        assert.equal(view.root.querySelector('[role="status"]'), null);
        assert.equal(user.location_id, 0, 'dashboard selection does not change the authenticated user context');
        assert.equal(user.branch.name, 'Administrative office');
    } finally { view.unmount(); }
});

test('a slower earlier location cannot restore its totals or selection during rapid dashboard choices', async () => {
    const earlier = deferred(); const latest = deferred(); let count = 0;
    const view = mountDashboard({ get() { count += 1; return count === 1 ? Promise.resolve(snapshot()) : count === 2 ? earlier.promise : latest.promise; } });
    try {
        await settle(); choose(view.root, 2); await settle(); choose(view.root, 3); await settle();
        earlier.resolve(snapshot(2, { units: 111 })); await settle();
        assert.equal(view.root.querySelector('select').value, '3');
        assert.equal(heroLocation(view.root), 'Շենգավիթ');
        assert.equal(heroUnits(view.root), undefined);
        assert.equal(view.root.querySelector('.dashboard-hero').getAttribute('aria-busy'), 'true');
        latest.resolve(snapshot(3, { units: 33 })); await settle();
        assert.equal(heroUnits(view.root), '33');
        assert.equal(view.root.querySelector('select').value, '3');
        assert.equal(view.root.querySelector('[role="alert"]'), null);
    } finally { view.unmount(); }
});

test('a failed location load keeps its chosen scope, clears stale totals and retries that same location', async () => {
    const retry = deferred(); const calls = [];
    const view = mountDashboard({ get(endpoint, { params }) {
        calls.push(structuredClone(params));
        if (calls.length === 1) return Promise.resolve(snapshot());
        if (calls.length === 2) return Promise.reject({ response: { data: { message: 'Selected warehouse unavailable' } } });
        return retry.promise;
    } });
    try {
        await settle(); choose(view.root, 2); await settle();
        assert.match(view.root.querySelector('[role="alert"]').textContent, /Selected warehouse unavailable/);
        assert.equal(heroUnits(view.root), undefined);
        assert.equal(heroLocation(view.root), 'Էրեբունի');
        assert.equal(view.root.querySelector('.dashboard-hero').getAttribute('aria-busy'), 'false');
        view.root.querySelector('[role="alert"] button').click(); await settle();
        assert.deepEqual(calls.at(-1), { branch_id: 2 });
        assert.equal(view.root.querySelector('[role="alert"]'), null);
        assert.ok(view.root.querySelector('[role="status"]'));
        retry.resolve(snapshot(2, { units: 22 })); await settle();
        assert.equal(heroUnits(view.root), '22');
    } finally { view.unmount(); }
});

test('realtime dashboard bursts and a trailing snapshot retain the newest chosen location', async () => {
    const oldLive = deferred(); const calls = [];
    const view = mountDashboard({ get(endpoint, { params }) {
        calls.push(structuredClone(params));
        if (calls.length === 3) return oldLive.promise;
        return Promise.resolve(snapshot(params.branch_id ?? 0, { units: calls.length === 5 ? 99 : 10 * calls.length }));
    } });
    try {
        await settle(); choose(view.root, 2); await settle();
        window.dispatchEvent(new CustomEvent('lager:data-changed'));
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls.length, 3, 'one fetch handles the realtime burst');
        choose(view.root, 3); await settle();
        assert.equal(heroUnits(view.root), '40');
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        oldLive.resolve(snapshot(2, { units: 222 })); await settle(); await settle();
        assert.deepEqual(calls, [{}, { branch_id: 2 }, { branch_id: 2 }, { branch_id: 3 }, { branch_id: 3 }]);
        assert.equal(heroLocation(view.root), 'Շենգավիթ');
        assert.equal(heroUnits(view.root), '99');
        view.unmount(); window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls.length, 5, 'the dashboard releases its live listener on unmount');
    } finally { if (view.root.isConnected) view.unmount(); }
});

for (const [label, locationId, permissions] of [
    ['branch worker', 2, { 'dashboard.view': true, 'stock.view': true }],
    ['central worker without branch visibility', 0, { 'dashboard.view': true, 'stock.view': true }],
]) {
    test(`${label} sees its own dashboard without a location selector`, async () => {
        const calls = [];
        const view = mountDashboard({ async get(endpoint, { params }) { calls.push(structuredClone(params)); return snapshot(locationId, {}, false); } }, { location_id: locationId, permissions });
        try {
            await settle();
            assert.equal(view.root.querySelector('select'), null);
            assert.equal(heroLocation(view.root), locations.find((location) => location.id === locationId).name);
            window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
            assert.deepEqual(calls, [{}, {}], 'an unselectable dashboard never sends a location override');
        } finally { view.unmount(); }
    });
}

test('changing user context clears the former dashboard selection and rejects its pending response', async () => {
    const former = deferred(); const calls = [];
    const view = mountDashboard({ get(endpoint, { params }) {
        calls.push(structuredClone(params));
        return calls.length === 1 ? Promise.resolve(snapshot()) : calls.length === 2 ? former.promise : Promise.resolve(snapshot(3, { units: 31 }, false));
    } });
    try {
        await settle(); choose(view.root, 2); await settle();
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { name: 'Branch worker', location_id: 3, permissions: { 'dashboard.view': true, 'stock.view': true } } })); await settle();
        assert.deepEqual(calls.at(-1), {});
        assert.equal(view.root.querySelector('select'), null);
        assert.equal(heroLocation(view.root), 'Շենգավիթ');
        assert.equal(heroUnits(view.root), '31');
        former.resolve(snapshot(2, { units: 222 })); await settle();
        assert.equal(heroUnits(view.root), '31');
        assert.equal(heroLocation(view.root), 'Շենգավիթ');
        assert.equal(view.root.querySelector('select'), null);
    } finally { view.unmount(); }
});

test('a routine user refresh preserves the dashboard filter and its pending selected-location request', async () => {
    const selected = deferred(); const user = centralUser(); const calls = [];
    const view = mountDashboard({ get(endpoint, { params }) {
        calls.push(structuredClone(params));
        return calls.length === 1 ? Promise.resolve(snapshot()) : selected.promise;
    } }, user);
    try {
        await settle(); choose(view.root, 2); await settle();
        window.dispatchEvent(new CustomEvent('lager:user', { detail: {
            ...user, name: 'Updated display name', permissions: Object.fromEntries(Object.entries(user.permissions).reverse()),
        } })); await settle();
        assert.equal(calls.length, 2, 'equivalent permission maps do not reset or refetch the selected scope');
        assert.equal(view.root.querySelector('select').value, '2');
        selected.resolve(snapshot(2, { units: 22 })); await settle();
        assert.equal(heroLocation(view.root), 'Էրեբունի');
        assert.equal(heroUnits(view.root), '22');
        assert.match(view.root.querySelector('h1').textContent, /Updated/);
    } finally { view.unmount(); }
});

for (const [label, permission, fields, headers, values] of [
    ['branch identities only', null, {}, ['Մասնաճյուղ'], ['Էրեբունի']],
    ['stock visibility', 'stock.view', { stock_units: 7 }, ['Մասնաճյուղ', 'Մնացորդ'], ['Էրեբունի', '7']],
    ['request visibility', 'requests.view', { open_requests: 4, unapproved_requests: 3, awaiting_receipt_requests: 2 }, ['Մասնաճյուղ', 'Բաց պահանջագիր', 'Չհաստատված', 'Սպասվող ընդունում'], ['Էրեբունի', '4', '3', '2']],
    ['transfer visibility', 'transfers.view', { awaiting_transfer_receipts: 6 }, ['Մասնաճյուղ', 'Տեղափոխման ընդունում'], ['Էրեբունի', '6']],
]) {
    test(`dashboard branch overview renders authorized columns for ${label}`, async () => {
        const user = { id: 1, location_id: 0, permissions: { 'dashboard.view': true, 'branches.view': true, ...(permission ? { [permission]: true } : {}) } };
        const view = mountDashboard({ async get() { return snapshot(0, { branches: [{ branch_id: 2, branch: 'Էրեբունի', ...fields }] }); } }, user);
        try {
            await settle();
            assert.deepEqual([...view.root.querySelectorAll('.dashboard-branches th')].map((element) => element.textContent), headers);
            assert.deepEqual([...view.root.querySelectorAll('.dashboard-branches td')].map((element) => element.textContent), values);
        } finally { view.unmount(); }
    });
}

test('dashboard searchable menu selects a branch, cleans up on unmount and starts a fresh dashboard at central', async () => {
    const selected = deferred(); const calls = []; const user = centralUser();
    let view = mountDashboard({ get(endpoint, { params }) {
        assert.equal(endpoint, 'dashboard'); calls.push(structuredClone(params));
        return calls.length === 1 ? Promise.resolve(snapshot()) : selected.promise;
    } }, user);
    try {
        await settle();
        const select = view.root.querySelector('#dashboard-location');
        select.dispatchEvent(new dom.window.MouseEvent('pointerdown', { button: 0, bubbles: true, cancelable: true })); await settle();
        const menu = document.querySelector('.searchable-select-menu');
        assert.ok(menu, 'the styled menu opens instead of a native OS picker');
        assert.equal(select.getAttribute('aria-expanded'), 'true');
        assert.equal(menu.querySelector('[role="combobox"]').getAttribute('aria-label'), 'Պահեստ / մասնաճյուղ՝ որոնում');
        const branch = [...menu.querySelectorAll('[role="option"]')].find((option) => option.textContent === 'Էրեբունի');
        branch.click(); await settle();
        assert.deepEqual(calls, [{}, { branch_id: 2 }]);
        assert.equal(document.querySelector('.searchable-select-menu'), null);
        assert.equal(select.getAttribute('aria-expanded'), 'false');
        selected.resolve(snapshot(2, { units: 42 })); await settle();
        assert.equal(select.value, '2');
        assert.equal(select.selectedOptions[0].textContent, 'Էրեբունի');
        assert.equal(heroLocation(view.root), 'Էրեբունի');
        assert.equal(heroUnits(view.root), '42');
        select.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true })); await settle();
        assert.equal(document.querySelector('.searchable-select-menu [aria-selected="true"]').textContent, 'Էրեբունի');
        view.unmount(); view = null;
        assert.equal(document.querySelector('.searchable-select-menu'), null, 'unmount removes the body-level popup');
        assert.equal(select.getAttribute('aria-expanded'), null);
        assert.equal(select.getAttribute('aria-controls'), null);

        const freshCalls = [];
        view = mountDashboard({ async get(endpoint, { params }) { freshCalls.push(structuredClone(params)); return snapshot(); } }, user);
        await settle();
        assert.deepEqual(freshCalls, [{}], 'a fresh dashboard requests the account default instead of retaining the previous branch');
        assert.equal(view.root.querySelector('select').value, '0');
        assert.equal(heroLocation(view.root), 'Կենտրոնական պահեստ');
        assert.equal(user.location_id, 0);
    } finally { view?.unmount(); }
});
