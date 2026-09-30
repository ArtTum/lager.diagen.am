export function parseIsoDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
    if (!match) return null;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return date.getFullYear() === Number(match[1])
        && date.getMonth() === Number(match[2]) - 1
        && date.getDate() === Number(match[3]) ? date : null;
}

export function toIsoDate(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function displayIsoDate(value) {
    const date = parseIsoDate(value);
    return date ? `${String(date.getDate()).padStart(2, '0')}.${String(date.getMonth() + 1).padStart(2, '0')}.${date.getFullYear()}` : '';
}

export function parseDisplayDate(value) {
    const match = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(String(value || '').trim());
    if (!match) return null;
    const date = new Date(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
    return date.getFullYear() === Number(match[3])
        && date.getMonth() === Number(match[2]) - 1
        && date.getDate() === Number(match[1]) ? date : null;
}

/** Format API dates for people while leaving API payloads in their original format. */
export function formatDisplayDate(value) {
    if (value === null || value === undefined || value === '') return '—';
    const source = String(value).trim();
    const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(source);
    if (dateOnly) {
        const date = parseIsoDate(source);
        return date ? displayIsoDate(source) : '—';
    }

    // PHP can emit microseconds, which JavaScript's Date parser does not consistently accept.
    const normalized = source.replace(/\.(\d{3})\d+(?=Z|[+-]\d{2}:?\d{2}$)/i, '.$1');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return '—';

    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Yerevan',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        ...( /[T ]\d{2}:\d{2}/.test(source) ? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } : {}),
    }).formatToParts(date).reduce((result, part) => ({ ...result, [part.type]: part.value }), {});

    const formatted = `${parts.day}.${parts.month}.${parts.year}`;
    return parts.hour ? `${formatted} ${parts.hour}:${parts.minute}` : formatted;
}

/** Format date-only values without exposing a serialized time component. */
export function formatDisplayDateOnly(value) {
    const formatted = formatDisplayDate(value);
    return formatted === '—' ? formatted : formatted.split(' ')[0];
}

export function isDateValue(value) {
    return typeof value === 'string'
        && /^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/.test(value.trim());
}
