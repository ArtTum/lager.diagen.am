<script setup>
import Pagination from '@/components/Pagination.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import ListFilterBar from '@/components/ListFilterBar.vue';
import { formatDisplayDate } from '@/dateUtils';

const route = useRoute();
const user = ref(currentUser());
const result = ref(null);
const options = ref({ branches: [], products: [] });
const busy = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const search = ref('');
const modal = ref(false);
const confirmAction = ref(null);
const filters = reactive({ status: '', from_branch: '', to_branch: '', from: '', to: '' });
const form = reactive({ from_branch: '', to_branch: '', reason: '', items: [{ product_id: '', qty: '' }] });
let debounce;
let listRequestVersion = 0;
const isAdmin = computed(() => user.value?.role?.name === 'admin');
const isCentral = computed(() => user.value?.location_id === 0);
const canCreate = computed(() => Boolean(user.value?.permissions?.['transfers.create']));
const canApprove = computed(() => Boolean(user.value?.permissions?.['transfers.approve']));
const canEdit = computed(() => Boolean(user.value?.permissions?.['transfers.edit']));
const rows = computed(() => result.value?.data || []);
const filterSelects = computed(() => [
    { key: 'status', label: 'Կարգավիճակ', allLabel: 'Բոլոր փուլերը', options: [{ value: 'pending', label: 'Սպասում է հաստատման' }, { value: 'stock_shortage', label: 'Սպասում է պաշարի համալրման' }, { value: 'approved', label: 'Հաստատված' }, { value: 'shipped', label: 'Ուղարկված է' }, { value: 'completed', label: 'Ստացված է' }] },
    { key: 'from_branch', label: 'Ուղարկող պահեստ', allLabel: 'Բոլոր պահեստները', options: (result.value?.filter_options?.branches || []).map((branch) => ({ value: branch.id, label: branch.name })) },
    { key: 'to_branch', label: 'Ստացող պահեստ', allLabel: 'Բոլոր պահեստները', options: (result.value?.filter_options?.branches || []).map((branch) => ({ value: branch.id, label: branch.name })) },
]);

async function load(page = 1, pageSize = result.value?.pagination.per_page || 15) {
    const version = ++listRequestVersion;
    busy.value = true; error.value = '';
    try { const response = await api.get('pages/transfers', { params: { ...filters, page, per_page: pageSize, search: search.value || undefined } }); if (version === listRequestVersion) result.value = response.data; }
    catch (e) { if (version === listRequestVersion) error.value = e.response?.data?.message || 'Տեղափոխումների ցանկը չհաջողվեց բեռնել։'; }
    finally { if (version === listRequestVersion) busy.value = false; }
}
function resetFilters() { Object.assign(filters, { status: '', from_branch: '', to_branch: '', from: '', to: '' }); load(1); }
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
const updateUser = (event) => { user.value = event.detail; };
onMounted(() => { load(); window.addEventListener('lager:user', updateUser); });
onBeforeUnmount(() => { listRequestVersion += 1; clearTimeout(debounce); window.removeEventListener('lager:user', updateUser); });

async function openCreate() {
    error.value = '';
    try {
        const response = await api.get('catalog/transfers/options'); options.value = response.data.data;
        form.from_branch = user.value?.branch?.id || '';
        form.to_branch = ''; form.reason = ''; form.items = [{ product_id: '', qty: '' }]; modal.value = true;
    } catch (e) { error.value = e.response?.data?.message || 'Ձևի տվյալները չհաջողվեց բեռնել։'; }
}
function addItem() { form.items.push({ product_id: '', qty: '' }); }
function removeItem(index) { if (form.items.length > 1) form.items.splice(index, 1); }
async function save() {
    if (saving.value) return; saving.value = true; error.value = '';
    try { await api.post('transfers', { ...form, from_branch: Number(form.from_branch), to_branch: Number(form.to_branch), items: form.items.map((line) => ({ product_id: Number(line.product_id), qty: Number(line.qty) })) }); modal.value = false; notice.value = 'Տեղափոխման հարցումն ուղարկվեց հաստատման։'; await load(1); setTimeout(() => notice.value = '', 3500); }
    catch (e) { error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Տեղափոխման հարցումը չստեղծվեց։'; }
    finally { saving.value = false; }
}

function actionFor(row) {
    if (['pending', 'stock_shortage'].includes(row.status) && canApprove.value) return ['approve', row.status === 'stock_shortage' ? 'Վերաստուգել պաշարը' : 'Հաստատել'];
    if (row.status === 'approved' && canEdit.value && (isAdmin.value || isCentral.value || Number(user.value?.branch?.id) === Number(row.from_branch_id))) return ['ship', 'Ուղարկել'];
    if (row.status === 'shipped' && canEdit.value && (isAdmin.value || Number(user.value?.branch?.id) === Number(row.to_branch_id))) return ['receive', 'Ստանալ'];
    return null;
}
function confirm(row, action) { confirmAction.value = { row, action }; }
async function runAction() {
    if (!confirmAction.value) return;
    const { row, action } = confirmAction.value; error.value = ''; saving.value = true;
    try { const response = await api.post(`transfers/${row.id}/${action}`); notice.value = response.data?.message || (action === 'approve' ? 'Տեղափոխումը հաստատվեց։' : action === 'ship' ? 'Տեղափոխումն ուղարկվեց։' : 'Ստացումը գրանցվեց։'); confirmAction.value = null; await load(result.value?.pagination.current_page || 1); setTimeout(() => notice.value = '', 7000); }
    catch (e) { error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Գործողությունը չկատարվեց։'; }
    finally { saving.value = false; }
}
const statusLabel = (status) => ({ pending: 'Սպասում է հաստատման', stock_shortage: 'Սպասում է պաշարի համալրման', approved: 'Հաստատված է', shipped: 'Ուղարկված է', completed: 'Ստացված է', rejected: 'Մերժված է' }[status] || status);
const itemName = (id) => options.value.products.find((p) => Number(p.id) === Number(id))?.name || 'Ապրանք';
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ՊԱՀԵՍՏԱՅԻՆ ԳՈՐԾԸՆԹԱՑ</p><h1>{{ route.meta.title }}</h1><p class="muted">Տեղափոխումը նախ հաստատվում է, հետո ուղարկվում աղբյուր պահեստից և վերջում ընդունվում ստացող մասնաճյուղում։</p></div><button v-if="canCreate" class="primary-button" @click="openCreate"><span><AppIcon name="add" /></span>Նոր տեղափոխում</button></div>
    <div v-if="error && !modal && !confirmAction" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="alert-info" role="status">{{ notice }}</div>
    <section class="table-card"><div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Որոնել համարով, պահեստով կամ կարգավիճակով…"></label><div class="list-count">Ընդամենը՝ <b>{{ result?.pagination.total ?? '…' }}</b></div></div><ListFilterBar :model-value="filters" @change="filters[$event.key] = $event.value" :selects="filterSelects" :date-range="true" @apply="load(1)" @reset="resetFilters" />
        <div class="table-scroll"><table class="data-table transfer-table"><thead><tr><th>Փաստաթուղթ</th><th>Ումից</th><th>Ուր</th><th>Կարգավիճակ</th><th>Պատճառ</th><th>Ստեղծվել է</th><th>Գործողություն</th></tr></thead><tbody>
            <tr v-for="row in rows" :key="row.id"><td><strong>{{ row.transfer_no }}</strong></td><td>{{ row.from_branch }}</td><td>{{ row.to_branch }}</td><td><span class="workflow-status" :class="`state-${row.status}`">{{ statusLabel(row.status) }}</span></td><td>{{ row.reason || '—' }}</td><td>{{ formatDisplayDate(row.created_at) }}</td><td><button v-if="actionFor(row)" class="secondary-button compact-action" @click="confirm(row,actionFor(row)[0])">{{ actionFor(row)[1] }}</button><span v-else class="muted">—</span></td></tr>
            <tr v-if="!busy && result && !rows.length"><td colspan="7" class="table-empty">{{ search ? 'Որոնմանը համապատասխան տեղափոխում չկա։' : 'Տեղափոխումներ դեռ չկան։' }}</td></tr><tr v-if="busy && !result"><td colspan="7" class="table-empty">Բեռնվում է…</td></tr>
        </tbody></table></div><Pagination v-if="result" :pagination="result.pagination" :busy="busy" @page-change="load" @per-page-change="load(1, $event)" /></section>

    <div v-if="modal" class="modal-backdrop" @click.self="modal=false"><form class="modal-card transfer-modal" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">ՊԱՀԵՍՏԻ ՄԻՋԵՎ ՏԵՂԱՓՈԽՈՒՄ</p><h2>Նոր տեղափոխման հարցում</h2><p>Հաստատումից հետո պաշարը կհանվի միայն ուղարկման պահին։</p></div><button class="icon-button close-button" type="button" aria-label="Փակել" @click="modal=false"><AppIcon name="xmark" /></button></div>
        <div class="form-grid"><label class="form-field">Ուղարկող պահեստ *<select v-searchable-select v-model="form.from_branch" class="form-control" :disabled="!isAdmin && !isCentral && !!user?.branch?.id" required><option value="">Ընտրել</option><option v-for="b in options.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="form-field">Ստացող պահեստ *<select v-searchable-select v-model="form.to_branch" class="form-control" required><option value="">Ընտրել նպատակակետը</option><option v-for="b in options.branches.filter((item)=>Number(item.id)!==Number(form.from_branch))" :key="b.id" :value="b.id">{{ b.name }}</option></select></label><label class="form-field span-2">Պատճառ *<textarea v-model.trim="form.reason" class="form-control" minlength="3" maxlength="2000" required placeholder="Նշեք տեղափոխման պատճառը"></textarea></label></div>
        <div class="transfer-lines"><div class="section-label">Ապրանքներ</div><div v-for="(line,index) in form.items" :key="index" class="transfer-line-row"><label class="form-field">Ապրանք<select v-searchable-select v-model="line.product_id" class="form-control" required><option value="">Ընտրել ապրանքը</option><option v-for="p in options.products" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select></label><label class="form-field">Քանակ<input v-model="line.qty" class="form-control" type="number" min="0.001" step="0.001" required></label><button v-if="form.items.length>1" class="icon-button danger remove-line" type="button" title="Հեռացնել տողը" @click="removeItem(index)"><AppIcon name="xmark" /></button></div><button class="secondary-button add-line" type="button" @click="addItem"><AppIcon name="add" /> Ավելացնել ապրանք</button></div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p><div class="modal-actions"><button class="secondary-button" type="button" @click="modal=false">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Պահպանվում է…' : 'Ուղարկել հաստատման' }}</button></div>
    </form></div>
    <div v-if="confirmAction" class="modal-backdrop" @click.self="confirmAction=null"><section class="modal-card confirm-card"><div class="metric-icon" :class="confirmAction.action==='approve'?'blue':'violet'"><AppIcon :name="confirmAction.action==='approve'?'clipboard':'transfers'" /></div><h2>{{ confirmAction.action==='approve'?(confirmAction.row.status==='stock_shortage'?'Վերաստուգե՞լ պաշարը':'Հաստատե՞լ տեղափոխումը'):confirmAction.action==='ship'?'Ուղարկե՞լ տեղափոխումը':'Գրանցե՞լ ստացումը' }}</h2><p>Փաստաթուղթ՝ <b>{{ confirmAction.row.transfer_no }}</b></p><p class="muted">{{ confirmAction.action==='approve'?(confirmAction.row.status==='stock_shortage'?'Կստուգվի ազատ պաշարը։ Եթե այն բավարար է, տեղափոխումը կհաստատվի, հակառակ դեպքում կմնա համալրման սպասման փուլում։':'Հաստատումից առաջ կստուգվի ազատ պաշարը։ Եթե այն բավարար չէ, հարցումը կտեղափոխվի պաշարի համալրման սպասման փուլ։'):confirmAction.action==='ship'?'Ուղարկելիս պաշարը կհանվի աղբյուր պահեստից FEFO հերթով։':'Ստացման հաստատումից հետո պաշարը կավելանա ձեր պահեստում։' }}</p><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button class="secondary-button" :disabled="saving" @click="confirmAction=null">Չեղարկել</button><button class="primary-button" :disabled="saving" @click="runAction">{{ saving ? 'Կատարվում է…' : confirmAction.action==='approve'&&confirmAction.row.status==='stock_shortage'?'Վերաստուգել':'Հաստատել' }}</button></div></section></div>
</template>
