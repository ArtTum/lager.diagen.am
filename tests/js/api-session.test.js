import test from 'node:test';
import assert from 'node:assert/strict';
import { AxiosError } from 'axios';
import api from '../../resources/js/services/api.js';

const values = new Map();
globalThis.localStorage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
};
globalThis.window = new EventTarget();
globalThis.CustomEvent ??= class extends Event {
    constructor(type, options = {}) { super(type, options); this.detail = options.detail; }
};

function deferred() {
    let resolve;
    const promise = new Promise((complete) => { resolve = complete; });
    return { promise, resolve };
}

function pendingUnauthorizedRequest() {
    let rejectRequest;
    const ready = deferred();
    const request = api.get('auth/me', {
        adapter(config) {
            ready.resolve(config);
            return new Promise((resolve, reject) => {
                rejectRequest = () => reject(new AxiosError('Unauthorized', 'ERR_BAD_REQUEST', config, null, {
                    status: 401, data: {}, headers: {}, config,
                }));
            });
        },
    });
    return { request, ready: ready.promise, reject: () => rejectRequest() };
}

test('a delayed unauthorized response preserves a newer session', async () => {
    localStorage.setItem('lagerAuthToken', 'expired-token');
    let unauthorizedEvents = 0;
    const listener = () => { unauthorizedEvents += 1; };
    window.addEventListener('lager:unauthorized', listener);
    try {
        const pending = pendingUnauthorizedRequest();
        assert.equal((await pending.ready).headers.Authorization, 'Bearer expired-token');
        localStorage.setItem('lagerAuthToken', 'new-token');
        pending.reject();
        await assert.rejects(pending.request);
        assert.equal(localStorage.getItem('lagerAuthToken'), 'new-token');
        assert.equal(unauthorizedEvents, 0);
    } finally { window.removeEventListener('lager:unauthorized', listener); }
});

test('an unauthorized response clears its own session and announces expiration once', async () => {
    localStorage.setItem('lagerAuthToken', 'current-token');
    let unauthorizedEvents = 0;
    const listener = () => { unauthorizedEvents += 1; };
    window.addEventListener('lager:unauthorized', listener);
    try {
        const first = pendingUnauthorizedRequest();
        const second = pendingUnauthorizedRequest();
        await Promise.all([first.ready, second.ready]);
        first.reject();
        await assert.rejects(first.request);
        second.reject();
        await assert.rejects(second.request);
        assert.equal(localStorage.getItem('lagerAuthToken'), null);
        assert.equal(unauthorizedEvents, 1);
    } finally { window.removeEventListener('lager:unauthorized', listener); }
});

test('a request without a bearer token cannot invalidate a later login', async () => {
    localStorage.removeItem('lagerAuthToken');
    const pending = pendingUnauthorizedRequest();
    await pending.ready;
    localStorage.setItem('lagerAuthToken', 'new-token');
    pending.reject();
    await assert.rejects(pending.request);
    assert.equal(localStorage.getItem('lagerAuthToken'), 'new-token');
});
