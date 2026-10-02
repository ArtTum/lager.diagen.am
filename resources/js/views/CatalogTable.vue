<script setup>
import Pagination from '@/components/Pagination.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import { canCreateRecord } from '@/permissions';
import ExportActions from '@/components/ExportActions.vue';
import BarcodeScanner from '@/components/BarcodeScanner.vue';
import DestructiveConfirmDialog from '@/components/DestructiveConfirmDialog.vue';
import { formatDisplayDate, isDateValue } from '@/dateUtils';

const route = useRoute();
const result = ref(null);
const options = ref({});
const busy = ref(false);
const saving = ref(false);
const modal = ref(false);
const traceOpen = ref(false);
const categoriesOpen = ref(false);
const categorySaving = ref(false);
const categoryBusy = ref(false);
const categoryError = ref('');
const categoryNotice = ref('');
const categoryName = ref('');
const categoryParent = ref('');
const destructiveConfirm = ref(null);
const destructiveBusy = ref(false);
const destructiveError = ref('');
const traceBusy = ref(false);
const traceData = ref(null);
const tracePages = reactive({ lots: 1, movements: 1, requests: 1 });
const error = ref('');
const notice = ref('');
const search = ref('');
const barcodeSearch = ref('');
const selected = ref(null);
const me = ref(currentUser());
const fieldsByPage = {
    branches: [
        ['name', 'Անվանում', 'text', true], ['code', 'Կոդ', 'text', true], ['address', 'Հասցե', 'text'],
        ['manager', 'Պատասխանատու', 'text'], ['phone', 'Հեռախոս', 'tel'], ['active', 'Ակտիվ մասնաճյուղ', 'checkbox'],
    ],
    products: [
        ['code', 'Ներքին կոդ', 'text', true], ['barcode', 'Շտրիխ կոդ', 'text'], ['name', 'Ապրանքի անվանում', 'text', true],
        ['category_id', 'Խումբ', 'categories'], ['subcategory', 'Ենթախումբ', 'text'], ['supplier_id', 'Մատակարար', 'suppliers'],
        ['purchase_price', 'Գնման գին՝ դրամ', 'number', true], ['manufacturer', 'Արտադրող', 'text'], ['unit', 'Չափման միավոր', 'text', true],
        ['package', 'Փաթեթավորում', 'text'], ['min_qty', 'MIN', 'number', true], ['optimal_qty', 'OPTIMAL', 'number', true],
        ['max_qty', 'MAX', 'number', true], ['storage_conditions', 'Պահման պայմաններ', 'text'], ['refrigerated', 'Սառնարանային պահպանում', 'checkbox'],
        ['lot_control', 'LOT վերահսկում', 'checkbox'], ['expiry_control', 'Ժամկետի վերահսկում', 'checkbox'], ['active', 'Ակտիվ ապրանք', 'checkbox'],
    ],
    users: [
        ['name', 'Անուն', 'text', true], ['email', 'Էլ. փոստ', 'email', true], ['role_id', 'Դեր', 'roles', true],
        ['branch_id', 'Մասնաճյուղ', 'branches'], ['password', 'Գաղտնաբառ', 'password'], ['active', 'Ակտիվ օգտահաշիվ', 'checkbox'],
    ],
};
const emptyByPage = {
    branches: { name: '', code: '', address: '', manager: '', phone: '', active: true },
    products: { code: '', barcode: '', name: '', category_id: '', subcategory: '', supplier_id: '', purchase_price: 0, manufacturer: '', unit: 'հատ', package: '', min_qty: 0, optimal_qty: 0, max_qty: 0, storage_conditions: '', refrigerated: false, lot_control: true, expiry_control: true, active: true },
    users: { name: '', email: '', role_id: '', branch_id: '', password: '', active: true },
    roles: { title: '', permissions: [] },
};
const form = reactive({});
const page = computed(() => route.path.slice(1));
const title = computed(() => route.meta.title || 'Կառավարում');
const modulePermission = (action) => Boolean(me.value?.permissions?.[`${page.value}.${action}`]);
const canCreate = computed(() => canCreateRecord(page.value, me.value?.permissions));
const fields = computed(() => (fieldsByPage[page.value] || []).filter(([key]) => key !== 'purchase_price' || Boolean(me.value?.permissions?.['purchases.view'])));
const rows = computed(() => result.value?.data || []);
const permissionsByModule = computed(() => (options.value.permissions || []).reduce((groups, item) => {
    (groups[item.module] ||= []).push(item);
    return groups;
}, {}));
const permissionModuleLabels = {
    audit: 'Գործողությունների պատմություն', branches: 'Մասնաճյուղեր', dashboard: 'Գլխավոր վահանակ',
    expiry: 'Ժամկետների վերահսկում', inventory: 'Գույքագրում', movements: 'Պահեստի շարժ',
    notifications: 'Ծանուցումներ', products: 'Ապրանքներ', purchases: 'Գնումների պատվերներ', receipts: 'Մուտքեր',
    reports: 'Հաշվետվություններ', requests: 'Պահանջագրեր', returns: 'Վերադարձներ', roles: 'Դերեր և իրավունքներ',
    stock: 'Պաշարներ', suppliers: 'Մատակարարներ', transfers: 'Տեղափոխումներ', users: 'Օգտատերեր',
};
const permissionModuleIcons = {
    audit: 'history', branches: 'branches', dashboard: 'dashboard', expiry: 'clock', inventory: 'clipboard',
    movements: 'movements', notifications: 'bell', products: 'box', purchases: 'clipboard', receipts: 'arrowDown',
    requests: 'plusFile', returns: 'returns', roles: 'shield', stock: 'boxes', suppliers: 'users', transfers: 'transfers', users: 'users',
};
const permissionModuleTitle = (moduleName) => permissionModuleLabels[moduleName] || moduleName;
const permissionModuleIcon = (moduleName) => permissionModuleIcons[moduleName] || 'shield';
const selectedPermissionCount = (items) => items.filter((item) => form.permissions.includes(item.code)).length;
function togglePermissionGroup(items, enabled) {
    const moduleCodes = new Set(items.map((item) => item.code));
    const otherPermissions = form.permissions.filter((code) => !moduleCodes.has(code));
    form.permissions = enabled ? [...new Set([...otherPermissions, ...moduleCodes])] : otherPermissions;
}
let timer;
let listRequestVersion = 0;
let formRequestVersion = 0;

async function load(pageNo = 1, pageSize = result.value?.pagination.per_page || 15) {
    const version = ++listRequestVersion;
    busy.value = true; error.value = '';
    try {
        const response = await api.get(`pages/${page.value}`, { params: {
            page: pageNo,
            per_page: pageSize,
            search: search.value || undefined,
            barcode: page.value === 'products' ? (barcodeSearch.value.trim() || undefined) : undefined,
        } });
        if (version === listRequestVersion) result.value = response.data;
    } catch (e) { if (version === listRequestVersion) error.value = e.response?.data?.message || 'Ցանկը չհաջողվեց բեռնել։'; }
    finally { if (version === listRequestVersion) busy.value = false; }
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); });
watch(page, () => { result.value = null; search.value = ''; barcodeSearch.value = ''; load(1); });
const updateUser = (event) => { me.value = event.detail; };
onMounted(() => { load(); window.addEventListener('lager:user', updateUser); });
onBeforeUnmount(() => { listRequestVersion += 1; formRequestVersion += 1; clearTimeout(timer); window.removeEventListener('lager:user', updateUser); });

function applyScannedBarcode(value) {
    barcodeSearch.value = value;
    load(1);
}

function clearBarcodeSearch() {
    barcodeSearch.value = '';
    load(1);
}

async function loadOptions() {
    if (page.value === 'branches') return;
    const response = await api.get(`catalog/${page.value}/options`);
    options.value = response.data.data;
}

async function openCategories() {
    categoryError.value = ''; categoryNotice.value = ''; categoryBusy.value = true;
    try {
        const response = await api.get('catalog/products/options');
        options.value = response.data.data;
        categoriesOpen.value = true;
    } catch (e) { categoryError.value = e.response?.data?.message || 'Ապրանքային խմբերը չհաջողվեց բեռնել։'; }
    finally { categoryBusy.value = false; }
}

async function saveCategory() {
    if (categorySaving.value || !categoryName.value.trim()) return;
    categorySaving.value = true; categoryError.value = ''; categoryNotice.value = '';
    try {
        await api.post('categories', { name: categoryName.value.trim(), parent_id: categoryParent.value || null });
        categoryName.value = ''; categoryParent.value = '';
        const response = await api.get('catalog/products/options'); options.value = response.data.data;
        categoryNotice.value = 'Ապրանքային խումբը ավելացվեց։';
    } catch (e) { categoryError.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Խումբը չպահպանվեց։'; }
    finally { categorySaving.value = false; }
}

async function removeCategory(category) {
    destructiveConfirm.value = { kind: 'category', id: category.id, entity: category.name };
    destructiveError.value = '';
}

function askDeactivate(row) {
    destructiveConfirm.value = { kind: 'record', id: row.id, entity: row.name || row.title || 'Գրառում' };
    destructiveError.value = '';
}

async function confirmDestructiveAction() {
    if (!destructiveConfirm.value || destructiveBusy.value) return;
    const pending = destructiveConfirm.value;
    destructiveBusy.value = true;
    destructiveError.value = '';
    try {
        if (pending.kind === 'category') {
            await api.delete(`categories/${pending.id}`);
            const response = await api.get('catalog/products/options'); options.value = response.data.data;
            categoryNotice.value = 'Ապրանքային խումբը ջնջվեց։';
        } else {
            await api.delete(`catalog/${page.value}/${pending.id}`);
            notice.value = 'Գրառումն ապաակտիվացվեց։';
            await load(result.value?.pagination.current_page || 1);
        }
        destructiveConfirm.value = null;
    } catch (e) {
        destructiveError.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || (pending.kind === 'category' ? 'Խումբը չջնջվեց։' : 'Գրառումը չհաջողվեց ապաակտիվացնել։');
    } finally { destructiveBusy.value = false; }
}

async function openCreate() {
    const version = ++formRequestVersion;
    selected.value = null; error.value = ''; Object.assign(form, structuredClone(emptyByPage[page.value]));
    try { await loadOptions(); if (version === formRequestVersion) modal.value = true; } catch (e) { if (version === formRequestVersion) error.value = e.response?.data?.message || 'Ձևի տվյալները չհաջողվեց բեռնել։'; }
}

async function openEdit(row) {
    const version = ++formRequestVersion;
    error.value = '';
    try {
        await loadOptions();
        if (version !== formRequestVersion) return;
        const response = await api.get(`catalog/${page.value}/${row.id}`);
        if (version !== formRequestVersion) return;
        selected.value = row.id;
        const data = response.data.data;
        if (page.value === 'roles') Object.assign(form, { title: data.title, permissions: data.permissions || [] });
        else Object.assign(form, { ...emptyByPage[page.value], ...data, password: '' });
        modal.value = true;
    } catch (e) { if (version === formRequestVersion) error.value = e.response?.data?.message || 'Գրառումը չհաջողվեց բացել։'; }
}

async function save() {
    if (saving.value) return;
    saving.value = true; error.value = '';
    try {
        if (page.value === 'roles') {
            if (selected.value) await api.put(`roles/${selected.value}/permissions`, { permissions: form.permissions });
            else await api.post('roles', form);
        } else if (selected.value) await api.put(`catalog/${page.value}/${selected.value}`, form);
        else await api.post(`catalog/${page.value}`, form);
        modal.value = false; notice.value = 'Տվյալները պահպանվեցին։'; await load(result.value?.pagination.current_page || 1);
        setTimeout(() => { notice.value = ''; }, 3000);
    } catch (e) { error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Գրառումը չպահպանվեց։'; }
    finally { saving.value = false; }
}

async function openTrace(row) {
    traceOpen.value = true;
    traceData.value = null;
    tracePages.lots = 1;
    tracePages.movements = 1;
    tracePages.requests = 1;
    error.value = '';
    await loadTrace(row.id);
}

async function loadTrace(productId) {
    traceBusy.value = true;
    error.value = '';
    try {
        const response = await api.get(`products/${productId}/history`, { params: {
            lots_page: tracePages.lots,
            movements_page: tracePages.movements,
            requests_page: tracePages.requests,
        } });
        traceData.value = response.data.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'Ապրանքի հետագիծը չհաջողվեց բեռնել։';
    } finally { traceBusy.value = false; }
}

function changeTracePage(list, pageNumber) {
    tracePages[list] = pageNumber;
    loadTrace(traceData.value.product.id);
}

function openLabel(row) {
    window.open(`/products/${row.id}/label`, '_blank', 'noopener');
}

function selectOptions(type) {
    const items = type === 'categories' ? options.value.categories : type === 'suppliers' ? options.value.suppliers : type === 'branches' ? options.value.branches : options.value.roles;
    return items || [];
}
function categoryParentName(category) {
    return (options.value.categories || []).find((item) => Number(item.id) === Number(category.parent_id))?.name || '';
}
function cell(row, key) {
    const value = row[key];
    if (value === null || value === undefined || value === '') return '—';
    if (/(?:_at|_on|_date)$/.test(key) || key === 'date' || isDateValue(value)) return formatDisplayDate(value);
    if (key === 'active') return Number(value) ? 'Ակտիվ' : 'Ապաակտիվ';
    if (/(?:_at|_on|_date)$/.test(key) || key === 'date') return formatDisplayDate(value);
    return String(value);
}
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ՀԱՄԱԿԱՐԳԻ ԿԱՌԱՎԱՐՈՒՄ</p><h1>{{ title }}</h1><p class="muted">Պահպանեք տվյալները միասնական ցանկում՝ պահպանելով գործողությունների պատմությունը։</p></div><div class="page-heading-actions"><button v-if="page === 'products' && (modulePermission('create') || modulePermission('delete'))" class="secondary-button" @click="openCategories"><AppIcon name="boxes" />Ապրանքային խմբեր</button><button v-if="canCreate" class="primary-button" @click="openCreate"><AppIcon name="add" />{{ page === 'roles' ? 'Ավելացնել դեր' : 'Ավելացնել գրառում' }}</button></div></div>
    <div v-if="error && !modal" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
    <section class="table-card">
        <form v-if="page === 'products'" class="barcode-search-toolbar" @submit.prevent="load(1)">
            <label class="search-input"><span class="search-icon"><AppIcon name="barcode" /></span><input v-model.trim="barcodeSearch" class="form-control" autocomplete="off" placeholder="Սկանավորեք կամ մուտքագրեք շտրիխ / QR կոդը"></label>
            <button class="secondary-button" type="submit" :disabled="busy"><AppIcon name="search" />Գտնել ապրանքը</button>
            <BarcodeScanner @detected="applyScannedBarcode" />
            <button v-if="barcodeSearch" class="text-button" type="button" @click="clearBarcodeSearch"><AppIcon name="xmark" />Մաքրել կոդը</button>
        </form>
        <div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Որոնել ցանկում…"></label><div class="list-count">Ընդամենը՝ <b>{{ result?.pagination.total ?? '…' }}</b></div><ExportActions :page="page" :search="search" :barcode="page === 'products' ? barcodeSearch : ''" :disabled="busy" /></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th v-for="(label,key) in result?.columns || {}" :key="key">{{ label }}</th><th>Գործողություններ</th></tr></thead><tbody>
            <tr v-for="row in rows" :key="row.id"><td v-for="(label,key) in result?.columns || {}" :key="key"><span v-if="key === 'active'" class="status-pill" :class="{ inactive: !Number(row[key]) }">{{ cell(row,key) }}</span><strong v-else-if="key === 'name' || key === 'title' || key.endsWith('_no')">{{ cell(row,key) }}</strong><span v-else>{{ cell(row,key) }}</span></td><td><div class="table-actions"><button v-if="page === 'products' && modulePermission('view')" class="icon-button" title="Ապրանքի հետագիծ" @click="openTrace(row)"><AppIcon name="history" /></button><button v-if="page === 'products' && modulePermission('view')" class="icon-button" title="Տպել պիտակ" @click="openLabel(row)"><AppIcon name="print" /></button><button v-if="modulePermission('edit')" class="icon-button" title="Խմբագրել" @click="openEdit(row)"><AppIcon name="edit" /></button><button v-if="modulePermission('delete') && row.active" class="icon-button danger" title="Ապաակտիվացնել" @click="askDeactivate(row)"><AppIcon name="trash" /></button></div></td></tr>
            <tr v-if="!busy && result && !rows.length"><td :colspan="Object.keys(result.columns).length+1" class="table-empty">{{ search ? 'Որոնմանը համապատասխան գրառում չկա։' : 'Գրառումներ դեռ չկան։' }}</td></tr>
            <tr v-if="busy && !result"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
        </tbody></table></div><Pagination v-if="result" :pagination="result.pagination" :busy="busy" @page-change="load" @per-page-change="load(1, $event)" />
    </section>

    <div v-if="categoriesOpen" class="modal-backdrop" @click.self="categoriesOpen=false" @keydown.esc="categoriesOpen=false"><section class="modal-card catalog-modal category-modal" role="dialog" aria-modal="true" aria-labelledby="category-title"><header class="modal-header"><div><p class="eyebrow">ԱՊՐԱՆՔՆԵՐԻ ԿԱՌԱՎԱՐՈՒՄ</p><h2 id="category-title">Ապրանքային խմբեր</h2><p class="muted">Խմբերը հասանելի են ապրանքի քարտի ընտրացանկում։ Օգտագործվող խումբը հնարավոր չէ ջնջել։</p></div><button class="icon-button close-button" aria-label="Փակել" @click="categoriesOpen=false"><AppIcon name="xmark" /></button></header>
        <p v-if="categoryError" class="form-error" role="alert">{{ categoryError }}</p><p v-if="categoryNotice" class="notice-success" role="status">{{ categoryNotice }}</p>
        <p v-if="categoryBusy" class="table-empty">Խմբերը բեռնվում են…</p>
        <form v-if="modulePermission('create')" class="category-create-form" @submit.prevent="saveCategory"><label class="form-field">Խմբի անվանում *<input v-model.trim="categoryName" class="form-control" maxlength="120" required placeholder="Օրինակ՝ Լաբորատոր նյութեր"></label><label class="form-field">Ծնող խումբ<select v-searchable-select v-model="categoryParent" class="form-control"><option value="">Առանց ծնող խմբի</option><option v-for="category in options.categories || []" :key="category.id" :value="category.id">{{ category.name }}</option></select></label><button class="primary-button" :disabled="categorySaving"><AppIcon name="add" />{{ categorySaving ? 'Պահպանվում է…' : 'Ավելացնել խումբ' }}</button></form>
        <div class="category-list"><div v-for="category in options.categories || []" :key="category.id" class="category-row"><div><strong>{{ category.name }}</strong><small v-if="category.parent_id">Ծնող՝ {{ categoryParentName(category) || '—' }}</small></div><button v-if="modulePermission('delete')" class="danger-button" @click="removeCategory(category)">Ջնջել</button></div><p v-if="!categoryBusy && !(options.categories || []).length" class="table-empty">Ապրանքային խմբեր դեռ չկան։</p></div>
        <footer class="modal-actions"><button class="secondary-button" @click="categoriesOpen=false">Փակել</button></footer>
    </section></div>

    <div v-if="modal" class="modal-backdrop" @click.self="modal=false" @keydown.esc="modal=false"><form class="modal-card catalog-modal" :class="{ 'role-catalog-modal': page === 'roles' }" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">{{ page === 'roles' ? 'ՀԱՍԱՆԵԼԻՈՒԹՅԱՆ ԿԱՌԱՎԱՐՈՒՄ' : 'ՏՎՅԱԼՆԵՐԻ ՔԱՐՏ' }}</p><h2>{{ selected ? 'Խմբագրել գրառումը' : (page === 'roles' ? 'Ստեղծել դեր' : 'Ավելացնել գրառում') }}</h2><p class="muted">{{ page === 'roles' ? 'Կարգավորեք դերի հասանելիությունն ու թույլատրելի գործողությունները։' : 'Փոփոխությունները կգրանցվեն գործողությունների պատմությունում։' }}</p></div><button class="icon-button close-button" type="button" aria-label="Փակել" @click="modal=false"><AppIcon name="xmark" /></button></div>
        <div v-if="page === 'roles'" class="role-permission-list"><label class="form-field">Դերի անվանում *<input v-model.trim="form.title" class="form-control" maxlength="120" required :disabled="!!selected"></label><div class="permission-overview"><span class="permission-overview-icon"><AppIcon name="shield" /></span><div class="permission-overview-copy"><strong>Դերի հասանելիության կարգավորում</strong><small>Ընտրեք՝ որ բաժիններն ու գործողություններն են հասանելի այս դերին։</small></div><span class="permission-total"><b>{{ form.permissions.length }}</b> ընտրված</span></div><section v-for="(items,moduleName) in permissionsByModule" :key="moduleName" class="permission-group" :aria-labelledby="`permission-group-${moduleName}`"><header class="permission-group-head"><span class="permission-module-icon"><AppIcon :name="permissionModuleIcon(moduleName)" /></span><div class="permission-module-title"><h3 :id="`permission-group-${moduleName}`">{{ permissionModuleTitle(moduleName) }}</h3><small>{{ selectedPermissionCount(items) }} / {{ items.length }} իրավունք ընտրված</small></div><div class="permission-group-tools"><button type="button" :disabled="!!selected && !modulePermission('edit') || selectedPermissionCount(items) === items.length" @click="togglePermissionGroup(items, true)">Ընտրել բոլորը</button><button type="button" :disabled="!!selected && !modulePermission('edit') || selectedPermissionCount(items) === 0" @click="togglePermissionGroup(items, false)">Մաքրել</button></div></header><div class="permission-options"><label v-for="item in items" :key="item.code" class="permission-option" :class="{ 'is-selected': form.permissions.includes(item.code) }"><input v-model="form.permissions" type="checkbox" :value="item.code" :disabled="!!selected && !modulePermission('edit')"><span class="permission-option-check"><AppIcon name="success" /></span><span class="permission-option-title">{{ item.title }}</span></label></div></section></div>
        <div v-else class="form-grid"><label v-for="[key,label,type,required] in fields" :key="key" class="form-field" :class="{ 'span-2': ['address','barcode','storage_conditions'].includes(key) }"><span v-if="type === 'checkbox'">{{ label }}{{ required ? ' *' : '' }}</span><template v-else>{{ label }}{{ required ? ' *' : '' }}</template>
            <select v-searchable-select v-if="['categories','suppliers','branches','roles'].includes(type)" v-model="form[key]" class="form-control" :required="!!required"><option value="">{{ key === 'category_id' || key === 'branch_id' ? 'Ընտրովի' : 'Ընտրել' }}</option><option v-for="item in selectOptions(type)" :key="item.id" :value="item.id">{{ item.name || item.title }}</option></select>
            <input v-else-if="type === 'checkbox'" v-model="form[key]" type="checkbox">
            <input v-else v-model="form[key]" class="form-control" :type="type" :required="!!required && (key !== 'password' || !selected)" :min="type === 'number' ? '0' : undefined" :step="type === 'number' ? (key === 'purchase_price' ? '0.01' : '0.001') : undefined" :maxlength="['name','code','unit'].includes(key) ? 190 : undefined">
        </label></div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="modal=false">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Պահպանվում է…' : 'Պահպանել' }}</button></div>
</form></div>
    <DestructiveConfirmDialog v-if="destructiveConfirm" :title="destructiveConfirm.kind === 'category' ? 'Ջնջե՞լ ապրանքային խումբը' : 'Ապաակտիվացնե՞լ գրառումը'" :entity="destructiveConfirm.entity" :description="destructiveConfirm.kind === 'category' ? `«${destructiveConfirm.entity}» խումբը ընդմիշտ կհեռացվի։ Գործողությունը հնարավոր չէ հետ բերել։` : `«${destructiveConfirm.entity}» գրառումը կանջատվի և այլևս չի երևա ակտիվ ցանկերում։ Պատմությունը չի ջնջվի։`" :confirm-label="destructiveConfirm.kind === 'category' ? 'Ջնջել խումբը' : 'Ապաակտիվացնել'" :busy-label="destructiveConfirm.kind === 'category' ? 'Ջնջվում է…' : 'Ապաակտիվացվում է…'" :busy="destructiveBusy" :error="destructiveError" @cancel="destructiveConfirm = null" @confirm="confirmDestructiveAction" />
<div v-if="traceOpen" class="modal-backdrop" @click.self="traceOpen=false" @keydown.esc="traceOpen=false"><section class="modal-card product-trace-modal"><header class="modal-header"><div><p class="eyebrow">ԱՊՐԱՆՔԻ ՀԵՏԱԳԻԾ</p><h2>{{ traceData?.product?.name || 'Բեռնում է…' }}</h2><p class="muted">{{ traceData?.product?.code || '' }} · LOT-եր, պահեստային շարժեր և մասնաճյուղերի պահանջներ։</p></div><button type="button" class="icon-button close-button" aria-label="Փակել" @click="traceOpen=false"><AppIcon name="xmark" /></button></header><p v-if="traceBusy" class="table-empty">Հետագիծը բեռնվում է…</p><p v-else-if="error" class="form-error" role="alert">{{ error }}</p><template v-else-if="traceData"><section class="trace-section"><h3>Ընթացիկ LOT մնացորդ <small>ընդհանուր՝ {{ traceData.lots.total }}</small></h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Պահեստ</th><th>LOT</th><th>Ժամկետ</th><th>Մնացորդ</th><th v-if="traceData.show_costs">Գին</th><th v-if="me?.permissions?.['suppliers.view']">Մատակարար</th><th>Տեղ</th></tr></thead><tbody><tr v-for="lot in traceData.lots.data" :key="lot.id"><td>{{ lot.location_name }}</td><td>{{ lot.lot_no }}</td><td>{{ formatDisplayDate(lot.expires_on) }}</td><td>{{ lot.qty }} {{ traceData.product.unit }}</td><td v-if="traceData.show_costs">{{ Number(lot.unit_cost).toLocaleString('hy-AM') }} ֏</td><td v-if="me?.permissions?.['suppliers.view']">{{ lot.supplier_name || '—' }}</td><td>{{ lot.bin_location || '—' }}</td></tr><tr v-if="!traceData.lots.data.length"><td :colspan="5 + Number(traceData.show_costs) + Number(!!me?.permissions?.['suppliers.view'])" class="table-empty">Ընթացիկ LOT չկա։</td></tr></tbody></table></div><div v-if="traceData.lots.last_page > 1" class="pagination"><span>{{ traceData.lots.from }}–{{ traceData.lots.to }} LOT՝ {{ traceData.lots.total }}-ից</span><div class="pagination-controls"><button :disabled="traceData.lots.current_page <= 1 || traceBusy" @click="changeTracePage('lots', traceData.lots.current_page - 1)">Նախորդ</button><span>Էջ {{ traceData.lots.current_page }} / {{ traceData.lots.last_page }}</span><button :disabled="traceData.lots.current_page >= traceData.lots.last_page || traceBusy" @click="changeTracePage('lots', traceData.lots.current_page + 1)">Հաջորդ</button></div></div></section><section class="trace-section"><h3>Շարժերի պատմություն <small>ընդհանուր՝ {{ traceData.movements.total }}</small></h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Ամսաթիվ</th><th>Փաստաթուղթ</th><th>Գործողություն</th><th>LOT</th><th>Ումից</th><th>Ուր</th><th>Քանակ</th><th>Կատարող</th><th>Պատճառ</th></tr></thead><tbody><tr v-for="move in traceData.movements.data" :key="move.id"><td>{{ formatDisplayDate(move.happened_at) }}</td><td>{{ move.reference || move.movement_no }}</td><td>{{ move.type }}</td><td>{{ move.lot_no || '—' }}</td><td>{{ move.from_name }}</td><td>{{ move.to_name }}</td><td>{{ move.qty }}</td><td>{{ move.actor_name || '—' }}</td><td>{{ move.reason || '—' }}</td></tr><tr v-if="!traceData.movements.data.length"><td colspan="9" class="table-empty">Շարժերի պատմություն չկա։</td></tr></tbody></table></div><div v-if="traceData.movements.last_page > 1" class="pagination"><span>{{ traceData.movements.from }}–{{ traceData.movements.to }} շարժ՝ {{ traceData.movements.total }}-ից</span><div class="pagination-controls"><button :disabled="traceData.movements.current_page <= 1 || traceBusy" @click="changeTracePage('movements', traceData.movements.current_page - 1)">Նախորդ</button><span>Էջ {{ traceData.movements.current_page }} / {{ traceData.movements.last_page }}</span><button :disabled="traceData.movements.current_page >= traceData.movements.last_page || traceBusy" @click="changeTracePage('movements', traceData.movements.current_page + 1)">Հաջորդ</button></div></div></section><section class="trace-section"><h3>Մասնաճյուղերի պահանջներ <small>ընդհանուր՝ {{ traceData.requests.total }}</small></h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Պահանջագիր</th><th>Ամսաթիվ</th><th>Մասնաճյուղ</th><th>Կարգավիճակ</th><th>Պահանջված</th><th>Հաստատված</th></tr></thead><tbody><tr v-for="request in traceData.requests.data" :key="`${request.request_no}-${request.created_at}`"><td>{{ request.request_no }}</td><td>{{ formatDisplayDate(request.created_at) }}</td><td>{{ request.branch_name }}</td><td>{{ request.status }}</td><td>{{ request.requested_qty }}</td><td>{{ request.approved_qty }}</td></tr><tr v-if="!traceData.requests.data.length"><td colspan="6" class="table-empty">Պահանջագրերի պատմություն չկա։</td></tr></tbody></table></div><div v-if="traceData.requests.last_page > 1" class="pagination"><span>{{ traceData.requests.from }}–{{ traceData.requests.to }} պահանջ՝ {{ traceData.requests.total }}-ից</span><div class="pagination-controls"><button :disabled="traceData.requests.current_page <= 1 || traceBusy" @click="changeTracePage('requests', traceData.requests.current_page - 1)">Նախորդ</button><span>Էջ {{ traceData.requests.current_page }} / {{ traceData.requests.last_page }}</span><button :disabled="traceData.requests.current_page >= traceData.requests.last_page || traceBusy" @click="changeTracePage('requests', traceData.requests.current_page + 1)">Հաջորդ</button></div></div></section></template><footer class="modal-actions"><button class="secondary-button" type="button" @click="traceOpen=false">Փակել</button></footer></section></div></template>
