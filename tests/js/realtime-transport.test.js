import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';

async function browserTransport() {
    // Bundle the actual service and installed browser SDKs as Vite does. Only
    // the HTTP auth service is replaced; WebSocket URLs come from Pusher itself.
    const result = await build({
        entryPoints: [fileURLToPath(new URL('../../resources/js/services/realtime.js', import.meta.url))],
        bundle: true,
        platform: 'browser',
        format: 'iife',
        globalName: 'RealtimeTransport',
        write: false,
        plugins: [{
            name: 'isolated-broadcast-auth',
            setup(builder) {
                builder.onResolve({ filter: /^\.\/api$/ }, () => ({ path: 'broadcast-auth', namespace: 'test' }));
                builder.onLoad({ filter: /.*/, namespace: 'test' }, () => ({
                    contents: 'export default window.realtimeTestApi;',
                    loader: 'js',
                }));
            },
        }],
    });
    return result.outputFiles[0].text;
}

async function waitFor(predicate) {
    const deadline = Date.now() + 1000;
    while (!predicate() && Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
    assert.ok(predicate(), 'The installed browser SDK must complete its private subscription');
}

test('production realtime uses the installed SDK to authorize a private channel over same-origin WSS', async () => {
    const script = await browserTransport();
    const dom = new JSDOM('<!doctype html><html><head><meta name="lager-realtime-key" content="test-key"></head><body></body></html>', {
        url: 'https://lager.diagen.am/',
        runScripts: 'outside-only',
    });
    const { window } = dom;
    const sockets = [];
    const authRequests = [];
    const unexpectedRequests = [];
    let invalidations = 0;
    let stop;

    class FakeWebSocket {
        static CONNECTING = 0;
        static OPEN = 1;
        static CLOSING = 2;
        static CLOSED = 3;

        constructor(url) {
            this.url = url;
            this.readyState = FakeWebSocket.CONNECTING;
            this.messages = [];
            sockets.push(this);
            window.queueMicrotask(() => {
                if (this.readyState !== FakeWebSocket.CONNECTING) return;
                this.readyState = FakeWebSocket.OPEN;
                this.onopen?.({});
                this.receive('pusher:connection_established', { socket_id: '123.456', activity_timeout: 30 });
            });
        }

        receive(event, data, channel) {
            this.onmessage?.({ data: JSON.stringify({ event, data: JSON.stringify(data), ...(channel ? { channel } : {}) }) });
        }

        send(message) {
            const payload = JSON.parse(message);
            this.messages.push(payload);
            if (payload.event === 'pusher:subscribe') {
                window.queueMicrotask(() => this.receive('pusher_internal:subscription_succeeded', {}, payload.data.channel));
            }
        }

        close() {
            if (this.readyState === FakeWebSocket.CLOSED) return;
            this.readyState = FakeWebSocket.CLOSED;
            this.onclose?.({ code: 1000, reason: '', wasClean: true });
        }
    }

    window.WebSocket = FakeWebSocket;
    window.XMLHttpRequest = class {
        constructor() {
            unexpectedRequests.push('XMLHttpRequest');
            throw new Error('External HTTP requests are disabled in this test');
        }
    };
    window.fetch = (...args) => {
        unexpectedRequests.push(args);
        throw new Error('External fetch requests are disabled in this test');
    };
    window.realtimeTestApi = {
        post: async (...args) => {
            authRequests.push(args);
            return { data: { auth: 'test-key:test-signature' } };
        },
    };
    window.localStorage.setItem('lagerAuthToken', 'test-token');
    window.addEventListener('lager:data-changed', () => { invalidations += 1; });

    try {
        window.eval(script);
        stop = window.RealtimeTransport.startRealtime(() => ({ id: 1 }));
        await waitFor(() => window.lagerRealtimeStatus?.connected);

        assert.equal(sockets.length, 1);
        const url = new URL(sockets[0].url);
        assert.equal(url.protocol, 'wss:');
        assert.equal(url.hostname, 'lager.diagen.am');
        assert.equal(url.pathname, '/app/test-key');
        assert.equal(url.searchParams.get('protocol'), '7');
        assert.deepEqual(unexpectedRequests, []);
        assert.equal(authRequests.length, 1);
        assert.equal(authRequests[0][0], 'broadcasting/auth');
        assert.equal(authRequests[0][1].socket_id, '123.456');
        assert.equal(authRequests[0][1].channel_name, 'private-lager.updates');
        assert.equal(authRequests[0][2].headers.Authorization, 'Bearer test-token');
        assert.equal(invalidations, 1);

        sockets[0].receive('data.changed', {}, 'private-lager.updates');
        assert.equal(invalidations, 2);
        stop();
        stop = undefined;
        assert.ok(sockets.every((socket) => socket.readyState === FakeWebSocket.CLOSED));
        assert.equal(window.lagerRealtimeStatus.connected, false);
    } finally {
        stop?.();
        for (const socket of sockets) socket.close();
        dom.window.close();
    }
});
