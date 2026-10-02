import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { parse, compileScript } from '@vue/compiler-sfc';
import { transformSync } from 'esbuild';
import { JSDOM } from 'jsdom';
import * as dateUtils from '../../resources/js/dateUtils.js';
import { canCreateRecord } from '../../resources/js/permissions.js';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/' });
for (const key of ['window', 'document', 'history', 'Document', 'HTMLElement', 'SVGElement', 'Element', 'Node', 'CustomEvent', 'localStorage']) globalThis[key] = dom.window[key];
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: dom.window.navigator });
const require = createRequire(import.meta.url);
const Vue = require('vue');
const VueRouter = require('vue-router');
const stub = { render: () => null };
const liveRefresh = { exports: {} };
new Function('require', 'module', 'exports', transformSync(readFileSync(new URL('../../resources/js/composables/useLiveRefresh.js', import.meta.url), 'utf8'), { format: 'cjs' }).code)(require, liveRefresh, liveRefresh.exports);

function deferred() {
    let resolve;
    const promise = new Promise((complete) => { resolve = complete; });
    return { promise, resolve };
}

function component(relativePath, dependencies = {}) {
    const filename = new URL(`../../resources/js/${relativePath}`, import.meta.url);
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname });
    const script = compileScript(descriptor, { id: relativePath, inlineTemplate: true });
    const { code } = transformSync(script.content, { format: 'cjs', loader: 'js' });
    const module = { exports: {} };
    new Function('require', 'module', 'exports', code)((name) => Object.hasOwn(dependencies, name) ? dependencies[name] : name === '@/composables/useLiveRefresh' ? liveRefresh.exports : require(name), module, module.exports);
    return module.exports.default;
}

async function settle() { await new Promise((resolve) => setImmediate(resolve)); await Vue.nextTick(); }
function change(input, value) { input.value = value; input.dispatchEvent(new dom.window.Event(input.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); }
function submit(form) { form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true })); }
function list(data, columns = { name: 'Name' }, extra = {}) {
    return { data: { data, columns, pagination: { total: data.length, per_page: 15, current_page: 1, last_page: 1 }, ...extra } };
}

async function mountView(relativePath, path, api, user = {}, dependencies = {}) {
    const definition = component(relativePath, {
        '@/components/Pagination.vue': stub, '@/components/ExportActions.vue': stub,
        '@/components/ListFilterBar.vue': stub, '@/components/BarcodeScanner.vue': stub,
        '@/components/DestructiveConfirmDialog.vue': stub,
        '@/services/api': api, '@/router': { currentUser: () => user },
        '@/dateUtils': dateUtils, '@/permissions': { canCreateRecord },
        ...dependencies,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path, component: stub, meta: { title: 'Audit scenario' } },
        ...['/stock', '/stock/matrix'].filter((candidate) => candidate !== path).map((candidate) => ({ path: candidate, component: stub })),
    ] });
    await router.push(path);
    return mount(definition, router);
}

function mount(definition, router) {
    const root = document.createElement('div');
    document.body.append(root);
    const app = Vue.createApp(definition);
    app.component('AppIcon', stub).component('DatePicker', stub).directive('searchable-select', {});
    if (router) app.use(router);
    app.mount(root);
    return { root, unmount() { app.unmount(); root.remove(); } };
}

test('overlapping catalog edits keep the latest record ID paired with its own form data', async () => {
    const first = deferred(); const second = deferred(); const updates = [];
    const records = [{ id: 1, name: 'First branch', active: true }, { id: 2, name: 'Second branch', active: true }];
    const view = await mountView('views/CatalogTable.vue', '/branches', {
        get(endpoint) {
            if (endpoint === 'pages/branches') return Promise.resolve(list(records));
            return endpoint.endsWith('/1') ? first.promise : second.promise;
        },
        async put(endpoint, payload) { updates.push({ endpoint, payload: structuredClone(Vue.toRaw(payload)) }); return {}; },
    }, { permissions: { 'branches.view': true, 'branches.edit': true } });
    try {
        await settle();
        const buttons = view.root.querySelectorAll('button[title="Խմբագրել"]');
        buttons[0].click(); buttons[1].click(); await settle();
        second.resolve({ data: { data: { ...records[1], code: 'SECOND' } } }); await settle();
        first.resolve({ data: { data: { ...records[0], code: 'FIRST' } } }); await settle();
        assert.equal(view.root.querySelector('.catalog-modal input').value, 'Second branch');
        submit(view.root.querySelector('.catalog-modal')); await settle();
        assert.equal(updates[0].endpoint, 'catalog/branches/2');
        assert.equal(updates[0].payload.name, 'Second branch');
    } finally { view.unmount(); }
});

test('a slower previous report cannot replace results after another report type has loaded', async () => {
    const earlier = deferred(); const latest = deferred(); let count = 0;
    const metadata = { report_types: { stock_by_location: 'Stock', receipts: 'Receipts' }, filters: {}, summary: {} };
    const view = await mountView('views/reports/Index.vue', '/reports', {
        get() {
            count += 1;
            return count === 1 ? Promise.resolve(list([{ name: 'Initial stock' }], undefined, metadata)) : count === 2 ? earlier.promise : latest.promise;
        },
    }, { location_id: 0, permissions: { 'reports.view': true } });
    try {
        await settle();
        submit(view.root.querySelector('.report-filter-grid')); await settle();
        change(view.root.querySelector('.report-filter-grid select'), 'receipts'); await settle();
        latest.resolve(list([{ name: 'Latest receipts' }], undefined, metadata)); await settle();
        earlier.resolve(list([{ name: 'Old stock response' }], undefined, metadata)); await settle();
        assert.match(view.root.querySelector('tbody').textContent, /Latest receipts/);
        assert.doesNotMatch(view.root.querySelector('tbody').textContent, /Old stock response/);
    } finally { view.unmount(); }
});

test('date-picker validity follows changing minimum and maximum dates including initial constraints', async () => {
    const DatePicker = component('components/DatePicker.vue', { './AppIcon.vue': stub, '../dateUtils': dateUtils });
    const state = Vue.reactive({ value: '2026-10-02', min: '2026-10-03', max: '' });
    const view = mount({ setup: () => () => Vue.h('form', [Vue.h(DatePicker, { modelValue: state.value, min: state.min, max: state.max, 'onUpdate:modelValue': (value) => { state.value = value; } })]) });
    try {
        await settle();
        const form = view.root.querySelector('form');
        assert.equal(form.checkValidity(), false, 'an initial date earlier than the minimum must be invalid');
        state.min = '2026-10-01'; await settle();
        assert.equal(form.checkValidity(), true);
        state.max = '2026-10-01'; await settle();
        assert.equal(form.checkValidity(), false, 'an existing date must become invalid when the maximum changes');
        state.max = '2026-10-04'; await settle();
        assert.equal(form.checkValidity(), true);
        state.min = '2026-10-03'; await settle();
        assert.equal(form.checkValidity(), false);
    } finally { view.unmount(); }
});

test('supplier viewers see history but no create, edit or deactivate controls', async () => {
    const supplier = { id: 1, name: 'Supplier', active: true, tax_id: '123', address: 'Address', contact_name: 'Contact', phone: '123', email: 'mail@example.test', contract_no: 'Contract', contract_start: '2026-10-01', contract_end: '2026-11-01', payment_terms: 'Cash', delivery_days: 0 };
    const view = await mountView('views/suppliers/Index.vue', '/suppliers', { async get() { return { data: { data: [supplier], total: 1, current_page: 1, per_page: 15, last_page: 1 } }; } }, { permissions: { 'suppliers.view': true } });
    try {
        await settle();
        assert.equal(view.root.querySelector('.page-heading .primary-button'), null);
        assert.equal(view.root.querySelector('button[title="Խմբագրել"]'), null);
        assert.equal(view.root.querySelector('.table-actions .danger'), null);
        assert.ok(view.root.querySelector('button[title="Պատմություն"]'));
        assert.match(view.root.querySelector('.completeness-pill').textContent, /Ամբողջական/);
    } finally { view.unmount(); }
});

test('an administrator can take sent requests for review while cancellation remains available', async () => {
    const calls = []; let status = 'sent';
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint) {
            if (endpoint === 'pages/requests') return list([{ id: 1, request_no: 'REQ-1', status, branch_id: 3, requested_by: 10 }]);
            return { data: { data: { id: 1, request_no: 'REQ-1', items: [] } } };
        },
        async post(endpoint, payload) { calls.push({ endpoint, payload }); status = 'review'; return {}; },
    }, { id: 9, role: { name: 'admin' }, branch: { id: 2 }, location_id: 0, permissions: { 'requests.view': true, 'requests.edit': true, 'requests.approve': true } });
    try {
        await settle();
        const takeReview = [...view.root.querySelectorAll('tbody button')].find((button) => button.textContent.includes('Վերցնել ստուգման'));
        assert.ok(takeReview, 'approval must not be hidden behind administrator cancellation');
        assert.ok([...view.root.querySelectorAll('tbody button')].some((button) => button.textContent.includes('Չեղարկել')));
        takeReview.click(); await settle();
        assert.deepEqual(calls, [{ endpoint: 'requests/1/review', payload: { decision: 'start_review' } }]);
        const review = [...view.root.querySelectorAll('tbody button')].find((button) => button.textContent.includes('Դիտարկել'));
        assert.ok(review);
        review.click(); await settle();
        assert.ok(view.root.querySelector('.approval-list'));
    } finally { view.unmount(); }
});

test('late request suggestions from another branch cannot replace the selected branch quantities', async () => {
    const first = deferred(); const latest = deferred();
    const view = await mountView('views/requests/Index.vue', '/requests', {
        get(endpoint, config) {
            if (endpoint === 'pages/requests') return Promise.resolve(list([]));
            if (endpoint === 'catalog/requests/options') return Promise.resolve({ data: { data: { branches: [{ id: 2, name: 'Branch two' }, { id: 3, name: 'Branch three' }], products: [{ id: 1, code: 'P1', name: 'Product' }] } } });
            return Number(config.params.branch_id) === 2 ? first.promise : latest.promise;
        },
    }, { branch: { id: 2 }, location_id: 0, permissions: { 'requests.view': true, 'requests.create': true } });
    try {
        await settle(); view.root.querySelector('.page-heading .primary-button').click(); await settle();
        change(view.root.querySelector('.request-line-row select'), '1');
        change(view.root.querySelector('.request-modal .form-grid select'), '3'); await settle();
        latest.resolve({ data: { data: { items: { 1: { current: 3, average: 4, suggested: 50 } } } } }); await settle();
        first.resolve({ data: { data: { items: { 1: { current: 2, average: 1, suggested: 20 } } } } }); await settle();
        view.root.querySelector('.request-stock-hint .text-link').click(); await settle();
        assert.equal(view.root.querySelector('.request-line-row input[type="number"]').value, '50');
    } finally { view.unmount(); }
});

test('changing a stock adjustment product clears its old lot and discards late lot responses', async () => {
    const secondLots = deferred();
    const view = await mountView('views/stock/Index.vue', '/stock', {
        get(endpoint, config) {
            if (endpoint === 'pages/stock') return Promise.resolve(list([{ id: 1, name: 'Product one', code: 'P1', quantity: 1 }]));
            if (endpoint === 'catalog/stock/options') return Promise.resolve({ data: { data: { branches: [], products: [{ id: 1, name: 'Product one' }, { id: 2, name: 'Product two' }] } } });
            return Number(config.params.product_id) === 1 ? Promise.resolve({ data: { data: [{ id: 101, lot_no: 'LOT-ONE', qty: 1 }] } }) : secondLots.promise;
        },
    }, { location_id: 0, permissions: { 'stock.view': true, 'stock.edit': true } });
    try {
        await settle(); view.root.querySelector('.table-actions button[title="Ճշգրտել"]').click(); await settle();
        const selects = view.root.querySelectorAll('.stock-modal select');
        change(selects[2], '101'); await settle();
        change(selects[0], '2'); change(view.root.querySelector('.stock-modal input[type="number"]'), '1'); await settle();
        assert.match(view.root.querySelector('.stock-modal').textContent, /Նոր LOT համար/);
        change(selects[0], '1'); await settle();
        secondLots.resolve({ data: { data: [{ id: 202, lot_no: 'LOT-TWO', qty: 2 }] } }); await settle();
        assert.match(selects[2].textContent, /LOT-ONE/);
        assert.doesNotMatch(selects[2].textContent, /LOT-TWO/);
    } finally { view.unmount(); }
});

const listViews = [
    ['CatalogTable.vue', '/branches', 'pages/branches'],
    ['PageTable.vue', '/other', 'pages/other'],
    ['purchasing/Index.vue', '/purchases', 'pages/purchases'],
    ['purchasing/Index.vue', '/receipts', 'pages/receipts'],
    ['requests/Index.vue', '/requests', 'pages/requests'],
    ['transfers/Index.vue', '/transfers', 'pages/transfers'],
    ['inventory/Index.vue', '/inventory', 'inventory'],
    ['returns/Index.vue', '/returns', 'returns'],
    ['movements/Index.vue', '/movements', 'movements'],
    ['suppliers/Index.vue', '/suppliers', 'suppliers'],
    ['stock/Index.vue', '/stock', 'pages/stock'],
    ['stock/Matrix.vue', '/stock/matrix', 'stock/matrix'],
    ['audit/Index.vue', '/audit', 'pages/audit'],
    ['expiry/Index.vue', '/expiry', 'pages/expiry'],
];

for (const [filename, path, endpoint] of listViews) {
    test(`${path} keeps the latest filtered list when the initial request finishes last`, async () => {
        const initial = deferred(); const latest = deferred(); let calls = 0; let searchCallback;
        const previousSetTimeout = globalThis.setTimeout;
        globalThis.setTimeout = (callback, delay, ...args) => {
            if (delay === 250) { searchCallback = () => callback(...args); return -1; }
            return previousSetTimeout(callback, delay, ...args);
        };
        const response = (marker) => list([{
            id: 1, name: marker, code: marker, request_no: marker, transfer_no: marker, inventory_no: marker,
            movement_no: marker, action: marker, quantity: 1, total: 1,
            product: path === '/returns' ? { name: marker, code: marker } : marker,
        }]);
        let view;
        try {
            view = await mountView(`views/${filename}`, path, {
                get(requestedEndpoint) {
                    assert.equal(requestedEndpoint, endpoint);
                    calls += 1;
                    return calls === 1 ? initial.promise : latest.promise;
                },
            }, { permissions: {} });
            await settle();
            change(view.root.querySelector('.search-input input, .expiry-search input, input[placeholder*="Փաստաթուղթ"]'), 'latest filter');
            await Vue.nextTick();
            if (path === '/expiry') submit(view.root.querySelector('.expiry-toolbar'));
            else { assert.ok(searchCallback); searchCallback(); }
            await settle();
            assert.equal(calls, 2);
            latest.resolve(response('LATEST-FILTERED-ROW')); await settle();
            initial.resolve(response('OUTDATED-INITIAL-ROW')); await settle();
            assert.match(view.root.querySelector('tbody').textContent, /LATEST-FILTERED-ROW/);
            assert.doesNotMatch(view.root.querySelector('tbody').textContent, /OUTDATED-INITIAL-ROW/);
        } finally { view?.unmount(); globalThis.setTimeout = previousSetTimeout; }
    });
}

const livePagination = {
    emits: ['page-change'],
    render() { return Vue.h('button', { class: 'test-next-page', onClick: () => this.$emit('page-change', 3) }, 'Page 3'); },
};
const liveFilters = {
    emits: ['change', 'apply'],
    render() { return Vue.h('button', { class: 'test-apply-filter', onClick: () => { this.$emit('change', { key: 'from', value: '2026-01-01' }); this.$emit('apply'); } }, 'Apply date'); },
};

for (const [filename, path, endpoint] of listViews) {
    test(`${path} refreshes a realtime burst on its current search, filters, page and page size`, async () => {
        const calls = [];
        let searchCallback;
        const previousSetTimeout = globalThis.setTimeout;
        globalThis.setTimeout = (callback, delay, ...args) => {
            if (delay === 250) { searchCallback = () => callback(...args); return -1; }
            return previousSetTimeout(callback, delay, ...args);
        };
        let view;
        try {
            view = await mountView(`views/${filename}`, path, {
                async get(requestedEndpoint, { params }) {
                    assert.equal(requestedEndpoint, endpoint);
                    calls.push(structuredClone(params));
                    const marker = `LIVE-ROW-${calls.length}`;
                    const response = list([{ id: 1, name: marker, code: marker, request_no: marker, transfer_no: marker, inventory_no: marker, movement_no: marker, action: marker, quantity: 1, total: 1, product: path === '/returns' ? { name: marker, code: marker } : marker }]);
                    response.data.pagination = { total: 90, per_page: 30, current_page: params.page, last_page: 3 };
                    if (path === '/suppliers') Object.assign(response.data, response.data.pagination);
                    return response;
                },
            }, {}, { '@/components/Pagination.vue': livePagination, '@/components/ListFilterBar.vue': liveFilters });
            await settle();
            change(view.root.querySelector('.search-input input, .expiry-search input, input[placeholder*="Փաստաթուղթ"]'), 'keep this search');
            await Vue.nextTick();
            if (path === '/expiry') submit(view.root.querySelector('.expiry-toolbar'));
            else searchCallback();
            await settle();
            view.root.querySelector('.test-apply-filter')?.click(); await settle();
            view.root.querySelector('.test-next-page').click(); await settle();
            const before = calls.length;
            const currentParams = calls.at(-1);
            assert.equal(currentParams.page, 3);
            assert.equal(currentParams.per_page, 30);
            window.dispatchEvent(new CustomEvent('lager:data-changed'));
            window.dispatchEvent(new CustomEvent('lager:data-changed'));
            await settle();
            assert.equal(calls.length, before + 1, 'one fetch handles a burst');
            assert.deepEqual(calls.at(-1), currentParams, 'realtime refresh retains the current view state');
            assert.match(view.root.querySelector('tbody').textContent, new RegExp(`LIVE-ROW-${before + 1}`));
            view.unmount(); view = null;
            window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
            assert.equal(calls.length, before + 1, 'unmounted pages release their live listener');
        } finally { view?.unmount(); globalThis.setTimeout = previousSetTimeout; }
    });
}

test('changes arriving during a list load fetch one trailing current snapshot without overwriting a draft', async () => {
    const waiting = deferred(); const calls = [];
    const view = await mountView('views/CatalogTable.vue', '/branches', {
        get(endpoint) {
            calls.push(endpoint);
            if (endpoint === 'catalog/branches/options') return Promise.resolve({ data: { data: {} } });
            const response = list([{ id: 1, name: `Snapshot ${calls.length}`, active: true }]);
            return calls.length === 2 ? waiting.promise : Promise.resolve(response);
        },
    }, { permissions: { 'branches.create': true } });
    try {
        await settle();
        view.root.querySelector('.page-heading .primary-button').click(); await settle();
        const input = view.root.querySelector('.catalog-modal input');
        change(input, 'Unsaved branch name'); await settle();
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls.length, 2);
        for (let index = 0; index < 5; index += 1) window.dispatchEvent(new CustomEvent('lager:data-changed'));
        await settle(); assert.equal(calls.length, 2, 'an in-flight load is not duplicated');
        waiting.resolve(list([{ id: 1, name: 'Older live snapshot' }])); await settle(); await settle();
        assert.equal(calls.length, 3, 'a change during loading requests one trailing snapshot');
        assert.match(view.root.querySelector('tbody').textContent, /Snapshot 3/);
        assert.equal(input.value, 'Unsaved branch name');
        assert.ok(view.root.querySelector('.catalog-modal'));
    } finally { view.unmount(); }
});

test('realtime report refresh retains the selected report, dates and pagination', async () => {
    const calls = [];
    const metadata = { report_types: { stock_by_location: 'Stock', receipts: 'Receipts' }, filters: {}, summary: {} };
    const view = await mountView('views/reports/Index.vue', '/reports', {
        async get(endpoint, { params }) {
            assert.equal(endpoint, 'reports'); calls.push(structuredClone(params));
            const response = list([{ name: `REPORT-${calls.length}` }], undefined, metadata);
            response.data.pagination = { total: 90, per_page: 30, current_page: params.page, last_page: 3 };
            return response;
        },
    }, { permissions: { 'reports.view': true } }, { '@/components/Pagination.vue': livePagination });
    try {
        await settle();
        change(view.root.querySelector('select'), 'receipts'); await settle();
        view.root.querySelector('.test-next-page').click(); await settle();
        const current = calls.at(-1); const before = calls.length;
        assert.equal(current.report_type, 'receipts'); assert.equal(current.page, 3); assert.equal(current.per_page, 30);
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls.length, before + 1); assert.deepEqual(calls.at(-1), current);
        assert.match(view.root.querySelector('tbody').textContent, new RegExp(`REPORT-${before + 1}`));
    } finally { view.unmount(); }
});

test('dashboard metrics refresh on a realtime invalidation and stop after unmount', async () => {
    let calls = 0;
    const view = await mountView('views/dashboard/Index.vue', '/', {
        async get(endpoint) { assert.equal(endpoint, 'dashboard'); calls += 1; return { data: { data: { products: 1, units: calls * 10 } } }; },
    }, { permissions: { 'stock.view': true } });
    try {
        await settle(); assert.equal(view.root.querySelector('.dashboard-hero-panel strong').textContent, '10');
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(view.root.querySelector('.dashboard-hero-panel strong').textContent, '20');
        view.unmount(); window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls, 2);
    } finally { if (view.root.isConnected) view.unmount(); }
});
