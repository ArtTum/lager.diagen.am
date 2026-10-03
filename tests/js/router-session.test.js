import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { transformSync } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';
import { JSDOM } from 'jsdom';
import * as access from '../../resources/js/router/access.js';

const require = createRequire(import.meta.url);
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/' });
globalThis.CustomEvent ??= class extends Event {
    constructor(type, options = {}) { super(type, options); this.detail = options.detail; }
};

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((success, failure) => { resolve = success; reject = failure; });
    return { promise, resolve, reject };
}

function loadScript(content, dependencies) {
    const { code } = transformSync(content, { format: 'cjs', loader: 'js' });
    const module = { exports: {} };
    new Function('require', 'module', 'exports', code)(
        (name) => Object.hasOwn(dependencies, name) ? dependencies[name] : require(name),
        module, module.exports,
    );
    return module.exports;
}

function routerEnvironment(api) {
    const values = new Map();
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
    globalThis.window = new EventTarget();
    let reloads = 0;
    window.location = { reload: () => { reloads += 1; } };
    const replacements = [];
    let guard;
    let configuredRoutes;
    const router = {
        currentRoute: { value: { path: '/stock' } },
        beforeEach: (callback) => { guard = callback; },
        replace: async (path) => { replacements.push(path); },
    };
    const content = readFileSync(new URL('../../resources/js/router/index.js', import.meta.url), 'utf8');
    const context = loadScript(content, {
        'vue-router': { createRouter: ({ routes }) => { configuredRoutes = routes; return router; }, createWebHistory: () => ({}) },
        '@/services/api': api,
        './access': access,
    });
    return { ...context, routes: configuredRoutes, guard: (to = { meta: { permission: 'stock.view' } }) => guard(to), replacements, reloads: () => reloads };
}

test('the product types route uses existing product viewing permission and preserves the products landing page', async () => {
    const context = routerEnvironment({ get: async () => { throw new Error('Cached user should not reload'); } });
    const categories = context.routes.find(route => route.path === '/categories');
    assert.equal(categories.meta.permission, 'products.view');
    localStorage.setItem('lagerAuthToken', 'test-session');
    context.setCurrentUser({ id: 1, permissions: { 'products.view': true } });
    assert.equal(await context.guard(categories), true);
    assert.equal(access.firstAvailablePath(context.routes, { 'products.view': true }), '/products');
    context.setCurrentUser({ id: 2, permissions: { 'stock.view': true } });
    assert.equal(await context.guard(categories), access.firstAvailablePath(context.routes, { 'stock.view': true }));
});

const account = (name) => ({ id: name, name, permissions: { 'stock.view': true } });

test('a delayed bootstrap failure cannot erase a newer login or its user context', async () => {
    const pending = deferred();
    const context = routerEnvironment({ get: () => pending.promise });
    localStorage.setItem('lagerAuthToken', 'old-token');
    const navigation = context.guard();
    localStorage.setItem('lagerAuthToken', 'new-token');
    const newUser = account('New login');
    context.setCurrentUser(newUser);
    pending.reject({ response: { status: 401 } });
    assert.equal(await navigation, true);
    assert.equal(localStorage.getItem('lagerAuthToken'), 'new-token');
    assert.equal(context.currentUser(), newUser);
});

test('a transient bootstrap failure retains the token and retries user loading on the next navigation', async () => {
    let attempts = 0;
    const user = account('Recovered session');
    const context = routerEnvironment({ get: async () => {
        attempts += 1;
        if (attempts === 1) throw { response: { status: 503 } };
        return { data: { data: user } };
    } });
    localStorage.setItem('lagerAuthToken', 'valid-token');
    assert.equal(await context.guard(), '/login');
    assert.equal(localStorage.getItem('lagerAuthToken'), 'valid-token');
    assert.equal(await context.guard(), true);
    assert.equal(attempts, 2);
    assert.equal(context.currentUser(), user);
});

test('a replaced stored token loads its own context before authorizing navigation', async () => {
    const newUser = account('New login');
    let loads = 0;
    const context = routerEnvironment({ get: async () => { loads += 1; return { data: { data: newUser } }; } });
    localStorage.setItem('lagerAuthToken', 'old-token');
    context.setCurrentUser(account('Old login'));
    localStorage.setItem('lagerAuthToken', 'new-token');
    assert.equal(await context.guard(), true);
    assert.equal(loads, 1);
    assert.equal(context.currentUser(), newUser);
});

test('setting a newer user context invalidates an already pending context refresh', async () => {
    const pending = deferred();
    const context = routerEnvironment({ get: () => pending.promise });
    localStorage.setItem('lagerAuthToken', 'same-token');
    context.setCurrentUser(account('Before refresh'));
    const refresh = context.refreshCurrentUser();
    const current = account('Updated permissions');
    context.setCurrentUser(current);
    pending.resolve({ data: { data: account('Stale permissions') } });
    assert.equal(await refresh, current);
    assert.equal(context.currentUser(), current);
});

test('a cross-tab token change clears cached user data and reloads for the new account', () => {
    const context = routerEnvironment({});
    localStorage.setItem('lagerAuthToken', 'old-token');
    context.setCurrentUser(account('Old login'));
    localStorage.setItem('lagerAuthToken', 'new-token');
    const event = new Event('storage');
    event.key = 'lagerAuthToken';
    window.dispatchEvent(event);
    assert.equal(context.currentUser(), null);
    assert.equal(context.reloads(), 1);
});

function mountShell(api) {
    for (const key of ['window', 'document', 'history', 'HTMLElement', 'SVGElement', 'Element', 'Node', 'CustomEvent', 'localStorage']) {
        globalThis[key] = dom.window[key];
    }
    window.matchMedia = () => ({ matches: false });
    const Vue = require('vue');
    const stub = { render: () => null };
    const replacements = [];
    let user = account('Original session');
    const context = {
        currentUser: () => user,
        refreshCurrentUser: async () => null,
        setCurrentUser(value) { user = value; window.dispatchEvent(new CustomEvent('lager:user', { detail: value })); },
    };
    const filename = new URL('../../resources/js/App.vue', import.meta.url);
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname });
    const script = compileScript(descriptor, { id: 'session-shell', inlineTemplate: true });
    const App = loadScript(script.content, {
        '@/services/api': api, '@/router': context, '@/router/access': access,
        '@/components/NotificationBell.vue': stub,
        '@/services/realtime': { startRealtime: () => () => {} },
        'vue-router': { useRoute: () => Vue.reactive({ path: '/stock', meta: {} }), useRouter: () => ({ replace: async (path) => { replacements.push(path); } }), RouterView: stub },
    }).default;
    const root = document.createElement('div');
    document.body.append(root);
    const app = Vue.createApp(App);
    app.component('AppIcon', stub);
    app.component('RouterLink', stub);
    app.mount(root);
    return { root, context, replacements, async settle() { await new Promise((resolve) => setImmediate(resolve)); await Vue.nextTick(); }, unmount() { app.unmount(); root.remove(); } };
}

test('a delayed logout revokes its original session and preserves a newer login', async () => {
    const pending = deferred();
    const calls = [];
    const view = mountShell({ post: (...args) => { calls.push(args); return pending.promise; } });
    try {
        localStorage.setItem('lagerAuthToken', 'old-token');
        view.root.querySelector('.logout-icon').click();
        assert.equal(calls[0][2].headers.Authorization, 'Bearer old-token');
        localStorage.setItem('lagerAuthToken', 'new-token');
        const newer = account('New session');
        view.context.setCurrentUser(newer);
        pending.resolve({});
        await view.settle();
        assert.equal(localStorage.getItem('lagerAuthToken'), 'new-token');
        assert.equal(view.context.currentUser(), newer);
        assert.deepEqual(view.replacements, []);
    } finally { view.unmount(); }
});

test('logging out the current session clears both stored credentials and router user context', async () => {
    const view = mountShell({ post: async () => ({}) });
    try {
        localStorage.setItem('lagerAuthToken', 'current-token');
        view.root.querySelector('.logout-icon').click();
        await view.settle();
        assert.equal(localStorage.getItem('lagerAuthToken'), null);
        assert.equal(view.context.currentUser(), null);
        assert.deepEqual(view.replacements, ['/login']);
    } finally { view.unmount(); }
});
