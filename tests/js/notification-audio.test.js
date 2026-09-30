import test from 'node:test';
import assert from 'node:assert/strict';
import { createNotificationAudio } from '../../resources/js/notificationAudio.js';

function fakeAudioContext(initialState = 'suspended') {
    const calls = { created: 0, resumed: 0, started: 0, stopped: 0, closed: 0, frequency: null };
    class AudioContextMock {
        state = initialState;
        currentTime = 10;
        destination = {};

        constructor() { calls.created += 1; }
        resume() { calls.resumed += 1; this.state = 'running'; return Promise.resolve(); }
        close() { calls.closed += 1; this.state = 'closed'; return Promise.resolve(); }
        createOscillator() {
            return {
                frequency: { set value(value) { calls.frequency = value; } },
                connect() {},
                start() { calls.started += 1; },
                stop() { calls.stopped += 1; },
            };
        }
        createGain() {
            return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} };
        }
    }

    return { AudioContextMock, calls };
}

test('notification sound resumes a suspended context and plays one short tone', async () => {
    const { AudioContextMock, calls } = fakeAudioContext();
    const audio = createNotificationAudio(() => AudioContextMock, () => true);

    assert.equal(await audio.play(), true);
    assert.deepEqual(calls, { created: 1, resumed: 1, started: 1, stopped: 1, closed: 0, frequency: 740 });
    await audio.close();
    assert.equal(calls.closed, 1);
});

test('disabled or unsupported audio does not create a context or play a tone', async () => {
    const supported = fakeAudioContext();
    const disabled = createNotificationAudio(() => supported.AudioContextMock, () => false);
    assert.equal(await disabled.play(), false);
    assert.equal(supported.calls.created, 0);

    const unsupported = createNotificationAudio(() => undefined, () => true);
    assert.equal(await unsupported.activate(), false);
    assert.equal(await unsupported.play(), false);
});

test('audio resume failures are handled without throwing into the notification UI', async () => {
    class ResumeFailureAudioContext {
        state = 'suspended';
        resume() { throw new Error('Audio is unavailable'); }
    }
    const audio = createNotificationAudio(() => ResumeFailureAudioContext, () => true);

    assert.equal(await audio.activate(), false);
    assert.equal(await audio.play(), false);
    await audio.close();
});

test('a pending sound is suppressed if the user disables sound before resume completes', async () => {
    let enabled = true;
    let resumePlayback;
    const calls = { started: 0 };
    class SuspendedAudioContext {
        state = 'suspended';
        currentTime = 0;
        destination = {};
        resume() { return new Promise((resolve) => { resumePlayback = () => { this.state = 'running'; resolve(); }; }); }
        createOscillator() { return { frequency: { value: 0 }, connect() {}, start() { calls.started += 1; }, stop() {} }; }
        createGain() { return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} }; }
    }
    const audio = createNotificationAudio(() => SuspendedAudioContext, () => enabled);
    const pending = audio.play();
    enabled = false;
    resumePlayback();

    assert.equal(await pending, false);
    assert.equal(calls.started, 0);
});
