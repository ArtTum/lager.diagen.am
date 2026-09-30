export function emptyNotificationSnapshot() {
    return { items: [], unread: 0, previousKeys: null };
}

export function isCurrentNotificationSnapshot(snapshot, sessionVersion, requestVersion, canView) {
    return canView
        && snapshot.sessionVersion === sessionVersion
        && snapshot.requestVersion === requestVersion;
}
