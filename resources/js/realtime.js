// Keep reconnects and late SDK/auth responses bound to the initiating session.
export function createRealtimeController({ createClient, getToken, enabled, emitStatus, invalidate }) {
    let version = 0;
    let client = null;
    let identity = null;
    const status = (value) => emitStatus({ connected: value === 'connected', status: value });

    function stop() {
        version += 1;
        identity = null;
        client?.disconnect();
        client = null;
        status(enabled ? 'disconnected' : 'disabled');
    }

    async function sync(user) {
        const token = getToken();
        if (!enabled || !user?.id || !token) { stop(); return; }
        if (identity?.userId === user.id && identity.token === token) return;
        stop();
        identity = { userId: user.id, token };
        const currentVersion = version;
        const isCurrent = () => currentVersion === version && getToken() === token;
        status('connecting');
        try {
            const nextClient = await createClient({ token, isCurrent });
            if (!isCurrent()) { nextClient.disconnect(); return; }
            client = nextClient;
            client.subscribe({
                onSubscribed() {
                    if (!isCurrent()) return;
                    status('connected');
                    // Recover changes missed while offline, including the first connection.
                    invalidate();
                },
                onChange() { if (isCurrent()) invalidate(); },
                // A failed private authorization is not retried by a healthy
                // Pusher socket. Clear it so the next retry reauthorizes.
                onError() { if (isCurrent()) stop(); },
                onConnectionChange(value) {
                    if (isCurrent()) status(value === 'connecting' || value === 'connected' ? 'connecting' : 'disconnected');
                },
            });
        } catch {
            if (isCurrent()) { identity = null; status('disconnected'); }
        }
    }

    return { sync, stop };
}
