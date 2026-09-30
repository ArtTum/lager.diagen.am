// Keep the native select as the source of truth for Vue, form validation and events.
const bindings = new WeakMap();
let activeMenu = null;
let nextMenuId = 0;

const normalize = (value) => String(value ?? '').normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase();
const isOptionDisabled = (option) => option.disabled || (option.parentElement?.tagName === 'OPTGROUP' && option.parentElement.disabled);

function labelFor(select) {
    const label = select.labels?.[0]?.cloneNode(true);
    label?.querySelectorAll('select, input, textarea, button, small').forEach((node) => node.remove());
    return label?.textContent?.replace(/\s+/g, ' ').trim() || select.getAttribute('aria-label') || select.name || 'Ընտրանքներ';
}

function openMenu(select, initialQuery = '') {
    if (select.disabled || select.multiple || select.size > 1 || !select.isConnected) return;
    if (activeMenu?.select === select) return;
    activeMenu?.close(false);

    const document = select.ownerDocument;
    const window = document.defaultView;
    const menu = document.createElement('div');
    const menuId = `lager-select-${++nextMenuId}`;
    menu.className = 'searchable-select-menu';
    menu.setAttribute('role', 'presentation');

    const searchWrap = document.createElement('div');
    searchWrap.className = 'searchable-select-search';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Որոնել ցանկում…';
    search.autocomplete = 'off';
    search.spellcheck = false;
    search.value = initialQuery;
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-label', `${labelFor(select)}՝ որոնում`);
    search.setAttribute('aria-autocomplete', 'list');
    search.setAttribute('aria-expanded', 'true');
    search.setAttribute('aria-controls', menuId);
    searchWrap.append(search);

    const list = document.createElement('div');
    list.id = menuId;
    list.className = 'searchable-select-options';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', labelFor(select));
    menu.append(searchWrap, list);
    document.body.append(menu);
    select.setAttribute('aria-expanded', 'true');
    select.setAttribute('aria-controls', menuId);

    let matches = [];
    let rows = [];
    let activeIndex = -1;
    let animationFrame = null;
    let closed = false;

    function close(restoreFocus) {
        if (closed) return;
        closed = true;
        observer.disconnect();
        if (animationFrame !== null) window.cancelAnimationFrame(animationFrame);
        document.removeEventListener('pointerdown', onOutside, true);
        document.removeEventListener('focusin', onOutside);
        window.removeEventListener('resize', schedulePosition);
        window.removeEventListener('scroll', schedulePosition, true);
        window.visualViewport?.removeEventListener('resize', schedulePosition);
        window.visualViewport?.removeEventListener('scroll', schedulePosition);
        menu.remove();
        select.setAttribute('aria-expanded', 'false');
        const originalControls = bindings.get(select)?.controls;
        if (originalControls == null) select.removeAttribute('aria-controls');
        else select.setAttribute('aria-controls', originalControls);
        if (activeMenu?.select === select) activeMenu = null;
        if (restoreFocus && select.isConnected && !select.disabled) select.focus({ preventScroll: true });
    }

    const position = () => {
        if (closed) return;
        if (!select.isConnected || select.disabled) return close(false);
        const rect = select.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth) return close(false);
        const viewport = window.visualViewport;
        const margin = 8;
        const leftEdge = viewport?.offsetLeft || 0;
        const topEdge = viewport?.offsetTop || 0;
        const viewportWidth = viewport?.width || window.innerWidth;
        const viewportHeight = viewport?.height || window.innerHeight;
        const below = topEdge + viewportHeight - rect.bottom - margin;
        const above = rect.top - topEdge - margin;
        const openBelow = below >= 220 || below >= above;
        const width = Math.max(0, Math.min(Math.max(rect.width, 260), viewportWidth - margin * 2));
        menu.style.width = `${width}px`;
        menu.style.maxHeight = `${Math.max(80, Math.min(350, (openBelow ? below : above) - 6))}px`;
        menu.style.left = `${Math.max(leftEdge + margin, Math.min(rect.left, leftEdge + viewportWidth - width - margin))}px`;
        const preferredTop = openBelow ? rect.bottom + 6 : rect.top - menu.offsetHeight - 6;
        menu.style.top = `${Math.max(topEdge + margin, Math.min(preferredTop, topEdge + viewportHeight - menu.offsetHeight - margin))}px`;
    };

    const schedulePosition = (event) => {
        if (event?.target instanceof window.Node && menu.contains(event.target)) return;
        if (animationFrame !== null) window.cancelAnimationFrame(animationFrame);
        animationFrame = window.requestAnimationFrame(() => { animationFrame = null; position(); });
    };

    const activate = (index, scroll = true) => {
        activeIndex = index;
        rows.forEach((row, rowIndex) => row.classList.toggle('is-active', rowIndex === index));
        if (index < 0) search.removeAttribute('aria-activedescendant');
        else {
            search.setAttribute('aria-activedescendant', rows[index].id);
            if (scroll) {
                const row = rows[index];
                if (row.offsetTop < list.scrollTop) list.scrollTop = row.offsetTop;
                else if (row.offsetTop + row.offsetHeight > list.scrollTop + list.clientHeight) {
                    list.scrollTop = row.offsetTop + row.offsetHeight - list.clientHeight;
                }
            }
        }
    };

    const choose = (entry) => {
        if (select.disabled || isOptionDisabled(entry.option)) return;
        const index = Array.from(select.options).indexOf(entry.option);
        if (index < 0) return refresh();
        const previousIndex = select.selectedIndex;
        close(true);
        select.selectedIndex = index;
        if (previousIndex !== index) {
            select.dispatchEvent(new window.Event('input', { bubbles: true }));
            select.dispatchEvent(new window.Event('change', { bubbles: true }));
        }
    };

    const refresh = () => {
        if (closed) return;
        if (select.disabled || !select.isConnected) return close(false);
        const previousOption = matches[activeIndex]?.option;
        const terms = normalize(search.value).trim().split(/\s+/).filter(Boolean);
        matches = Array.from(select.options, (option, index) => ({ option, index, label: option.label }))
            .filter((entry) => terms.every((term) => normalize(entry.label).includes(term)));
        list.replaceChildren();
        rows = [];
        for (const entry of matches) {
            const row = document.createElement('div');
            row.id = `${menuId}-option-${entry.index}`;
            row.className = 'searchable-select-option';
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', String(entry.option.selected));
            row.setAttribute('aria-disabled', String(Boolean(isOptionDisabled(entry.option))));
            row.textContent = entry.label;
            row.addEventListener('pointerdown', (event) => event.preventDefault());
            row.addEventListener('click', () => choose(entry));
            row.addEventListener('pointermove', () => {
                if (!isOptionDisabled(entry.option)) activate(rows.indexOf(row), false);
            });
            rows.push(row);
            list.append(row);
        }
        if (!matches.length) {
            const empty = document.createElement('div');
            empty.className = 'searchable-select-empty';
            empty.setAttribute('role', 'status');
            empty.textContent = 'Համապատասխան տարբերակ չկա։';
            list.append(empty);
        }
        const previous = matches.findIndex((entry) => entry.option === previousOption && !isOptionDisabled(entry.option));
        const selected = matches.findIndex((entry) => entry.option.selected && !isOptionDisabled(entry.option));
        position();
        activate(previous >= 0 ? previous : selected >= 0 ? selected : matches.findIndex((entry) => !isOptionDisabled(entry.option)));
    };

    const move = (direction) => {
        if (!matches.length) return;
        let index = activeIndex;
        for (let step = 0; step < matches.length; step += 1) {
            index = (index + direction + matches.length) % matches.length;
            if (!isOptionDisabled(matches[index].option)) return activate(index);
        }
    };

    const onKeydown = (event) => {
        if (event.isComposing) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            move(event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeIndex >= 0) choose(matches[activeIndex]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (event.key === 'Tab') {
            close(true);
        }
    };
    const onOutside = (event) => {
        if (event.target !== select && !menu.contains(event.target)) close(false);
    };
    const observer = new window.MutationObserver(refresh);

    activeMenu = { select, close, refresh };
    search.addEventListener('input', () => { activeIndex = -1; refresh(); });
    search.addEventListener('keydown', onKeydown);
    document.addEventListener('pointerdown', onOutside, true);
    document.addEventListener('focusin', onOutside);
    window.addEventListener('resize', schedulePosition);
    window.addEventListener('scroll', schedulePosition, true);
    window.visualViewport?.addEventListener('resize', schedulePosition);
    window.visualViewport?.addEventListener('scroll', schedulePosition);
    observer.observe(select, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['disabled', 'label', 'value', 'selected'] });
    refresh();
    search.focus({ preventScroll: true });
}

export const searchableSelect = {
    mounted(select) {
        const onPointer = (event) => {
            if (event.button !== undefined && event.button !== 0) return;
            if (select.disabled || select.multiple || select.size > 1) return;
            event.preventDefault();
            openMenu(select);
        };
        const onKeydown = (event) => {
            if (event.isComposing || event.ctrlKey || event.metaKey) return;
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                openMenu(select);
            } else if (event.key.length === 1 && !event.altKey) {
                event.preventDefault();
                openMenu(select, event.key);
            }
        };
        bindings.set(select, {
            onPointer, onKeydown,
            controls: select.getAttribute('aria-controls'),
        });
        select.setAttribute('aria-expanded', 'false');
        select.addEventListener('pointerdown', onPointer);
        select.addEventListener('mousedown', onPointer);
        select.addEventListener('click', onPointer);
        select.addEventListener('keydown', onKeydown);
    },
    updated(select) {
        queueMicrotask(() => {
            if (activeMenu?.select === select) activeMenu.refresh();
        });
    },
    beforeUnmount(select) {
        if (activeMenu?.select === select) activeMenu.close(false);
        const binding = bindings.get(select);
        if (!binding) return;
        select.removeEventListener('pointerdown', binding.onPointer);
        select.removeEventListener('mousedown', binding.onPointer);
        select.removeEventListener('click', binding.onPointer);
        select.removeEventListener('keydown', binding.onKeydown);
        select.removeAttribute('aria-expanded');
        if (binding.controls == null) select.removeAttribute('aria-controls');
        else select.setAttribute('aria-controls', binding.controls);
        bindings.delete(select);
    },
};
