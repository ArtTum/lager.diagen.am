/** Keep action visibility aligned with the legacy role-management rules. */
export function canCreateRecord(page, permissions = {}) {
    if (page === 'roles') {
        return Boolean(permissions['roles.create'] && permissions['roles.edit']);
    }

    return Boolean(permissions[`${page}.create`]);
}
