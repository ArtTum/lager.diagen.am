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

function loadSourceDependency(name, dependencies, importer) {
    if (Object.hasOwn(dependencies, name)) return dependencies[name];
    if (name === '@/composables/useLiveRefresh') return liveRefresh.exports;
    if (!name.startsWith('@/') && !name.startsWith('.')) return require(name);
    const root = new URL('../../resources/js/', import.meta.url);
    const filename = name.startsWith('@/') ? new URL(name.slice(2), root) : new URL(name, importer);
    if (!/\.(vue|js|json)$/.test(filename.pathname)) filename.pathname += '.js';
    if (filename.pathname.endsWith('.vue')) return component(filename.pathname.slice(root.pathname.length), dependencies);
    if (filename.pathname.endsWith('.json')) return JSON.parse(readFileSync(filename, 'utf8'));
    const module = { exports: {} };
    const code = transformSync(readFileSync(filename, 'utf8'), { format: 'cjs' }).code;
    new Function('require', 'module', 'exports', code)((child) => loadSourceDependency(child, dependencies, filename), module, module.exports);
    return module.exports;
}

function component(relativePath, dependencies = {}) {
    const filename = new URL(`../../resources/js/${relativePath}`, import.meta.url);
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname });
    const script = compileScript(descriptor, { id: relativePath, inlineTemplate: true });
    const { code } = transformSync(script.content, { format: 'cjs', loader: 'js' });
    const module = { exports: {} };
    const loadDependency = (name) => loadSourceDependency(name, dependencies, filename);
    new Function('require', 'module', 'exports', code)(loadDependency, module, module.exports);
    return module.exports.default;
}

async function settle() { await new Promise((resolve) => setImmediate(resolve)); await Vue.nextTick(); }
function change(input, value) { input.value = value; input.dispatchEvent(new dom.window.Event(input.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); }
function submit(form) { form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true })); }
function list(data, columns = { name: 'Name' }, extra = {}) {
    return { data: { data, columns, pagination: { total: data.length, per_page: 15, current_page: 1, last_page: 1 }, ...extra } };
}

function inventorySnapshot(id = 1, overrides = {}) {
    return { id, inventory_no: `INVENTORY-${id}`, status: 'counted', started_by: 8, location: { name: 'Erebuni' }, starter: { name: 'Counter' },
        lines: [{ id: id * 10, product: { code: `ITEM-${id}`, name: 'Reagent', unit: 'հատ' }, lot: { lot_no: 'LOT-A' }, expected_qty: '5.000', counted_qty: '3.000', difference_reason: 'Breakage' }], ...overrides };
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
        ...['/stock', '/stock/matrix', '/requests/:request/dispatch'].filter((candidate) => candidate !== path).map((candidate) => ({ path: candidate, component: stub })),
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

for (const scenario of [
    { label: 'minimum only', min: '2026-10-10', max: '', blocked: [9], allowed: [10, 20], chosen: 20 },
    { label: 'maximum only', min: '', max: '2026-10-20', blocked: [21], allowed: [5, 20], chosen: 5 },
    { label: 'both inclusive bounds', min: '2026-10-10', max: '2026-10-20', blocked: [9, 21], allowed: [10, 15, 20], chosen: 20 },
    { label: 'no bounds', min: '', max: '', blocked: [], allowed: [1, 15, 31], chosen: 31 },
]) {
    test(`date-picker calendar permits an allowed day with ${scenario.label} and emits its ISO date`, async () => {
        const DatePicker = component('components/DatePicker.vue', { './AppIcon.vue': stub, '../dateUtils': dateUtils });
        const state = Vue.reactive({ value: '2026-10-15' }); const emitted = [];
        const view = mount({ setup: () => () => Vue.h(DatePicker, {
            modelValue: state.value, min: scenario.min, max: scenario.max,
            'onUpdate:modelValue': (value) => { emitted.push(value); state.value = value; },
        }) });
        try {
            await settle(); view.root.querySelector('.date-picker-trigger').click(); await settle();
            const calendar = document.querySelector('.date-picker-popover');
            assert.ok(calendar);
            assert.match(calendar.querySelector('.date-picker-month strong').textContent, /հոկտեմբեր/iu);
            assert.match(calendar.querySelector('.date-picker-month strong').textContent, /2026/);
            assert.notEqual(calendar.querySelector('.date-picker-head strong').textContent, 'ՕՕ.ԱԱ.ՏՏՏՏ');
            const days = [...calendar.querySelectorAll('.date-picker-days button:not(.is-outside)')];
            const day = (number) => days.find((button) => button.textContent.trim() === String(number));
            for (const blocked of scenario.blocked) {
                assert.equal(day(blocked).disabled, true, `day ${blocked} is outside the permitted range`);
                day(blocked).click(); await settle();
                assert.deepEqual(emitted, [], 'clicking a disabled date does not emit a value');
            }
            for (const allowed of scenario.allowed) {
                assert.equal(day(allowed).disabled, false, `day ${allowed} remains selectable`);
                assert.equal(day(allowed).hasAttribute('disabled'), false, 'an empty Boolean attribute must not disable an allowed day');
            }
            if (!scenario.min && !scenario.max) {
                assert.ok([...calendar.querySelectorAll('.date-picker-days button')].every((button) => !button.disabled), 'an unrestricted calendar also permits adjacent-month dates');
            }
            assert.match(day(scenario.chosen).getAttribute('aria-label'), /[Ա-Ֆա-ֆ]/u);
            assert.doesNotMatch(day(scenario.chosen).getAttribute('aria-label'), /October|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday/i);
            day(scenario.chosen).click(); await settle();
            const expected = `2026-10-${String(scenario.chosen).padStart(2, '0')}`;
            assert.deepEqual(emitted, [expected]);
            assert.equal(state.value, expected);
            assert.equal(view.root.querySelector('input').value, `${String(scenario.chosen).padStart(2, '0')}.10.2026`);
            assert.equal(document.querySelector('.date-picker-popover'), null, 'choosing an allowed day closes the calendar');
        } finally { view.unmount(); }
    });
}

test('an empty minimum-only expected receipt field can select a future day from the calendar', async () => {
    const DatePicker = component('components/DatePicker.vue', { './AppIcon.vue': stub, '../dateUtils': dateUtils });
    const today = new Date();
    const tomorrow = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
    const state = Vue.reactive({ value: '' });
    const view = mount({ setup: () => () => Vue.h(DatePicker, {
        modelValue: state.value, min: dateUtils.toIsoDate(today),
        'onUpdate:modelValue': (value) => { state.value = value; },
    }) });
    try {
        await settle(); view.root.querySelector('.date-picker-trigger').click(); await settle();
        assert.equal(document.querySelector('.date-picker-head strong').textContent, 'Ընտրեք ամսաթիվը');
        if (tomorrow.getMonth() !== today.getMonth()) {
            document.querySelector('.date-picker-month button[aria-label="Հաջորդ ամիս"]').click(); await settle();
        }
        const futureDay = [...document.querySelectorAll('.date-picker-days button:not(.is-outside)')].find((button) => button.textContent.trim() === String(tomorrow.getDate()));
        assert.equal(futureDay.disabled, false);
        futureDay.click(); await settle();
        assert.equal(state.value, dateUtils.toIsoDate(tomorrow));
        assert.equal(view.root.querySelector('input').value, dateUtils.displayIsoDate(state.value));
        view.root.querySelector('.date-picker-trigger').click(); await settle();
        assert.equal(document.querySelector('.date-picker-head strong').textContent, view.root.querySelector('input').value, 'the reopened header shows the selected date');
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

test('a pending supplier edit cannot replace a newer supplier creation draft or change its write endpoint', async () => {
    const pending = deferred(); const writes = [];
    const supplier = { id: 1, name: 'Existing supplier', active: true };
    const view = await mountView('views/suppliers/Index.vue', '/suppliers', {
        async get(endpoint) { return endpoint === 'suppliers' ? { data: { data: [supplier], total: 1 } } : pending.promise; },
        async post(endpoint, body) { writes.push({ endpoint, body }); return {}; },
        async put() { assert.fail('the new creation draft must never edit an existing supplier'); },
    }, { id: 7, permissions: { 'suppliers.view': true, 'suppliers.create': true, 'suppliers.edit': true } });
    try {
        await settle(); view.root.querySelector('[title="Խմբագրել"]').click(); await settle();
        view.root.querySelector('.page-heading .primary-button').click(); await settle();
        change(view.root.querySelector('.supplier-modal input'), 'New supplier'); await settle();
        pending.resolve({ data: { data: supplier } }); await settle();
        assert.equal(view.root.querySelector('.supplier-modal input').value, 'New supplier');
        submit(view.root.querySelector('.supplier-modal')); await settle();
        assert.equal(writes[0].endpoint, 'suppliers');
        assert.equal(writes[0].body.name, 'New supplier');
    } finally { view.unmount(); }
});

for (const module of ['transfers', 'returns']) {
    test(`a late ${module} options response cannot erase a newer creation draft`, async () => {
        const first = deferred(); const second = deferred(); let optionLoads = 0;
        const view = await mountView(`views/${module}/Index.vue`, `/${module}`, {
            async get(endpoint) {
                if (endpoint === `pages/${module}` || endpoint === 'returns') return list([]);
                optionLoads += 1; return optionLoads === 1 ? first.promise : second.promise;
            },
            async post() { assert.fail('opening a draft must not write'); },
        }, { id: 7, branch: { id: 2 }, location_id: 2, permissions: { [`${module}.view`]: true, [`${module}.create`]: true } });
        try {
            await settle();
            view.root.querySelector('.page-heading .primary-button').click(); await settle();
            view.root.querySelector('.page-heading .primary-button').click(); await settle();
            const options = { data: { data: { branches: [{ id: 2, name: 'Erebuni' }, { id: 3, name: 'Arabkir' }], products: [], suppliers: [] } } };
            second.resolve(options); await settle();
            const currentForm = view.root.querySelector('form.modal-card');
            change(currentForm.querySelector('textarea'), 'Keep this newer reason'); await settle();
            first.resolve(options); await settle();
            assert.equal(view.root.querySelector('form.modal-card textarea').value, 'Keep this newer reason');
            currentForm.querySelector('.close-button').click(); await settle();
            assert.equal(view.root.querySelector('form.modal-card'), null);
        } finally { view.unmount(); }
    });
}

test('supplier, transfer and return openers cannot restore old forms after an account change or permission loss', async () => {
    for (const module of ['suppliers', 'transfers', 'returns']) {
        for (const revoked of [false, true]) {
            const pending = deferred(); const supplier = { id: 1, name: 'Private supplier', active: true };
            const user = { id: 7, location_id: 2, branch: { id: 2 }, permissions: { [`${module}.view`]: true, [`${module}.create`]: true, [`${module}.edit`]: true } };
            const view = await mountView(`views/${module}/Index.vue`, `/${module}`, {
                async get(endpoint) {
                    if (endpoint === 'suppliers') return { data: { data: [supplier], total: 1 } };
                    if (endpoint === 'returns' || endpoint.startsWith('pages/')) return list([]);
                    return pending.promise;
                },
                async post() { assert.fail('an old opener must not write'); },
            }, user);
            try {
                await settle();
                view.root.querySelector(module === 'suppliers' ? '[title="Խմբագրել"]' : '.page-heading .primary-button').click(); await settle();
                window.dispatchEvent(new CustomEvent('lager:user', { detail: revoked ? { ...user, permissions: {} } : { ...user, id: 8, location_id: 3, branch: { id: 3 } } })); await settle();
                pending.resolve(module === 'suppliers' ? { data: { data: supplier } } : { data: { data: { branches: [{ id: 2, name: 'Erebuni' }], products: [], suppliers: [] } } }); await settle();
                assert.equal(view.root.querySelector('.modal-backdrop'), null, `${module}: ${revoked ? 'revoked' : 'changed account'}`);
            } finally { view.unmount(); }
        }
    }
});

test('supplier, transfer and return save completions cannot close the next account draft or reopen while saving', async () => {
    for (const module of ['suppliers', 'transfers', 'returns']) {
        const pending = deferred(); const writes = [];
        const user = { id: 7, location_id: 2, branch: { id: 2 }, permissions: { [`${module}.view`]: true, [`${module}.create`]: true } };
        const view = await mountView(`views/${module}/Index.vue`, `/${module}`, {
            async get(endpoint) {
                if (endpoint === 'suppliers') return { data: { data: [], total: 0 } };
                if (endpoint === 'returns' || endpoint.startsWith('pages/')) return list([]);
                return { data: { data: { branches: [{ id: 2, name: 'Erebuni' }, { id: 3, name: 'Arabkir' }], products: [], suppliers: [] } } };
            },
            post(endpoint, body) { writes.push({ endpoint, body }); return pending.promise; },
        }, user);
        try {
            await settle(); view.root.querySelector('.page-heading .primary-button').click(); await settle();
            submit(view.root.querySelector('form.modal-card')); await settle();
            assert.equal(writes.length, 1, module);
            view.root.querySelector('form.modal-card .close-button').click(); await settle();
            view.root.querySelector('.page-heading .primary-button').click(); await settle();
            assert.equal(view.root.querySelector('form.modal-card'), null, `${module} cannot start another draft while its save is pending`);
            window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, id: 8 } })); await settle();
            view.root.querySelector('.page-heading .primary-button').click(); await settle();
            const current = view.root.querySelector('form.modal-card');
            assert.ok(current, module);
            const field = current.querySelector(module === 'suppliers' ? 'input' : 'textarea');
            change(field, 'New account draft'); await settle();
            pending.resolve({}); await settle();
            assert.equal(view.root.querySelector('form.modal-card'), current, module);
            assert.equal(field.value, 'New account draft', module);
            assert.equal(current.querySelector('.primary-button').disabled, false, module);
            assert.equal(view.root.querySelector('.notice-success,.alert-info'), null, module);
            assert.equal(writes.length, 1, module);
        } finally { view.unmount(); }
    }
});

test('transfer confirmations stop on a permission change and revalidate the latest visible workflow state', async () => {
    const writes = [];
    let row = { id: 1, transfer_no: 'TRANSFER-1', status: 'approved', from_branch_id: 2, to_branch_id: 3 };
    const user = { id: 7, location_id: 2, branch: { id: 2 }, permissions: { 'transfers.view': true, 'transfers.edit': true } };
    const view = await mountView('views/transfers/Index.vue', '/transfers', {
        async get() { return list([row]); },
        async post(...args) { writes.push(args); return { data: {} }; },
    }, user);
    try {
        await settle(); view.root.querySelector('tbody button').click(); await settle();
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, permissions: { 'transfers.view': true } } })); await settle();
        assert.equal(view.root.querySelector('.confirm-card'), null);
        assert.equal(view.root.querySelector('tbody button'), null);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: user })); await settle();
        view.root.querySelector('tbody button').click(); await settle();
        row = { ...row, status: 'shipped' };
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle(); await settle();
        view.root.querySelector('.confirm-card .primary-button').click(); await settle();
        assert.deepEqual(writes, [], 'the old source shipping confirmation must not post after the transfer is already shipped');
    } finally { view.unmount(); }
});

test('purchase and receipt saves from a previous account cannot close or overwrite the next account draft', async () => {
    for (const module of ['purchases', 'receipts']) {
        const pending = deferred(); const writes = [];
        const user = { id: 7, location_id: 0, permissions: { [`${module}.view`]: true, [`${module}.create`]: true } };
        const view = await mountView('views/purchasing/Index.vue', `/${module}`, {
            async get(endpoint) { return endpoint.startsWith('pages/') ? list([], {}) : { data: { data: { suppliers: [], products: [], orders: [] } } }; },
            post(endpoint, body) { writes.push({ endpoint, body }); return pending.promise; },
        }, user);
        try {
            await settle(); view.root.querySelector('.page-heading .primary-button').click(); await settle();
            submit(view.root.querySelector('.purchase-modal')); await settle();
            assert.equal(writes[0].endpoint, module);
            window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, id: 8 } })); await settle();
            assert.equal(view.root.querySelector('.purchase-modal'), null);
            view.root.querySelector('.page-heading .primary-button').click(); await settle();
            const current = view.root.querySelector('.purchase-modal');
            assert.ok(current, module);
            const field = current.querySelector('.form-grid input');
            change(field, 'New account draft'); await settle();
            pending.resolve({}); await settle();
            assert.equal(view.root.querySelector('.purchase-modal'), current, module);
            assert.equal(field.value, 'New account draft');
            assert.equal(current.querySelector('.primary-button').disabled, false);
            assert.equal(view.root.querySelector('.notice-success'), null);
            assert.equal(writes.length, 1);
        } finally { view.unmount(); }
    }
});

test('purchase approvals revalidate current permission and pending status before posting', async () => {
    const writes = [];
    let row = { id: 1, order_no: 'ORDER-1', status: 'pending' };
    const user = { id: 7, location_id: 0, permissions: { 'purchases.view': true, 'purchases.approve': true } };
    const view = await mountView('views/purchasing/Index.vue', '/purchases', {
        async get() { return list([row], { order_no: 'Order' }); },
        async post(...args) { writes.push(args); return {}; },
    }, user);
    try {
        await settle(); view.root.querySelector('tbody button').click(); await settle();
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, permissions: { 'purchases.view': true } } })); await settle();
        assert.equal(view.root.querySelector('.confirm-card'), null);
        assert.equal(view.root.querySelector('tbody button'), null);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: user })); await settle();
        view.root.querySelector('tbody button').click(); await settle();
        row = { ...row, status: 'approved' };
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle(); await settle();
        view.root.querySelector('.confirm-card .primary-button').click(); await settle();
        assert.deepEqual(writes, []);
        assert.match(view.root.querySelector('.confirm-card .form-error').textContent, /այլևս հաստատման չի սպասում/);
    } finally { view.unmount(); }
});

test('a delayed purchase approval cannot close the next account confirmation or announce its old decision', async () => {
    const pending = deferred(); const writes = [];
    const user = { id: 7, location_id: 0, permissions: { 'purchases.view': true, 'purchases.approve': true } };
    const view = await mountView('views/purchasing/Index.vue', '/purchases', {
        async get() { return list([1, 2].map(id => ({ id, order_no: `ORDER-${id}`, status: 'pending' })), { order_no: 'Order' }); },
        post(endpoint) { writes.push(endpoint); return pending.promise; },
    }, user);
    try {
        await settle(); view.root.querySelectorAll('tbody button')[0].click(); await settle();
        view.root.querySelector('.confirm-card .primary-button').click(); await settle();
        assert.deepEqual(writes, ['purchases/1/approve']);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, id: 8 } })); await settle();
        view.root.querySelectorAll('tbody button')[1].click(); await settle();
        const current = view.root.querySelector('.confirm-card');
        assert.match(current.textContent, /ORDER-2/);
        pending.resolve({}); await settle();
        assert.equal(view.root.querySelector('.confirm-card'), current);
        assert.equal(current.querySelector('.primary-button').disabled, false);
        assert.equal(view.root.querySelector('.notice-success'), null);
        assert.deepEqual(writes, ['purchases/1/approve']);
    } finally { view.unmount(); }
});

test('an administrator can take sent requests for review while cancellation remains available', async () => {
    const calls = []; let status = 'sent';
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint) {
            if (endpoint === 'pages/requests') return list([{ id: 1, request_no: 'REQ-1', status, branch_id: 3, requested_by: 10 }]);
            return { data: { data: { id: 1, request_no: 'REQ-1', status, items: [] } } };
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
            }, { permissions: { [`${path.split('/')[1]}.view`]: true } });
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
            }, { permissions: { [`${path.split('/')[1]}.view`]: true } }, { '@/components/Pagination.vue': livePagination, '@/components/ListFilterBar.vue': liveFilters });
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
    }, { permissions: { 'branches.view': true, 'branches.create': true } });
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

test('transfer receiving is offered only to an editor assigned to the actual destination branch', async () => {
    const profiles = [
        { label: 'central administrator', user: { role: { name: 'admin' }, location_id: 0, branch: { id: 1 }, permissions: { 'transfers.view': true, 'transfers.edit': true } }, expected: false },
        { label: 'source branch editor', user: { role: { name: 'branch' }, location_id: 2, branch: { id: 2 }, permissions: { 'transfers.view': true, 'transfers.edit': true } }, expected: false },
        { label: 'destination branch editor', user: { role: { name: 'branch' }, location_id: 3, branch: { id: 3 }, permissions: { 'transfers.view': true, 'transfers.edit': true } }, expected: true },
        { label: 'destination branch viewer', user: { role: { name: 'viewer' }, location_id: 3, branch: { id: 3 }, permissions: { 'transfers.view': true } }, expected: false },
    ];
    for (const { label, user, expected } of profiles) {
        const view = await mountView('views/transfers/Index.vue', '/transfers', {
            async get() { return list([{ id: 7, transfer_no: 'SHIPPED-TRANSFER', status: 'shipped', from_branch_id: 2, to_branch_id: 3 }]); },
        }, user);
        try {
            await settle();
            const receive = [...view.root.querySelectorAll('tbody button')].find((button) => button.textContent === 'Ստանալ');
            assert.equal(Boolean(receive), expected, label);
            if (receive) { receive.click(); await settle(); assert.ok(view.root.querySelector('.confirm-card')); }
        } finally { view.unmount(); }
    }
});

test('transfer shipping follows the actual source or central location even for an administrator role', async () => {
    const profiles = [
        { label: 'central storekeeper', user: { role: { name: 'storekeeper' }, location_id: 0, branch: { id: 1 }, permissions: { 'transfers.view': true, 'transfers.edit': true } }, expected: true },
        { label: 'source branch editor', user: { role: { name: 'branch' }, location_id: 2, branch: { id: 2 }, permissions: { 'transfers.view': true, 'transfers.edit': true } }, expected: true },
        { label: 'destination branch administrator', user: { role: { name: 'admin' }, location_id: 3, branch: { id: 3 }, permissions: { 'transfers.view': true, 'transfers.edit': true, 'transfers.create': true } }, expected: false },
        { label: 'source branch viewer', user: { role: { name: 'viewer' }, location_id: 2, branch: { id: 2 }, permissions: { 'transfers.view': true } }, expected: false },
    ];
    for (const { label, user, expected } of profiles) {
        const view = await mountView('views/transfers/Index.vue', '/transfers', {
            async get(endpoint) {
                if (endpoint === 'catalog/transfers/options') return { data: { data: { branches: [{ id: 2, name: 'Source' }, { id: 3, name: 'Destination' }], products: [] } } };
                return list([{ id: 7, transfer_no: 'APPROVED-TRANSFER', status: 'approved', from_branch_id: 2, to_branch_id: 3 }]);
            },
        }, user);
        try {
            await settle();
            const ship = [...view.root.querySelectorAll('tbody button')].find((button) => button.textContent === 'Ուղարկել');
            assert.equal(Boolean(ship), expected, label);
            if (user.permissions['transfers.create']) {
                view.root.querySelector('.page-heading .primary-button').click(); await settle();
                const source = view.root.querySelector('.transfer-modal select');
                assert.equal(source.disabled, true, 'a branch-scoped administrator cannot change the source warehouse');
                assert.equal(source.value, '3');
            }
        } finally { view.unmount(); }
    }
});

test('independent inventory approval excludes the current starter and still requires approval permission', async () => {
    const records = [
        { id: 1, inventory_no: 'OWN-COUNTED', status: 'counted', started_by: '7', lines_count: 1, counted_lines_count: 1 },
        { id: 2, inventory_no: 'PEER-COUNTED', status: 'counted', started_by: 8, lines_count: 1, counted_lines_count: 1 },
        { id: 3, inventory_no: 'EMPTY-COUNTED', status: 'counted', started_by: 8, lines_count: 0, counted_lines_count: 0 },
        { id: 4, inventory_no: 'PEER-OPEN', status: 'open', started_by: 8, lines_count: 1, counted_lines_count: 0 },
    ];
    for (const allowed of [true, false]) {
        const view = await mountView('views/inventory/Index.vue', '/inventory', { async get(endpoint) {
            if (endpoint === 'inventory') return list(records);
            const row = records.find((record) => endpoint === `inventory/${record.id}`);
            return { data: { data: { session: { ...row, lines: [{ id: 10, product: { code: 'PRODUCT', name: 'Item', unit: 'հատ' }, expected_qty: '5.000', counted_qty: '3.000', difference_reason: 'Breakage' }] } } } };
        } }, {
            id: 7, role: { name: allowed ? 'admin' : 'viewer' }, location_id: 0, permissions: { 'inventory.view': true, 'inventory.approve': allowed },
        });
        try {
            await settle();
            const rows = [...view.root.querySelectorAll('tbody tr')];
            assert.match(rows[0].querySelector('.workflow-state-note').textContent, /Դուք եք սկսել/);
            assert.match(rows[1].querySelector('.workflow-state-note').textContent, /սկսածից տարբեր աշխատակից/);
            for (const row of rows) {
                const approve = [...row.querySelectorAll('button')].find((button) => button.textContent === 'Անկախ հաստատել');
                assert.equal(Boolean(approve), allowed && row.textContent.includes('PEER-COUNTED'), row.textContent);
            }
            if (allowed) {
                view.root.querySelector('tbody .primary-button').click(); await settle();
                assert.match(view.root.querySelector('.inventory-inspection-modal').textContent, /PEER-COUNTED/);
                view.root.querySelector('.inventory-inspection-modal .primary-button').click(); await settle();
                assert.match(view.root.querySelector('.confirm-card').textContent, /PEER-COUNTED/);
            }
        } finally { view.unmount(); }
    }
});

test('inventory viewers inspect quantities and reasons without edit or approval permissions', async () => {
    const snapshot = inventorySnapshot();
    const calls = [];
    const view = await mountView('views/inventory/Index.vue', '/inventory', {
        async get(endpoint) {
            calls.push(endpoint);
            return endpoint === 'inventory' ? list([{ ...snapshot, lines_count: 1, counted_lines_count: 1 }]) : { data: { data: { session: snapshot } } };
        },
        async post() { assert.fail('inspection must not write'); },
        async put() { assert.fail('inspection must not write'); },
    }, { id: 7, location_id: 2, permissions: { 'inventory.view': true } });
    try {
        await settle();
        assert.equal(view.root.querySelector('tbody button:not(.inventory-inspection-trigger)'), null);
        view.root.querySelector('.inventory-inspection-trigger').click(); await settle();
        const detail = view.root.querySelector('.inventory-inspection-modal');
        assert.match(detail.textContent, /INVENTORY-1/);
        const cells = [...detail.querySelectorAll('tbody td')].map((cell) => cell.textContent);
        assert.deepEqual(cells.slice(1), ['5.000', '3.000', '-2', 'հատ', 'Breakage']);
        assert.equal(detail.querySelector('form,input,select,textarea'), null);
        assert.equal(detail.querySelector('.primary-button'), null);
        assert.deepEqual(calls, ['inventory', 'inventory/1']);
    } finally { view.unmount(); }
});

test('an inventory approver loads a complete comparison before explicit independent confirmation', async () => {
    const pending = deferred(); const writes = [];
    const snapshot = inventorySnapshot();
    const view = await mountView('views/inventory/Index.vue', '/inventory', {
        async get(endpoint) {
            return endpoint === 'inventory' ? list([{ ...snapshot, lines_count: 1, counted_lines_count: 1 }]) : pending.promise;
        },
        async post(endpoint) { writes.push(endpoint); return {}; },
        async put() { assert.fail('approval inspection must not edit counts'); },
    }, { id: 7, location_id: 0, permissions: { 'inventory.view': true, 'inventory.approve': true } });
    try {
        await settle(); view.root.querySelector('tbody .primary-button').click(); await settle();
        assert.ok(view.root.querySelector('.inventory-inspection-modal'));
        assert.equal(view.root.querySelector('.confirm-card'), null);
        assert.equal(view.root.querySelector('.inventory-inspection-modal .primary-button'), null);
        assert.deepEqual(writes, []);
        pending.resolve({ data: { data: { session: snapshot } } }); await settle();
        assert.match(view.root.querySelector('.inventory-inspection-modal tbody').textContent, /5\.000.*3\.000.*-2.*Breakage/s);
        view.root.querySelector('.inventory-inspection-modal .primary-button').click(); await settle();
        assert.ok(view.root.querySelector('.confirm-card'));
        assert.deepEqual(writes, []);
        view.root.querySelector('.confirm-card .primary-button').click(); await settle();
        assert.deepEqual(writes, ['inventory/1/approve']);
        assert.equal(view.root.querySelector('.inventory-inspection-modal'), null);
    } finally { view.unmount(); }
});

test('inventory inspection prevents approval for incomplete counts, missing reasons, own sessions and closed sessions', async () => {
    for (const scenario of [
        { label: 'missing line', lines_count: 2 },
        { label: 'uncounted line', line: { counted_qty: null } },
        { label: 'missing reason', line: { difference_reason: '' } },
        { label: 'missing product details', line: { product: null } },
        { label: 'own session', session: { started_by: 7 } },
        { label: 'closed since list load', session: { status: 'closed' } },
    ]) {
        const snapshot = inventorySnapshot(1, scenario.session);
        if (scenario.line) snapshot.lines[0] = { ...snapshot.lines[0], ...scenario.line };
        const view = await mountView('views/inventory/Index.vue', '/inventory', {
            async get(endpoint) {
                return endpoint === 'inventory' ? list([{ id: 1, inventory_no: 'INVENTORY-1', status: 'counted', started_by: 8, lines_count: scenario.lines_count || 1, counted_lines_count: 1 }]) : { data: { data: { session: snapshot } } };
            },
            async post() { assert.fail(`${scenario.label} must not approve`); },
        }, { id: 7, location_id: 0, permissions: { 'inventory.view': true, 'inventory.approve': true } });
        try {
            await settle(); view.root.querySelector('.inventory-inspection-trigger').click(); await settle();
            assert.ok(view.root.querySelector('.inventory-inspection-modal'), scenario.label);
            assert.equal(view.root.querySelector('.inventory-inspection-modal .primary-button'), null, scenario.label);
            assert.equal(view.root.querySelector('.confirm-card'), null, scenario.label);
        } finally { view.unmount(); }
    }
});

test('inventory changes invalidate approval and an outdated refresh cannot restore it', async () => {
    const pending = deferred(); let detailLoads = 0;
    const snapshot = inventorySnapshot();
    const view = await mountView('views/inventory/Index.vue', '/inventory', {
        async get(endpoint) {
            if (endpoint === 'inventory') return list([{ ...snapshot, lines_count: 1, counted_lines_count: 1 }]);
            detailLoads += 1;
            if (detailLoads === 2) return pending.promise;
            const session = detailLoads === 3 ? inventorySnapshot(1, { lines: [{ ...snapshot.lines[0], counted_qty: '1.000', difference_reason: 'Updated count' }] }) : snapshot;
            return { data: { data: { session } } };
        },
        async post() { assert.fail('inspection refresh must not write'); },
    }, { id: 7, location_id: 0, permissions: { 'inventory.view': true, 'inventory.approve': true } });
    try {
        await settle(); view.root.querySelector('.inventory-inspection-trigger').click(); await settle();
        view.root.querySelector('.inventory-inspection-modal .primary-button').click(); await settle();
        assert.ok(view.root.querySelector('.confirm-card'));
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(view.root.querySelector('.confirm-card'), null);
        assert.equal(view.root.querySelector('.inventory-inspection-modal .primary-button'), null);
        assert.ok(view.root.querySelector('.inventory-inspection-modal .notice-warning'));
        view.root.querySelector('.inventory-inspection-modal .modal-actions .secondary-button').click(); await settle();
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        pending.resolve({ data: { data: { session: snapshot } } }); await settle();
        assert.equal(view.root.querySelector('.inventory-inspection-modal .primary-button'), null);
        view.root.querySelector('.inventory-inspection-modal .modal-actions .secondary-button').click(); await settle();
        assert.match(view.root.querySelector('.inventory-inspection-modal tbody').textContent, /1\.000.*-4.*Updated count/s);
        assert.ok(view.root.querySelector('.inventory-inspection-modal .primary-button'));
    } finally { view.unmount(); }
});

test('inventory detail loads discard old selections and session changes and keep failed loads unapprovable', async () => {
    const first = deferred(); const second = deferred();
    const user = { id: 7, location_id: 0, permissions: { 'inventory.view': true, 'inventory.approve': true } };
    const view = await mountView('views/inventory/Index.vue', '/inventory', {
        async get(endpoint) {
            if (endpoint === 'inventory') return list([1, 2, 3].map((id) => ({ ...inventorySnapshot(id), lines_count: 1, counted_lines_count: 1 })));
            if (endpoint === 'inventory/3') throw { response: { status: 403, data: { message: 'Access denied' } } };
            return endpoint === 'inventory/1' ? first.promise : second.promise;
        },
        async post() { assert.fail('inspection must not write'); },
    }, user);
    try {
        await settle(); view.root.querySelectorAll('.inventory-inspection-trigger')[0].click(); await settle();
        view.root.querySelectorAll('.inventory-inspection-trigger')[1].click(); await settle();
        second.resolve({ data: { data: { session: inventorySnapshot(2) } } }); await settle();
        first.resolve({ data: { data: { session: inventorySnapshot(1) } } }); await settle();
        assert.match(view.root.querySelector('.inventory-inspection-modal').textContent, /INVENTORY-2/);
        assert.doesNotMatch(view.root.querySelector('.inventory-inspection-modal').textContent, /INVENTORY-1/);
        view.root.querySelectorAll('.inventory-inspection-trigger')[2].click(); await settle();
        assert.match(view.root.querySelector('.inventory-inspection-modal [role="alert"]').textContent, /Access denied/);
        assert.equal(view.root.querySelector('.inventory-inspection-modal .primary-button'), null);
        assert.equal(view.root.querySelector('.inventory-inspection-modal table'), null);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, id: 9, location_id: 3 } })); await settle();
        assert.equal(view.root.querySelector('.inventory-inspection-modal'), null);
    } finally { view.unmount(); }
});

test('an inventory detail response after closure or an access change cannot restore private data or approval', async () => {
    for (const endInspection of ['close', 'session', 'permission']) {
        const pending = deferred();
        const snapshot = inventorySnapshot();
        const user = { id: 7, location_id: 0, permissions: { 'inventory.view': true, 'inventory.approve': true } };
        const view = await mountView('views/inventory/Index.vue', '/inventory', {
            async get(endpoint) { return endpoint === 'inventory' ? list([{ ...snapshot, lines_count: 1, counted_lines_count: 1 }]) : pending.promise; },
            async post() { assert.fail('an obsolete detail load must not approve'); },
        }, user);
        try {
            await settle(); view.root.querySelector('.inventory-inspection-trigger').click(); await settle();
            if (endInspection === 'close') view.root.querySelector('.inventory-inspection-modal .close-button').click();
            else window.dispatchEvent(new CustomEvent('lager:user', { detail: endInspection === 'session' ? { ...user, id: 9, location_id: 3 } : { ...user, permissions: {} } }));
            await settle(); pending.resolve({ data: { data: { session: snapshot } } }); await settle();
            assert.equal(view.root.querySelector('.inventory-inspection-modal'), null, endInspection);
            assert.equal(view.root.querySelector('.confirm-card'), null, endInspection);
        } finally { view.unmount(); }
    }
});

test('overlapping request draft edits keep the latest chosen draft and its quantities through submission', async () => {
    const first = deferred(); const second = deferred(); const writes = [];
    const records = [1, 2].map((id) => ({ id, request_no: `DRAFT-${id}`, status: 'draft', branch_id: 2, requested_by: 7 }));
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint) {
            if (endpoint === 'pages/requests') return list(records);
            if (endpoint === 'catalog/requests/options') return { data: { data: { branches: [{ id: 2, name: 'Erebuni' }], products: [1, 2].map((id) => ({ id, code: `P-${id}`, name: `Product ${id}` })) } } };
            if (endpoint === 'requests/suggestions') return { data: { data: { items: {} } } };
            return endpoint === 'requests/1' ? first.promise : second.promise;
        },
        async put(endpoint, body) { writes.push({ endpoint, body }); return {}; },
        async post() { assert.fail('an edited draft must use its draft endpoint'); },
    }, { id: 7, branch: { id: 2 }, location_id: 2, permissions: { 'requests.view': true, 'requests.edit': true } });
    try {
        await settle();
        for (const row of view.root.querySelectorAll('tbody tr')) { row.querySelector('button').click(); await settle(); }
        const snapshot = (id) => ({ data: { data: { id, status: 'draft', branch_id: 2, urgency: 'normal', reason: '', items: [{ id: id * 10, product_id: id, requested_qty: String(id), note: '' }] } } });
        second.resolve(snapshot(2)); await settle(); first.resolve(snapshot(1)); await settle();
        assert.equal(view.root.querySelector('.request-modal .request-line-row select').value, '2');
        assert.equal(view.root.querySelector('.request-modal .request-line-row input[type="number"]').value, '2');
        submit(view.root.querySelector('.request-modal')); await settle();
        assert.equal(writes[0].endpoint, 'requests/2/draft');
        assert.equal(writes[0].body.items[0].product_id, 2);
        assert.equal(writes[0].body.items[0].qty, 2);
    } finally { view.unmount(); }
});

test('overlapping inventory count forms keep the latest session and its own line IDs through submission', async () => {
    const first = deferred(); const second = deferred(); const writes = [];
    const records = [1, 2].map((id) => ({ ...inventorySnapshot(id), status: 'open', lines_count: 1, counted_lines_count: 0 }));
    const view = await mountView('views/inventory/Index.vue', '/inventory', {
        async get(endpoint) { return endpoint === 'inventory' ? list(records) : endpoint === 'inventory/1' ? first.promise : second.promise; },
        async put(endpoint, body) { writes.push({ endpoint, body }); return {}; },
        async post() { assert.fail('counting must not use an approval endpoint'); },
    }, { id: 7, location_id: 2, permissions: { 'inventory.view': true, 'inventory.edit': true } });
    try {
        await settle();
        for (const row of view.root.querySelectorAll('tbody tr')) { [...row.querySelectorAll('button')].find((button) => button.textContent === 'Լրացնել քանակները').click(); await settle(); }
        second.resolve({ data: { data: { session: inventorySnapshot(2, { status: 'open' }), suppliers: [] } } }); await settle();
        first.resolve({ data: { data: { session: inventorySnapshot(1, { status: 'open' }), suppliers: [] } } }); await settle();
        assert.match(view.root.querySelector('form.inventory-modal .eyebrow').textContent, /INVENTORY-2/);
        submit(view.root.querySelector('form.inventory-modal')); await settle();
        assert.equal(writes[0].endpoint, 'inventory/2/count');
        assert.deepEqual(Object.keys(writes[0].body.counts), ['20']);
    } finally { view.unmount(); }
});

test('a late stock adjustment opener cannot replace a newer consumption form and its selected product', async () => {
    const first = deferred(); const second = deferred(); let optionLoads = 0;
    const products = [1, 2].map((id) => ({ id, code: `P-${id}`, name: `Product ${id}`, quantity: 5, min_qty: 0, unit: 'հատ' }));
    const view = await mountView('views/stock/Index.vue', '/stock', {
        async get(endpoint) {
            if (endpoint === 'pages/stock') return list(products);
            if (endpoint === 'stock/lots') return { data: { data: [] } };
            optionLoads += 1; return optionLoads === 1 ? first.promise : second.promise;
        },
        async post() { assert.fail('opening forms must not write'); },
    }, { id: 7, location_id: 2, permissions: { 'stock.view': true, 'stock.create': true, 'stock.edit': true } });
    try {
        await settle();
        view.root.querySelectorAll('tbody tr')[0].querySelector('[title="Ճշգրտել"]').click(); await settle();
        view.root.querySelectorAll('tbody tr')[1].querySelector('[title="Գրանցել ելք"]').click(); await settle();
        const options = { data: { data: { products, branches: [{ id: 2, name: 'Erebuni', code: 'EREB' }] } } };
        second.resolve(options); await settle(); first.resolve(options); await settle();
        assert.equal(view.root.querySelector('.stock-modal h2').textContent, 'Գրանցել ներքին ելք');
        assert.equal(view.root.querySelector('.stock-modal select').value, '2');
    } finally { view.unmount(); }
});

test('overlapping request reviews submit only the latest request and suggest available quantities for an unapproved decimal zero', async () => {
    const first = deferred(); const second = deferred(); const writes = [];
    const records = [1, 2].map(id => ({ id, request_no: `REVIEW-${id}`, status: 'review', branch_id: 2 }));
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint) { return endpoint === 'pages/requests' ? list(records) : endpoint === 'requests/1' ? first.promise : second.promise; },
        async post(endpoint, body) { writes.push({ endpoint, body }); return {}; },
    }, { id: 7, location_id: 0, permissions: { 'requests.view': true, 'requests.approve': true } });
    try {
        await settle();
        for (const row of view.root.querySelectorAll('tbody tr')) {
            [...row.querySelectorAll('button')].find(button => button.textContent === 'Դիտարկել').click(); await settle();
        }
        const snapshot = id => ({ data: { data: { ...records[id - 1], items: [{ id: id * 10, name: `Product ${id}`, unit: 'հատ', requested_qty: '5.000', approved_qty: '0.000', central_free_qty: '2.000' }] } } });
        second.resolve(snapshot(2)); await settle(); first.resolve(snapshot(1)); await settle();
        assert.equal(view.root.querySelector('.request-modal h2').textContent, 'REVIEW-2');
        assert.equal(view.root.querySelector('.approval-line input').value, '2');
        view.root.querySelector('.request-modal .primary-button').click(); await settle();
        assert.deepEqual(writes, [{ endpoint: 'requests/2/review', body: { decision: 'approve', approved: { 20: 2 } } }]);
    } finally { view.unmount(); }
});

test('new requests select a real destination for central users and keep a branch user at their own destination', async () => {
    const branches = [{ id: 9, name: 'Central', code: 'CENTRAL' }, { id: 3, name: 'Arabkir', code: 'ARAB' }, { id: 2, name: 'Erebuni', code: 'EREB' }];
    for (const { location, branch, expected } of [{ location: 0, branch: 9, expected: '3' }, { location: 2, branch: 2, expected: '2' }]) {
        const suggestions = [];
        const view = await mountView('views/requests/Index.vue', '/requests', {
            async get(endpoint, options) {
                if (endpoint === 'pages/requests') return list([]);
                if (endpoint === 'catalog/requests/options') return { data: { data: { branches, products: [] } } };
                suggestions.push(options.params.branch_id); return { data: { data: { items: {} } } };
            },
            async post() { assert.fail('opening a request must not write'); },
        }, { id: 7, location_id: location, branch: { id: branch }, permissions: { 'requests.view': true, 'requests.create': true } });
        try {
            await settle(); view.root.querySelector('.page-heading .primary-button').click(); await settle();
            const destination = view.root.querySelector('.request-modal .form-grid select');
            assert.equal(destination.value, expected);
            assert.equal(destination.disabled, location > 0);
            assert.ok(suggestions.length > 0);
            assert.ok(suggestions.every(id => String(id) === expected));
        } finally { view.unmount(); }
    }
});

test('late mutation form loads cannot reopen after an account change or permission revocation', async () => {
    for (const module of ['requests', 'inventory', 'stock']) {
        for (const revoked of [false, true]) {
            const pending = deferred(); let writes = 0;
            const user = { id: 7, branch: { id: 2 }, location_id: 2, permissions: { [`${module}.view`]: true, [`${module}.edit`]: true } };
            const request = { id: 1, request_no: 'OLD-DRAFT', status: 'draft', branch_id: 2, requested_by: 7, items: [] };
            const inventory = inventorySnapshot(1, { status: 'open', lines_count: 1, counted_lines_count: 0 });
            const stock = { id: 1, code: 'P-1', name: 'Old stock', quantity: 5, min_qty: 0, unit: 'հատ' };
            const view = await mountView(`views/${module}/Index.vue`, `/${module}`, {
                async get(endpoint) {
                    if (endpoint === 'pages/requests') return list([request]);
                    if (endpoint === 'inventory') return list([inventory]);
                    if (endpoint === 'pages/stock') return list([stock]);
                    if (endpoint === 'catalog/requests/options') return { data: { data: { branches: [{ id: 2, name: 'Erebuni' }], products: [] } } };
                    return pending.promise;
                },
                async post() { writes += 1; }, async put() { writes += 1; },
            }, user);
            try {
                await settle();
                if (module === 'requests') [...view.root.querySelectorAll('tbody button')].find(button => button.textContent === 'Խմբագրել').click();
                else if (module === 'inventory') [...view.root.querySelectorAll('tbody button')].find(button => button.textContent === 'Լրացնել քանակները').click();
                else view.root.querySelector('tbody [title="Ճշգրտել"]').click();
                await settle();
                window.dispatchEvent(new CustomEvent('lager:user', { detail: revoked ? { ...user, permissions: {} } : { ...user, id: 8, location_id: 3, branch: { id: 3 } } }));
                await settle();
                pending.resolve(module === 'requests' ? { data: { data: request } } : module === 'inventory' ? { data: { data: { session: inventory, suppliers: [] } } } : { data: { data: { products: [stock], branches: [{ id: 2, name: 'Erebuni' }] } } });
                await settle();
                assert.equal(view.root.querySelector('.modal-backdrop'), null, `${module}: ${revoked ? 'permission revoked' : 'account changed'}`);
                assert.equal(writes, 0);
            } finally { view.unmount(); }
        }
    }
});

test('a save completion from the previous account cannot close or overwrite the new account draft', async () => {
    for (const module of ['requests', 'inventory', 'stock']) {
        const pending = deferred(); const writes = [];
        const product = { id: 1, code: 'P-1', name: 'Reagent', quantity: 5, min_qty: 0, unit: 'հատ' };
        const user = { id: 7, branch: { id: 2 }, location_id: 2, permissions: { [`${module}.view`]: true, [`${module}.create`]: true } };
        const view = await mountView(`views/${module}/Index.vue`, `/${module}`, {
            async get(endpoint) {
                if (endpoint === 'inventory') return list([], {}, { locations: [{ id: 2, name: 'Erebuni' }] });
                if (endpoint.startsWith('pages/')) return list(module === 'stock' ? [product] : []);
                if (endpoint === 'requests/suggestions') return { data: { data: { items: {} } } };
                return { data: { data: { products: [product], branches: [{ id: 2, name: 'Erebuni' }] } } };
            },
            post(endpoint, body) { writes.push({ endpoint, body }); return pending.promise; },
        }, user);
        try {
            await settle();
            const open = () => module === 'stock' ? view.root.querySelector('tbody [title="Գրանցել ելք"]').click() : view.root.querySelector('.page-heading .primary-button').click();
            const modal = () => view.root.querySelector('form.modal-card');
            open(); await settle();
            submit(modal()); await settle();
            assert.equal(writes.length, 1, module);
            window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, id: 8 } })); await settle();
            assert.equal(modal(), null, module);
            open(); await settle();
            const currentForm = modal();
            assert.ok(currentForm, module);
            const field = module === 'stock' ? currentForm.querySelector('input[type="number"]') : currentForm.querySelector('textarea');
            change(field, module === 'stock' ? '4' : 'New account draft'); await settle();
            pending.resolve({}); await settle();
            assert.equal(modal(), currentForm, module);
            assert.equal(field.value, module === 'stock' ? '4' : 'New account draft');
            assert.equal(view.root.querySelector('.notice-success'), null, module);
            assert.equal(currentForm.querySelector('.primary-button').disabled, false, module);
            assert.equal(writes.length, 1, module);
        } finally { view.unmount(); }
    }
});

test('request viewers inspect requested and approved quantities and rejection reasons without write controls', async () => {
    const records = [
        { id: 1, request_no: 'PARTIAL-REQUEST', status: 'partially_approved' },
        { id: 2, request_no: 'REJECTED-REQUEST', status: 'rejected' },
    ];
    const calls = [];
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint) {
            calls.push(endpoint);
            if (endpoint === 'pages/requests') return list(records);
            const row = records.find((record) => endpoint === `requests/${record.id}`);
            return { data: { data: { ...row, branch_name: 'Erebuni', rejection_reason: row.status === 'rejected' ? 'No usable stock' : null,
                items: [{ id: 9, code: 'QA-ITEM', name: 'Reagent', requested_qty: '5.000', approved_qty: '2.000', unit: 'հատ', note: 'For tomorrow' }] } } };
        },
        async post() { assert.fail('inspection must not write'); },
        async put() { assert.fail('inspection must not write'); },
    }, { id: 7, branch: { id: 2 }, location_id: 2, permissions: { 'requests.view': true } });
    try {
        await settle();
        assert.equal(view.root.querySelector('tbody button:not(.request-details-trigger)'), null);
        view.root.querySelectorAll('.request-details-trigger')[0].click(); await settle();
        const partial = view.root.querySelector('.request-details-modal');
        assert.match(partial.textContent, /PARTIAL-REQUEST/);
        assert.match(partial.textContent, /Մասնակի է հաստատված/);
        assert.match(partial.textContent, /5\.000/);
        assert.match(partial.textContent, /2\.000/);
        assert.match(partial.textContent, /QA-ITEM.*Reagent/s);
        assert.equal(partial.querySelector('form,input,select,textarea'), null);
        assert.equal(partial.querySelector('.primary-button'), null);
        partial.querySelector('.close-button').click(); await settle();
        view.root.querySelectorAll('.request-details-trigger')[1].click(); await settle();
        assert.match(view.root.querySelector('.request-rejection-reason').textContent, /No usable stock/);
        assert.equal(view.root.querySelector('.request-details-modal tbody tr td:nth-child(3)').textContent, '—');
        assert.deepEqual(calls, ['pages/requests', 'requests/1', 'requests/2']);
    } finally { view.unmount(); }
});

test('overlapping request inspections keep the latest details and close safely on access changes', async () => {
    const first = deferred(); const second = deferred();
    const user = { id: 7, location_id: 2, permissions: { 'requests.view': true } };
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint) {
            if (endpoint === 'pages/requests') return list([{ id: 1, request_no: 'OLD' }, { id: 2, request_no: 'CURRENT' }]);
            return endpoint === 'requests/1' ? first.promise : second.promise;
        },
        async post() { assert.fail('inspection must not write'); },
    }, user);
    try {
        await settle();
        view.root.querySelectorAll('.request-details-trigger')[0].click(); await settle();
        view.root.querySelectorAll('.request-details-trigger')[1].click(); await settle();
        second.resolve({ data: { data: { id: 2, request_no: 'CURRENT', status: 'sent', items: [] } } }); await settle();
        first.resolve({ data: { data: { id: 1, request_no: 'OLD', status: 'sent', items: [] } } }); await settle();
        assert.match(view.root.querySelector('.request-details-modal').textContent, /CURRENT/);
        assert.doesNotMatch(view.root.querySelector('.request-details-modal').textContent, /OLD/);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, permissions: {} } })); await settle();
        assert.equal(view.root.querySelector('.request-details-modal'), null);
        assert.equal(view.root.querySelector('.request-details-trigger'), null);
    } finally { view.unmount(); }
});

test('a request inspection response after closure or a session change cannot restore private details', async () => {
    for (const endInspection of ['close', 'session']) {
        const pending = deferred();
        const user = { id: 7, location_id: 2, permissions: { 'requests.view': true } };
        const view = await mountView('views/requests/Index.vue', '/requests', {
            async get(endpoint) { return endpoint === 'pages/requests' ? list([{ id: 1, request_no: 'PRIVATE' }]) : pending.promise; },
        }, user);
        try {
            await settle(); view.root.querySelector('.request-details-trigger').click(); await settle();
            if (endInspection === 'close') view.root.querySelector('.request-details-modal .close-button').click();
            else window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, id: 8, location_id: 3 } }));
            await settle();
            pending.resolve({ data: { data: { id: 1, request_no: 'PRIVATE', status: 'approved', items: [] } } }); await settle();
            assert.equal(view.root.querySelector('.request-details-modal'), null);
        } finally { view.unmount(); }
    }
});

test('a failed request detail load shows its error without showing stale data or mutation controls', async () => {
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint) {
            if (endpoint === 'pages/requests') return list([{ id: 1, request_no: 'DENIED' }]);
            throw { response: { status: 403, data: { message: 'Access denied' } } };
        },
        async post() { assert.fail('inspection must not write'); },
    }, { id: 7, permissions: { 'requests.view': true } });
    try {
        await settle(); view.root.querySelector('.request-details-trigger').click(); await settle();
        assert.match(view.root.querySelector('.request-details-modal [role="alert"]').textContent, /Access denied/);
        assert.equal(view.root.querySelector('.request-details-modal table'), null);
        assert.equal(view.root.querySelector('.request-details-modal .primary-button'), null);
    } finally { view.unmount(); }
});

test('request statuses distinguish review submission from goods shipment while filters keep their API codes and review permission', async () => {
    const records = [
        { id: 1, request_no: 'AWAITING-REVIEW', status: 'sent', branch_id: 2, branch: 'Branch two', urgency: 'normal' },
        { id: 2, request_no: 'GOODS-SHIPPED', status: 'shipped', branch_id: 2, branch: 'Branch two', urgency: 'normal' },
    ];
    const calls = []; const user = { id: 7, location_id: 0, permissions: { 'requests.view': true, 'requests.approve': true } };
    const FilterBar = component('components/ListFilterBar.vue', { '@/components/AppIcon.vue': stub });
    const view = await mountView('views/requests/Index.vue', '/requests', {
        async get(endpoint, { params }) {
            assert.equal(endpoint, 'pages/requests'); calls.push(structuredClone(params));
            return list(records.filter((row) => !params.status || row.status === params.status));
        },
    }, user, { '@/components/ListFilterBar.vue': FilterBar });
    try {
        await settle();
        const sent = view.root.querySelector('tbody [data-status="sent"]');
        const shipped = view.root.querySelector('tbody [data-status="shipped"]');
        assert.equal(sent.querySelector('.workflow-status').textContent, 'Սպասում է ստուգման');
        assert.equal(shipped.querySelector('.workflow-status').textContent, 'Ապրանքն ուղարկված է');
        assert.match(sent.querySelector('.workflow-state-note').textContent, /Ապրանքը դեռ չի ուղարկվել/);
        assert.match(shipped.querySelector('.workflow-state-note').textContent, /հաստատի ընդունումը/);
        const select = view.root.querySelector('.list-filter-bar select');
        assert.equal(select.querySelector('option[value="sent"]').textContent, sent.querySelector('.workflow-status').textContent);
        assert.equal(select.querySelector('option[value="shipped"]').textContent, shipped.querySelector('.workflow-status').textContent);
        change(select, 'shipped'); submit(view.root.querySelector('.list-filter-bar')); await settle();
        assert.equal(calls.at(-1).status, 'shipped');
        assert.equal(view.root.querySelectorAll('tbody tr').length, 1);
        assert.ok(view.root.querySelector('tbody [data-status="shipped"]'));
        change(select, 'sent'); submit(view.root.querySelector('.list-filter-bar')); await settle();
        assert.equal(calls.at(-1).status, 'sent');
        assert.ok([...view.root.querySelectorAll('tbody button')].some((button) => button.textContent === 'Վերցնել ստուգման'));
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...user, permissions: { 'requests.view': true } } })); await settle();
        assert.equal(view.root.querySelector('tbody button:not(.request-details-trigger)'), null, 'clearer badges do not grant workflow actions to a viewer');
        assert.ok(view.root.querySelector('tbody .request-details-trigger'), 'a viewer can inspect the request without workflow rights');
        assert.equal(view.root.querySelector('tbody .workflow-status').textContent, 'Սպասում է ստուգման');
        const guide = view.root.querySelector('.workflow-guide');
        assert.equal(guide.open, false);
        guide.querySelector('summary').click(); await settle();
        assert.equal(guide.open, true);
        assert.ok(guide.querySelector('[data-status="sent"]'));
        assert.ok(guide.querySelector('[data-status="shipped"]'));
    } finally { view.unmount(); }
});

test('the same approved API code keeps its purchase and transfer meaning in badges and status filters', async () => {
    const FilterBar = component('components/ListFilterBar.vue', { '@/components/AppIcon.vue': stub });
    for (const { module, label } of [
        { module: 'purchases', label: 'Գնումը հաստատված է' },
        { module: 'transfers', label: 'Պատրաստ է ուղարկման' },
    ]) {
        const calls = [];
        const view = await mountView(`views/${module === 'purchases' ? 'purchasing' : module}/Index.vue`, `/${module}`, {
            async get(endpoint, { params }) {
                assert.equal(endpoint, `pages/${module}`); calls.push(structuredClone(params));
                return list([{ id: 1, order_no: 'ORDER-1', transfer_no: 'TRANSFER-1', status: 'approved' }], { status: 'Կարգավիճակ' });
            },
        }, { location_id: 0, permissions: { [`${module}.view`]: true } }, { '@/components/ListFilterBar.vue': FilterBar });
        try {
            await settle();
            assert.equal(view.root.querySelector('tbody [data-status="approved"] .workflow-status').textContent, label);
            const select = view.root.querySelector('.list-filter-bar select');
            assert.equal(select.querySelector('option[value="approved"]').textContent, label);
            change(select, 'approved'); submit(view.root.querySelector('.list-filter-bar')); await settle();
            assert.equal(calls.at(-1).status, 'approved');
            assert.equal(view.root.querySelector('tbody button'), null, 'status context does not change view-only action permissions');
        } finally { view.unmount(); }
    }
});

test('transfer exports respect export permission and submit the visible search and filters', async () => {
    const originalClick = dom.window.HTMLAnchorElement.prototype.click;
    const originalCreate = URL.createObjectURL;
    const originalRevoke = URL.revokeObjectURL;
    const downloads = [];
    const exports = [];
    dom.window.HTMLAnchorElement.prototype.click = function () { downloads.push(this.download); };
    URL.createObjectURL = () => 'blob:transfer-export';
    URL.revokeObjectURL = () => {};
    try {
        for (const permitted of [false, true]) {
            const user = { permissions: { 'transfers.view': true, 'transfers.export': permitted } };
            const api = {
                async get(endpoint, options) {
                    if (endpoint.endsWith('/export')) {
                        exports.push({ endpoint, params: structuredClone(options.params) });
                        return { data: new Blob(['transfer_no\nEXPORTED-TRANSFER'], { type: 'text/csv' }) };
                    }
                    return list([{ id: 7, transfer_no: 'EXPORTED-TRANSFER', status: 'completed' }]);
                },
            };
            const ExportActions = component('components/ExportActions.vue', {
                '@/services/api': api, '@/router': { currentUser: () => user }, '@/components/AppIcon.vue': stub,
            });
            const view = await mountView('views/transfers/Index.vue', '/transfers', api, user, {
                '@/components/ExportActions.vue': ExportActions, '@/components/ListFilterBar.vue': liveFilters,
            });
            try {
                await settle();
                assert.equal(Boolean(view.root.querySelector('.export-actions')), permitted);
                if (!permitted) continue;
                change(view.root.querySelector('.search-input input'), 'EXPORTED'); await Vue.nextTick();
                view.root.querySelector('.test-apply-filter').click(); await settle();
                view.root.querySelector('.export-actions button').click(); await settle();
                assert.equal(exports.length, 1);
                assert.equal(exports[0].endpoint, 'pages/transfers/export');
                assert.equal(exports[0].params.search, 'EXPORTED');
                assert.equal(exports[0].params.from, '2026-01-01');
                assert.equal(exports[0].params.format, 'csv');
                assert.deepEqual(downloads, ['diagen-transfers.csv']);
            } finally { view.unmount(); }
        }
    } finally {
        dom.window.HTMLAnchorElement.prototype.click = originalClick;
        URL.createObjectURL = originalCreate;
        URL.revokeObjectURL = originalRevoke;
    }
});
