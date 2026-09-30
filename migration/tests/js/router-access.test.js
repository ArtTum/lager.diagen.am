import test from 'node:test';
import assert from 'node:assert/strict';
import { firstAvailablePath, userContextChanged } from '../../resources/js/router/access.js';

const routes = [
    { path: '/login', meta: { guest: true } },
    { path: '/dashboard', meta: { permission: 'dashboard.view' } },
    { path: '/products/:product/label', meta: { permission: 'products.view' } },
    { path: '/products', meta: { permission: 'products.view' } },
];

test('permissions fallback skips dynamic detail routes and uses the module page', () => {
    assert.equal(firstAvailablePath(routes, { 'products.view': true }), '/products');
});

test('permissions fallback prefers dashboard when it is available', () => {
    assert.equal(firstAvailablePath(routes, { 'dashboard.view': true, 'products.view': true }), '/dashboard');
});

test('permissions fallback sends users with no page access to the no-access screen', () => {
    assert.equal(firstAvailablePath(routes, {}), '/no-access');
});

test('user context comparison detects permission and branch changes', () => {
    const current = { role: { name: 'branch' }, branch: { id: 2 }, permissions: { 'stock.view': true } };
    assert.equal(userContextChanged(current, structuredClone(current)), false);
    assert.equal(userContextChanged(current, { ...current, permissions: {} }), true);
    assert.equal(userContextChanged(current, { ...current, branch: { id: 3 } }), true);
});
