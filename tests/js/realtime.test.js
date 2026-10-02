import test from 'node:test';
import assert from 'node:assert/strict';
import { createRealtimeController } from '../../resources/js/realtime.js';

function environment(factory) {
    let token = 'first';
    let updates = 0;
    const states = [];
    const clients = [];
    const createClient = factory || (async () => {
        const client = { stopped: false, subscribe(callbacks) { this.callbacks = callbacks; }, disconnect() { this.stopped = true; } };
        clients.push(client);
        return client;
    });
    const controller = createRealtimeController({ createClient, getToken: () => token, enabled: true, emitStatus: (state) => states.push(state), invalidate: () => { updates += 1; } });
    return { controller, clients, states, updates: () => updates, token: (value) => { token = value; } };
}

test('only an authorized private subscription enables realtime and reconnect recovers missed changes', async () => {
    const env = environment();
    await env.controller.sync({ id: 1 });
    const client = env.clients[0];
    assert.equal(env.states.at(-1).connected, false);
    client.callbacks.onSubscribed();
    assert.deepEqual(env.states.at(-1), { connected: true, status: 'connected' });
    assert.equal(env.updates(), 1);
    client.callbacks.onChange();
    assert.equal(env.updates(), 2);
    client.callbacks.onConnectionChange('unavailable');
    assert.equal(env.states.at(-1).connected, false);
    client.callbacks.onConnectionChange('connected');
    assert.equal(env.states.at(-1).connected, false);
    client.callbacks.onSubscribed();
    assert.equal(env.updates(), 3);
    env.controller.stop();
});

test('new login closes its old socket and ignores old messages and authorization callbacks', async () => {
    const env = environment();
    await env.controller.sync({ id: 1 });
    const first = env.clients[0];
    first.callbacks.onSubscribed();
    env.token('second');
    await env.controller.sync({ id: 2 });
    assert.equal(first.stopped, true);
    const updates = env.updates();
    first.callbacks.onChange();
    first.callbacks.onSubscribed();
    first.callbacks.onError();
    assert.equal(env.updates(), updates);
    assert.equal(env.states.at(-1).status, 'connecting');
    env.clients[1].callbacks.onSubscribed();
    assert.equal(env.updates(), updates + 1);
    env.controller.stop();
});

test('a socket whose SDK finishes loading after logout is disconnected without subscribing', async () => {
    let resolve;
    const pending = new Promise((done) => { resolve = done; });
    const env = environment(() => pending);
    const start = env.controller.sync({ id: 1 });
    env.token(null);
    await env.controller.sync(null);
    const late = { stopped: false, subscribe() { throw new Error('Stale socket must not subscribe'); }, disconnect() { this.stopped = true; } };
    resolve(late);
    await start;
    assert.equal(late.stopped, true);
    assert.equal(env.updates(), 0);
    assert.equal(env.states.at(-1).connected, false);
});

test('a failed SDK load retains the session and can retry on the next sync', async () => {
    let calls = 0;
    const client = { subscribe(callbacks) { this.callbacks = callbacks; }, disconnect() {} };
    const env = environment(async () => {
        calls += 1;
        if (calls === 1) throw new Error('Temporary network failure');
        return client;
    });
    await env.controller.sync({ id: 1 });
    assert.equal(env.states.at(-1).status, 'disconnected');
    await env.controller.sync({ id: 1 });
    client.callbacks.onSubscribed();
    assert.equal(calls, 2);
    assert.equal(env.states.at(-1).connected, true);
    env.controller.stop();
});

test('a normal user refresh keeps its subscribed socket while token removal invalidates it', async () => {
    const env = environment();
    await env.controller.sync({ id: 1 });
    env.clients[0].callbacks.onSubscribed();
    await env.controller.sync({ id: 1, name: 'Updated profile' });
    assert.equal(env.clients.length, 1);
    env.token(null);
    await env.controller.sync(null);
    assert.equal(env.clients[0].stopped, true);
    env.clients[0].callbacks.onChange();
    assert.equal(env.updates(), 1);
});

test('temporary private authorization failure is recoverable with the same user and token', async () => {
    const env = environment();
    await env.controller.sync({ id: 1 });
    const failed = env.clients[0];
    failed.callbacks.onError();
    assert.equal(failed.stopped, true);
    assert.equal(env.states.at(-1).status, 'disconnected');
    await env.controller.sync({ id: 1 });
    assert.equal(env.clients.length, 2);
    failed.callbacks.onSubscribed();
    assert.equal(env.updates(), 0);
    env.clients[1].callbacks.onSubscribed();
    assert.equal(env.states.at(-1).connected, true);
    assert.equal(env.updates(), 1);
    env.controller.stop();
});
