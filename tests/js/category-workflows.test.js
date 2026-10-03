import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { parse, compileScript } from '@vue/compiler-sfc';
import { transformSync } from 'esbuild';
import { JSDOM } from 'jsdom';
import { searchableSelect } from '../../resources/js/directives/searchableSelect.js';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/', pretendToBeVisual: true });
for (const key of ['window', 'document', 'history', 'Document', 'HTMLElement', 'SVGElement', 'Element', 'Node', 'CustomEvent']) globalThis[key] = dom.window[key];
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: dom.window.navigator });
const require = createRequire(import.meta.url);
const Vue = require('vue');
const VueRouter = require('vue-router');
const stub = { render: () => null };

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

async function mountCategories(api, user = { id: 1, location_id: 0, permissions: { 'products.view': true } }) {
    const definition = sourceModule(new URL('../../resources/js/views/categories/Index.vue', import.meta.url), {
        '@/services/api': api, '@/router': { currentUser: () => user }, '@/components/AppIcon.vue': stub,
    }).default;
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/categories', component: stub }, { path: '/products', component: stub },
    ] });
    await router.push('/categories');
    const root = document.createElement('div'); document.body.append(root);
    const app = Vue.createApp(definition);
    app.use(router).component('AppIcon', stub).directive('searchable-select', searchableSelect);
    app.mount(root);
    return { root, unmount() { app.unmount(); root.remove(); } };
}

const actor = (permissions = {}) => ({ id: 7, location_id: 0, permissions: { 'products.view': true, ...permissions } });
const records = () => [
    { id: 1, name: 'Լաբորատոր նյութեր', parent_id: null, parent: null, products_count: 2, children_count: 1 },
    { id: 2, name: 'Ռեագենտներ', parent_id: 1, parent: { id: 1, name: 'Լաբորատոր նյութեր' }, products_count: 3, children_count: 0 },
    { id: 3, name: 'Դատարկ տեսակ', parent_id: null, parent: null, products_count: 0, children_count: 0 },
    { id: 4, name: 'Միայն ենթատեսակներ', parent_id: null, parent: null, products_count: 0, children_count: 2 },
];
const response = (items = records()) => ({ data: { data: items } });
function deferred() { let resolve; let reject; const promise = new Promise((complete, fail) => { resolve = complete; reject = fail; }); return { promise, resolve, reject }; }
async function settle() { await new Promise((resolve) => setImmediate(resolve)); await Vue.nextTick(); }
function change(input, value) { input.value = value; input.dispatchEvent(new dom.window.Event(input.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); }
function submit(form) { form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true })); }
function row(root, id) { return root.querySelector(`[data-category-id="${id}"]`); }

test('product viewers see category parent and usage counts and search immediately without mutation controls or more requests', async () => {
    const calls = [];
    const view = await mountCategories({ async get(endpoint) { calls.push(endpoint); return response(); } });
    try {
        await settle();
        assert.equal(view.root.querySelector('h1').textContent, 'Ապրանքի տեսակներ');
        assert.equal(view.root.querySelector('.category-page-actions a').getAttribute('href'), '/products');
        assert.equal(view.root.querySelector('.category-page-actions button'), null);
        assert.equal(view.root.querySelector('tbody button'), null);
        assert.equal(row(view.root, 2).querySelectorAll('td')[1].textContent, 'Լաբորատոր նյութեր');
        assert.deepEqual([...row(view.root, 1).querySelectorAll('td')].slice(2).map((cell) => cell.textContent), ['2', '1']);
        assert.match(row(view.root, 1).textContent, /Կապակցված է 2 ապրանքի։ Ունի 1 ենթատեսակ/);
        const search = view.root.querySelector('input[type="search"]');
        change(search, 'ռեագենտ'); await settle();
        assert.equal(view.root.querySelectorAll('[data-category-id]').length, 1);
        assert.ok(row(view.root, 2));
        change(search, 'լաբորատոր'); await settle();
        assert.equal(view.root.querySelectorAll('[data-category-id]').length, 2, 'parent names also match the immediate search');
        change(search, 'անհայտ տեսակ'); await settle();
        assert.match(view.root.querySelector('.table-empty').textContent, /Որոնմանը համապատասխան տեսակ չկա/);
        assert.deepEqual(calls, ['categories']);
    } finally { view.unmount(); }
});

test('category creation uses the actual searchable parent menu, keeps validation drafts and separates a successful save from refresh failure', async () => {
    const posts = []; let gets = 0;
    const view = await mountCategories({
        async get() { gets += 1; if (gets > 1) throw { response: { data: { message: 'List refresh unavailable' } } }; return response(); },
        async post(endpoint, payload) {
            posts.push({ endpoint, payload: structuredClone(payload) });
            if (posts.length === 1) throw { response: { data: { errors: { name: ['Այս տեսակը արդեն կա։'] } } } };
            return {};
        },
    }, actor({ 'products.create': true }));
    try {
        await settle(); view.root.querySelector('.category-page-actions button').click(); await settle();
        const form = view.root.querySelector('.category-form');
        const name = form.querySelector('input'); const parent = form.querySelector('select');
        assert.equal(document.activeElement, name);
        assert.match(parent.closest('label').textContent, /Հիմնական տեսակ \(ընտրովի\)/);
        assert.equal(parent.value, '');
        change(name, '  Նոր տեսակ  ');
        parent.dispatchEvent(new dom.window.MouseEvent('pointerdown', { button: 0, bubbles: true, cancelable: true })); await settle();
        const menu = document.querySelector('.searchable-select-menu');
        assert.ok(menu);
        [...menu.querySelectorAll('[role="option"]')].find((option) => option.textContent === 'Լաբորատոր նյութեր').click(); await settle();
        assert.equal(parent.value, '1');
        assert.equal(document.querySelector('.searchable-select-menu'), null);
        submit(form); await settle();
        assert.equal(form.querySelector('[role="alert"]').textContent, 'Այս տեսակը արդեն կա։');
        assert.equal(name.value, 'Նոր տեսակ');
        assert.equal(parent.value, '1');
        submit(form); await settle();
        assert.deepEqual(posts, Array(2).fill({ endpoint: 'categories', payload: { name: 'Նոր տեսակ', parent_id: 1 } }));
        assert.equal(view.root.querySelector('.category-form'), null);
        assert.match(view.root.querySelector('.notice-success').textContent, /տեսակը ավելացվեց/);
        assert.match(view.root.querySelector('.category-load-error').textContent, /List refresh unavailable/);
        assert.doesNotMatch(view.root.textContent, /Տեսակը չհաջողվեց ավելացնել/);
    } finally { view.unmount(); }
});

test('category deletion requires explicit confirmation and excludes product or child usage, including after a failed refresh', async () => {
    const deletion = deferred(); const deletes = []; let gets = 0;
    const view = await mountCategories({
        async get() { gets += 1; if (gets > 1) throw new Error('offline'); return response(); },
        delete(endpoint) { deletes.push(endpoint); return deletion.promise; },
    }, actor({ 'products.delete': true }));
    try {
        await settle();
        assert.equal(row(view.root, 1).querySelector('button'), null);
        assert.equal(row(view.root, 2).querySelector('button'), null);
        assert.equal(row(view.root, 4).querySelector('button'), null);
        assert.match(row(view.root, 4).textContent, /Ունի 2 ենթատեսակ/);
        row(view.root, 3).querySelector('button').click(); await settle();
        let confirmation = document.querySelector('[role="alertdialog"]');
        assert.ok(confirmation);
        assert.match(confirmation.textContent, /ընդմիշտ կհեռացվի.*հնարավոր չէ հետ բերել/s);
        assert.deepEqual(deletes, [], 'opening confirmation never sends DELETE');
        confirmation.querySelector('.secondary-button').click(); await settle();
        assert.equal(document.querySelector('[role="alertdialog"]'), null);
        assert.deepEqual(deletes, []);
        row(view.root, 3).querySelector('button').click(); await settle();
        confirmation = document.querySelector('[role="alertdialog"]');
        confirmation.querySelector('.destructive-confirm-submit').click(); await settle();
        confirmation.querySelector('.destructive-confirm-submit').click();
        assert.deepEqual(deletes, ['categories/3']);
        assert.equal(confirmation.querySelector('.destructive-confirm-submit').disabled, true);
        deletion.resolve({}); await settle();
        assert.equal(document.querySelector('[role="alertdialog"]'), null);
        assert.equal(row(view.root, 3), null, 'a confirmed deletion is not offered again if list refresh fails');
        assert.match(view.root.querySelector('.notice-success').textContent, /տեսակը ջնջվեց/);
        assert.ok(view.root.querySelector('.category-load-error'));
    } finally { view.unmount(); }
});

test('realtime category updates retain the current search and open draft and coalesce changes during loading', async () => {
    const firstLive = deferred(); const calls = [];
    const view = await mountCategories({ get(endpoint) {
        calls.push(endpoint);
        if (calls.length === 2) return firstLive.promise;
        return Promise.resolve(response(records().map((item) => item.id === 2 ? { ...item, products_count: calls.length === 3 ? 9 : 3 } : item)));
    } }, actor({ 'products.create': true }));
    try {
        await settle();
        change(view.root.querySelector('input[type="search"]'), 'ռեագենտ');
        view.root.querySelector('.category-page-actions button').click(); await settle();
        change(view.root.querySelector('.category-form input'), 'Չպահպանված տեսակ');
        change(view.root.querySelector('.category-form select'), '1');
        window.dispatchEvent(new CustomEvent('lager:data-changed'));
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls.length, 2);
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        firstLive.resolve(response()); await settle(); await settle();
        assert.equal(calls.length, 3, 'one trailing request recovers changes that arrived during a load');
        assert.equal(view.root.querySelector('input[type="search"]').value, 'ռեագենտ');
        assert.equal(view.root.querySelectorAll('[data-category-id]').length, 1);
        assert.equal(row(view.root, 2).querySelectorAll('td')[2].textContent, '9');
        assert.equal(view.root.querySelector('.category-form input').value, 'Չպահպանված տեսակ');
        assert.equal(view.root.querySelector('.category-form select').value, '1');
        view.unmount(); window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls.length, 3);
    } finally { if (view.root.isConnected) view.unmount(); }
});

test('an account change closes category drafts and rejects old list snapshots and later permission loss stops live fetching', async () => {
    const oldLive = deferred(); let calls = 0;
    const view = await mountCategories({ get() {
        calls += 1;
        return calls === 1 ? Promise.resolve(response()) : calls === 2 ? oldLive.promise : Promise.resolve(response([{ id: 9, name: 'Նոր հաշվի ցանկ', parent: null, products_count: 0, children_count: 0 }]));
    } }, actor({ 'products.create': true }));
    try {
        await settle(); view.root.querySelector('.category-page-actions button').click(); await settle();
        change(view.root.querySelector('.category-form input'), 'Նախորդ հաշվի սևագիր');
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...actor(), id: 8 } })); await settle();
        assert.equal(view.root.querySelector('.category-form'), null);
        assert.equal(view.root.querySelector('.category-page-actions button'), null);
        assert.ok(row(view.root, 9));
        oldLive.resolve(response()); await settle();
        assert.equal(row(view.root, 1), null);
        assert.ok(row(view.root, 9));
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { id: 8, location_id: 0, permissions: {} } })); await settle();
        assert.equal(view.root.querySelector('table'), null);
        assert.match(view.root.querySelector('[role="alert"]').textContent, /դիտելու իրավունք չունեք/);
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(calls, 3);
    } finally { view.unmount(); }
});

test('a category save finishing for a previous account cannot close or replace the new account draft', async () => {
    const oldSave = deferred(); const posts = []; let gets = 0;
    const view = await mountCategories({
        async get() { gets += 1; return response(); },
        post(endpoint, payload) { posts.push({ endpoint, payload: structuredClone(payload) }); return oldSave.promise; },
    }, actor({ 'products.create': true }));
    try {
        await settle(); view.root.querySelector('.category-page-actions button').click(); await settle();
        change(view.root.querySelector('.category-form input'), 'Առաջին հաշվի տեսակ');
        submit(view.root.querySelector('.category-form')); await settle();
        assert.deepEqual(posts, [{ endpoint: 'categories', payload: { name: 'Առաջին հաշվի տեսակ', parent_id: null } }]);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { ...actor({ 'products.create': true }), id: 8 } })); await settle();
        view.root.querySelector('.category-page-actions button').click(); await settle();
        change(view.root.querySelector('.category-form input'), 'Երկրորդ հաշվի սևագիր');
        oldSave.resolve({}); await settle();
        assert.equal(view.root.querySelector('.category-form input').value, 'Երկրորդ հաշվի սևագիր');
        assert.equal(view.root.querySelector('.notice-success'), null);
        assert.equal(gets, 2, 'the old mutation does not refresh the new account list');
    } finally { view.unmount(); }
});

test('category loading errors support retry and an empty loaded list permits creation', async () => {
    const initial = deferred(); let calls = 0;
    const view = await mountCategories({ get() { calls += 1; return calls === 1 ? initial.promise : Promise.resolve(response([])); } }, actor({ 'products.create': true }));
    try {
        await settle();
        assert.match(view.root.querySelector('.table-empty[role="status"]').textContent, /բեռնվում են/);
        assert.equal(view.root.querySelector('.category-page-actions button').disabled, true);
        initial.reject({ response: { data: { message: 'Category list unavailable' } } }); await settle();
        assert.match(view.root.querySelector('.category-load-error').textContent, /Category list unavailable/);
        assert.doesNotMatch(view.root.textContent, /Ապրանքի տեսակներ դեռ չկան/);
        view.root.querySelector('.category-load-error button').click(); await settle();
        assert.match(view.root.querySelector('.table-empty').textContent, /Ապրանքի տեսակներ դեռ չկան/);
        assert.equal(view.root.querySelector('.category-page-actions button').disabled, false);
    } finally { view.unmount(); }
});

test('a category that gains product usage while deletion is being confirmed is protected before DELETE', async () => {
    const deletes = []; let gets = 0;
    const view = await mountCategories({
        async get() { gets += 1; return response(records().map((item) => item.id === 3 && gets > 1 ? { ...item, products_count: 1 } : item)); },
        async delete(endpoint) { deletes.push(endpoint); return {}; },
    }, actor({ 'products.delete': true }));
    try {
        await settle(); row(view.root, 3).querySelector('button').click(); await settle();
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(row(view.root, 3).querySelector('button'), null);
        document.querySelector('.destructive-confirm-submit').click(); await settle();
        assert.match(document.querySelector('[role="alertdialog"] [role="alert"]').textContent, /Կապակցված է 1 ապրանքի/);
        assert.deepEqual(deletes, []);
    } finally { view.unmount(); }
});
