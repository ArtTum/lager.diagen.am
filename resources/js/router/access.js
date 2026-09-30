export function firstAvailablePath(routes, permissions = {}) {
    return routes.find((candidate) => !candidate.path.includes(':')
        && candidate.meta?.permission
        && permissions[candidate.meta.permission])?.path || '/no-access';
}

export function userContextChanged(current, next) {
    return JSON.stringify(current) !== JSON.stringify(next);
}
