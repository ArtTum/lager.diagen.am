import { onBeforeUnmount, onMounted, ref, unref, watch } from 'vue';

// A burst of invalidations needs one fetch; a change during a fetch needs one
// follow-up snapshot. The caller retains its own filters and response guards.
export function useLiveRefresh(callback, { isBusy = false, fallbackInterval = 0 } = {}) {
    const connected = ref(Boolean(window.lagerRealtimeStatus?.connected));
    const status = ref(window.lagerRealtimeStatus?.status || 'disconnected');
    let mounted = false;
    let queued = false;
    let pending = false;
    let running = false;
    let generation = 0;
    let timer;
    const busy = () => Boolean(typeof isBusy === 'function' ? isBusy() : unref(isBusy));

    async function flush() {
        queued = false;
        if (!mounted || !pending || running || busy()) return;
        pending = false;
        running = true;
        const currentGeneration = generation;
        try { await callback(); }
        catch { /* The page owns its error presentation. */ }
        finally {
            if (currentGeneration !== generation) return;
            running = false;
            if (pending) refresh();
        }
    }

    function resetSession() {
        generation += 1;
        pending = false;
        running = false;
    }

    function refresh() {
        if (!mounted) return;
        pending = true;
        if (queued || running || busy()) return;
        queued = true;
        queueMicrotask(flush);
    }

    function updateStatus(event) {
        const next = event?.detail || window.lagerRealtimeStatus || {};
        connected.value = Boolean(next.connected);
        status.value = next.status || 'disconnected';
        window.clearInterval(timer);
        timer = undefined;
        if (fallbackInterval && !connected.value) timer = window.setInterval(refresh, fallbackInterval);
    }

    const onVisible = () => { if (document.visibilityState === 'visible') refresh(); };

    watch(busy, (value) => { if (!value && pending) refresh(); });
    onMounted(() => {
        mounted = true;
        window.addEventListener('lager:data-changed', refresh);
        window.addEventListener('lager:realtime-status', updateStatus);
        window.addEventListener('lager:user', resetSession);
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', onVisible);
        updateStatus();
    });
    onBeforeUnmount(() => {
        mounted = false;
        generation += 1;
        pending = false;
        window.clearInterval(timer);
        window.removeEventListener('lager:data-changed', refresh);
        window.removeEventListener('lager:realtime-status', updateStatus);
        window.removeEventListener('lager:user', resetSession);
        window.removeEventListener('focus', refresh);
        document.removeEventListener('visibilitychange', onVisible);
    });
    return { refresh, connected, status };
}
