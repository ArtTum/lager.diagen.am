function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    const focus = modal.querySelector('input:not([type=hidden]), select, textarea');
    if (focus) window.setTimeout(() => focus.focus(), 40);
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.hidden = true;
    if (!document.querySelector('.modal-backdrop:not([hidden])')) document.body.classList.remove('modal-open');
}

// A single Armenian confirmation dialog for destructive and irreversible actions.
let activeConfirmation = null;
let confirmedSubmission = null;
let confirmationReturnFocus = null;

function ensureConfirmationDialog() {
    let dialog = document.getElementById('action-confirmation');
    if (dialog) return dialog;
    dialog = document.createElement('div');
    dialog.id = 'action-confirmation';
    dialog.className = 'modal-backdrop confirm-backdrop';
    dialog.hidden = true;
    dialog.innerHTML = `<section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-description" tabindex="-1">
        <div class="confirm-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3.5 2.9 19.2a1 1 0 0 0 .9 1.5h16.4a1 1 0 0 0 .9-1.5L12 3.5Z"/><path d="M12 9v4.5m0 3h.01"/></svg></div>
        <h2 id="confirm-title"></h2><p id="confirm-description"></p>
        <div class="confirm-actions"><button type="button" class="secondary-button" data-confirm-cancel>Չեղարկել</button><button type="button" class="danger-button" data-confirm-accept></button></div>
    </section>`;
    document.body.append(dialog);
    dialog.addEventListener('click', event => {
        if (event.target === dialog || event.target.closest('[data-confirm-cancel]')) closeConfirmation();
        if (event.target.closest('[data-confirm-accept]')) acceptConfirmation();
    });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); closeConfirmation(); return; }
        if (event.key !== 'Tab') return;
        const controls = [...dialog.querySelectorAll('button:not(:disabled)')];
        if (!controls.length) return;
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    return dialog;
}

function confirmationCopy(form, submitter, type) {
    const dialog = submitter?.closest('.modal-backdrop');
    const entity = form.dataset.confirmLabel
        || form.closest('.category-chip')?.querySelector('b')?.textContent?.trim()
        || form.closest('tr')?.querySelector('td b')?.textContent?.trim();
    const copy = {
        'deactivate-record': {
            title: 'Ապաակտիվացնե՞լ գրառումը',
            description: `${entity ? `«${entity}» գրառումը` : 'Գրառումը'} կանջատվի և այլևս չի երևա ակտիվ ցանկերում։ Պատմությունը չի ջնջվի։`,
            action: 'Ապաակտիվացնել',
        },
        'delete-category': {
            title: 'Ջնջե՞լ ապրանքային խումբը',
            description: `${entity ? `«${entity}» խումբը` : 'Այս խումբը'} ընդմիշտ կհեռացվի։ Գործողությունը հնարավոր չէ հետ բերել։`,
            action: 'Ջնջել խումբը',
        },
        'cancel-request': {
            title: 'Չեղարկե՞լ պահանջագիրը',
            description: `${entity ? `«${entity}» պահանջագիրը` : 'Պահանջագիրը'} կտեղափոխվի «Չեղարկված» կարգավիճակ։ Գործողությունը հնարավոր չէ հետ բերել։`,
            action: 'Չեղարկել պահանջագիրը',
        },
        'close-inventory': {
            title: 'Փակե՞լ գույքագրումը',
            description: `${dialog?.querySelector('.modal-head h2')?.textContent?.trim() || 'Գույքագրումը'} կփակվի, և փաստացի քանակների տարբերությունները կկիրառվեն պահեստի մնացորդներին։`,
            action: 'Փակել գույքագրումը',
        },
    };
    return copy[type] || { title: 'Հաստատե՞լ գործողությունը', description: 'Այս գործողությունը կարող է փոխել տվյալները։ Շարունակե՞լ։', action: 'Հաստատել' };
}

function openConfirmation(form, submitter, type) {
    const dialog = ensureConfirmationDialog();
    const copy = confirmationCopy(form, submitter, type);
    dialog.querySelector('#confirm-title').textContent = copy.title;
    dialog.querySelector('#confirm-description').textContent = copy.description;
    dialog.querySelector('[data-confirm-accept]').textContent = copy.action;
    activeConfirmation = { form, submitter };
    confirmationReturnFocus = submitter || document.activeElement;
    dialog.hidden = false;
    document.body.classList.add('modal-open');
    dialog.querySelector('[data-confirm-cancel]').focus();
}

function closeConfirmation() {
    const dialog = document.getElementById('action-confirmation');
    if (dialog) dialog.hidden = true;
    activeConfirmation = null;
    if (!document.querySelector('.modal-backdrop:not([hidden])')) document.body.classList.remove('modal-open');
    confirmationReturnFocus?.focus?.();
    confirmationReturnFocus = null;
}

function acceptConfirmation() {
    if (!activeConfirmation) return;
    const pending = activeConfirmation;
    closeConfirmation();
    confirmedSubmission = pending.form;
    if (pending.submitter && pending.submitter.isConnected) pending.form.requestSubmit(pending.submitter);
    else pending.form.requestSubmit();
}

document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (confirmedSubmission === form) { confirmedSubmission = null; return; }
    const type = event.submitter?.dataset.confirm || form.dataset.confirm;
    if (!type) return;
    event.preventDefault();
    openConfirmation(form, event.submitter, type);
});

const stockAdjustLot = document.getElementById('stock-adjust-lot');
const stockAdjustLocation = document.getElementById('stock-adjust-location');
const stockAdjustProduct = document.getElementById('stock-adjust-product');
if (stockAdjustLot && stockAdjustLocation && stockAdjustProduct) {
    const filterStockAdjustmentLots = () => {
        const location = stockAdjustLocation.value;
        const product = stockAdjustProduct.value;
        Array.from(stockAdjustLot.options).forEach((option) => {
            if (!option.value) return;
            option.hidden = (location !== '' && option.dataset.location !== location)
                || (product !== '' && option.dataset.product !== product);
        });
        const selected = stockAdjustLot.selectedOptions[0];
        if (selected?.hidden) stockAdjustLot.value = '0';
    };
    stockAdjustLocation.addEventListener('change', filterStockAdjustmentLots);
    stockAdjustProduct.addEventListener('change', filterStockAdjustmentLots);
    stockAdjustLot.addEventListener('change', () => {
        const selected = stockAdjustLot.selectedOptions[0];
        if (!selected?.value) return;
        if (!stockAdjustLocation.disabled) stockAdjustLocation.value = selected.dataset.location;
        stockAdjustProduct.value = selected.dataset.product;
        filterStockAdjustmentLots();
    });
    filterStockAdjustmentLots();
}

const purchaseItems = document.querySelector('[data-purchase-items]');
const purchaseItemTemplate = document.getElementById('purchase-item-template');
if (purchaseItems && purchaseItemTemplate) {
    const refreshPurchaseRemoveButtons = () => {
        const buttons = purchaseItems.querySelectorAll('[data-remove-purchase-item]');
        buttons.forEach((button) => { button.disabled = buttons.length < 2; });
    };
    document.querySelector('[data-add-purchase-item]')?.addEventListener('click', () => {
        const index = purchaseItems.querySelectorAll('.purchase-item-row').length;
        const row = purchaseItemTemplate.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replaceAll('__INDEX__', String(index));
        });
        purchaseItems.appendChild(row);
        refreshPurchaseRemoveButtons();
        row.querySelector('select')?.focus();
    });
    purchaseItems.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-purchase-item]');
        if (!button || purchaseItems.querySelectorAll('.purchase-item-row').length < 2) return;
        button.closest('.purchase-item-row')?.remove();
        refreshPurchaseRemoveButtons();
    });
    purchaseItems.addEventListener('change', (event) => {
        const select = event.target.closest('.purchase-item-row select');
        if (!select) return;
        const price = select.selectedOptions[0]?.dataset.price;
        const cost = select.closest('.purchase-item-row')?.querySelector('input[name$="[unit_cost]"]');
        if (cost && price !== undefined) cost.value = price;
    });
}

document.querySelectorAll('.alert').forEach((alert) => {
    const message = alert.textContent.trim();
    if (!message || alert.dataset.modernized === 'true') return;
    const isError = alert.classList.contains('error');
    const mark = document.createElement('span');
    mark.className = 'alert-mark';
    mark.setAttribute('aria-hidden', 'true');
    mark.innerHTML = isError
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 9v4m0 4h.01M10.3 3.9 2.7 17.1A2 2 0 0 0 4.4 20h15.2a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>';

    const copy = document.createElement('span');
    copy.className = 'alert-copy';
    const title = document.createElement('strong');
    title.textContent = isError ? 'Գործողությունը չկատարվեց' : 'Գործողությունը կատարված է';
    const detail = document.createElement('span');
    detail.textContent = message;
    copy.append(title, detail);

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'alert-dismiss';
    dismiss.setAttribute('aria-label', 'Փակել հաղորդագրությունը');
    dismiss.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg>';
    dismiss.addEventListener('click', () => alert.remove());

    alert.classList.add('alert-modern');
    alert.dataset.modernized = 'true';
    alert.setAttribute('role', isError ? 'alert' : 'status');
    alert.setAttribute('aria-live', isError ? 'assertive' : 'polite');
    alert.replaceChildren(mark, copy, dismiss);
});

document.querySelectorAll('.modal-backdrop').forEach((modal) => modal.addEventListener('click', (event) => {
    if (event.target === modal) closeModal(modal.id);
}));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        const modal = document.querySelector('.modal-backdrop:not([hidden])');
        if (modal) closeModal(modal.id);
        const popover = document.getElementById('notification-popover');
        if (popover && !popover.hidden) setPopover(false);
        setProfilePopover(false);
    }
});

function initPaginatedLists() {
    const pageSizeDefault = 25;
    const externalSearches = [...document.querySelectorAll('[data-table-search]')];
    const usedSearches = new Set();

    document.querySelectorAll('.table-card, .stock-matrix-wrap').forEach((card) => {
        const table = card.querySelector(':scope > .table-scroll table');
        const body = table?.tBodies?.[0];
        if (!table || !body) return;
        const rows = [...body.rows].filter((row) => !row.querySelector('td[colspan]'));
        let search = card.previousElementSibling?.querySelector('[data-table-search]') || null;
        if (!search) search = externalSearches.find((item) => !usedSearches.has(item)) || null;
        let toolbar = card.querySelector(':scope > .table-list-tools');
        if (!toolbar && !search) {
            toolbar = document.createElement('div');
            toolbar.className = 'table-list-tools';
            toolbar.innerHTML = '<label class="table-list-search-wrap"><span aria-hidden="true">⌕</span><input type="search" data-table-search placeholder="Որոնել ցուցակում…" aria-label="Որոնել ցուցակի գրառումներում"></label>';
            card.insertBefore(toolbar, card.querySelector('.table-scroll'));
            search = toolbar.querySelector('[data-table-search]');
        } else if (toolbar) {
            search = toolbar.querySelector('[data-table-search]');
        }
        if (search) usedSearches.add(search);
        const footer = document.createElement('div');
        footer.className = 'list-pagination';
        footer.innerHTML = '<span class="list-pagination-count" aria-live="polite"></span><div class="list-pagination-controls"><label>Տողեր՝ <select aria-label="Տողերի քանակը մեկ էջում"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label><button type="button" class="secondary-button" data-page-prev aria-label="Նախորդ էջ">‹</button><span class="list-pagination-page" aria-live="polite"></span><button type="button" class="secondary-button" data-page-next aria-label="Հաջորդ էջ">›</button></div>';
        card.append(footer);
        const count = footer.querySelector('.list-pagination-count');
        const pageText = footer.querySelector('.list-pagination-page');
        const sizeSelect = footer.querySelector('select');
        const prev = footer.querySelector('[data-page-prev]');
        const next = footer.querySelector('[data-page-next]');
        let page = 1;
        let pageSize = pageSizeDefault;
        const render = () => {
            const query = (search?.value || '').trim().toLocaleLowerCase('hy-AM');
            const matching = rows.filter((row) => !query || row.innerText.toLocaleLowerCase('hy-AM').includes(query));
            const pages = Math.max(1, Math.ceil(matching.length / pageSize));
            page = Math.min(page, pages);
            const firstIndex = (page - 1) * pageSize;
            rows.forEach((row) => { row.hidden = true; });
            matching.slice(firstIndex, firstIndex + pageSize).forEach((row) => { row.hidden = false; });
            count.textContent = matching.length
                ? `${firstIndex + 1}–${Math.min(firstIndex + pageSize, matching.length)}՝ ${rows.length} գրառումից${matching.length !== rows.length ? ` (գտնվել է ${matching.length})` : ''}`
                : (rows.length ? 'Որոնմամբ գրառում չի գտնվել' : 'Ցուցակում գրառում չկա');
            pageText.textContent = `${page} / ${pages}`;
            prev.disabled = page <= 1;
            next.disabled = page >= pages;
            const showPages = matching.length > pageSizeDefault;
            sizeSelect.closest('label').hidden = !showPages;
            prev.hidden = !showPages;
            next.hidden = !showPages;
            pageText.hidden = !showPages;
            footer.classList.toggle('has-pages', showPages);
        };
        search?.addEventListener('input', () => { page = 1; render(); });
        sizeSelect.addEventListener('change', () => { pageSize = Number(sizeSelect.value) || pageSizeDefault; page = 1; render(); });
        prev.addEventListener('click', () => { page = Math.max(1, page - 1); render(); });
        next.addEventListener('click', () => { page += 1; render(); });
        render();
    });

    document.querySelectorAll('.notification-list, .category-chip-list').forEach((list) => {
        const items = [...list.children];
        const tools = document.createElement('div');
        tools.className = 'table-list-tools';
        tools.innerHTML = '<label class="table-list-search-wrap"><span aria-hidden="true">⌕</span><input type="search" placeholder="Որոնել ցուցակում…" aria-label="Որոնել ցուցակի գրառումներում"></label>';
        list.parentElement.insertBefore(tools, list);
        const search = tools.querySelector('input');
        const footer = document.createElement('div');
        footer.className = 'list-pagination';
        footer.innerHTML = '<span class="list-pagination-count" aria-live="polite"></span><div class="list-pagination-controls"><button type="button" class="secondary-button" data-page-prev aria-label="Նախորդ էջ">‹</button><span class="list-pagination-page"></span><button type="button" class="secondary-button" data-page-next aria-label="Հաջորդ էջ">›</button></div>';
        list.after(footer);
        let page = 1;
        const render = () => {
            const query = search.value.trim().toLocaleLowerCase('hy-AM');
            const matches = items.filter((item) => !query || item.innerText.toLocaleLowerCase('hy-AM').includes(query));
            const pages = Math.max(1, Math.ceil(matches.length / pageSizeDefault));
            page = Math.min(page, pages);
            items.forEach((item) => { item.hidden = true; });
            matches.slice((page - 1) * pageSizeDefault, page * pageSizeDefault).forEach((item) => { item.hidden = false; });
            footer.querySelector('.list-pagination-count').textContent = matches.length ? `${(page - 1) * pageSizeDefault + 1}–${Math.min(page * pageSizeDefault, matches.length)}՝ ${items.length} գրառումից` : 'Որոնմամբ գրառում չի գտնվել';
            footer.querySelector('.list-pagination-page').textContent = `${page} / ${pages}`;
            footer.querySelector('[data-page-prev]').disabled = page <= 1;
            footer.querySelector('[data-page-next]').disabled = page >= pages;
            const showPages = matches.length > pageSizeDefault;
            footer.classList.toggle('has-pages', showPages);
            footer.querySelector('[data-page-prev]').hidden = !showPages;
            footer.querySelector('[data-page-next]').hidden = !showPages;
            footer.querySelector('.list-pagination-page').hidden = !showPages;
        };
        search.addEventListener('input', () => { page = 1; render(); });
        footer.querySelector('[data-page-prev]').addEventListener('click', () => { page = Math.max(1, page - 1); render(); });
        footer.querySelector('[data-page-next]').addEventListener('click', () => { page += 1; render(); });
        render();
    });
}

initPaginatedLists();

// Add column names to cells so dense data tables can become readable cards on phones.
document.querySelectorAll('.table-scroll table').forEach((table) => {
    const headings = [...table.querySelectorAll('thead th')].map((heading) => heading.textContent.trim());
    table.querySelectorAll('tbody tr').forEach((row) => {
        [...row.children].forEach((cell, index) => {
            if (cell.tagName === 'TD' && !cell.hasAttribute('colspan')) {
                const label = headings[index] || '';
                cell.dataset.label = label;
                cell.setAttribute('aria-label', label);
                const value = document.createElement('span');
                value.className = 'cell-value';
                while (cell.firstChild) value.appendChild(cell.firstChild);
                cell.appendChild(value);
            }
        });
    });
});

let requestLineIndex = 1;

function initSearchableSelects(root = document) {
    const selects = root.matches?.('select') ? [root] : Array.from(root.querySelectorAll('select'));
    selects.forEach((select) => {
        if (select.dataset.searchEnhanced === 'true' || select.multiple) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'searchable-select';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        const input = document.createElement('input');
        const listId = `select-options-${Math.random().toString(36).slice(2, 10)}`;
        input.type = 'text';
        input.className = 'searchable-select-input';
        input.autocomplete = 'off';
        input.placeholder = 'Որոնել կամ ընտրել…';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);
        input.setAttribute('aria-label', select.closest('label')?.firstChild?.textContent?.trim() || 'Ընտրել արժեքը');
        const wasRequired = select.required;
        input.required = wasRequired;
        input.disabled = select.disabled;
        select.required = false;
        select.tabIndex = -1;
        select.dataset.searchEnhanced = 'true';

        const menu = document.createElement('div');
        menu.className = 'searchable-select-menu';
        menu.id = listId;
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;
        wrapper.append(input, menu);

        let matches = [];
        let activeIndex = -1;
        const selectedOption = () => select.options[select.selectedIndex] || null;
        const syncInput = () => {
            input.value = selectedOption()?.textContent.trim() || '';
            input.setCustomValidity(wasRequired && (!select.value || input.value !== selectedOption()?.textContent.trim()) ? 'Ընտրեք արժեքը ցանկից։' : '');
            input.setAttribute('aria-invalid', String(Boolean(input.validationMessage)));
        };
        const close = (restore = true) => {
            menu.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            wrapper.classList.remove('is-open', 'opens-up');
            input.removeAttribute('aria-activedescendant');
            if (restore) syncInput();
        };
        const choose = (option) => {
            if (!option || option.disabled) return;
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncInput();
            close(false);
        };
        const render = (query = '') => {
            const normalized = query.trim().toLocaleLowerCase('hy-AM');
            matches = Array.from(select.options).filter((option) => {
                const searchable = `${option.textContent} ${option.value}`.toLocaleLowerCase('hy-AM');
                return !normalized || searchable.includes(normalized);
            });
            activeIndex = -1;
            menu.replaceChildren();
            if (!matches.length) {
                const empty = document.createElement('div');
                empty.className = 'searchable-select-empty';
                empty.textContent = 'Համընկնող տարբերակ չի գտնվել';
                menu.appendChild(empty);
            } else {
                matches.forEach((option, index) => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'searchable-select-option';
                    item.id = `${listId}-option-${index}`;
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', String(option.value === select.value));
                    item.disabled = option.disabled;
                    item.textContent = option.textContent.trim();
                    item.addEventListener('mousedown', (event) => event.preventDefault());
                    item.addEventListener('click', () => choose(option));
                    menu.appendChild(item);
                });
            }
        };
        const open = () => {
            render('');
            menu.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            wrapper.classList.add('is-open');
            const bounds = wrapper.getBoundingClientRect();
            wrapper.classList.toggle('opens-up', window.innerHeight - bounds.bottom < 250 && bounds.top > window.innerHeight - bounds.bottom);
        };

        input.addEventListener('focus', () => {
            input.select();
            open();
        });
        input.addEventListener('click', open);
        input.addEventListener('input', () => {
            input.setCustomValidity(wasRequired && (!select.value || input.value !== selectedOption()?.textContent.trim()) ? 'Ընտրեք արժեքը ցանկից։' : '');
            render(input.value);
            menu.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            wrapper.classList.add('is-open');
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (menu.hidden) open();
                activeIndex = Math.max(0, Math.min(matches.length - 1, activeIndex + (event.key === 'ArrowDown' ? 1 : -1)));
                menu.querySelectorAll('[role="option"]').forEach((item, index) => item.classList.toggle('is-active', index === activeIndex));
                const active = menu.querySelectorAll('[role="option"]')[activeIndex];
                if (active) {
                    input.setAttribute('aria-activedescendant', active.id);
                    active.scrollIntoView({ block: 'nearest' });
                }
            } else if (event.key === 'Enter' && !menu.hidden) {
                event.preventDefault();
                choose(matches[activeIndex >= 0 ? activeIndex : 0]);
            } else if (event.key === 'Escape' && !menu.hidden) {
                event.preventDefault();
                close();
            }
        });
        select.addEventListener('change', syncInput);
        select.addEventListener('reset-search', syncInput);
        select.style.display = 'none';
        syncInput();
    });
}

initSearchableSelects();

document.addEventListener('mousedown', (event) => {
    document.querySelectorAll('.searchable-select.is-open').forEach((wrapper) => {
        if (!wrapper.contains(event.target)) {
            const input = wrapper.querySelector('.searchable-select-input');
            input?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        }
    });
});

function addRequestLine() {
    const host = document.getElementById('request-lines');
    const first = host?.querySelector('.request-line');
    if (!first) return;
    const copy = first.cloneNode(true);
    const indices = [...host.querySelectorAll('[name^="items["]')].map((field) => Number((field.name.match(/items\[(\d+)\]/) || [])[1] || 0));
    requestLineIndex = Math.max(requestLineIndex, ...indices.map((index) => index + 1));
    copy.querySelectorAll('.searchable-select').forEach((wrapper) => {
        const nativeSelect = wrapper.querySelector('select');
        if (nativeSelect) {
            delete nativeSelect.dataset.searchEnhanced;
            wrapper.replaceWith(nativeSelect);
        }
    });
    copy.querySelectorAll('select, input').forEach((field) => {
        field.name = field.name.replace(/items\[\d+\]/, `items[${requestLineIndex}]`);
        if (field.tagName === 'SELECT') field.selectedIndex = 0;
        else field.value = '';
    });
    copy.querySelectorAll('.request-stock-hint').forEach((hint) => hint.remove());
    host.appendChild(copy);
    initSearchableSelects(copy);
    requestLineIndex += 1;
}

const requestStatsCache = new Map();
async function updateRequestLineStats(select) {
    const line = select.closest('.request-line');
    const modal = select.closest('#requests-modal');
    if (!line || !modal || !select.value) return;
    const branch = modal.querySelector('select[name="branch_id"]')?.value || modal.querySelector('input[name="branch_id"]')?.value;
    if (!branch) return;
    const cacheKey = String(branch);
    let stats = requestStatsCache.get(cacheKey);
    if (!stats) {
        try {
            const response = await fetch(`?page=requests&ajax=request_stats&branch_id=${encodeURIComponent(branch)}`, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            stats = (await response.json()).items;
            requestStatsCache.set(cacheKey, stats);
        } catch (_) { return; }
    }
    const item = stats[String(select.value)];
    if (!item) return;
    let hint = line.querySelector('.request-stock-hint');
    if (!hint) {
        hint = document.createElement('div');
        hint.className = 'request-stock-hint';
        select.closest('label')?.appendChild(hint);
    }
    const number = (value) => new Intl.NumberFormat('hy-AM', { maximumFractionDigits: 3 }).format(value);
    hint.replaceChildren();
    const text = document.createElement('span');
    text.textContent = `Մասնաճյուղում՝ ${number(item.current)} · Միջին ամսական սպառում՝ ${number(item.average)} · Առաջարկ՝ ${number(item.suggested)}`;
    const use = document.createElement('button');
    use.type = 'button';
    use.className = 'request-suggestion-button';
    use.textContent = 'Լրացնել առաջարկը';
    use.addEventListener('click', () => {
        const quantity = line.querySelector('input[name$="[qty]"]');
        if (quantity) {
            quantity.value = item.suggested;
            quantity.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
    hint.append(text, use);
}

document.addEventListener('change', (event) => {
    const target = event.target;
    if (target.matches('#requests-modal .request-line select[name$="[product_id]"]')) updateRequestLineStats(target);
    if (target.matches('#requests-modal select[name="branch_id"]')) {
        requestStatsCache.clear();
        document.querySelectorAll('#requests-modal .request-line select[name$="[product_id]"]').forEach(updateRequestLineStats);
    }
});

// Keep older server-rendered actions consistent with the refreshed icon button system.
const actionIconPaths = {
    'Փոփոխել': 'M12 20h9 M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z',
    'Անջատել': 'M3 6h18 M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6',
    'Դիտել / որոշել': 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
    'Կիրառել': 'M4 7h16M7 4v6m10-6v6M4 17h16m-5-3v6m-8-6v6',
    'Գրանցել մուտքը': 'M12 3v12m0 0 4-4m-4 4-4-4M4 17v3h16v-3',
    'Գրանցել գույքագրումը': 'M8 4h8l2 2h2v14H4V6h2Zm0 7h8m-8 4h5m-5-8h8',
    'Մուտքագրել գույքագրումը': 'M8 4h8l2 2h2v14H4V6h2Zm0 7h8m-8 4h5m-5-8h8',
    'Կատարված է': 'm5 12 4 4L19 6',
    'Մերժել': 'm6 6 12 12M18 6 6 18',
    'Հաստատել': 'm5 12 4 4L19 6',
    'Հաստատել պատվերը': 'm5 12 4 4L19 6',
    'Հաստատել և ուղարկել': 'M3 7h11v11H3zM14 11h4l3 3v4h-7zM7 18a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0',
    'Հավաքագրել և ուղարկել': 'M3 7h11v11H3zM14 11h4l3 3v4h-7zM7 18a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0',
    'Ստացա': 'm5 12 4 4L19 6',
};
document.querySelectorAll('.table-action, .danger-link').forEach((button) => {
    const label = button.textContent.trim();
    if (label) {
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    }
    if (button.querySelector('svg')) {
        button.classList.add('icon-only-action');
        button.querySelectorAll(':scope > .action-label').forEach((node) => node.remove());
        [...button.childNodes].filter((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim()).forEach((node) => {
            const caption = document.createElement('span');
            caption.className = 'action-label';
            caption.textContent = node.textContent.trim();
            node.replaceWith(caption);
        });
        return;
    }
    const path = actionIconPaths[label];
    if (!path) return;
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '1.8');
    icon.setAttribute('stroke-linecap', 'round');
    icon.setAttribute('stroke-linejoin', 'round');
    icon.setAttribute('aria-hidden', 'true');
    const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    shape.setAttribute('d', path);
    icon.appendChild(shape);
    button.prepend(icon);
    button.classList.add('icon-only-action');
    [...button.childNodes].filter((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim()).forEach((node) => {
        const caption = document.createElement('span');
        caption.className = 'action-label';
        caption.textContent = node.textContent.trim();
        node.replaceWith(caption);
    });
});

const navigationIcons = {
    dashboard: 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z',
    suppliers: 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m12-13a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm3 3a4 4 0 0 1 0 8m2 2v-2a4 4 0 0 0-3-3.87',
    products: 'm12 3 9 5-9 5-9-5 9-5Zm-9 5v8l9 5 9-5V8m-9 5v8',
    branches: 'M3 21h18M5 21V5l7-3 7 3v16M9 9h.01M15 9h.01M9 13h.01M15 13h.01M10 21v-4h4v4',
    purchases: 'M6 3h12l2 4v14H4V7l2-4Zm-2 4h16M9 11h6m-6 4h6',
    receipts: 'M4 4h16v16H4zM12 7v9m0 0 4-4m-4 4-4-4',
    stock: 'm12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5m-18 5 9 5 9-5',
    requests: 'M8 3h8l2 2h3v16H3V5h3l2-2Zm1 8h6m-6 4h6m-6 4h4',
    movements: 'M7 7h14m0 0-4-4m4 4-4 4M17 17H3m0 0 4-4m-4 4 4 4',
    inventory: 'M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01',
    expiry: 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    returns: 'M3 12a9 9 0 1 0 2.64-6.36L3 8m0-5v5h5m4-1v5l3 2',
    transfers: 'M3 7h11v11H3zM14 11h4l3 3v4h-7zM7 18a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0',
    notifications: 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4',
    reports: 'M4 19V5m0 14h17M8 15l4-4 3 3 6-7m0 0h-5m5 0v5',
    users: 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m12-13a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm3 3a4 4 0 0 1 0 8m2 2v-2a4 4 0 0 0-3-3.87',
    roles: 'M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Zm-3-11 2 2 4-4',
    audit: 'M3 12a9 9 0 1 0 2.64-6.36L3 8m0-5v5h5m4-1v5l3 2',
};
document.querySelectorAll('.nav-item').forEach((link) => {
    const page = new URL(link.href, location.href).searchParams.get('page');
    const path = navigationIcons[page];
    const holder = link.querySelector('.nav-icon');
    const label = link.querySelector('span:not(.nav-icon)')?.textContent.trim();
    if (label) link.title = label;
    if (!path || !holder) return;
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '1.8');
    icon.setAttribute('stroke-linecap', 'round');
    icon.setAttribute('stroke-linejoin', 'round');
    icon.setAttribute('aria-hidden', 'true');
    const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    shape.setAttribute('d', path);
    icon.appendChild(shape);
    holder.replaceChildren(icon);
});
document.querySelectorAll('.nav-group-icon[data-page]').forEach((holder) => {
    const path = navigationIcons[holder.dataset.page];
    if (!path) return;
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '1.8');
    icon.setAttribute('stroke-linecap', 'round');
    icon.setAttribute('stroke-linejoin', 'round');
    icon.setAttribute('aria-hidden', 'true');
    const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    shape.setAttribute('d', path);
    icon.appendChild(shape);
    holder.replaceChildren(icon);
});

const mobileSidebar = document.querySelector('.sidebar');
const mobileTopbar = document.querySelector('.topbar');
const mobileTopbarAnchor = mobileTopbar?.querySelector('.global-search') || mobileTopbar?.firstElementChild;
if (mobileSidebar && mobileTopbar) {
    const navToggle = document.createElement('button');
    navToggle.type = 'button';
    navToggle.className = 'mobile-nav-toggle';
    navToggle.setAttribute('aria-label', 'Բացել նավիգացիան');
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
    mobileTopbar.insertBefore(navToggle, mobileTopbarAnchor);

    const collapseToggle = document.createElement('button');
    collapseToggle.type = 'button';
    collapseToggle.className = 'sidebar-collapse-toggle';
    collapseToggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16m4-11h5m-5 4h5"/></svg>';
    mobileTopbar.insertBefore(collapseToggle, mobileTopbarAnchor);
    const collapsedByUser = localStorage.getItem('diagen-sidebar-collapsed') === 'true';
    if (collapsedByUser && window.innerWidth > 760) document.body.classList.add('sidebar-collapsed');
    const syncCollapseToggle = () => {
        const collapsed = document.body.classList.contains('sidebar-collapsed');
        collapseToggle.setAttribute('aria-label', collapsed ? 'Ընդարձակել կողային ընտրացանկը' : 'Ծալել կողային ընտրացանկը');
        collapseToggle.title = collapsed ? 'Ընդարձակել ընտրացանկը' : 'Ծալել ընտրացանկը';
        collapseToggle.setAttribute('aria-pressed', String(collapsed));
    };
    syncCollapseToggle();
    collapseToggle.addEventListener('click', () => {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('diagen-sidebar-collapsed', String(collapsed));
        syncCollapseToggle();
    });

    const scrim = document.createElement('button');
    scrim.type = 'button';
    scrim.className = 'sidebar-scrim';
    scrim.setAttribute('aria-label', 'Փակել նավիգացիան');
    document.body.appendChild(scrim);

    const closeNavigation = () => {
        document.body.classList.remove('sidebar-open');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', 'Բացել նավիգացիան');
    };
    navToggle.addEventListener('click', () => {
        const open = document.body.classList.toggle('sidebar-open');
        navToggle.setAttribute('aria-expanded', String(open));
        navToggle.setAttribute('aria-label', open ? 'Փակել նավիգացիան' : 'Բացել նավիգացիան');
    });
    scrim.addEventListener('click', closeNavigation);
    mobileSidebar.querySelectorAll('.nav-item').forEach((link) => link.addEventListener('click', closeNavigation));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeNavigation(); });
}

const sectionSearch = document.getElementById('section-search');
const sectionSearchInput = document.getElementById('section-search-input');
const sectionSearchResults = document.getElementById('section-search-results');
const sectionSearchOptions = sectionSearch ? [...sectionSearch.querySelectorAll('[data-section-option]')] : [];
const sectionSearchEmpty = sectionSearch?.querySelector('.global-search-empty');
let sectionSearchActive = -1;

if (sectionSearchInput && sectionSearchResults) {
    sectionSearchOptions.forEach((option, index) => { option.id ||= `section-option-${index}`; });
    const visibleSectionOptions = () => sectionSearchOptions.filter((option) => !option.hidden);
    const closeSectionSearch = () => {
        sectionSearchResults.hidden = true;
        sectionSearchInput.setAttribute('aria-expanded', 'false');
        sectionSearchInput.removeAttribute('aria-activedescendant');
        sectionSearchOptions.forEach((option) => {
            option.classList.remove('is-active');
            option.setAttribute('aria-selected', 'false');
        });
        sectionSearchActive = -1;
    };
    const updateSectionSearch = () => {
        const query = sectionSearchInput.value.trim().toLocaleLowerCase('hy-AM');
        let matches = 0;
        sectionSearchOptions.forEach((option) => {
            const match = !query || option.dataset.searchValue.includes(query);
            option.hidden = !match;
            option.classList.remove('is-active');
            option.setAttribute('aria-selected', 'false');
            if (match) matches += 1;
        });
        if (sectionSearchEmpty) sectionSearchEmpty.hidden = matches > 0;
        sectionSearchActive = -1;
        sectionSearchResults.hidden = false;
        sectionSearchInput.setAttribute('aria-expanded', 'true');
        sectionSearchInput.removeAttribute('aria-activedescendant');
    };
    const activateSectionOption = (index) => {
        const options = visibleSectionOptions();
        if (!options.length) return;
        sectionSearchActive = (index + options.length) % options.length;
        options.forEach((option, optionIndex) => {
            const active = optionIndex === sectionSearchActive;
            option.classList.toggle('is-active', active);
            option.setAttribute('aria-selected', String(active));
        });
        const activeOption = options[sectionSearchActive];
        sectionSearchInput.setAttribute('aria-activedescendant', activeOption.id);
        activeOption.scrollIntoView({ block: 'nearest' });
    };
    sectionSearchInput.value = '';
    closeSectionSearch();
    window.addEventListener('pageshow', () => {
        sectionSearchInput.value = '';
        closeSectionSearch();
    });
    sectionSearchInput.addEventListener('focus', updateSectionSearch);
    sectionSearchInput.addEventListener('input', updateSectionSearch);
    sectionSearchInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (sectionSearchResults.hidden) updateSectionSearch();
            activateSectionOption(sectionSearchActive + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (sectionSearchResults.hidden) updateSectionSearch();
            activateSectionOption(sectionSearchActive < 0 ? visibleSectionOptions().length - 1 : sectionSearchActive - 1);
        } else if (event.key === 'Enter' && !sectionSearchResults.hidden) {
            const options = visibleSectionOptions();
            if (options.length) {
                event.preventDefault();
                options[sectionSearchActive < 0 ? 0 : sectionSearchActive].click();
            }
        } else if (event.key === 'Escape' && !sectionSearchResults.hidden) {
            event.stopPropagation();
            closeSectionSearch();
        }
    });
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            sectionSearchInput.focus();
            sectionSearchInput.select();
        }
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('#section-search')) closeSectionSearch();
    });
    sectionSearchOptions.forEach((option) => option.addEventListener('click', closeSectionSearch));
}

const notificationButton = document.getElementById('notification-toggle');
const notificationPopover = document.getElementById('notification-popover');
const notificationBadge = document.getElementById('notification-count');
const notificationItems = document.getElementById('notification-items');
const notificationSubtitle = document.getElementById('notification-subtitle');
const notificationStatus = document.getElementById('notification-live-status');
const soundButton = document.getElementById('notification-sound');
const profileButton = document.getElementById('profile-toggle');
const profilePopover = document.getElementById('profile-popover');
const seenNotifications = new Set(Array.from(document.querySelectorAll('[data-notice-key]'), (item) => item.dataset.noticeKey));
let audioContext = null;
let soundEnabled = localStorage.getItem('diagen-notification-sound') === 'on';
let soundUnlockHandler = null;

function setPopover(open) {
    if (!notificationPopover || !notificationButton) return;
    notificationPopover.hidden = !open;
    notificationButton.setAttribute('aria-expanded', String(open));
}

function setProfilePopover(open) {
    if (!profilePopover || !profileButton) return;
    profilePopover.hidden = !open;
    profileButton.setAttribute('aria-expanded', String(open));
}

profileButton?.addEventListener('click', (event) => {
    event.stopPropagation();
    setProfilePopover(profilePopover.hidden);
});

document.addEventListener('click', (event) => {
    if (profilePopover && !profilePopover.hidden && !event.target.closest('.profile-menu')) setProfilePopover(false);
});

notificationButton?.addEventListener('click', (event) => {
    event.stopPropagation();
    setPopover(notificationPopover.hidden);
});

document.addEventListener('click', (event) => {
    if (notificationPopover && !notificationPopover.hidden && !event.target.closest('.notification-center')) setPopover(false);
});

function updateSoundButton() {
    if (!soundButton) return;
    soundButton.classList.toggle('is-enabled', soundEnabled);
    soundButton.setAttribute('aria-pressed', String(soundEnabled));
    soundButton.setAttribute('aria-label', soundEnabled ? 'Անջատել ծանուցումների ձայնը' : 'Միացնել ծանուցումների ձայնը');
    soundButton.title = soundEnabled ? 'Անջատել ծանուցումների ձայնը' : 'Միացնել ծանուցումների ձայնը';
}

function playNotificationSound() {
    if (!soundEnabled || !audioContext || audioContext.state !== 'running') return;
    const start = audioContext.currentTime;
    [740, 980].forEach((frequency, index) => {
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();
        const time = start + index * 0.13;
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(frequency, time);
        gain.gain.setValueAtTime(0.0001, time);
        gain.gain.exponentialRampToValueAtTime(0.11, time + 0.025);
        gain.gain.exponentialRampToValueAtTime(0.0001, time + 0.22);
        oscillator.connect(gain);
        gain.connect(audioContext.destination);
        oscillator.start(time);
        oscillator.stop(time + 0.24);
    });
}

function unlockSound() {
    if (!soundEnabled || audioContext) return;
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;
    audioContext = new AudioContextClass();
    audioContext.resume().catch(() => {});
    if (soundUnlockHandler) {
        document.removeEventListener('pointerdown', soundUnlockHandler);
        document.removeEventListener('keydown', soundUnlockHandler);
        soundUnlockHandler = null;
    }
}

soundButton?.addEventListener('click', () => {
    soundEnabled = !soundEnabled;
    localStorage.setItem('diagen-notification-sound', soundEnabled ? 'on' : 'off');
    updateSoundButton();
    if (soundEnabled) unlockSound();
});

updateSoundButton();
if (soundEnabled) {
    soundUnlockHandler = unlockSound;
    document.addEventListener('pointerdown', soundUnlockHandler, { once: true });
    document.addEventListener('keydown', soundUnlockHandler, { once: true });
}

function showNotificationToast(item) {
    const toast = document.createElement('div');
    toast.className = 'live-notification-toast';
    toast.setAttribute('role', 'status');
    toast.innerHTML = '<span class="toast-live-dot"></span><span class="toast-live-copy"><strong></strong><small></small></span><button type="button" aria-label="Փակել">×</button>';
    toast.querySelector('strong').textContent = item.title;
    toast.querySelector('small').textContent = item.detail;
    toast.querySelector('button').addEventListener('click', () => toast.remove());
    document.body.appendChild(toast);
    window.setTimeout(() => toast.remove(), 7000);
}

function renderNotifications(payload, announce = false) {
    if (!notificationItems) return;
    const items = Array.isArray(payload.items) ? payload.items : [];
    let foundNew = false;
    const fragment = document.createDocumentFragment();

    items.forEach((item) => {
        const signature = item.key || `${item.title}|${item.detail}|${item.link}`;
        if (announce && !seenNotifications.has(signature)) {
            foundNew = true;
            showNotificationToast(item);
        }
        seenNotifications.add(signature);

        const link = document.createElement('a');
        link.className = 'notification-item';
        link.href = item.link;
        const tone = document.createElement('span');
        tone.className = `notification-tone ${item.tone || 'blue'}`;
        const copy = document.createElement('span');
        copy.className = 'notification-item-copy';
        const title = document.createElement('strong');
        title.textContent = item.title;
        const detail = document.createElement('small');
        detail.textContent = item.detail;
        const chevron = document.createElement('span');
        chevron.className = 'notification-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = '›';
        copy.append(title, detail);
        link.append(tone, copy, chevron);
        fragment.append(link);
    });

    if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'notification-empty';
        empty.innerHTML = '<span class="notification-empty-icon">✓</span><strong>Ամեն ինչ կարգին է</strong><small>Նոր ծանուցումներ չկան։</small>';
        fragment.append(empty);
    }

    notificationItems.replaceChildren(fragment);
    const count = Number(payload.count || 0);
    if (notificationBadge) {
        notificationBadge.textContent = count > 99 ? '99+' : String(count);
        notificationBadge.hidden = count === 0;
    }
    if (notificationSubtitle) notificationSubtitle.textContent = `${count} ակտիվ ազդանշան`;
    if (announce && foundNew) {
        playNotificationSound();
        if (notificationStatus) notificationStatus.textContent = 'Ստացվեց նոր ծանուցում։';
        notificationButton?.classList.add('has-new');
        window.setTimeout(() => notificationButton?.classList.remove('has-new'), 2200);
    }
}

async function refreshNotifications(announce = false) {
    try {
        const response = await fetch('?page=notifications&ajax=notifications', { credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) return;
        renderNotifications(await response.json(), announce);
    } catch (_) {
        // Keep the last known notification list if the app briefly loses its network connection.
    }
}

const liveStatus = document.createElement('span');
liveStatus.className = 'realtime-status is-connecting';
liveStatus.innerHTML = '<i aria-hidden="true"></i><span>Ուղիղ կապ</span>';
liveStatus.setAttribute('role', 'status');
liveStatus.setAttribute('aria-live', 'polite');
document.querySelector('.topbar-right')?.prepend(liveStatus);

let notificationsRefreshTimer = null;
let pageRefreshTimer = null;
function showRefreshPrompt() {
    let toast = document.querySelector('.live-refresh-toast');
    if (toast) return;
    toast = document.createElement('div');
    toast.className = 'live-refresh-toast';
    toast.setAttribute('role', 'status');
    const message = document.createElement('span');
    message.textContent = 'Նոր տվյալներ կան';
    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.textContent = 'Թարմացնել';
    refresh.addEventListener('click', () => window.location.reload());
    toast.append(message, refresh);
    document.body.appendChild(toast);
}

function handleDataRefresh(change) {
    if (notificationButton) {
        window.clearTimeout(notificationsRefreshTimer);
        notificationsRefreshTimer = window.setTimeout(() => refreshNotifications(true), 180);
    }
    // The submitting page has already rendered the committed result and its flash message.
    if (Number(change.actor) === Number(window.DIAGEN_USER_ID) && document.querySelector('.page > .alert')) return;

    const hasUnsavedForm = Array.from(document.querySelectorAll('form')).some((form) => form.dataset.dirty === 'true');
    const hasOpenModal = Boolean(document.querySelector('.modal-backdrop:not([hidden])'));
    if (hasUnsavedForm || hasOpenModal) {
        showRefreshPrompt();
        return;
    }
    window.clearTimeout(pageRefreshTimer);
    pageRefreshTimer = window.setTimeout(() => window.location.reload(), 300);
}

document.addEventListener('input', (event) => {
    const form = event.target.closest('form');
    if (form && !event.target.matches('[data-table-search]')) form.dataset.dirty = 'true';
});
document.addEventListener('change', (event) => {
    const form = event.target.closest('form');
    if (form) form.dataset.dirty = 'true';
});
document.addEventListener('submit', (event) => {
    event.target.dataset.dirty = 'false';
});

let socketRetryDelay = 1200;
let activeSocket = null;
function connectRealtime() {
    if (!window.DIAGEN_USER_ID || !('WebSocket' in window)) return;
    const url = window.DIAGEN_WS_URL || `${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.hostname}:8096`;
    liveStatus?.classList.remove('is-live');
    liveStatus?.classList.add('is-connecting');
    let socket;
    try { socket = new WebSocket(url); activeSocket = socket; } catch (_) { scheduleReconnect(); return; }
    socket.addEventListener('open', () => {
        if (activeSocket !== socket) return;
        socketRetryDelay = 1200;
        liveStatus?.classList.remove('is-connecting');
        liveStatus?.classList.add('is-live');
        notificationButton?.classList.add('is-live');
    });
    socket.addEventListener('message', (event) => {
        try {
            const change = JSON.parse(event.data);
            if (change.type === 'data.refresh') handleDataRefresh(change);
        } catch (_) {}
    });
    socket.addEventListener('close', () => {
        if (activeSocket !== socket) return;
        notificationButton?.classList.remove('is-live');
        liveStatus?.classList.remove('is-live');
        liveStatus?.classList.add('is-connecting');
        scheduleReconnect();
    });
    socket.addEventListener('error', () => socket.close());
}

function scheduleReconnect() {
    window.setTimeout(connectRealtime, socketRetryDelay);
    socketRetryDelay = Math.min(20000, Math.round(socketRetryDelay * 1.8));
}

connectRealtime();

const pageKey = new URLSearchParams(window.location.search).get('page') || 'dashboard';
const exportPages = {
    stock: 'Ընդհանուր մնացորդ',
    expiry: 'Ժամկետների տվյալներ',
    requests: 'Պահանջագրերի տվյալներ',
    returns: 'Վերադարձների տվյալներ',
    inventory: 'Գույքագրման տվյալներ',
};
const pageHeadingNode = document.querySelector('.page-heading');
if (pageHeadingNode && exportPages[pageKey]) {
    const toolbar = document.createElement('div');
    toolbar.className = 'report-export-toolbar';
    const link = document.createElement('a');
    link.className = 'secondary-button';
    link.href = `?page=${encodeURIComponent(pageKey)}&export=csv`;
    link.textContent = `Ներբեռնել CSV · ${exportPages[pageKey]}`;
    toolbar.appendChild(link);
    pageHeadingNode.after(toolbar);
}
if (pageHeadingNode && pageKey === 'reports') {
    const toolbar = document.createElement('div');
    toolbar.className = 'report-export-toolbar';
    const note = document.createElement('span');
    note.textContent = 'Հաշվետվությունները կարելի է տպել կամ պահպանել PDF-ով։';
    const print = document.createElement('button');
    print.type = 'button';
    print.className = 'secondary-button';
    print.textContent = 'Տպել / պահպանել PDF';
    print.addEventListener('click', () => {
        window.focus();
        window.print();
    });
    toolbar.append(note, print);
    pageHeadingNode.after(toolbar);


}

const pageCreateButton = document.querySelector('.page > .create-button');
if (pageCreateButton) {
    const actionRow = document.querySelector('.page .report-export-toolbar') || pageHeadingNode;
    actionRow?.appendChild(pageCreateButton);
    pageCreateButton.classList.add('page-create-action');
}

function addTransferLine(){
 const wrap=document.getElementById('transfer-lines');if(!wrap)return;const index=wrap.querySelectorAll('.transfer-line').length;const first=wrap.querySelector('select[name^="items["]');if(!first)return;const row=document.createElement('div');row.className='transfer-line';const product=document.createElement('label');product.textContent='Ապրանք';const select=first.cloneNode(true);select.name='items['+index+'][product_id]';select.required=true;select.value='';product.append(select);const qty=document.createElement('label');qty.textContent='Քանակ';const input=document.createElement('input');input.name='items['+index+'][qty]';input.type='number';input.min='0.001';input.step='0.001';input.required=true;qty.append(input);const remove=document.createElement('button');remove.type='button';remove.className='icon-button';remove.setAttribute('aria-label','Հեռացնել տողը');remove.textContent='×';remove.addEventListener('click',()=>row.remove());row.append(product,qty,remove);wrap.append(row);
}

function addReturnLine(){
 const wrap=document.getElementById('return-lines');if(!wrap)return;const index=wrap.querySelectorAll('.return-line').length;const first=wrap.querySelector('select[name^="items["]');if(!first)return;const row=document.createElement('div');row.className='return-line';const product=document.createElement('label');product.textContent='Ապրանք';const select=first.cloneNode(true);select.name='items['+index+'][product_id]';select.required=true;select.value='';product.append(select);const qty=document.createElement('label');qty.textContent='Քանակ';const input=document.createElement('input');input.name='items['+index+'][qty]';input.type='number';input.min='0.001';input.step='0.001';input.required=true;qty.append(input);const remove=document.createElement('button');remove.type='button';remove.className='icon-button';remove.setAttribute('aria-label','Հեռացնել տողը');remove.textContent='×';remove.addEventListener('click',()=>row.remove());row.append(product,qty,remove);wrap.append(row);
}

const barcodeScanForm = document.querySelector('.product-barcode-search');
const barcodeCameraOpen = document.getElementById('barcode-camera-open');
const barcodeCameraClose = document.getElementById('barcode-camera-close');
const barcodeCameraPanel = document.getElementById('barcode-camera-panel');
const barcodeCameraVideo = document.getElementById('barcode-camera-video');
const barcodeCameraStatus = document.getElementById('barcode-camera-status');
let barcodeCameraStream = null;
let barcodeCameraFrame = 0;
let barcodeDetector = null;
function stopBarcodeCamera() {
    if (barcodeCameraFrame) cancelAnimationFrame(barcodeCameraFrame);
    barcodeCameraFrame = 0;
    barcodeCameraStream?.getTracks().forEach((track) => track.stop());
    barcodeCameraStream = null;
    if (barcodeCameraVideo) barcodeCameraVideo.srcObject = null;
    if (barcodeCameraPanel) barcodeCameraPanel.hidden = true;
}
barcodeCameraOpen?.addEventListener('click', async () => {
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
        if (barcodeCameraStatus) barcodeCameraStatus.textContent = 'Այս դիտարկիչը չի աջակցում տեսախցիկով սկանավորումը։ Օգտագործեք շտրիխ սկաները կամ մուտքագրեք կոդը։';
        if (barcodeCameraPanel) barcodeCameraPanel.hidden = false;
        return;
    }
    try {
        const supported = await window.BarcodeDetector.getSupportedFormats();
        const formats = ['qr_code', 'ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e'].filter((format) => supported.includes(format));
        if (!formats.length) throw new Error('Սկանավորվող կոդի ձևաչափ չի աջակցվում։');
        barcodeDetector = new window.BarcodeDetector({ formats });
        barcodeCameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        barcodeCameraVideo.srcObject = barcodeCameraStream;
        barcodeCameraPanel.hidden = false;
        barcodeCameraStatus.textContent = 'Ուղղեք շտրիխը կամ QR կոդը տեսախցիկին։';
        await barcodeCameraVideo.play();
        const scan = async () => {
            if (!barcodeCameraStream) return;
            try {
                const results = await barcodeDetector.detect(barcodeCameraVideo);
                if (results.length && results[0].rawValue) {
                    barcodeScanForm.querySelector('[name="barcode"]').value = results[0].rawValue;
                    stopBarcodeCamera();
                    barcodeScanForm.requestSubmit();
                    return;
                }
            } catch (_) {}
            barcodeCameraFrame = requestAnimationFrame(scan);
        };
        barcodeCameraFrame = requestAnimationFrame(scan);
    } catch (error) {
        stopBarcodeCamera();
        if (barcodeCameraPanel) barcodeCameraPanel.hidden = false;
        if (barcodeCameraStatus) barcodeCameraStatus.textContent = error?.name === 'NotAllowedError' ? 'Տեսախցիկի թույլտվությունը մերժված է։ Կարող եք օգտագործել շտրիխ սկաները կամ մուտքագրել կոդը։' : (error?.message || 'Տեսախցիկը հասանելի չէ։');
    }
});
barcodeCameraClose?.addEventListener('click', stopBarcodeCamera);
window.addEventListener('pagehide', stopBarcodeCamera);

// Replace browser-specific date popups with one Armenian, keyboard-friendly picker.
const armenianMonths = ['Հունվար','Փետրվար','Մարտ','Ապրիլ','Մայիս','Հունիս','Հուլիս','Օգոստոս','Սեպտեմբեր','Հոկտեմբեր','Նոյեմբեր','Դեկտեմբեր'];
const armenianWeekdays = ['Երկ','Երք','Չրք','Հնգ','Ուրբ','Շբթ','Կիր'];
let activeDatePicker = null;
let datePickerSequence = 0;

function localDateISO(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function parsePickerDate(value) {
    const match = String(value).trim().match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/);
    if (!match) return null;
    const [, day, month, year] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day));
    return date.getFullYear() === Number(year) && date.getMonth() === Number(month) - 1 && date.getDate() === Number(day) ? date : null;
}

function formatPickerDate(date) {
    return date ? `${String(date.getDate()).padStart(2, '0')}.${String(date.getMonth() + 1).padStart(2, '0')}.${date.getFullYear()}` : '';
}

function enhanceDateInput(nativeInput) {
    if (nativeInput.dataset.datePickerReady === 'true' || !nativeInput.isConnected) return;
    nativeInput.dataset.datePickerReady = 'true';
    const required = nativeInput.required;
    const initialDate = nativeInput.value ? parsePickerDate(nativeInput.value.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3.$2.$1')) : null;
    const minValue = nativeInput.min;
    const maxValue = nativeInput.max;
    const wrapper = document.createElement('div');
    wrapper.className = 'date-picker';
    wrapper.dataset.datePicker = 'true';
    wrapper.innerHTML = `<div class="date-picker-control"><input class="date-picker-text" type="text" inputmode="numeric" autocomplete="off" placeholder="օր.՝ 27.09.2026" aria-label="Ամսաթիվ՝ օր. ամիս. տարի"><button class="date-picker-trigger" type="button" aria-label="Բացել օրացույցը" aria-haspopup="dialog" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M4 10h16"></path></svg></button></div><div class="date-picker-popover" role="dialog" aria-label="Ընտրել ամսաթիվը" hidden></div>`;
    const visibleInput = wrapper.querySelector('.date-picker-text');
    const trigger = wrapper.querySelector('.date-picker-trigger');
    const popover = wrapper.querySelector('.date-picker-popover');
    const id = `date-picker-${++datePickerSequence}`;
    const originalId = nativeInput.id;
    if (originalId) {
        visibleInput.id = `${originalId}-display`;
        nativeInput.removeAttribute('id');
    }
    visibleInput.required = required;
    visibleInput.name = '';
    visibleInput.autocapitalize = 'off';
    visibleInput.spellcheck = false;
    trigger.setAttribute('aria-controls', id);
    popover.id = id;
    const name = nativeInput.name;
    nativeInput.required = false;
    nativeInput.tabIndex = -1;
    nativeInput.classList.add('date-picker-native');
    nativeInput.setAttribute('aria-hidden', 'true');
    nativeInput.setAttribute('name', name);
    nativeInput.type = 'hidden';
    nativeInput.replaceWith(wrapper);
    wrapper.append(nativeInput);

    let viewDate = initialDate || new Date();
    const minDate = minValue ? parsePickerDate(minValue.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3.$2.$1')) : null;
    const maxDate = maxValue ? parsePickerDate(maxValue.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3.$2.$1')) : null;

    const syncValue = (date, dispatch = false) => {
        const iso = date ? localDateISO(date) : '';
        nativeInput.value = iso;
        visibleInput.value = formatPickerDate(date);
        const outOfRange = date && ((minDate && date < minDate) || (maxDate && date > maxDate));
        visibleInput.setCustomValidity(outOfRange ? 'Ամսաթիվը թույլատրելի միջակայքից դուրս է։' : '');
        wrapper.classList.toggle('has-value', Boolean(date));
        if (dispatch) {
            nativeInput.dispatchEvent(new Event('input', { bubbles: true }));
            nativeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    const positionPopover = () => {
        const rect = wrapper.getBoundingClientRect();
        const width = Math.min(320, Math.max(280, window.innerWidth - 24));
        popover.style.width = `${width}px`;
        popover.style.left = `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`;
        const estimatedHeight = 370;
        const below = window.innerHeight - rect.bottom;
        popover.style.top = `${below < estimatedHeight && rect.top > estimatedHeight ? Math.max(12, rect.top - estimatedHeight - 8) : Math.min(window.innerHeight - estimatedHeight - 12, rect.bottom + 8)}px`;
    };

    const renderCalendar = () => {
        const year = viewDate.getFullYear();
        const month = viewDate.getMonth();
        const first = new Date(year, month, 1);
        const mondayOffset = (first.getDay() + 6) % 7;
        const start = new Date(year, month, 1 - mondayOffset);
        const selectedISO = nativeInput.value;
        const todayISO = localDateISO(new Date());
        const days = Array.from({ length: 42 }, (_, index) => {
            const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
            const iso = localDateISO(date);
            const disabled = (minDate && date < minDate) || (maxDate && date > maxDate);
            return `<button type="button" class="date-picker-day${date.getMonth() !== month ? ' outside' : ''}${iso === selectedISO ? ' selected' : ''}${iso === todayISO ? ' today' : ''}" data-date="${iso}" ${disabled ? 'disabled' : ''} aria-label="${formatPickerDate(date)}"${iso === selectedISO ? ' aria-pressed="true"' : ''}>${date.getDate()}</button>`;
        }).join('');
        popover.innerHTML = `<div class="date-picker-heading"><button type="button" class="date-picker-nav" data-month="-1" aria-label="Նախորդ ամիս">‹</button><strong>${armenianMonths[month]} ${year}</strong><button type="button" class="date-picker-nav" data-month="1" aria-label="Հաջորդ ամիս">›</button></div><div class="date-picker-weekdays">${armenianWeekdays.map(day => `<span>${day}</span>`).join('')}</div><div class="date-picker-days">${days}</div><div class="date-picker-footer"><button type="button" data-clear>Մաքրել</button><button type="button" data-today>Այսօր</button></div>`;
    };

    const close = (restoreFocus = false) => {
        popover.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        wrapper.classList.remove('is-open');
        if (activeDatePicker === wrapper) activeDatePicker = null;
        if (restoreFocus) trigger.focus();
    };

    const open = () => {
        if (activeDatePicker && activeDatePicker !== wrapper) activeDatePicker.querySelector('.date-picker-trigger')?.click();
        viewDate = nativeInput.value ? (parsePickerDate(nativeInput.value.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3.$2.$1')) || new Date()) : new Date();
        renderCalendar();
        popover.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        wrapper.classList.add('is-open');
        activeDatePicker = wrapper;
        positionPopover();
    };

    syncValue(initialDate);
    visibleInput.addEventListener('input', () => {
        const date = parsePickerDate(visibleInput.value);
        if (!visibleInput.value.trim()) syncValue(null);
        else if (date) syncValue(date);
        else {
            nativeInput.value = '';
            visibleInput.setCustomValidity('Մուտքագրեք ամսաթիվը օր.ամիս.տարի ձևաչափով։');
        }
    });
    visibleInput.addEventListener('blur', () => {
        const date = parsePickerDate(visibleInput.value);
        if (date) visibleInput.value = formatPickerDate(date);
    });
    visibleInput.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' || event.key === 'F4') { event.preventDefault(); open(); }
        if (event.key === 'Escape' && !popover.hidden) close();
    });
    trigger.addEventListener('click', () => popover.hidden ? open() : close());
    popover.addEventListener('click', event => {
        const monthButton = event.target.closest('[data-month]');
        if (monthButton) {
            viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + Number(monthButton.dataset.month), 1);
            renderCalendar();
            positionPopover();
            return;
        }
        const dayButton = event.target.closest('[data-date]');
        if (dayButton) {
            syncValue(parsePickerDate(dayButton.dataset.date.split('-').reverse().join('.')), true);
            close(true);
            return;
        }
        if (event.target.closest('[data-clear]')) { syncValue(null, true); close(true); return; }
        if (event.target.closest('[data-today]')) { syncValue(new Date(), true); close(true); }
    });
    visibleInput.addEventListener('focus', () => wrapper.classList.add('is-focused'));
    visibleInput.addEventListener('blur', () => wrapper.classList.remove('is-focused'));
    document.addEventListener('pointerdown', event => {
        if (activeDatePicker === wrapper && !wrapper.contains(event.target) && !popover.contains(event.target)) close();
    });
    document.addEventListener('keydown', event => {
        if (activeDatePicker === wrapper && event.key === 'Escape') close(true);
    });
    window.addEventListener('resize', () => { if (activeDatePicker === wrapper) positionPopover(); });
    window.addEventListener('scroll', () => { if (activeDatePicker === wrapper) positionPopover(); }, true);
}

function enhanceDateInputs(root = document) {
    if (root.matches?.('input[type="date"]')) enhanceDateInput(root);
    root.querySelectorAll?.('input[type="date"]').forEach(enhanceDateInput);
}

enhanceDateInputs();
new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node.nodeType === Node.ELEMENT_NODE) enhanceDateInputs(node);
}))).observe(document.body, { childList: true, subtree: true });
