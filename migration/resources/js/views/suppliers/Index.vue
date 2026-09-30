<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '@/services/api';
import { currentUser } from '@/router';

const page = ref(null);
const search = ref('');
const showInactive = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const modalOpen = ref(false);
const historyOpen = ref(false);
const historyData = ref(null);
const historyBusy = ref(false);
const historySupplierId = ref(null);
const receiptPage = ref(1);
const lotPage = ref(1);
const selectedId = ref(null);
const saving = ref(false);
const user = ref(currentUser());
const canViewFinancial = computed(() => Boolean(user.value?.permissions?.['purchases.view']));
const blank = () => ({ name: '', tax_id: '', address: '', contact_name: '', phone: '', email: '', bank_details: '', contract_no: '', contract_start: '', contract_end: '', payment_terms: '', delivery_days: '', active: true });
const form = reactive(blank());
let debounce;

const suppliers = computed(() => page.value?.data || []);
const missingCount = (supplier) => ['tax_id', 'address', 'contact_name', 'phone', 'email', ...(canViewFinancial.value ? ['bank_details'] : []), 'contract_no', 'contract_start', 'contract_end', 'payment_terms', 'delivery_days'].filter((key) => !supplier[key]).length;

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
onMounted(() => { load(); window.addEventListener('lager:user', (event) => { user.value = event.detail; }); });

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

async function openHistory(supplier) {
    historySupplierId.value = supplier.id;
    receiptPage.value = 1;
    lotPage.value = 1;
    historyData.value = null;
    historyOpen.value = true;
    await loadHistory();
}

async function loadHistory() {
    if (!historySupplierId.value) return;
    historyBusy.value = true; error.value = '';
    try {
        const response = await api.get(`suppliers/${historySupplierId.value}/history`, {
            params: { receipts_page: receiptPage.value, lots_page: lotPage.value },
        });
        historyData.value = response.data.data;
    }
    catch (e) { error.value = e.response?.data?.message || 'Մատակարարի պատմությունը չհաջողվեց բեռնել։'; }
    finally { historyBusy.value = false; }
}

function changeHistoryPage(list, pageNumber) {
    if (list === 'receipts') receiptPage.value = pageNumber;
    else lotPage.value = pageNumber;
    loadHistory();
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
    <div class="page-heading"><div><p class="eyebrow">ԳՆՈՒՄՆԵՐ ԵՎ ԳՈՐԾՈՂՈՒԹՅՈՒՆՆԵՐ</p><h1>Մատակարարներ</h1><p class="muted">Կառավարեք մատակարարների քարտերը, պայմանագրերն ու կապակցված գնումները։</p></div><button class="primary-button" @click="openCreate"><span><AppIcon name="add" /></span>Ավելացնել մատակարար</button></div>
    <div v-if="error && !modalOpen" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
    <section class="table-card">
        <div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Փնտրել անունով, ՀՎՀՀ-ով կամ կոնտակտով…"></label><label class="toggle-label"><input v-model="showInactive" type="checkbox"> Ցույց տալ ապաակտիվացվածները</label><div class="list-count">Ընդամենը՝ <b>{{ page?.total ?? '…' }}</b></div></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Մատակարար</th><th>Կոնտակտ</th><th>ՀՎՀՀ</th><th>Պայմանագիր</th><th>Առաքում</th><th>Քարտի տվյալներ</th><th>Կարգավիճակ</th><th>Գործողություն</th></tr></thead><tbody>
            <tr v-for="supplier in suppliers" :key="supplier.id"><td><strong>{{ supplier.name }}</strong><small class="cell-subtitle">{{ supplier.address || 'Հասցեն լրացված չէ' }}</small></td><td>{{ supplier.contact_name || '—' }}<small class="cell-subtitle">{{ supplier.phone || '—' }}</small></td><td>{{ supplier.tax_id || '—' }}</td><td>{{ supplier.contract_no || '—' }}<small class="cell-subtitle">{{ supplier.contract_start || '—' }} — {{ supplier.contract_end || '—' }}</small></td><td>{{ supplier.delivery_days ?? '—' }} օր</td><td><span class="completeness-pill" :class="missingCount(supplier) ? 'needs-data' : 'complete'">{{ missingCount(supplier) ? `Պակաս՝ ${missingCount(supplier)} դաշտ` : 'Ամբողջական' }}</span></td><td><span class="status-pill" :class="{ inactive: !supplier.active }">{{ supplier.active ? 'Ակտիվ' : 'Ապաակտիվ' }}</span></td><td><div class="table-actions"><button class="secondary-button compact-action" @click="openHistory(supplier)">Պատմություն</button><button class="icon-button" :aria-label="`${supplier.name} խմբագրել`" title="Խմբագրել" @click="openEdit(supplier)"><AppIcon name="edit" /></button><button v-if="supplier.active" class="icon-button danger" :aria-label="`${supplier.name} ապաակտիվացնել`" title="Ապաակտիվացնել" @click="deactivate(supplier)"><AppIcon name="trash" /></button></div></td></tr>
            <tr v-if="!busy && !suppliers.length"><td colspan="8" class="table-empty">{{ search ? 'Որոնմանը համապատասխան մատակարար չկա։' : 'Մատակարարներ դեռ ավելացված չեն։' }}</td></tr>
            <tr v-if="busy && !suppliers.length"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
        </tbody></table></div>
        <div v-if="page && page.last_page > 1" class="pagination"><span>Ցուցադրված է {{ page.from }}–{{ page.to }}՝ {{ page.total }} մատակարարից</span><div class="pagination-controls"><button :disabled="page.current_page <= 1 || busy" @click="load(page.current_page - 1)">Նախորդ</button><span>Էջ {{ page.current_page }} / {{ page.last_page }}</span><button :disabled="page.current_page >= page.last_page || busy" @click="load(page.current_page + 1)">Հաջորդ</button></div></div>
    </section>

    <div v-if="historyOpen" class="modal-backdrop" @click.self="historyOpen = false" @keydown.esc="historyOpen = false"><section class="modal-card supplier-history-modal"><header class="modal-header"><div><p class="eyebrow">ՄԱՏԱԿԱՐԱՐԻ ՀԵՏԱԳԻԾ</p><h2>{{ historyData?.supplier?.name || 'Բեռնվում է…' }}</h2><p>Կապակցված ապրանքներ, վերջին մատակարարում, ստացված փաստաթղթեր և LOT-երի գների պատմություն։</p></div><button type="button" class="icon-button close-button" aria-label="Փակել" @click="historyOpen = false"><AppIcon name="xmark" /></button></header><p v-if="historyBusy" class="table-empty">Պատմությունը բեռնվում է…</p><p v-else-if="error" class="form-error" role="alert">{{ error }}</p><template v-else-if="historyData"><div class="supplier-history-grid"><article><span class="metric-icon blue"><AppIcon name="box" /></span><div><strong>{{ historyData.products.length }}</strong><small>Կապակցված ապրանք</small></div></article><article><span class="metric-icon green"><AppIcon name="arrowDown" /></span><div><strong>{{ historyData.receipts.total }}</strong><small>Մուտքի փաստաթուղթ</small></div></article><article><span class="metric-icon violet"><AppIcon name="boxes" /></span><div><strong>{{ historyData.lots.total }}</strong><small>Ստացված LOT</small></div></article></div><section class="supplier-history-section"><h3>Մատակարարվող ապրանքներ</h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Կոդ</th><th>Ապրանք</th><th>Խումբ</th><th>Միավոր</th><th>Վերջին մուտք</th><th v-if="historyData.show_prices">Վերջին գին</th><th>Կարգավիճակ</th></tr></thead><tbody><tr v-for="product in historyData.products" :key="product.id"><td>{{ product.code }}</td><td><strong>{{ product.name }}</strong></td><td>{{ product.category || '—' }}</td><td>{{ product.unit }}</td><td>{{ product.last_delivery_on || '—' }}</td><td v-if="historyData.show_prices">{{ product.latest_unit_cost !== null && product.latest_unit_cost !== undefined ? `${Number(product.latest_unit_cost).toLocaleString('hy-AM')} ֏` : '—' }}</td><td>{{ product.active ? 'Ակտիվ' : 'Ապաակտիվ' }}</td></tr><tr v-if="!historyData.products.length"><td :colspan="historyData.show_prices ? 7 : 6" class="table-empty">Այս մատակարարին ապրանք կապակցված չէ։</td></tr></tbody></table></div></section><section class="supplier-history-section"><h3>Մատակարարումների պատմություն</h3><article v-for="receipt in historyData.receipts.data" :key="receipt.id" class="supplier-receipt-card"><header><strong>{{ receipt.receipt_no }}</strong><span>{{ receipt.received_on }} · {{ receipt.receiver || '—' }}</span><small>{{ receipt.invoice_no || 'Հաշիվ չկա' }} · {{ receipt.contract_no || 'Պայմանագիր չկա' }}</small></header><div v-for="line in receipt.lines" :key="`${line.product_code}-${line.lot_no}`" class="supplier-receipt-line"><span><b>{{ line.product_code }} · {{ line.product }}</b><small>LOT {{ line.lot_no || '—' }} · պիտանի է մինչև {{ line.expires_on || '—' }}</small></span><span>{{ line.qty }} {{ line.unit }}<small v-if="line.unit_cost !== undefined">{{ Number(line.unit_cost).toLocaleString('hy-AM') }} ֏ / միավոր</small></span></div><p v-if="!receipt.lines.length" class="muted">Փաստաթղթում տողեր չկան։</p></article><p v-if="!historyData.receipts.data.length" class="table-empty">Մուտքի փաստաթուղթ դեռ չկա։</p><div v-if="historyData.receipts.last_page > 1" class="pagination"><span>{{ historyData.receipts.from }}–{{ historyData.receipts.to }}՝ {{ historyData.receipts.total }} փաստաթղթից</span><div class="pagination-controls"><button :disabled="historyData.receipts.current_page <= 1 || historyBusy" @click="changeHistoryPage('receipts', historyData.receipts.current_page - 1)">Նախորդ</button><span>Էջ {{ historyData.receipts.current_page }} / {{ historyData.receipts.last_page }}</span><button :disabled="historyData.receipts.current_page >= historyData.receipts.last_page || historyBusy" @click="changeHistoryPage('receipts', historyData.receipts.current_page + 1)">Հաջորդ</button></div></div></section><section class="supplier-history-section"><h3>Գնային և LOT պատմություն</h3><div class="table-scroll"><table class="data-table"><thead><tr><th>Ստացման օր</th><th>Ապրանք</th><th>LOT</th><th>Պահեստ</th><th>Ժամկետ</th><th>Քանակ</th><th v-if="historyData.lots.data.some(lot => lot.unit_cost !== undefined)">Գին</th></tr></thead><tbody><tr v-for="lot in historyData.lots.data" :key="lot.id"><td>{{ lot.received_on }}</td><td><strong>{{ lot.product_code }} · {{ lot.product }}</strong></td><td>{{ lot.lot_no }}</td><td>{{ lot.branch || 'Կենտրոնական պահեստ' }}<small class="cell-subtitle">{{ lot.bin_location || 'Տեղը նշված չէ' }}</small></td><td>{{ lot.expires_on || '—' }}</td><td>{{ lot.qty }} {{ lot.unit }}</td><td v-if="lot.unit_cost !== undefined">{{ Number(lot.unit_cost).toLocaleString('hy-AM') }} ֏</td></tr><tr v-if="!historyData.lots.data.length"><td colspan="7" class="table-empty">LOT-երի պատմություն չկա։</td></tr></tbody></table></div><div v-if="historyData.lots.last_page > 1" class="pagination"><span>{{ historyData.lots.from }}–{{ historyData.lots.to }}՝ {{ historyData.lots.total }} LOT-ից</span><div class="pagination-controls"><button :disabled="historyData.lots.current_page <= 1 || historyBusy" @click="changeHistoryPage('lots', historyData.lots.current_page - 1)">Նախորդ</button><span>Էջ {{ historyData.lots.current_page }} / {{ historyData.lots.last_page }}</span><button :disabled="historyData.lots.current_page >= historyData.lots.last_page || historyBusy" @click="changeHistoryPage('lots', historyData.lots.current_page + 1)">Հաջորդ</button></div></div></section></template><footer class="modal-actions"><button type="button" class="secondary-button" @click="historyOpen=false">Փակել</button></footer></section></div>

    <div v-if="modalOpen" class="modal-backdrop" @click.self="modalOpen = false" @keydown.esc="modalOpen = false"><form class="modal-card supplier-modal" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">ՄԱՏԱԿԱՐԱՐԻ ՔԱՐՏ</p><h2>{{ selectedId ? 'Խմբագրել մատակարարին' : 'Ավելացնել մատակարար' }}</h2><p>Լրացրեք պայմանագրային և վճարման տվյալները՝ փաստաթղթերին համապատասխան։</p></div><button type="button" class="icon-button close-button" aria-label="Փակել" @click="modalOpen = false"><AppIcon name="xmark" /></button></div>
        <div class="form-grid"><label class="form-field span-2">Անվանում *<input v-model.trim="form.name" class="form-control" maxlength="190" required></label><label class="form-field">ՀՎՀՀ *<input v-model.trim="form.tax_id" class="form-control" maxlength="50" required></label><label class="form-field">Կոնտակտային անձ *<input v-model.trim="form.contact_name" class="form-control" maxlength="160" required></label><label class="form-field span-2">Հասցե *<input v-model.trim="form.address" class="form-control" maxlength="255" required></label><label class="form-field">Հեռախոս *<input v-model.trim="form.phone" class="form-control" maxlength="50" required></label><label class="form-field">Էլ. փոստ *<input v-model.trim="form.email" type="email" class="form-control" maxlength="190" required></label><label v-if="canViewFinancial" class="form-field span-2">Բանկային տվյալներ *<input v-model.trim="form.bank_details" class="form-control" maxlength="255" required></label><label class="form-field">Պայմանագրի համար *<input v-model.trim="form.contract_no" class="form-control" maxlength="100" required></label><label class="form-field">Առաքման ժամկետ՝ օրեր *<input v-model="form.delivery_days" type="number" min="0" step="1" class="form-control" required></label><label class="form-field">Պայմանագրի սկիզբ *<input v-model="form.contract_start" type="date" class="form-control" required></label><label class="form-field">Պայմանագրի ավարտ *<input v-model="form.contract_end" type="date" class="form-control" required></label><label class="form-field span-2">Վճարման պայմաններ *<input v-model.trim="form.payment_terms" class="form-control" maxlength="190" required></label><label v-if="selectedId" class="toggle-label span-2"><input v-model="form.active" type="checkbox"> Մատակարարը ակտիվ է</label></div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="modalOpen = false">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Պահպանվում է…' : 'Պահպանել' }}</button></div></form></div>
</template>
