export function createNotificationAudio(getContextConstructor, isEnabled) {
    let context = null;

    function getContext() {
        if (context || !isEnabled()) return context;
        const Context = getContextConstructor();
        if (typeof Context !== 'function') return null;

        try {
            context = new Context();
        } catch {
            return null;
        }

        return context;
    }

    function resume(audioContext) {
        if (audioContext.state === 'running') return Promise.resolve(true);
        if (typeof audioContext.resume !== 'function') return Promise.resolve(false);

        try {
            return Promise.resolve(audioContext.resume())
                .then(() => audioContext.state === 'running')
                .catch(() => false);
        } catch {
            return Promise.resolve(false);
        }
    }

    function activate() {
        if (!isEnabled()) return Promise.resolve(false);
        const audioContext = getContext();
        return audioContext ? resume(audioContext) : Promise.resolve(false);
    }

    function play() {
        if (!isEnabled()) return Promise.resolve(false);
        const audioContext = getContext();
        if (!audioContext) return Promise.resolve(false);

        const emitTone = () => {
            if (!isEnabled() || audioContext.state !== 'running') return false;

            try {
                const oscillator = audioContext.createOscillator();
                const gain = audioContext.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.value = 740;
                gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.1, audioContext.currentTime + 0.025);
                gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.22);
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start();
                oscillator.stop(audioContext.currentTime + 0.23);

                return true;
            } catch {
                return false;
            }
        };

        return audioContext.state === 'running'
            ? Promise.resolve(emitTone())
            : resume(audioContext).then((resumed) => resumed && emitTone());
    }

    function close() {
        if (!context || context.state === 'closed' || typeof context.close !== 'function') return Promise.resolve();
        try {
            return Promise.resolve(context.close()).catch(() => {});
        } catch {
            return Promise.resolve();
        }
    }

    return { activate, play, close };
}
