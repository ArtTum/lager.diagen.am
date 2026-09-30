<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';

const route = useRoute(); const user = ref(currentUser()); const result = ref(null);
const busy = ref(false); const saving = ref(false); const exporting = ref(false); const error = ref(''); const notice = ref('');
const selected = ref(null); const search = ref(''); const reason = ref(''); let debounce;
const filters = reactive({ from: '', to: '', product_id: '', category_id: '', branch_id: '', supplier_id: '', actor_id: '', lot: '', type: '' });
const rows = computed(() => result.value?.data || []); const options = computed(() => result.value?.filters || {});
const can = (code) => Boolean(user.value?.permissions?.[code]);
const canViewSuppliers = computed(() => can('suppliers.view'));
const reversible = (row) => ['consumption', 'inventory_adjustment', 'receipt', 'return_supplier'].includes(row.type)
  && ((row.from_location !== null && row.to_location === null) || (row.from_location === null && row.to_location !== null));
async function load(page = 1) {
  busy.value = true; error.value = '';
  try { const response = await api.get('movements', { params: { ...filters, search: search.value || undefined, page } }); result.value = response.data; }
  catch (e) { error.value = e.response?.data?.message || 'Շարժերի ցանկը չհաջողվեց բեռնել։'; }
  finally { busy.value = false; }
}
function applyFilters() { load(1); }
async function exportMovements(format) {
  exporting.value = true; error.value = '';
  try {
    const response = await api.get('movements/export', { params: { ...filters, search: search.value || undefined, format }, responseType: 'blob' });
    const url = URL.createObjectURL(response.data); const anchor = document.createElement('a');
    anchor.href = url; anchor.download = `diagen-movements.${format}`; document.body.appendChild(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url);
  } catch (e) { error.value = e.response?.data?.message || `${format.toUpperCase()} ֆայլը չհաջողվեց ներբեռնել։`; }
  finally { exporting.value = false; }
}
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
onMounted(() => { load(); window.addEventListener('lager:user', (event) => { user.value = event.detail; }); });
function openReverse(row) { selected.value = row; reason.value = ''; error.value = ''; }
async function reverse() {
  if (!selected.value || saving.value) return; saving.value = true; error.value = '';
  try { await api.post(`movements/${selected.value.id}/reverse`, { reason: reason.value.trim() }); selected.value = null; reason.value = '';
    notice.value = 'Հակադարձ շարժը գրանցվեց, սկզբնական գրառումը պահպանվել է։'; await load(result.value?.pagination.current_page || 1); setTimeout(() => { notice.value = ''; }, 3500);
  } catch (e) { error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Շարժը չհակադարձվեց։'; }
  finally { saving.value = false; }
}
const typeName = (type) => ({ receipt: 'Մուտք', consumption: 'Ելք', transfer_sent: 'Ուղարկում', branch_transfer: 'Տեղափոխում', inventory_adjustment: 'Գույքագրման ուղղում', return_in: 'Վերադարձ կենտրոն', return_supplier: 'Մատակարարին վերադարձ', movement_reversal: 'Հակադարձ շարժ' })[type] || type;
const locationName = (id, name) => Number(id) === 0 ? 'Կենտրոնական պահեստ' : (name || '—');
</script>

<template>
  <div class="page-heading"><div><p class="eyebrow">ՊԱՇԱՐԻ ՀԵՏԱԳԻԾ</p><h1>{{ route.meta.title }}</h1><p class="muted">Փնտրեք շարժերը ըստ ժամանակահատվածի, ապրանքի, պահեստի, մատակարարի, աշխատակցի, LOT-ի և գործողության։</p></div></div>
  <div v-if="error && !selected" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
  <section class="table-card"><form class="movement-filter-grid" @submit.prevent="applyFilters">
    <label class="form-field">Սկսած<input v-model="filters.from" class="form-control" type="date"></label><label class="form-field">Մինչև<input v-model="filters.to" class="form-control" type="date"></label>
    <label class="form-field">Ապրանք<select v-model="filters.product_id" class="form-control"><option value="">Բոլոր ապրանքները</option><option v-for="item in options.products" :key="item.id" :value="item.id">{{ item.code }} · {{ item.name }}</option></select></label>
    <label class="form-field">Խումբ<select v-model="filters.category_id" class="form-control"><option value="">Բոլոր խմբերը</option><option v-for="item in options.categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
    <label class="form-field">Պահեստ<select v-model="filters.branch_id" class="form-control"><option value="">Բոլոր պահեստները</option><option value="0">Կենտրոնական պահեստ</option><option v-for="item in options.branches" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
    <label v-if="canViewSuppliers" class="form-field">Մատակարար<select v-model="filters.supplier_id" class="form-control"><option value="">Բոլոր մատակարարները</option><option v-for="item in options.suppliers" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
    <label class="form-field">Աշխատակից<select v-model="filters.actor_id" class="form-control"><option value="">Բոլորը</option><option v-for="item in options.users" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
    <label class="form-field">Գործողություն<select v-model="filters.type" class="form-control"><option value="">Բոլոր տեսակները</option><option v-for="item in options.types" :key="item" :value="item">{{ typeName(item) }}</option></select></label>
    <label class="form-field">LOT<input v-model.trim="filters.lot" class="form-control" placeholder="LOT համար"></label>
    <label class="form-field movement-search">Որոնում<input v-model.trim="search" class="form-control" placeholder="Փաստաթուղթ, ապրանք կամ պատճառ"></label>
    <button class="primary-button movement-filter-submit">Կիրառել ֆիլտրերը</button>
  </form>
  <div class="table-toolbar"><div class="list-count">Գրառումներ՝ <b>{{ result?.pagination.total ?? '…' }}</b></div><div v-if="can('movements.export')" class="table-actions"><button class="secondary-button compact-action" :disabled="exporting || busy" @click="exportMovements('csv')">{{ exporting ? 'Պատրաստվում է…' : 'CSV' }}</button><button class="secondary-button compact-action" :disabled="exporting || busy" @click="exportMovements('xlsx')">Excel</button></div></div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Ամսաթիվ</th><th>Փաստաթուղթ</th><th>Գործողություն</th><th>Ապրանք</th><th>{{ canViewSuppliers ? 'LOT / մատակարար' : 'LOT' }}</th><th>Պահեստից → Ուր</th><th>Քանակ</th><th>Աշխատակից / պատճառ</th><th>Ուղղում</th></tr></thead><tbody>
    <tr v-for="row in rows" :key="row.id"><td>{{ row.happened_at }}</td><td><strong>{{ row.reference || row.movement_no }}</strong><small class="cell-subtitle">{{ row.movement_no }}</small></td><td>{{ typeName(row.type) }}</td><td><b>{{ row.product_code }} · {{ row.product }}</b><small class="cell-subtitle">{{ row.category || 'Առանց խմբի' }}</small></td><td>{{ row.lot_no || '—' }}<small v-if="canViewSuppliers" class="cell-subtitle">{{ row.supplier || 'Մատակարար՝ նշված չէ' }}</small></td><td>{{ locationName(row.from_location,row.from_branch) }} → {{ locationName(row.to_location,row.to_branch) }}</td><td>{{ row.qty }}</td><td>{{ row.actor || '—' }}<small class="cell-subtitle">{{ row.reason || '—' }}</small></td><td><span v-if="row.correction_no" class="workflow-status state-completed">Հակադարձված՝ {{ row.correction_no }}</span><button v-else-if="can('movements.edit') && reversible(row)" class="secondary-button compact-action" @click="openReverse(row)">Հակադարձել</button><span v-else class="muted">—</span></td></tr>
    <tr v-if="!busy && result && !rows.length"><td colspan="9" class="table-empty">Ընտրված ֆիլտրերով շարժ չի գտնվել։</td></tr><tr v-if="busy && !result"><td colspan="9" class="table-empty">Բեռնվում է…</td></tr>
  </tbody></table></div><div v-if="result" class="pagination"><span>Ընդամենը՝ {{ result.pagination.total }} շարժ</span><div class="pagination-controls"><button :disabled="result.pagination.current_page<=1 || busy" @click="load(result.pagination.current_page-1)">Նախորդ</button><span>Էջ {{ result.pagination.current_page }} / {{ result.pagination.last_page }}</span><button :disabled="result.pagination.current_page>=result.pagination.last_page || busy" @click="load(result.pagination.current_page+1)">Հաջորդ</button></div></div></section>

  <div v-if="selected" class="modal-backdrop" @click.self="selected=null"><form class="modal-card confirm-card" @submit.prevent="reverse"><div class="metric-icon rose">↶</div><h2>Հակադարձել պահեստային շարժը</h2><p><b>{{ selected.movement_no }}</b> · {{ selected.product_code }} · {{ selected.qty }} քանակ</p><p class="muted">Սկզբնական գրառումը կմնա պատմության մեջ։ Կգրանցվի առանձին ուղղիչ շարժ։</p><label class="form-field reverse-reason">Պատճառը պարտադիր է *<textarea v-model.trim="reason" class="form-control" minlength="8" maxlength="2000" required placeholder="Նկարագրեք սխալի պատճառը և ուղղման հիմքը"></textarea></label><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="selected=null">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Գրանցվում է…' : 'Գրանցել ուղղումը' }}</button></div></form></div>
</template>
