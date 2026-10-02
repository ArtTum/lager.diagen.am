import api from './api';
import { createRealtimeController } from '../realtime';

export function startRealtime(user) {
    const key = document.querySelector('meta[name="lager-realtime-key"]')?.content || '';
    let retryTimer;
    let disposed = false;
    const controller = createRealtimeController({
        enabled: Boolean(key),
        getToken: () => localStorage.getItem('lagerAuthToken'),
        emitStatus(detail) {
            window.lagerRealtimeStatus = detail;
            window.dispatchEvent(new CustomEvent('lager:realtime-status', { detail }));
            window.clearTimeout(retryTimer);
            if (!disposed && key && detail.status === 'disconnected' && user()?.id && localStorage.getItem('lagerAuthToken')) {
                retryTimer = window.setTimeout(() => { void controller.sync(user()); }, 10000);
            }
        },
        invalidate: () => window.dispatchEvent(new Event('lager:data-changed')),
        async createClient({ token, isCurrent }) {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
            const secure = window.location.protocol === 'https:';
            const socket = new Pusher(key, {
                cluster: 'mt1',
                wsHost: window.location.hostname,
                wsPort: Number(window.location.port || (secure ? 443 : 80)),
                wssPort: Number(window.location.port || 443),
                forceTLS: secure,
                // Pusher names this transport "ws"; forceTLS selects wss.
                enabledTransports: ['ws'],
                enableStats: false,
                authorizer: (channel) => ({
                    authorize(socketId, callback) {
                        if (!isCurrent()) { callback(new Error('Session changed'), null); return; }
                        api.post('broadcasting/auth', { socket_id: socketId, channel_name: channel.name }, {
                            headers: { Authorization: `Bearer ${token}` },
                        }).then((response) => {
                            if (isCurrent()) callback(null, response.data);
                            else callback(new Error('Session changed'), null);
                        }).catch((error) => callback(error, null));
                    },
                }),
            });
            const echo = new Echo({ broadcaster: 'reverb', client: socket });
            let onStateChange;
            return {
                subscribe({ onSubscribed, onChange, onError, onConnectionChange }) {
                    onStateChange = ({ current }) => onConnectionChange(current);
                    socket.connection.bind('state_change', onStateChange);
                    echo.private('lager.updates').listen('.data.changed', onChange).subscribed(onSubscribed).error(onError);
                },
                disconnect() {
                    if (onStateChange) socket.connection.unbind('state_change', onStateChange);
                    echo.disconnect();
                },
            };
        },
    });
    const onUserChange = (event) => { void controller.sync(event.detail); };
    const onFocus = () => { void controller.sync(user()); };
    window.addEventListener('lager:user', onUserChange);
    window.addEventListener('focus', onFocus);
    void controller.sync(user());
    return () => {
        disposed = true;
        window.clearTimeout(retryTimer);
        window.removeEventListener('lager:user', onUserChange);
        window.removeEventListener('focus', onFocus);
        controller.stop();
    };
}
