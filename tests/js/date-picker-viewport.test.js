import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { parse, compileScript } from '@vue/compiler-sfc';
import { transformSync } from 'esbuild';
import { JSDOM } from 'jsdom';
import * as dateUtils from '../../resources/js/dateUtils.js';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://lager.test/' });
for (const key of ['window', 'document', 'Document', 'HTMLElement', 'SVGElement', 'Element', 'Node']) globalThis[key] = dom.window[key];
const require = createRequire(import.meta.url);
const Vue = require('vue');
const filename = new URL('../../resources/js/components/DatePicker.vue', import.meta.url);
const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname });
const script = compileScript(descriptor, { id: 'date-picker-viewport', inlineTemplate: true });
const module = { exports: {} };
new Function('require', 'module', 'exports', transformSync(script.content, { format: 'cjs' }).code)(
    (name) => name === './AppIcon.vue' ? { render: () => null } : name === '../dateUtils' ? dateUtils : require(name),
    module, module.exports,
);
const DatePicker = module.exports.default;

let naturalHeight = 520;
// JSDOM has no layout engine; supply the rendered calendar and trigger dimensions.
const nativeScrollHeight = Object.getOwnPropertyDescriptor(dom.window.Element.prototype, 'scrollHeight');
const nativeClientHeight = Object.getOwnPropertyDescriptor(dom.window.Element.prototype, 'clientHeight');
const nativeOffsetHeight = Object.getOwnPropertyDescriptor(dom.window.HTMLElement.prototype, 'offsetHeight');
const isPopover = (node) => node.classList.contains('date-picker-popover');
const popoverHeight = (node) => Math.min(naturalHeight + 2, parseFloat(node.style.maxHeight) || Infinity);
Object.defineProperty(dom.window.Element.prototype, 'scrollHeight', { configurable: true, get() {
    return isPopover(this) ? naturalHeight : nativeScrollHeight.get.call(this);
} });
Object.defineProperty(dom.window.Element.prototype, 'clientHeight', { configurable: true, get() {
    return isPopover(this) ? Math.max(0, popoverHeight(this) - 2) : nativeClientHeight.get.call(this);
} });
Object.defineProperty(dom.window.HTMLElement.prototype, 'offsetHeight', { configurable: true, get() {
    return isPopover(this) ? popoverHeight(this) : nativeOffsetHeight.get.call(this);
} });

function setViewport({ width, height, clientWidth = width, clientHeight = height, visual = null }) {
    Object.defineProperties(window, {
        innerWidth: { configurable: true, value: width }, innerHeight: { configurable: true, value: height },
        visualViewport: { configurable: true, value: visual },
    });
    Object.defineProperties(document.documentElement, {
        clientWidth: { configurable: true, value: clientWidth }, clientHeight: { configurable: true, value: clientHeight },
    });
}
function trackListeners(target) {
    const active = new Map();
    const add = target.addEventListener.bind(target); const remove = target.removeEventListener.bind(target);
    target.addEventListener = (type, listener, options) => {
        if (!active.has(type)) active.set(type, new Set());
        active.get(type).add(listener); add(type, listener, options);
    };
    target.removeEventListener = (type, listener, options) => { active.get(type)?.delete(listener); remove(type, listener, options); };
    return { count: (type) => active.get(type)?.size || 0, restore() { target.addEventListener = add; target.removeEventListener = remove; } };
}
function visualViewport(properties) { return Object.assign(new dom.window.EventTarget(), properties); }
async function settle() { await Vue.nextTick(); await Vue.nextTick(); }
function mountPicker(trigger, props = {}) {
    const value = Vue.ref(props.modelValue || '2026-10-03');
    const root = document.createElement('div'); document.body.append(root);
    const app = Vue.createApp({ setup: () => () => Vue.h(DatePicker, {
        ...props, modelValue: value.value, 'onUpdate:modelValue': (next) => { value.value = next; },
    }) });
    app.mount(root);
    root.querySelector('.date-picker-control').getBoundingClientRect = () => trigger;
    return { root, value, async open() { root.querySelector('.date-picker-trigger').click(); await settle(); return document.querySelector('.date-picker-popover'); },
        unmount() { app.unmount(); root.remove(); } };
}
function bounds(popover) {
    const left = parseFloat(popover.style.left); const top = parseFloat(popover.style.top);
    return { left, top, right: left + parseFloat(popover.style.width), bottom: top + popoverHeight(popover) };
}
function assertInside(popover, { left = 0, top = 0, right, bottom }) {
    const rectangle = bounds(popover);
    assert.ok(rectangle.left >= left + 12, `left ${rectangle.left} must clear visible edge ${left}`);
    assert.ok(rectangle.right <= right - 12, `right ${rectangle.right} must fit visible edge ${right}`);
    assert.ok(rectangle.top >= top + 8, `top ${rectangle.top} must clear visible edge ${top}`);
    assert.ok(rectangle.bottom <= bottom - 8, `bottom ${rectangle.bottom} must fit visible edge ${bottom}`);
}

test('calendar fits a 320px screen with a classic scrollbar instead of using the wider innerWidth', async () => {
    setViewport({ width: 320, clientWidth: 305, height: 640 }); naturalHeight = 520;
    const view = mountPicker({ left: 220, right: 300, top: 380, bottom: 428 });
    try {
        const popover = await view.open();
        assert.ok(popover);
        assertInside(popover, { right: 305, bottom: 640 });
        assert.equal(popover.querySelectorAll('[role="gridcell"]').length, 42);
    } finally { view.unmount(); }
});

test('short landscape calendar is bounded and an allowed date still emits its ISO value', async () => {
    setViewport({ width: 812, clientWidth: 797, height: 375 }); naturalHeight = 520;
    const view = mountPicker({ left: 680, right: 780, top: 270, bottom: 318 }, { min: '2026-10-01', max: '2026-10-31' });
    try {
        const popover = await view.open();
        assertInside(popover, { right: 797, bottom: 375 });
        assert.ok(parseFloat(popover.style.maxHeight) < naturalHeight, 'the calendar needs internal scrolling in landscape');
        popover.querySelector('[aria-label*="12 հոկտեմբերի 2026"]').click(); await settle();
        assert.equal(view.value.value, '2026-10-12');
        assert.equal(document.querySelector('.date-picker-popover'), null);
    } finally { view.unmount(); }
});

test('calendar uses its measured height to open below a trigger when it fits', async () => {
    setViewport({ width: 320, clientWidth: 305, height: 500 }); naturalHeight = 380;
    const trigger = { left: 15, right: 290, top: 40, bottom: 88 };
    const view = mountPicker(trigger);
    try {
        const popover = await view.open();
        assertInside(popover, { right: 305, bottom: 500 });
        assert.ok(bounds(popover).top > trigger.bottom, 'a fitting calendar must not cover its trigger');
    } finally { view.unmount(); }
});

test('window and keyboard visual viewport changes reposition an open calendar and all listeners clean up', async () => {
    const visual = visualViewport({ width: 1200, height: 900, offsetLeft: 0, offsetTop: 0 });
    const visualListeners = trackListeners(visual); const windowListeners = trackListeners(window);
    setViewport({ width: 1200, height: 900, visual }); naturalHeight = 520;
    const trigger = { left: 750, right: 1000, top: 80, bottom: 128 };
    const view = mountPicker(trigger);
    try {
        const popover = await view.open();
        assertInside(popover, { right: 1200, bottom: 900 });
        assert.ok(bounds(popover).top > trigger.bottom);
        assert.equal(visualListeners.count('resize'), 1); assert.equal(visualListeners.count('scroll'), 1);
        assert.equal(windowListeners.count('resize'), 1); assert.equal(windowListeners.count('scroll'), 1);

        const initial = bounds(popover);
        setViewport({ width: 390, clientWidth: 375, height: 700, visual });
        Object.assign(visual, { width: 390, height: 700 });
        window.dispatchEvent(new dom.window.Event('resize')); await settle();
        assertInside(popover, { right: 375, bottom: 700 });
        assert.notEqual(bounds(popover).right, initial.right);

        Object.assign(visual, { width: 300, height: 240, offsetLeft: 8, offsetTop: 100 });
        visual.dispatchEvent(new dom.window.Event('resize')); await settle();
        assertInside(popover, { left: 8, top: 100, right: 308, bottom: 340 });
        assert.ok(parseFloat(popover.style.maxHeight) < naturalHeight, 'keyboard space bounds the scrolling calendar');

        Object.assign(visual, { offsetLeft: 20, offsetTop: 140 });
        visual.dispatchEvent(new dom.window.Event('scroll')); await settle();
        assertInside(popover, { left: 20, top: 140, right: 320, bottom: 380 });

        popover.querySelector('.date-picker-close').click(); await settle();
        assert.equal(visualListeners.count('resize'), 0); assert.equal(visualListeners.count('scroll'), 0);
        assert.equal(windowListeners.count('resize'), 0); assert.equal(windowListeners.count('scroll'), 0);
        await view.open();
        assert.equal(visualListeners.count('resize'), 1); assert.equal(visualListeners.count('scroll'), 1);
        view.unmount();
        assert.equal(document.querySelector('.date-picker-popover'), null);
        assert.equal(visualListeners.count('resize'), 0); assert.equal(visualListeners.count('scroll'), 0);
        assert.equal(windowListeners.count('resize'), 0); assert.equal(windowListeners.count('scroll'), 0);
        visual.dispatchEvent(new dom.window.Event('resize'));
        window.dispatchEvent(new dom.window.Event('resize')); await settle();
        assert.equal(document.querySelector('.date-picker-popover'), null);
    } finally { if (view.root.isConnected) view.unmount(); visualListeners.restore(); windowListeners.restore(); }
});
