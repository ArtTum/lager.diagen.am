export function emptyNotificationSnapshot() {
    return { items: [], unread: 0, previousKeys: null };
}

export function isCurrentNotificationSnapshot(snapshot, sessionVersion, requestVersion, canView) {
    return canView
        && snapshot.sessionVersion === sessionVersion
        && snapshot.requestVersion === requestVersion;
}

export function notificationScope(user) {
    return JSON.stringify([user?.id, user?.branch_id, user?.branch?.code,
        Object.entries(user?.permissions || {}).sort(([a], [b]) => a.localeCompare(b))]);
}
