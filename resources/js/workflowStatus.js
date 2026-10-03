import definitions from './workflowStatuses.json';

export function statusInfo(workflow, status) {
    const key = String(status ?? '');
    const contextual = Object.hasOwn(definitions, workflow) ? definitions[workflow] : {};
    if (Object.hasOwn(contextual, key)) return contextual[key];
    if (Object.hasOwn(definitions.generic, key)) return definitions.generic[key];
    return { label: key || '—', description: '', tone: 'neutral' };
}

export function statusLabel(workflow, status) {
    return statusInfo(workflow, status).label;
}

export function statusOptions(workflow) {
    const contextual = Object.hasOwn(definitions, workflow) ? definitions[workflow] : {};
    return Object.entries(contextual).filter(([, info]) => info.filter !== false)
        .map(([value, info]) => ({ value, label: info.label }));
}

export function workflowFromEntity(entity) {
    return ({ stock_requests: 'requests', purchase_orders: 'purchases', inventory_sessions: 'inventory',
        transfers: 'transfers', returns: 'generic' })[entity] || entity || 'generic';
}

export function workflowFromReport(type) {
    return ({ supplier_purchases: 'purchases', purchases_by_period: 'purchases', inventory_differences: 'inventory',
        branch_requests: 'requests', rejected_requests: 'requests' })[type] || 'generic';
}
