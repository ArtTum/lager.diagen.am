<script setup>
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import Pagination from '@/components/Pagination.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';

const user = ref(currentUser());
const canExport = computed(() => Boolean(user.value?.permissions?.['stock.export']));
const updateUser = (event) => { user.value = event.detail; };
const result = ref(null);
const search = ref('');
const busy = ref(false);
const error = ref('');
const page = ref(1);
let debounce;
let listRequestVersion = 0;
const rows = computed(() => result.value?.data || []);
const locations = computed(() => result.value?.locations || []);

async function load(pageNumber = 1, pageSize = result.value?.pagination.per_page || 25) {
  const version = ++listRequestVersion;
  busy.value = true;
  error.value = '';
  try {
    const response = await api.get('stock/matrix', { params: { page: pageNumber, search: search.value || undefined, per_page: pageSize } });
    if (version !== listRequestVersion) return;
    result.value = response.data;
    page.value = response.data.pagination.current_page;
  } catch (e) {
    if (version === listRequestVersion) error.value = e.response?.data?.message || 'Մնացորդների մատրիցան չհաջողվեց բեռնել։';
  } finally { if (version === listRequestVersion) busy.value = false; }
}

watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
onMounted(() => { load(); window.addEventListener('lager:user', updateUser); });
onBeforeUnmount(() => { listRequestVersion += 1; clearTimeout(debounce); window.removeEventListener('lager:user', updateUser); });

function quantity(row, locationId) { return Number(row.quantities?.[locationId] || 0); }
async function exportCsv() {
  try {
    const response = await api.get('stock/matrix/export', { params: { search: search.value || undefined }, responseType: 'blob' });
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'diagen-stock-matrix.csv';
    anchor.click();
    URL.revokeObjectURL(url);
  } catch (e) { error.value = e.response?.data?.message || 'CSV ֆայլը չհաջողվեց ներբեռնել։'; }
}
useLiveRefresh(() => load(page.value), { isBusy: busy });
</script>

<template>
  <div class="page-heading"><div><p class="eyebrow">ՊԱՇԱՐԻ ՎԵՐԱՀՍԿՈՒՄ</p><h1>Պահեստների մնացորդների մատրիցա</h1><p class="muted">Յուրաքանչյուր ապրանքի քանակը՝ ըստ հասանելի պահեստների։</p></div><div class="stock-actions"><RouterLink class="secondary-button" to="/stock"><AppIcon name="boxes" />Դիտել LOT-երը</RouterLink><button v-if="canExport" class="primary-button" type="button" @click="exportCsv"><AppIcon name="fileCsv" />Ներբեռնել CSV</button></div></div>
  <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
  <section class="table-card"><div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model.trim="search" class="form-control" placeholder="Որոնել ապրանքի անունով կամ կոդով…"></label><span class="list-count">Ապրանքներ՝ <b>{{ result?.pagination.total ?? '…' }}</b></span></div>
    <div class="table-scroll stock-matrix-scroll"><table class="data-table stock-matrix-table"><thead><tr><th>Ապրանք</th><th v-for="location in locations" :key="location.id">{{ location.name }}</th><th>Ընդամենը</th></tr></thead><tbody>
      <tr v-for="row in rows" :key="row.id"><td><strong>{{ row.name }}</strong><small class="cell-subtitle">{{ row.code }} · {{ row.unit }}</small></td><td v-for="location in locations" :key="location.id">{{ quantity(row, location.id).toLocaleString('hy-AM', { maximumFractionDigits: 3 }) }}</td><td><strong>{{ Number(row.total).toLocaleString('hy-AM', { maximumFractionDigits: 3 }) }}</strong></td></tr>
      <tr v-if="!busy && result && !rows.length"><td :colspan="locations.length + 2" class="table-empty">{{ search ? 'Որոնմանը համապատասխան ապրանք չկա։' : 'Ակտիվ ապրանքներ չկան։' }}</td></tr><tr v-if="busy && !result"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
    </tbody></table></div>
    <Pagination v-if="result" :pagination="result.pagination" :busy="busy" item-label="ապրանքից" @page-change="load" @per-page-change="load(1, $event)" />
  </section>
</template>
