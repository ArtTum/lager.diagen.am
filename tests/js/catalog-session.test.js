import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { parse, compileScript } from '@vue/compiler-sfc';
import { transformSync } from 'esbuild';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/' });
for (const key of ['window', 'document', 'history', 'Document', 'HTMLElement', 'SVGElement', 'Element', 'Node', 'CustomEvent']) globalThis[key] = dom.window[key];
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: dom.window.navigator });
const require = createRequire(import.meta.url);
const Vue = require('vue'); const VueRouter = require('vue-router'); const stub = { render: () => null };

function sourceModule(filename, dependencies) {
    if (filename.pathname.endsWith('.json')) return JSON.parse(readFileSync(filename, 'utf8'));
    const source = readFileSync(filename, 'utf8');
    const script = filename.pathname.endsWith('.vue')
        ? compileScript(parse(source, { filename: filename.pathname }).descriptor, { id: filename.pathname, inlineTemplate: true }).content : source;
    const module = { exports: {} };
    const load = (name) => {
        if (Object.hasOwn(dependencies, name)) return dependencies[name];
        if (!name.startsWith('@/') && !name.startsWith('.')) return require(name);
        const target = name.startsWith('@/') ? new URL(`../../resources/js/${name.slice(2)}`, import.meta.url) : new URL(name, filename);
        if (!/\.(vue|js|json)$/.test(target.pathname)) target.pathname += '.js';
        const result = sourceModule(target, dependencies);
        return target.pathname.endsWith('.vue') ? result.default : result;
    };
    new Function('require', 'module', 'exports', transformSync(script, { format: 'cjs' }).code)(load, module, module.exports);
    return module.exports;
}
const actor = (page, id = 1, permissions = {}) => ({ id, location_id: 0, permissions: {
    [`${page}.view`]: true, [`${page}.create`]: true, [`${page}.edit`]: true, [`${page}.delete`]: true, ...permissions,
} });
const row = (id = 1) => ({ id, name: `Record ${id}`, title: `Role ${id}`, active: true });
const list = (id = 1) => ({ data: { data: [row(id)], columns: { name: 'Name' }, pagination: { current_page: 1, per_page: 15, total: 1, last_page: 1 } } });
const options = (name) => ({ data: { data: {
    categories: [{ id: 10, name }], roles: [{ id: 10, title: name }], branches: [], suppliers: [],
    permissions: [{ code: 'products.view', module: 'products', title: name }],
} } });
function deferred() { let resolve; let reject; const promise = new Promise((complete, fail) => { resolve = complete; reject = fail; }); return { promise, resolve, reject }; }
async function settle() { await new Promise((resolve) => setImmediate(resolve)); await Vue.nextTick(); }
function change(input, value) { input.value = value; input.dispatchEvent(new dom.window.Event('input', { bubbles: true })); }
function submit(form) { form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true })); }
function context(user) { window.dispatchEvent(new dom.window.CustomEvent('lager:user', { detail: user })); }
function create(view) { view.root.querySelector('.page-heading-actions .primary-button').click(); }
function draft(view) { return view.root.querySelector('.catalog-modal'); }
function nameInput(view) { return draft(view).querySelector('input:not([type]), input[type="text"]'); }

async function mountCatalog(page, api, user = actor(page)) {
    const definition = sourceModule(new URL('../../resources/js/views/CatalogTable.vue', import.meta.url), {
        '@/services/api': api, '@/router': { currentUser: () => user }, '@/components/AppIcon.vue': stub,
        '@/components/Pagination.vue': stub, '@/components/ExportActions.vue': stub, '@/components/BarcodeScanner.vue': stub,
    }).default;
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: ['products', 'branches', 'users', 'roles', 'categories'].map((name) => ({ path: `/${name}`, component: stub })) });
    await router.push(`/${page}`);
    const root = document.createElement('div'); document.body.append(root);
    const app = Vue.createApp(definition); app.use(router).component('AppIcon', stub).directive('searchable-select', {}); app.mount(root);
    return { root, router, unmount() { app.unmount(); root.remove(); } };
}

test('new users require a password, while editing an existing user permits keeping it unchanged', async () => {
    const view = await mountCatalog('users', { async get(endpoint) {
        if (endpoint.startsWith('pages/')) return list();
        if (endpoint.endsWith('/options')) return options('User role');
        return { data: { data: { ...row(), email: 'user@example.test', role_id: 10 } } };
    } });
    try {
        await settle(); create(view); await settle();
        let password = draft(view).querySelector('input[type="password"]');
        assert.equal(password.required, true);
        assert.equal(password.checkValidity(), false, 'an empty new-user password blocks native form submission');
        assert.equal(password.minLength, 8);
        assert.match(password.closest('label').textContent, /\*/);
        draft(view).querySelector('.close-button').click(); await settle();
        view.root.querySelector('[title="Խմբագրել"]').click(); await settle();
        password = draft(view).querySelector('input[type="password"]');
        assert.equal(password.value, '');
        assert.equal(password.required, false);
        assert.equal(password.checkValidity(), true, 'editing allows an empty password to preserve the current one');
        assert.equal(password.minLength, 8, 'a replacement password has the same minimum as user creation');
        assert.doesNotMatch(password.closest('label').textContent, /\*/);
    } finally { view.unmount(); }
});

test('user role choices show their human titles instead of internal role names in create and edit forms', async () => {
    const roles = [
        { id: 10, name: 'admin', title: 'Համակարգի ադմինիստրատոր' },
        { id: 11, name: 'custom_f92b65c9651a155d', title: 'Թեստային պատասխանատու' },
        { id: 12, name: 'legacy_role', title: '' },
    ];
    const view = await mountCatalog('users', { async get(endpoint) {
        if (endpoint.startsWith('pages/')) return list();
        if (endpoint.endsWith('/options')) {
            const response = options('Choices'); response.data.data.roles = roles; return response;
        }
        return { data: { data: { ...row(), email: 'user@example.test', role_id: 11 } } };
    } });
    try {
        await settle(); create(view); await settle();
        let role = draft(view).querySelector('select');
        assert.deepEqual([...role.options].slice(1).map((option) => [option.value, option.textContent]), [
            ['10', roles[0].title], ['11', roles[1].title], ['12', 'legacy_role'],
        ]);
        draft(view).querySelector('.close-button').click(); await settle();
        view.root.querySelector('[title="Խմբագրել"]').click(); await settle();
        role = draft(view).querySelector('select');
        assert.equal(role.value, '11');
        assert.equal(role.selectedOptions[0].textContent, roles[1].title);
    } finally { view.unmount(); }
});

test('product internal codes are optional in both create and edit forms', async () => {
    const view = await mountCatalog('products', { async get(endpoint) {
        if (endpoint.startsWith('pages/')) return list();
        if (endpoint.endsWith('/options')) return options('Choices');
        return { data: { data: { ...row(), code: 'EXISTING', unit: 'pcs' } } };
    } });
    try {
        await settle(); create(view); await settle();
        for (const editing of [false, true]) {
            if (editing) {
                draft(view).querySelector('.close-button').click(); await settle();
                view.root.querySelector('[title="Խմբագրել"]').click(); await settle();
            }
            const field = [...draft(view).querySelectorAll('.form-field')].find((field) => field.textContent.includes('Ներքին կոդ'));
            const code = field.querySelector('input');
            change(code, ''); await settle();
            assert.equal(code.required, false);
            assert.equal(code.checkValidity(), true);
            assert.doesNotMatch(field.textContent, /\*/);
        }
    } finally { view.unmount(); }
});

for (const [page, limits] of [
    ['branches', { 'Անվանում': 160, 'Կոդ': 40 }],
    ['products', { 'Ապրանքի անվանում': 190, 'Ներքին կոդ': 80, 'Չափման միավոր': 50 }],
    ['users', { 'Անուն': 160 }],
]) {
    test(`${page} inputs expose the API's name, code and unit length limits`, async () => {
        const view = await mountCatalog(page, { get: async (endpoint) => endpoint.startsWith('pages/') ? list() : options('Choices') });
        try {
            await settle(); create(view); await settle();
            for (const [label, maximum] of Object.entries(limits)) {
                const field = [...draft(view).querySelectorAll('.form-field')].find((field) => field.textContent.replace(/\*/g, '').trim() === label);
                assert.equal(field?.querySelector('input')?.maxLength, maximum, label);
            }
        } finally { view.unmount(); }
    });
}

for (const page of ['products', 'branches', 'users', 'roles']) {
    test(`${page} ignore an old account's form opener while preserving the new account's draft and options`, async () => {
        const old = deferred(); let optionCalls = 0;
        const view = await mountCatalog(page, { get(endpoint) {
            if (endpoint.startsWith('pages/')) return Promise.resolve(list());
            if (endpoint.endsWith('/options')) return ++optionCalls === 1 ? old.promise : Promise.resolve(options('New account choices'));
            if (endpoint === 'catalog/branches/1') return old.promise;
            throw new Error(endpoint);
        } });
        try {
            await settle();
            if (page === 'branches') view.root.querySelector('[title="Խմբագրել"]').click(); else create(view);
            await settle(); assert.equal(draft(view), null);
            context(actor(page, 2)); await settle(); create(view); await settle();
            change(nameInput(view), 'New account draft');
            old.resolve(page === 'branches' ? { data: { data: { ...row(), name: 'Old private record', code: 'OLD' } } } : options('Old private choices'));
            await settle();
            assert.equal(nameInput(view).value, 'New account draft');
            assert.doesNotMatch(draft(view).textContent, /Old private/);
            if (page !== 'branches') assert.match(draft(view).textContent, /New account choices/);
            assert.equal(view.root.querySelector('.notice-success'), null);
        } finally { view.unmount(); }
    });
}

test('overlapping catalog options commit only to the latest opener, and an equivalent user event preserves its draft', async () => {
    const old = deferred(); const user = actor('products'); let optionCalls = 0; let listCalls = 0;
    const view = await mountCatalog('products', { get(endpoint) {
        if (endpoint.startsWith('pages/')) { listCalls += 1; return Promise.resolve(list()); }
        return ++optionCalls === 1 ? old.promise : Promise.resolve(options('Latest choices'));
    } }, user);
    try {
        await settle(); create(view); await settle(); create(view); await settle();
        change(nameInput(view), 'Latest draft');
        old.resolve(options('Obsolete choices')); await settle();
        assert.match(draft(view).textContent, /Latest choices/); assert.doesNotMatch(draft(view).textContent, /Obsolete/);
        context(structuredClone(user)); await settle();
        assert.equal(nameInput(view).value, 'Latest draft'); assert.equal(listCalls, 1);
    } finally { view.unmount(); }
});

test('revoked catalog permissions invalidate pending forms, discard old list data and reject retained form submissions', async () => {
    const optionsLoad = deferred(); const staleList = deferred(); const calls = []; let listCalls = 0;
    const view = await mountCatalog('users', {
        get(endpoint) { calls.push(endpoint); if (endpoint.startsWith('pages/')) return ++listCalls === 1 ? Promise.resolve(list()) : staleList.promise; return optionsLoad.promise; },
        post() { assert.fail('revoked create must never POST'); },
    });
    try {
        await settle(); create(view); await settle();
        context(actor('users', 1, { 'users.create': false, 'users.edit': false })); await settle();
        optionsLoad.resolve(options('Sensitive choices')); await settle();
        assert.equal(draft(view), null); assert.equal(view.root.querySelector('.page-heading-actions .primary-button'), null);
        context(actor('users', 1, { 'users.view': false, 'users.create': false, 'users.edit': false, 'users.delete': false }));
        staleList.resolve(list(9)); await settle();
        assert.equal(view.root.querySelectorAll('tbody tr').length, 0);
        window.dispatchEvent(new dom.window.Event('lager:data-changed')); await settle();
        assert.equal(listCalls, 2, 'no new catalog fetch after view permission is revoked');
    } finally { view.unmount(); }

    const second = await mountCatalog('branches', { get: async () => list(), post() { assert.fail('a detached, revoked form must not POST'); } });
    try {
        await settle(); create(second); await settle(); const retainedForm = draft(second);
        context(actor('branches', 1, { 'branches.create': false })); await settle();
        submit(retainedForm); await settle(); assert.equal(draft(second), null);
    } finally { second.unmount(); }
});

for (const oldOutcome of ['success', 'failure']) {
    test(`an old catalog save ${oldOutcome} cannot close, report errors in or unlock a new account's pending draft`, async () => {
        const old = deferred(); const fresh = deferred(); const posts = []; let listCalls = 0;
        const view = await mountCatalog('users', {
            async get(endpoint) { if (endpoint.startsWith('pages/')) { listCalls += 1; return list(); } return options('Current choices'); },
            post(endpoint, payload) { posts.push({ endpoint, payload }); return posts.length === 1 ? old.promise : fresh.promise; },
        });
        try {
            await settle(); create(view); await settle(); change(nameInput(view), 'Old draft'); submit(draft(view)); await settle();
            context(actor('users', 2)); await settle(); create(view); await settle();
            change(nameInput(view), 'New draft'); submit(draft(view)); await settle();
            assert.equal(posts[0].payload.name, 'Old draft', 'outgoing payload is a snapshot rather than the reactive form');
            assert.equal(posts[1].payload.name, 'New draft');
            if (oldOutcome === 'success') old.resolve({}); else old.reject({ response: { data: { message: 'Old save failure' } } });
            await settle();
            assert.equal(nameInput(view).value, 'New draft');
            assert.equal(draft(view).querySelector('.primary-button').disabled, true);
            assert.equal(view.root.querySelector('.notice-success'), null); assert.doesNotMatch(view.root.textContent, /Old save failure/);
            assert.equal(listCalls, 2, 'old completion does not fetch in the new account');
            fresh.reject({ response: { data: { message: 'New save failure' } } }); await settle();
            assert.equal(draft(view).querySelector('[role="alert"]').textContent, 'New save failure');
            assert.equal(draft(view).querySelector('.primary-button').disabled, false);
        } finally { view.unmount(); }
    });
}

function trace(name) { return { data: { data: { product: { id: 1, code: 'P1', name, unit: 'հատ' }, show_costs: true,
    lots: { data: [{ id: 1, lot_no: `${name}-LOT`, location_name: 'Central', qty: 1, unit_cost: 12 }], total: 1, last_page: 1 },
    movements: { data: [], total: 0, last_page: 1 }, requests: { data: [], total: 0, last_page: 1 },
} } }; }
test('old account trace results cannot populate a new trace, and revoking product view removes all trace data', async () => {
    const old = deferred(); let traces = 0;
    const view = await mountCatalog('products', { get(endpoint) {
        return endpoint.startsWith('pages/') ? Promise.resolve(list()) : ++traces === 1 ? old.promise : Promise.resolve(trace('Fresh product'));
    } });
    try {
        await settle(); view.root.querySelector('[title="Ապրանքի հետագիծ"]').click(); await settle();
        context(actor('products', 2)); await settle(); assert.equal(view.root.querySelector('.product-trace-modal'), null);
        view.root.querySelector('[title="Ապրանքի հետագիծ"]').click(); await settle();
        old.resolve(trace('Old private product')); await settle();
        assert.match(view.root.querySelector('.product-trace-modal').textContent, /Fresh product/);
        assert.doesNotMatch(view.root.textContent, /Old private product/);
        context(actor('products', 2, { 'products.view': false })); await settle();
        assert.equal(view.root.querySelector('.product-trace-modal'), null);
        assert.doesNotMatch(view.root.textContent, /Fresh product/);
    } finally { view.unmount(); }
});

test('a previous account delete completion leaves the new account confirmation busy and its error independent', async () => {
    const old = deferred(); const fresh = deferred(); let lists = 0; let deletes = 0;
    const view = await mountCatalog('branches', {
        async get() { return list(++lists); }, delete() { return ++deletes === 1 ? old.promise : fresh.promise; },
    });
    try {
        await settle(); view.root.querySelector('[title="Ապաակտիվացնել"]').click(); await settle();
        document.querySelector('.destructive-confirm-submit').click(); await settle();
        context(actor('branches', 2)); await settle(); view.root.querySelector('[title="Ապաակտիվացնել"]').click(); await settle();
        document.querySelector('.destructive-confirm-submit').click(); await settle();
        old.resolve({}); await settle();
        assert.equal(document.querySelector('.destructive-confirm-submit').disabled, true);
        assert.equal(document.querySelector('.destructive-confirm-entity').textContent, 'Record 2');
        assert.equal(view.root.querySelector('.notice-success'), null); assert.equal(lists, 2);
        fresh.reject({ response: { data: { message: 'Fresh delete rejected' } } }); await settle();
        assert.equal(document.querySelector('[role="alertdialog"] [role="alert"]').textContent, 'Fresh delete rejected');
        assert.equal(document.querySelector('.destructive-confirm-submit').disabled, false);
    } finally { view.unmount(); }
});

test('unmount cancels pending catalog opener follow-ups and mutation refreshes', async () => {
    const optionsLoad = deferred(); const getCalls = [];
    const first = await mountCatalog('users', { get(endpoint) { getCalls.push(endpoint); return endpoint.startsWith('pages/') ? Promise.resolve(list()) : optionsLoad.promise; } });
    await settle(); first.root.querySelector('[title="Խմբագրել"]').click(); await settle(); first.unmount();
    optionsLoad.resolve(options('Old choices')); await settle();
    assert.deepEqual(getCalls, ['pages/users', 'catalog/users/options']);
    assert.equal(document.querySelector('.catalog-modal'), null);

    const save = deferred(); let listCalls = 0;
    const second = await mountCatalog('branches', { get: async () => { listCalls += 1; return list(); }, post: () => save.promise });
    await settle(); create(second); await settle(); submit(draft(second)); await settle(); second.unmount();
    save.resolve({}); context(actor('branches', 3)); await settle();
    assert.equal(listCalls, 1); assert.equal(document.querySelector('.catalog-modal'), null);
});
