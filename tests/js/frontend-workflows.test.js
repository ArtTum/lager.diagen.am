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
import * as notifications from '../../resources/js/notifications.js';
import * as notificationAudio from '../../resources/js/notificationAudio.js';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/' });
for (const key of ['window', 'document', 'history', 'Document', 'HTMLElement', 'SVGElement', 'Element', 'Node', 'CustomEvent', 'localStorage']) {
    globalThis[key] = dom.window[key];
}
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: dom.window.navigator });
window.matchMedia = () => ({ matches: false });
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
    if (name === '@/services/realtime') return { startRealtime: () => () => {} };
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

// Compile the actual SFC and replace only external services/components for each scenario.
function component(relativePath, dependencies = {}) {
    const filename = new URL(`../../resources/js/${relativePath}`, import.meta.url);
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname });
    const script = compileScript(descriptor, { id: relativePath, inlineTemplate: true });
    const { code } = transformSync(script.content, { format: 'cjs', loader: 'js' });
    const module = { exports: {} };
    const load = (name) => loadSourceDependency(name, dependencies, filename);
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
        assert.ok(view.root.querySelector('.workflow-guide'));
        assert.equal(userListeners.size, 3);
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
        assert.equal(view.root.querySelector('.workflow-guide'), null, 'receipt records do not gain the purchase approval workflow');
        assert.equal(view.root.querySelector('.search-input input').value, '');
        assert.ok(requests.some(({ endpoint, params }) => endpoint === 'pages/receipts' && !params.search));
        assert.equal(userListeners.size, 3, 'the previous routed page must release its user and refresh listeners');
    } finally {
        view.unmount();
        window.addEventListener = addListener;
        window.removeEventListener = removeListener;
    }
    assert.equal(userListeners.size, 0);
});

test('product viewers can reach product types from the sidebar without a separate category permission', async () => {
    const user = { id: 7, permissions: { 'products.view': true } };
    const App = component('App.vue', {
        '@/services/api': {}, '@/router': { currentUser: () => user, refreshCurrentUser: async () => null },
        '@/router/access': { userContextChanged }, '@/components/NotificationBell.vue': stub,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/products', component: stub, meta: { title: 'Products' } },
        { path: '/categories', component: stub, meta: { title: 'Ապրանքի տեսակներ' } },
        { path: '/no-access', component: stub },
    ] });
    await router.push('/products');
    const view = mount(App, { router });
    try {
        await settle();
        const link = view.root.querySelector('.sidebar a[href="/categories"]');
        assert.ok(link);
        link.click(); await settle();
        assert.equal(router.currentRoute.value.path, '/categories');
        assert.match(view.root.querySelector('.topbar-label').textContent, /Ապրանքի տեսակներ/);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { id: 7, permissions: {} } }));
        await settle();
        assert.equal(view.root.querySelector('.sidebar a[href="/categories"]'), null);
    } finally { view.unmount(); }
});

test('account details stay open on outside clicks and close from their explicit controls', async () => {
    const user = { id: 7, name: 'User', permissions: { 'products.view': true } };
    const App = component('App.vue', {
        '@/services/api': {}, '@/router': { currentUser: () => user, refreshCurrentUser: async () => null },
        '@/router/access': { userContextChanged }, '@/components/NotificationBell.vue': stub,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/products', component: stub }, { path: '/no-access', component: stub },
    ] });
    await router.push('/products');
    const view = mount(App, { router });
    try {
        for (const dismiss of ['x', 'close']) {
            view.root.querySelector('.profile-trigger').click(); await settle();
            view.root.querySelector('.profile-menu-item').click(); await settle();
            const backdrop = view.root.querySelector('.profile-details-backdrop');
            assert.ok(backdrop);
            backdrop.dispatchEvent(new dom.window.Event('pointerdown', { bubbles: true }));
            backdrop.click(); await settle();
            assert.equal(view.root.querySelector('.profile-details-backdrop'), backdrop);
            backdrop.querySelector(dismiss === 'x' ? '.close-button' : '.modal-actions button').click(); await settle();
            assert.equal(view.root.querySelector('.profile-details-backdrop'), null, dismiss);
        }
    } finally { view.unmount(); }
});

async function purchasingShell(api, user, path) {
    const context = { currentUser: () => user, refreshCurrentUser: async () => null };
    const Purchasing = component('views/purchasing/Index.vue', {
        '@/components/Pagination.vue': stub, '@/components/ExportActions.vue': stub,
        '@/components/ListFilterBar.vue': stub, '@/services/api': api, '@/router': context, '@/dateUtils': dateUtils,
    });
    const App = component('App.vue', {
        '@/services/api': api, '@/router': context, '@/router/access': { userContextChanged }, '@/components/NotificationBell.vue': stub,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/purchases', component: Purchasing, meta: { title: 'Purchases' } },
        { path: '/receipts', component: Purchasing, meta: { title: 'Receipts' } },
        { path: '/no-access', component: stub },
    ] });
    await router.push(path);
    return { ...mount(App, { router }), router };
}

const purchaseSnapshot = (records = []) => ({ data: { data: records, columns: { order_no: 'Order', status: 'Status' }, pagination: { total: records.length, per_page: 15, current_page: 1, last_page: 1 } } });
const receiptOrder = { id: 7, order_no: 'ORDER-7', supplier: 'Supplier', items: [{ id: 4, name: 'Reagent', code: 'REAG-4', unit: 'հատ', ordered_qty: 10, received_qty: 6, expiry_control: true }] };

test('an approved purchase shortcut opens its outstanding receipt lines without writing or showing full orders as receivable', async () => {
    const calls = [];
    const user = { id: 8, permissions: { 'purchases.view': true, 'receipts.view': true, 'receipts.create': true } };
    const api = { async get(endpoint) {
        calls.push(endpoint);
        if (endpoint === 'purchasing/receipts/options') return { data: { data: { suppliers: [], products: [], orders: [receiptOrder] } } };
        return purchaseSnapshot(endpoint === 'pages/purchases' ? [
            { id: 7, order_no: 'ORDER-7', status: 'approved', has_remaining_items: true },
            { id: 9, order_no: 'ORDER-9', status: 'approved', has_remaining_items: false },
        ] : []);
    }, async post() { assert.fail('Opening a receipt must not register stock'); } };
    const view = await purchasingShell(api, user, '/purchases');
    try {
        await settle();
        const links = view.root.querySelectorAll('tbody a[href="/receipts?order=7"]');
        assert.equal(links.length, 1);
        assert.equal(view.root.querySelector('tbody a[href="/receipts?order=9"]'), null);
        assert.match(view.root.querySelector('tbody').textContent, /Մուտքագրման մնացորդ չկա/);
        links[0].click(); await settle(); await settle();
        assert.equal(view.router.currentRoute.value.path, '/receipts');
        assert.equal(view.root.querySelector('.purchase-modal select').value, '7');
        assert.match(view.root.querySelector('.receipt-line').textContent, /Պատվերի մնացորդ՝ 4/);
        assert.ok(calls.includes('purchasing/receipts/options'));
    } finally { view.unmount(); }

    const viewer = await purchasingShell(api, { id: 8, permissions: { 'purchases.view': true, 'receipts.view': true } }, '/purchases');
    try { await settle(); assert.equal(viewer.root.querySelector('tbody a[href="/receipts?order=7"]'), null); }
    finally { viewer.unmount(); }
});

test('a receipt deep link with no outstanding order explains the issue instead of opening an empty form', async () => {
    const view = await purchasingShell({ async get(endpoint) {
        return endpoint.startsWith('purchasing/') ? { data: { data: { suppliers: [], products: [], orders: [] } } } : purchaseSnapshot();
    } }, { id: 8, permissions: { 'receipts.view': true, 'receipts.create': true } }, '/receipts?order=7');
    try {
        await settle();
        assert.equal(view.root.querySelector('.purchase-modal'), null);
        assert.match(view.root.querySelector('.notice-success').textContent, /չունի մուտքագրման ենթակա մնացորդ/);
    } finally { view.unmount(); }
});

test('a late receipt options response cannot open a previous-account form after receipt creation permission is removed', async () => {
    const pending = deferred();
    const user = { id: 8, permissions: { 'receipts.view': true, 'receipts.create': true } };
    const view = await purchasingShell({ async get(endpoint) {
        return endpoint.startsWith('purchasing/') ? pending.promise : purchaseSnapshot();
    } }, user, '/receipts?order=7');
    try {
        await settle();
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { id: 9, permissions: { 'receipts.view': true } } }));
        await settle();
        pending.resolve({ data: { data: { suppliers: [], products: [], orders: [receiptOrder] } } });
        await settle();
        assert.equal(view.root.querySelector('.purchase-modal'), null);
        assert.equal(view.root.querySelector('.page-heading button'), null);
    } finally { view.unmount(); }
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
        { path: '/categories', component: stub, meta: { title: 'Categories' } },
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

function notificationEnvironment(sound = 'on') {
    const previousSound = localStorage.getItem('lagerNotificationSound');
    const previousContext = window.AudioContext;
    const previousSetInterval = window.setInterval;
    const previousClearInterval = window.clearInterval;
    const previousRealtimeStatus = window.lagerRealtimeStatus;
    const intervals = new Map();
    const audio = { started: 0, resumed: 0, closed: 0, frequencies: [] };
    let nextInterval = 0;
    localStorage.setItem('lagerNotificationSound', sound);
    window.lagerRealtimeStatus = { connected: false, status: 'disconnected' };
    window.setInterval = (callback, delay) => {
        intervals.set(++nextInterval, { callback, delay });
        return nextInterval;
    };
    window.clearInterval = (id) => intervals.delete(id);
    window.AudioContext = class {
        state = 'suspended';
        currentTime = 0;
        destination = {};
        async resume() { this.state = 'running'; audio.resumed += 1; }
        async close() { this.state = 'closed'; audio.closed += 1; }
        createOscillator() {
            return {
                frequency: { set value(value) { audio.frequencies.push(value); } },
                connect() {},
                start() { audio.started += 1; },
                stop() {},
            };
        }
        createGain() {
            return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} };
        }
    };
    return {
        audio, intervals,
        poll() {
            assert.equal(intervals.size, 1);
            const { callback, delay } = intervals.values().next().value;
            assert.equal(delay, 45000);
            return callback();
        },
        restore() {
            window.AudioContext = previousContext;
            window.setInterval = previousSetInterval;
            window.clearInterval = previousClearInterval;
            window.lagerRealtimeStatus = previousRealtimeStatus;
            if (previousSound === null) localStorage.removeItem('lagerNotificationSound');
            else localStorage.setItem('lagerNotificationSound', previousSound);
        },
    };
}

function notificationResponse(items) {
    return { data: { data: structuredClone(items), unread_count: items.filter((item) => !item.read).length } };
}

function notificationItem(key, read = false) {
    return { key, read, title: key, detail: 'Notification detail', tone: 'blue', link: '/notifications' };
}

async function mountNotificationBell(api) {
    const Bell = component('components/NotificationBell.vue', {
        '@/services/api': api,
        '@/router': { currentUser: () => ({ id: 1, permissions: { 'notifications.view': true } }) },
        '@/notifications': notifications,
        '@/notificationAudio': notificationAudio,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/notifications', component: stub },
    ] });
    await router.push('/notifications');
    return mount(Bell, { router });
}

test('the notification bell sounds once for new unread poll results and stays silent for existing or read items', async () => {
    const environment = notificationEnvironment();
    let feed = [notificationItem('existing'), notificationItem('already-read', true)];
    const view = await mountNotificationBell({
        async get(endpoint) { assert.equal(endpoint, 'notifications'); return notificationResponse(feed); },
    });
    try {
        await settle();
        assert.equal(view.root.querySelector('.notification-badge').textContent, '1');
        assert.equal(environment.audio.started, 0, 'the initial feed must not play a notification tone');
        view.root.querySelector('.notification-bell-trigger').click();
        await settle();
        assert.equal(environment.audio.started, 0, 'opening the bell activates audio without sounding');
        feed.push(notificationItem('new-read', true));
        await environment.poll();
        await settle();
        assert.equal(environment.audio.started, 0, 'new read items must not sound');
        feed.push(notificationItem('new-unread-one'), notificationItem('new-unread-two'));
        await environment.poll();
        await settle();
        assert.equal(view.root.querySelector('.notification-badge').textContent, '3');
        assert.match(view.root.textContent, /new-unread-one/);
        assert.equal(environment.audio.started, 1, 'a poll with multiple new unread items plays one tone');
        assert.deepEqual(environment.audio.frequencies, [740]);
        await environment.poll();
        await settle();
        assert.equal(environment.audio.started, 1, 'the same unread feed must not repeat its tone');
        feed.find((item) => item.key === 'new-unread-one').read = true;
        await environment.poll();
        await settle();
        assert.equal(view.root.querySelector('.notification-badge').textContent, '2');
        assert.equal(environment.audio.started, 1, 'marking an item read must not sound');
        window.dispatchEvent(new CustomEvent('lager:notification-sound', { detail: false }));
        feed.push(notificationItem('new-but-muted'));
        await environment.poll();
        await settle();
        assert.equal(environment.audio.started, 1, 'disabled sound suppresses new unread tones');
    } finally { view.unmount(); environment.restore(); }
    assert.equal(environment.intervals.size, 0);
    assert.equal(environment.audio.closed, 1);
});

test('enabling sound from the notification bell plays a preview and persists the preference', async () => {
    const environment = notificationEnvironment('off');
    const view = await mountNotificationBell({ async get() { return notificationResponse([]); } });
    try {
        await settle();
        view.root.querySelector('.notification-bell-trigger').click();
        await settle();
        const soundToggle = view.root.querySelector('.sound-mini-toggle');
        assert.equal(soundToggle.getAttribute('aria-pressed'), 'false');
        soundToggle.click();
        await settle();
        assert.equal(localStorage.getItem('lagerNotificationSound'), 'on');
        assert.equal(soundToggle.getAttribute('aria-pressed'), 'true');
        assert.equal(environment.audio.resumed, 1);
        assert.equal(environment.audio.started, 1);
        assert.deepEqual(environment.audio.frequencies, [740]);
        soundToggle.click();
        await settle();
        assert.equal(localStorage.getItem('lagerNotificationSound'), 'off');
        assert.equal(soundToggle.getAttribute('aria-pressed'), 'false');
        assert.equal(environment.audio.started, 1, 'disabling sound does not play another preview');
    } finally { view.unmount(); environment.restore(); }
});

test('a session change discards the old notification poll and establishes a silent new-user feed', async () => {
    const environment = notificationEnvironment();
    const pending = [];
    const view = await mountNotificationBell({
        get(endpoint) {
            assert.equal(endpoint, 'notifications');
            const request = deferred();
            pending.push(request);
            return request.promise;
        },
    });
    try {
        pending[0].resolve(notificationResponse([notificationItem('old-user-initial')]));
        await settle();
        view.root.querySelector('.notification-bell-trigger').click();
        await settle();
        const oldPoll = environment.poll();
        await settle();
        assert.equal(pending.length, 2);
        window.dispatchEvent(new CustomEvent('lager:user', {
            detail: { id: 2, permissions: { 'notifications.view': true } },
        }));
        await settle();
        assert.equal(pending.length, 3);
        assert.equal(view.root.querySelector('.notification-badge'), null);
        assert.doesNotMatch(view.root.textContent, /old-user-initial/);
        pending[1].resolve(notificationResponse([notificationItem('late-old-user-alert')]));
        await oldPoll;
        await settle();
        assert.equal(view.root.querySelector('.notification-badge'), null);
        assert.doesNotMatch(view.root.textContent, /late-old-user-alert/);
        assert.equal(environment.audio.started, 0);
        pending[2].resolve(notificationResponse([notificationItem('new-user-initial')]));
        await settle();
        assert.equal(view.root.querySelector('.notification-badge').textContent, '1');
        view.root.querySelector('.notification-bell-trigger').click(); await settle();
        assert.match(view.root.textContent, /new-user-initial/);
        assert.equal(environment.audio.started, 0, 'the new user feed is a silent initial snapshot');
    } finally { view.unmount(); environment.restore(); }
});

async function mountNotificationsPage(api) {
    const Page = component('views/notifications/Index.vue', {
        '@/services/api': api,
        '@/router': { currentUser: () => ({ id: 1, permissions: { 'notifications.view': true } }) },
        '@/notifications': notifications, '@/notificationAudio': notificationAudio,
    });
    const router = VueRouter.createRouter({ history: VueRouter.createMemoryHistory(), routes: [
        { path: '/notifications', component: stub, meta: { title: 'Notifications' } },
    ] });
    await router.push('/notifications');
    return mount(Page, { router });
}

function realtimeStatus(connected) {
    window.lagerRealtimeStatus = { connected, status: connected ? 'connected' : 'disconnected' };
    window.dispatchEvent(new CustomEvent('lager:realtime-status', { detail: window.lagerRealtimeStatus }));
}

test('realtime notifications coalesce bursts, sound once and synchronize read status with fallback only offline', async () => {
    const environment = notificationEnvironment();
    let feed = [notificationItem('existing')];
    let calls = 0;
    const api = {
        async get() { calls += 1; return notificationResponse(feed); },
        async post(endpoint, payload) { assert.equal(endpoint, 'notifications/read'); feed.find((item) => item.key === payload.key).read = true; return {}; },
    };
    const bell = await mountNotificationBell(api);
    const page = await mountNotificationsPage(api);
    try {
        await settle();
        assert.equal(calls, 2);
        assert.equal(environment.intervals.size, 2);
        bell.root.querySelector('.notification-bell-trigger').click(); await settle();
        realtimeStatus(true); await settle();
        assert.equal(environment.intervals.size, 0, 'WebSocket subscription stops fallback polls');
        assert.match(page.root.textContent, /Փոփոխությունները ցուցադրվում են անմիջապես/);
        feed.push(notificationItem('live-unread'));
        window.dispatchEvent(new CustomEvent('lager:data-changed'));
        window.dispatchEvent(new CustomEvent('lager:data-changed'));
        await settle();
        assert.equal(calls, 4, 'a burst fetches each visible notification component once');
        assert.equal(bell.root.querySelector('.notification-badge').textContent, '2');
        assert.match(page.root.textContent, /live-unread/);
        assert.equal(environment.audio.started, 1, 'only the bell announces new unread items');
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(environment.audio.started, 1, 'duplicate invalidations do not repeat a tone');
        page.root.querySelector('.notification-open').click(); await settle();
        assert.equal(bell.root.querySelector('.notification-badge').textContent, '1');
        assert.doesNotMatch(page.root.querySelector('.notification-list').textContent, /existing/);
        assert.equal(environment.audio.started, 1, 'read synchronization stays silent');
        realtimeStatus(false); await settle();
        assert.equal(environment.intervals.size, 2, 'disconnect restores fallback polls');
        assert.match(page.root.textContent, /պահուստային թարմացումը ակտիվ է/);
        realtimeStatus(true); await settle();
        assert.equal(environment.intervals.size, 0);
    } finally { bell.unmount(); page.unmount(); environment.restore(); }
    assert.equal(environment.intervals.size, 0);
});

test('realtime notification responses cannot restore an old session on either bell or page', async () => {
    const environment = notificationEnvironment();
    const requests = [];
    const api = { get() { const request = deferred(); requests.push(request); return request.promise; } };
    const bell = await mountNotificationBell(api);
    const page = await mountNotificationsPage(api);
    try {
        requests[0].resolve(notificationResponse([notificationItem('initial')]));
        requests[1].resolve(notificationResponse([notificationItem('initial')]));
        await settle(); bell.root.querySelector('.notification-bell-trigger').click(); await settle();
        realtimeStatus(true);
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(requests.length, 4);
        window.dispatchEvent(new CustomEvent('lager:user', { detail: { id: 2, permissions: { 'notifications.view': true } } }));
        await settle(); assert.equal(requests.length, 6);
        requests[4].resolve(notificationResponse([notificationItem('new-session')]));
        requests[5].resolve(notificationResponse([notificationItem('new-session')]));
        await settle();
        assert.match(page.root.textContent, /new-session/);
        assert.equal(bell.root.querySelector('.notification-badge').textContent, '1');
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(requests.length, 8, 'an unresolved old-session fetch cannot block the current live subscription');
        requests[6].resolve(notificationResponse([notificationItem('new-session')]));
        requests[7].resolve(notificationResponse([notificationItem('new-session')]));
        requests[2].resolve(notificationResponse([notificationItem('stale-secret')]));
        requests[3].resolve(notificationResponse([notificationItem('stale-secret')]));
        await settle();
        assert.doesNotMatch(page.root.textContent, /stale-secret/);
        assert.equal(bell.root.querySelector('.notification-badge').textContent, '1');
        assert.equal(environment.audio.started, 0);
        bell.unmount(); page.unmount();
        window.dispatchEvent(new CustomEvent('lager:data-changed')); await settle();
        assert.equal(requests.length, 8);
    } finally { if (bell.root.isConnected) bell.unmount(); if (page.root.isConnected) page.unmount(); environment.restore(); }
});
