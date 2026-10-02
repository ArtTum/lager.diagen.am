import test from 'node:test';
import assert from 'node:assert/strict';
import { displayIsoDate, parseDisplayDate, parseIsoDate, todayIsoDate, toIsoDate } from '../../resources/js/dateUtils.js';

test('date picker displays and submits dates without changing the API ISO format', () => {
    assert.equal(displayIsoDate('2026-09-30'), '30.09.2026');
    assert.equal(toIsoDate(parseDisplayDate('30.09.2026')), '2026-09-30');
});

test('date picker rejects impossible dates and only accepts strict ISO values', () => {
    assert.equal(parseDisplayDate('31.02.2026'), null);
    assert.equal(parseDisplayDate('1.02.2026'), null);
    assert.equal(parseIsoDate('2026-02-31'), null);
    assert.equal(displayIsoDate('2026-02-31'), '');
    assert.equal(toIsoDate(parseDisplayDate('29.02.2024')), '2024-02-29');
});

test('business document defaults use the current date in Yerevan around UTC midnight', () => {
    assert.equal(todayIsoDate(new Date('2026-10-01T19:59:59Z')), '2026-10-01');
    assert.equal(todayIsoDate(new Date('2026-10-01T20:00:00Z')), '2026-10-02');
    assert.equal(todayIsoDate(new Date('2026-12-31T20:15:00Z')), '2027-01-01');
});
