import test from 'node:test';
import assert from 'node:assert/strict';
import { canCreateRecord } from '../../resources/js/permissions.js';

test('role creation requires both legacy create and permission-edit grants', () => {
    assert.equal(canCreateRecord('roles', { 'roles.create': true }), false);
    assert.equal(canCreateRecord('roles', { 'roles.edit': true }), false);
    assert.equal(canCreateRecord('roles', { 'roles.create': true, 'roles.edit': true }), true);
});

test('other catalog creation uses its own create permission', () => {
    assert.equal(canCreateRecord('products', { 'products.create': true }), true);
    assert.equal(canCreateRecord('products', { 'products.view': true }), false);
});
