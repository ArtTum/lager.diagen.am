import test from 'node:test';
import assert from 'node:assert/strict';
import { emptyNotificationSnapshot, isCurrentNotificationSnapshot } from '../../resources/js/notifications.js';

test('a user change starts with an empty notification snapshot', () => {
    assert.deepEqual(emptyNotificationSnapshot(), { items: [], unread: 0, previousKeys: null });
});

test('a notification response from an old user session or poll is discarded', () => {
    const response = { sessionVersion: 4, requestVersion: 8 };

    assert.equal(isCurrentNotificationSnapshot(response, 5, 8, true), false);
    assert.equal(isCurrentNotificationSnapshot(response, 4, 9, true), false);
    assert.equal(isCurrentNotificationSnapshot(response, 4, 8, false), false);
    assert.equal(isCurrentNotificationSnapshot(response, 4, 8, true), true);
});
