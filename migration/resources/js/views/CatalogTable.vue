<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';

const route = useRoute();
const result = ref(null);
const options = ref({});
const busy = ref(false);
const saving = ref(false);
const modal = ref(false);
const error = ref('');
const notice = ref('');
const search = ref('');
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
const fields = computed(() => fieldsByPage[page.value] || []);
const rows = computed(() => result.value?.data || []);
const permissionsByModule = computed(() => (options.value.permissions || []).reduce((groups, item) => {
    (groups[item.module] ||= []).push(item);
    return groups;
}, {}));
let timer;

async function load(pageNo = 1) {
    busy.value = true; error.value = '';
    try {
        const response = await api.get(`pages/${page.value}`, { params: { page: pageNo, search: search.value || undefined } });
        result.value = response.data;
    } catch (e) { error.value = e.response?.data?.message || 'Ցանկը չհաջողվեց բեռնել։'; }
    finally { busy.value = false; }
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); });
watch(page, () => { result.value = null; search.value = ''; load(1); });
onMounted(() => { load(); window.addEventListener('lager:user', (event) => { me.value = event.detail; }); });

async function loadOptions() {
    if (page.value === 'branches') return;
    const response = await api.get(`catalog/${page.value}/options`);
    options.value = response.data.data;
}

async function openCreate() {
    selected.value = null; error.value = ''; Object.assign(form, structuredClone(emptyByPage[page.value]));
    try { await loadOptions(); modal.value = true; } catch (e) { error.value = e.response?.data?.message || 'Ձևի տվյալները չհաջողվեց բեռնել։'; }
}

async function openEdit(row) {
    selected.value = row.id; error.value = '';
    try {
        await loadOptions();
        const response = await api.get(`catalog/${page.value}/${row.id}`);
        const data = response.data.data;
        if (page.value === 'roles') Object.assign(form, { title: data.title, permissions: data.permissions || [] });
        else Object.assign(form, { ...emptyByPage[page.value], ...data, password: '' });
        modal.value = true;
    } catch (e) { error.value = e.response?.data?.message || 'Գրառումը չհաջողվեց բացել։'; }
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

async function deactivate(row) {
    if (!window.confirm(`Ապաակտիվացնե՞լ «${row.name}» գրառումը։ Պատմական տվյալները կմնան պահպանված։`)) return;
    try { await api.delete(`catalog/${page.value}/${row.id}`); notice.value = 'Գրառումն ապաակտիվացվեց։'; await load(result.value?.pagination.current_page || 1); }
    catch (e) { error.value = e.response?.data?.message || 'Գրառումը չհաջողվեց ապաակտիվացնել։'; }
}

function selectOptions(type) {
    const items = type === 'categories' ? options.value.categories : type === 'suppliers' ? options.value.suppliers : type === 'branches' ? options.value.branches : options.value.roles;
    return items || [];
}
function cell(row, key) {
    const value = row[key];
    if (value === null || value === undefined || value === '') return '—';
    if (key === 'active') return Number(value) ? 'Ակտիվ' : 'Ապաակտիվ';
    return String(value);
}
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ՀԱՄԱԿԱՐԳԻ ԿԱՌԱՎԱՐՈՒՄ</p><h1>{{ title }}</h1><p class="muted">Պահպանեք տվյալները միասնական ցանկում՝ պահպանելով գործողությունների պատմությունը։</p></div><button v-if="modulePermission('create')" class="primary-button" @click="openCreate"><span>＋</span>{{ page === 'roles' ? 'Ավելացնել դեր' : 'Ավելացնել գրառում' }}</button></div>
    <div v-if="error && !modal" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
    <section class="table-card"><div class="table-toolbar"><label class="search-input"><span>⌕</span><input v-model="search" class="form-control" placeholder="Որոնել ցանկում…"></label><div class="list-count">Ընդամենը՝ <b>{{ result?.pagination.total ?? '…' }}</b></div></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th v-for="(label,key) in result?.columns || {}" :key="key">{{ label }}</th><th>Գործողություններ</th></tr></thead><tbody>
            <tr v-for="row in rows" :key="row.id"><td v-for="(label,key) in result?.columns || {}" :key="key"><span v-if="key === 'active'" class="status-pill" :class="{ inactive: !Number(row[key]) }">{{ cell(row,key) }}</span><strong v-else-if="key === 'name' || key === 'title' || key.endsWith('_no')">{{ cell(row,key) }}</strong><span v-else>{{ cell(row,key) }}</span></td><td><div class="table-actions"><button v-if="modulePermission('edit')" class="icon-button" title="Խմբագրել" @click="openEdit(row)">✎</button><button v-if="modulePermission('delete') && row.active" class="icon-button danger" title="Ապաակտիվացնել" @click="deactivate(row)">⌑</button></div></td></tr>
            <tr v-if="!busy && result && !rows.length"><td :colspan="Object.keys(result.columns).length+1" class="table-empty">{{ search ? 'Որոնմանը համապատասխան գրառում չկա։' : 'Գրառումներ դեռ չկան։' }}</td></tr>
            <tr v-if="busy && !result"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
        </tbody></table></div><div v-if="result" class="pagination"><span>Ընդամենը՝ {{ result.pagination.total }} գրառում</span><div class="pagination-controls"><button :disabled="result.pagination.current_page<=1 || busy" @click="load(result.pagination.current_page-1)">← Նախորդ</button><span>Էջ {{ result.pagination.current_page }} / {{ result.pagination.last_page }}</span><button :disabled="result.pagination.current_page>=result.pagination.last_page || busy" @click="load(result.pagination.current_page+1)">Հաջորդ →</button></div></div>
    </section>

    <div v-if="modal" class="modal-backdrop" @click.self="modal=false" @keydown.esc="modal=false"><form class="modal-card catalog-modal" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">ՏՎՅԱԼՆԵՐԻ ՔԱՐՏ</p><h2>{{ selected ? 'Խմբագրել գրառումը' : (page === 'roles' ? 'Ստեղծել դեր' : 'Ավելացնել գրառում') }}</h2><p class="muted">Փոփոխությունները կգրանցվեն գործողությունների պատմությունում։</p></div><button class="icon-button close-button" type="button" aria-label="Փակել" @click="modal=false">×</button></div>
        <div v-if="page === 'roles'" class="role-permission-list"><label class="form-field">Դերի անվանում *<input v-model.trim="form.title" class="form-control" maxlength="120" required :disabled="!!selected"></label><fieldset v-for="(items,moduleName) in permissionsByModule" :key="moduleName" class="permission-group"><legend>{{ moduleName }}</legend><label v-for="item in items" :key="item.code" class="toggle-label"><input v-model="form.permissions" type="checkbox" :value="item.code" :disabled="!!selected && !modulePermission('edit')">{{ item.title }}</label></fieldset></div>
        <div v-else class="form-grid"><label v-for="[key,label,type,required] in fields" :key="key" class="form-field" :class="{ 'span-2': ['address','barcode','storage_conditions'].includes(key) }">{{ label }}{{ required ? ' *' : '' }}
            <select v-if="['categories','suppliers','branches','roles'].includes(type)" v-model="form[key]" class="form-control" :required="!!required"><option value="">{{ key === 'category_id' || key === 'branch_id' ? 'Ընտրովի' : 'Ընտրել' }}</option><option v-for="item in selectOptions(type)" :key="item.id" :value="item.id">{{ item.name || item.title }}</option></select>
            <input v-else-if="type === 'checkbox'" v-model="form[key]" type="checkbox">
            <input v-else v-model="form[key]" class="form-control" :type="type" :required="!!required && (key !== 'password' || !selected)" :min="type === 'number' ? '0' : undefined" :step="type === 'number' ? '0.001' : undefined" :maxlength="['name','code','unit'].includes(key) ? 190 : undefined">
        </label></div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="modal=false">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Պահպանվում է…' : 'Պահպանել' }}</button></div>
    </form></div>
</template>
