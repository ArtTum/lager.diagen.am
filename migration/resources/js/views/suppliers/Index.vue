<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '@/services/api';

const page = ref(null);
const search = ref('');
const showInactive = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const modalOpen = ref(false);
const selectedId = ref(null);
const saving = ref(false);
const blank = () => ({ name: '', tax_id: '', address: '', contact_name: '', phone: '', email: '', bank_details: '', contract_no: '', contract_start: '', contract_end: '', payment_terms: '', delivery_days: '', active: true });
const form = reactive(blank());
let debounce;

const suppliers = computed(() => page.value?.data || []);
const missingCount = (supplier) => ['tax_id', 'address', 'contact_name', 'phone', 'email', 'bank_details', 'contract_no', 'contract_start', 'contract_end', 'payment_terms', 'delivery_days'].filter((key) => !supplier[key]).length;

async function load(pageNumber = 1) {
    busy.value = true;
    error.value = '';
    try {
        const response = await api.get('suppliers', { params: { page: pageNumber, search: search.value || undefined, active_only: showInactive.value ? undefined : 1 } });
        page.value = response.data;
    } catch (e) { error.value = e.response?.data?.message || 'Մատակարարների ցանկը չհաջողվեց բեռնել։'; }
    finally { busy.value = false; }
}

watch([search, showInactive], () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
onMounted(() => load());

function openCreate() {
    selectedId.value = null;
    Object.assign(form, blank());
    error.value = '';
    modalOpen.value = true;
}

async function openEdit(supplier) {
    error.value = '';
    try {
        const response = await api.get(`suppliers/${supplier.id}`);
        selectedId.value = supplier.id;
        Object.assign(form, { ...blank(), ...response.data.data, contract_start: response.data.data.contract_start || '', contract_end: response.data.data.contract_end || '', delivery_days: String(response.data.data.delivery_days ?? '') });
        modalOpen.value = true;
    } catch (e) { error.value = e.response?.data?.message || 'Քարտը չհաջողվեց բացել։'; }
}

async function save() {
    if (saving.value) return;
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...form, delivery_days: Number(form.delivery_days), active: Boolean(form.active) };
        if (selectedId.value) await api.put(`suppliers/${selectedId.value}`, payload);
        else await api.post('suppliers', payload);
        modalOpen.value = false;
        notice.value = selectedId.value ? 'Մատակարարի տվյալները պահպանվեցին։' : 'Մատակարարը ավելացվեց։';
        await load(page.value?.current_page || 1);
        setTimeout(() => { notice.value = ''; }, 3500);
    } catch (e) {
        error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Տվյալները չպահպանվեցին։ Ստուգեք դաշտերը։';
    } finally { saving.value = false; }
}

async function deactivate(supplier) {
    if (!window.confirm(`Ապաակտիվացնե՞լ «${supplier.name}» մատակարարին։ Պատմական մուտքերն ու շարժերը կմնան։`)) return;
    try { await api.delete(`suppliers/${supplier.id}`); notice.value = 'Մատակարարը ապաակտիվացվեց։'; await load(page.value?.current_page || 1); }
    catch (e) { error.value = e.response?.data?.message || 'Մատակարարը չհաջողվեց ապաակտիվացնել։'; }
}
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ԳՆՈՒՄՆԵՐ ԵՎ ԳՈՐԾՈՂՈՒԹՅՈՒՆՆԵՐ</p><h1>Մատակարարներ</h1><p class="muted">Կառավարեք մատակարարների քարտերը, պայմանագրերն ու կապակցված գնումները։</p></div><button class="primary-button" @click="openCreate"><span>＋</span>Ավելացնել մատակարար</button></div>
    <div v-if="error && !modalOpen" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
    <section class="table-card">
        <div class="table-toolbar"><label class="search-input"><span>⌕</span><input v-model="search" class="form-control" placeholder="Փնտրել անունով, ՀՎՀՀ-ով կամ կոնտակտով…"></label><label class="toggle-label"><input v-model="showInactive" type="checkbox"> Ցույց տալ ապաակտիվացվածները</label><div class="list-count">Ընդամենը՝ <b>{{ page?.total ?? '…' }}</b></div></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Մատակարար</th><th>Կոնտակտ</th><th>ՀՎՀՀ</th><th>Պայմանագիր</th><th>Առաքում</th><th>Քարտի տվյալներ</th><th>Կարգավիճակ</th><th>Գործողություն</th></tr></thead><tbody>
            <tr v-for="supplier in suppliers" :key="supplier.id"><td><strong>{{ supplier.name }}</strong><small class="cell-subtitle">{{ supplier.address || 'Հասցեն լրացված չէ' }}</small></td><td>{{ supplier.contact_name || '—' }}<small class="cell-subtitle">{{ supplier.phone || '—' }}</small></td><td>{{ supplier.tax_id || '—' }}</td><td>{{ supplier.contract_no || '—' }}<small class="cell-subtitle">{{ supplier.contract_start || '—' }} — {{ supplier.contract_end || '—' }}</small></td><td>{{ supplier.delivery_days ?? '—' }} օր</td><td><span class="completeness-pill" :class="missingCount(supplier) ? 'needs-data' : 'complete'">{{ missingCount(supplier) ? `Պակաս՝ ${missingCount(supplier)} դաշտ` : 'Ամբողջական' }}</span></td><td><span class="status-pill" :class="{ inactive: !supplier.active }">{{ supplier.active ? 'Ակտիվ' : 'Ապաակտիվ' }}</span></td><td><div class="table-actions"><button class="icon-button" :aria-label="`${supplier.name} խմբագրել`" title="Խմբագրել" @click="openEdit(supplier)">✎</button><button v-if="supplier.active" class="icon-button danger" :aria-label="`${supplier.name} ապաակտիվացնել`" title="Ապաակտիվացնել" @click="deactivate(supplier)">⌑</button></div></td></tr>
            <tr v-if="!busy && !suppliers.length"><td colspan="8" class="table-empty">{{ search ? 'Որոնմանը համապատասխան մատակարար չկա։' : 'Մատակարարներ դեռ ավելացված չեն։' }}</td></tr>
            <tr v-if="busy && !suppliers.length"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
        </tbody></table></div>
        <div v-if="page && page.last_page > 1" class="pagination"><span>Ցուցադրված է {{ page.from }}–{{ page.to }}՝ {{ page.total }} մատակարարից</span><div class="pagination-controls"><button :disabled="page.current_page <= 1 || busy" @click="load(page.current_page - 1)">← Նախորդ</button><span>Էջ {{ page.current_page }} / {{ page.last_page }}</span><button :disabled="page.current_page >= page.last_page || busy" @click="load(page.current_page + 1)">Հաջորդ →</button></div></div>
    </section>

    <div v-if="modalOpen" class="modal-backdrop" @click.self="modalOpen = false" @keydown.esc="modalOpen = false"><form class="modal-card supplier-modal" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">ՄԱՏԱԿԱՐԱՐԻ ՔԱՐՏ</p><h2>{{ selectedId ? 'Խմբագրել մատակարարին' : 'Ավելացնել մատակարար' }}</h2><p>Լրացրեք պայմանագրային և վճարման տվյալները՝ փաստաթղթերին համապատասխան։</p></div><button type="button" class="icon-button close-button" aria-label="Փակել" @click="modalOpen = false">×</button></div>
        <div class="form-grid"><label class="form-field span-2">Անվանում *<input v-model.trim="form.name" class="form-control" maxlength="190" required></label><label class="form-field">ՀՎՀՀ *<input v-model.trim="form.tax_id" class="form-control" maxlength="50" required></label><label class="form-field">Կոնտակտային անձ *<input v-model.trim="form.contact_name" class="form-control" maxlength="160" required></label><label class="form-field span-2">Հասցե *<input v-model.trim="form.address" class="form-control" maxlength="255" required></label><label class="form-field">Հեռախոս *<input v-model.trim="form.phone" class="form-control" maxlength="50" required></label><label class="form-field">Էլ. փոստ *<input v-model.trim="form.email" type="email" class="form-control" maxlength="190" required></label><label class="form-field span-2">Բանկային տվյալներ *<input v-model.trim="form.bank_details" class="form-control" maxlength="255" required></label><label class="form-field">Պայմանագրի համար *<input v-model.trim="form.contract_no" class="form-control" maxlength="100" required></label><label class="form-field">Առաքման ժամկետ՝ օրեր *<input v-model="form.delivery_days" type="number" min="0" step="1" class="form-control" required></label><label class="form-field">Պայմանագրի սկիզբ *<input v-model="form.contract_start" type="date" class="form-control" required></label><label class="form-field">Պայմանագրի ավարտ *<input v-model="form.contract_end" type="date" class="form-control" required></label><label class="form-field span-2">Վճարման պայմաններ *<input v-model.trim="form.payment_terms" class="form-control" maxlength="190" required></label><label v-if="selectedId" class="toggle-label span-2"><input v-model="form.active" type="checkbox"> Մատակարարը ակտիվ է</label></div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="modalOpen = false">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Պահպանվում է…' : 'Պահպանել' }}</button></div></form></div>
</template>
