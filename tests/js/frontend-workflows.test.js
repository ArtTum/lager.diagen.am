import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { parse, compileScript } from '@vue/compiler-sfc';
import { transformSync } from 'esbuild';
import { JSDOM } from 'jsdom';
import { userContextChanged } from '../../resources/js/router/access.js';
import * as dateUtils from '../../resources/js/dateUtils.js';
import { canCreateRecord } from '../../resources/js/permissions.js';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/' });
for (const key of ['window', 'document', 'history', 'HTMLElement', 'SVGElement', 'Element', 'Node', 'CustomEvent', 'localStorage']) {
    globalThis[key] = dom.window[key];
}
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: dom.window.navigator });
window.matchMedia = () => ({ matches: false });
const require = createRequire(import.meta.url);
const Vue = require('vue');
const VueRouter = require('vue-router');
const stub = { render: () => null };

function deferred() {
    let resolve;
    const promise = new Promise((complete) => { resolve = complete; });
    return { promise, resolve };
}

// Compile the actual SFC and replace only external services/components for each scenario.
function component(relativePath, dependencies = {}) {
    const filename = new URL(`../../resources/js/${relativePath}`, import.meta.url);
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname });
    const script = compileScript(descriptor, { id: relativePath, inlineTemplate: true });
    const { code } = transformSync(script.content, { format: 'cjs', loader: 'js' });
    const module = { exports: {} };
    const load = (name) => Object.hasOwn(dependencies, name) ? dependencies[name] : require(name);
    new Function('require', 'module', 'exports', code)(load, module, module.exports);
    return module.exports.default;
}

function mount(componentDefinition, options = {}) {
    const root = document.createElement('div');
    document.body.append(root);
    const app = Vue.createApp(componentDefinition, options.props);
    app.component('AppIcon', stub);
    app.component('DatePicker', stub);
    app.directive('searchable-select', {});
    if (options.router) app.use(options.router);
    app.mount(root);
    return { root, unmount() { app.unmount(); root.remove(); } };
}

async function settle() {
    await new Promise((resolve) => setImmediate(resolve));
    await Vue.nextTick();
}

function scannerEnvironment(getUserMedia, detect = async () => []) {
    const frames = new Map();
    let nextFrame = 0;
    globalThis.requestAnimationFrame = (callback) => { frames.set(++nextFrame, callback); return nextFrame; };
    globalThis.cancelAnimationFrame = (id) => frames.delete(id);
    window.BarcodeDetector = class {
        static async getSupportedFormats() { return ['code_128']; }
        detect = detect;
    };
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia } });
    dom.window.HTMLMediaElement.prototype.play = async function () {};
    return frames;
}

const Scanner = component('components/BarcodeScanner.vue', { '@/components/AppIcon.vue': stub });

test('camera scanning attaches the stream, emits a detected code, and stops every track', async () => {
    let stopped = 0;
    const stream = { getTracks: () => [{ stop: () => { stopped += 1; } }] };
    const frames = scannerEnvironment(async () => stream, async () => [{ rawValue: '  ABC123  ' }]);
    const detected = [];
    const view = mount(Scanner, { props: { onDetected: (value) => detected.push(value) } });
    try {
        view.root.querySelector('button').click();
        await settle();
        assert.equal(view.root.querySelector('video').srcObject, stream);
        assert.equal(frames.size, 1);
        const [id, scan] = frames.entries().next().value;
        frames.delete(id);
        await scan();
        await settle();
        assert.deepEqual(detected, ['ABC123']);
        assert.equal(stopped, 1);
        assert.equal(view.root.querySelector('video'), null);
    } finally { view.unmount(); }
});

test('closing the scanner while camera permission is pending stops the eventual stream', async () => {
    const permission = deferred();
    let stopped = 0;
    const frames = scannerEnvironment(() => permission.promise);
    const view = mount(Scanner);
    try {
        view.root.querySelector('button').click();
        await settle();
        assert.equal(view.root.querySelector('button').disabled, true);
        view.root.querySelector('.barcode-camera-status button').click();
        await settle();
        permission.resolve({ getTracks: () => [{ stop: () => { stopped += 1; } }] });
        await settle();
        assert.equal(stopped, 1);
        assert.equal(frames.size, 0);
        assert.equal(view.root.querySelector('video'), null);
        assert.equal(view.root.querySelector('button').disabled, false);
    } finally { view.unmount(); }
});

test('a detection finishing after scanner closure does not emit a stale code', async () => {
    const detection = deferred();
    const frames = scannerEnvironment(async () => ({ getTracks: () => [{ stop() {} }] }), () => detection.promise);
    const detected = [];
    const view = mount(Scanner, { props: { onDetected: (value) => detected.push(value) } });
    try {
        view.root.querySelector('button').click();
        await settle();
        const [id, scan] = frames.entries().next().value;
        frames.delete(id);
        const pendingScan = scan();
        view.root.querySelector('.barcode-camera-status button').click();
        detection.resolve([{ rawValue: 'stale-code' }]);
        await pendingScan;
        await settle();
        assert.deepEqual(detected, []);
        assert.equal(frames.size, 0);
    } finally { view.unmount(); }
});

test('navigating between purchase orders and receipts reloads the module and closes the old form', async () => {
    const requests = [];
    const permissions = { 'purchases.view': true, 'purchases.create': true, 'receipts.view': true, 'receipts.create': true };
    const userContext = { currentUser: () => ({ name: 'Auditor', permissions }), refreshCurrentUser: async () => null };
    const api = {
        async get(endpoint, config) {
            requests.push({ endpoint, params: config?.params });
            if (endpoint.startsWith('purchasing/')) return { data: { data: { suppliers: [], products: [], orders: [] } } };
            const value = endpoint === 'pages/purchases' ? 'PURCHASE-001' : 'RECEIPT-001';
            return { data: { data: [{ id: 1, document_no: value }], columns: { document_no: 'Number' }, pagination: { total: 1, per_page: 15, current_page: 1, last_page: 1 } } };
        },
    };
    const Purchasing = component('views/purchasing/Index.vue', {
        '@/components/Pagination.vue': stub, '@/components/ExportActions.vue': stub,
        '@/components/ListFilterBar.vue': stub, '@/services/api': api, '@/router': userContext, '@/dateUtils': dateUtils,
    });
    const App = component('App.vue', {
        '@/services/api': api, '@/router': userContext, '@/router/access': { userContextChanged },
        '@/components/NotificationBell.vue': stub,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/purchases', component: Purchasing, meta: { title: 'Purchases' } },
        { path: '/receipts', component: Purchasing, meta: { title: 'Receipts' } },
        { path: '/no-access', component: stub },
    ] });
    await router.push('/purchases');
    const userListeners = new Set();
    const addListener = window.addEventListener.bind(window);
    const removeListener = window.removeEventListener.bind(window);
    window.addEventListener = (name, listener, ...options) => {
        if (name === 'lager:user') userListeners.add(listener);
        return addListener(name, listener, ...options);
    };
    window.removeEventListener = (name, listener, ...options) => {
        if (name === 'lager:user') userListeners.delete(listener);
        return removeListener(name, listener, ...options);
    };
    const view = mount(App, { router });
    try {
        await settle();
        assert.match(view.root.textContent, /PURCHASE-001/);
        assert.equal(userListeners.size, 2);
        view.root.querySelector('.page-heading button').click();
        await settle();
        assert.ok(view.root.querySelector('.purchase-modal'));
        const search = view.root.querySelector('.search-input input');
        search.value = 'previous search';
        search.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
        await Vue.nextTick();
        await router.push('/receipts');
        await settle();
        assert.equal(view.root.querySelector('.purchase-modal'), null);
        assert.doesNotMatch(view.root.textContent, /PURCHASE-001/);
        assert.match(view.root.textContent, /RECEIPT-001/);
        assert.equal(view.root.querySelector('.search-input input').value, '');
        assert.ok(requests.some(({ endpoint, params }) => endpoint === 'pages/receipts' && !params.search));
        assert.equal(userListeners.size, 2, 'the previous routed page must release its user listener');
    } finally {
        view.unmount();
        window.addEventListener = addListener;
        window.removeEventListener = removeListener;
    }
    assert.equal(userListeners.size, 0);
});

test('a product deactivation confirmation cannot survive navigation into branch management', async () => {
    const permissions = { 'products.view': true, 'products.delete': true, 'branches.view': true, 'branches.delete': true };
    const userContext = { currentUser: () => ({ name: 'Auditor', permissions }), refreshCurrentUser: async () => null };
    const api = {
        async get(endpoint) {
            const name = endpoint === 'pages/products' ? 'Product record' : 'Branch record';
            return { data: { data: [{ id: 7, name, active: true }], columns: { name: 'Name', active: 'Active' }, pagination: { total: 1, per_page: 15, current_page: 1, last_page: 1 } } };
        },
    };
    const confirmation = { render: () => Vue.h('div', { class: 'pending-deactivation' }) };
    const Catalog = component('views/CatalogTable.vue', {
        '@/components/Pagination.vue': stub, '@/components/ExportActions.vue': stub,
        '@/components/BarcodeScanner.vue': stub, '@/components/DestructiveConfirmDialog.vue': confirmation,
        '@/services/api': api, '@/router': userContext, '@/permissions': { canCreateRecord }, '@/dateUtils': dateUtils,
    });
    const App = component('App.vue', {
        '@/services/api': api, '@/router': userContext, '@/router/access': { userContextChanged },
        '@/components/NotificationBell.vue': stub,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/products', component: Catalog, meta: { title: 'Products' } },
        { path: '/branches', component: Catalog, meta: { title: 'Branches' } },
        { path: '/no-access', component: stub },
    ] });
    await router.push('/products');
    const view = mount(App, { router });
    try {
        await settle();
        view.root.querySelector('.table-actions .danger').click();
        await settle();
        assert.ok(view.root.querySelector('.pending-deactivation'));
        await router.push('/branches');
        await settle();
        assert.equal(view.root.querySelector('.pending-deactivation'), null);
        assert.match(view.root.textContent, /Branch record/);
        assert.doesNotMatch(view.root.textContent, /Product record/);
    } finally { view.unmount(); }
});
