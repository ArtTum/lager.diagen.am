<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import ExportActions from '@/components/ExportActions.vue';

const route = useRoute();
const user = ref(currentUser());
const result = ref(null);
const options = ref({ branches: [], products: [], suppliers: [] });
const busy = ref(false); const saving = ref(false); const modal = ref(false);
const error = ref(''); const notice = ref(''); const search = ref('');
const rows = computed(() => result.value?.data || []);
const can = (permission) => Boolean(user.value?.permissions?.[permission]);
const form = reactive({ direction: 'branch_to_central', from_location: '', supplier_id: '', reason: '', items: [{ product_id: '', qty: '' }] });
let debounce;
async function load(page = 1) {
  busy.value = true; error.value = '';
  try { const response = await api.get('returns', { params: { page, search: search.value || undefined } }); result.value = response.data; }
  catch (e) { error.value = e.response?.data?.message || 'Վերադարձների ցանկը չհաջողվեց բեռնել։'; }
  finally { busy.value = false; }
}
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
onMounted(() => { load(); window.addEventListener('lager:user', (event) => { user.value = event.detail; }); });
function addLine() { form.items.push({ product_id: '', qty: '' }); }
function removeLine(index) { if (form.items.length > 1) form.items.splice(index, 1); }
async function openCreate() {
  error.value = '';
  try {
    const response = await api.get('returns/options'); options.value = response.data.data;
    Object.assign(form, { direction: 'branch_to_central', from_location: String(options.value.branches[0]?.id || ''), supplier_id: '', reason: '', items: [{ product_id: '', qty: '' }] });
    modal.value = true;
  } catch (e) { error.value = e.response?.data?.message || 'Վերադարձի ձևը չհաջողվեց բացել։'; }
}
async function save() {
  if (saving.value) return; saving.value = true; error.value = '';
  try {
    const body = { ...form, from_location: form.direction === 'branch_to_central' ? Number(form.from_location) : 0,
      supplier_id: form.direction === 'central_to_supplier' ? Number(form.supplier_id) : null,
      items: form.items.map((item) => ({ product_id: Number(item.product_id), qty: Number(item.qty) })) };
    await api.post('returns', body); modal.value = false; notice.value = 'Վերադարձը գրանցվեց, մնացորդը և շարժերի մատյանը թարմացվեցին։';
    await load(1); setTimeout(() => { notice.value = ''; }, 3500);
  } catch (e) { error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Վերադարձը չգրանցվեց։'; }
  finally { saving.value = false; }
}
const directionName = (direction) => direction === 'central_to_supplier' ? 'Կենտրոն → Մատակարար' : 'Մասնաճյուղ → Կենտրոն';
</script>

<template>
  <div class="page-heading"><div><p class="eyebrow">ԱՊՐԱՆՔԻ ՇԱՐԺ ԵՎ ՎԵՐԱԴԱՐՁ</p><h1>{{ route.meta.title }}</h1><p class="muted">Գրանցեք մասնաճյուղից կենտրոն կամ կենտրոնից մատակարար վերադարձը․ մնացորդը կնվազի LOT-երով FEFO հերթով։</p></div><button v-if="can('returns.create')" class="primary-button" @click="openCreate"><AppIcon name="add" /> Գրանցել վերադարձ</button></div>
  <div v-if="error && !modal" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
  <section class="table-card"><div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Որոնել համարով, ապրանքով կամ պահեստով…"></label><div class="list-count">Գրառումներ՝ <b>{{ result?.pagination.total ?? '…' }}</b></div><ExportActions page="returns" endpoint="returns/export" :search="search" :disabled="busy" /></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Համար</th><th>Ուղղություն</th><th>Պահեստ / մատակարար</th><th>Ապրանք</th><th>LOT</th><th>Քանակ</th><th>Պատճառ</th><th>Ամսաթիվ</th></tr></thead><tbody>
    <tr v-for="row in rows" :key="row.id"><td><strong>{{ row.return_no }}</strong></td><td>{{ directionName(row.direction) }}</td><td>{{ row.direction === 'central_to_supplier' ? row.supplier?.name || 'Մատակարար' : row.branch?.name || 'Կենտրոնական պահեստ' }}</td><td>{{ row.product?.code }} · {{ row.product?.name }}</td><td>{{ row.lot?.lot_no || '—' }}</td><td>{{ row.qty }} {{ row.product?.unit }}</td><td>{{ row.reason }}</td><td>{{ row.created_at || '—' }}</td></tr>
    <tr v-if="!busy && result && !rows.length"><td colspan="8" class="table-empty">Վերադարձի գրառումներ դեռ չկան։</td></tr><tr v-if="busy && !result"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
  </tbody></table></div><div v-if="result" class="pagination"><span>Ընդամենը՝ {{ result.pagination.total }} գրառում</span><div class="pagination-controls"><button :disabled="result.pagination.current_page<=1 || busy" @click="load(result.pagination.current_page-1)">Նախորդ</button><span>Էջ {{ result.pagination.current_page }} / {{ result.pagination.last_page }}</span><button :disabled="result.pagination.current_page>=result.pagination.last_page || busy" @click="load(result.pagination.current_page+1)">Հաջորդ</button></div></div></section>

  <div v-if="modal" class="modal-backdrop" @click.self="modal=false"><form class="modal-card return-modal" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">ՊԱՀԵՍՏԱՅԻՆ ԳՈՐԾԱՌՆՈՒԹՅՈՒՆ</p><h2>Գրանցել ապրանքների վերադարձ</h2><p>Ապրանքները կբաշխվեն ըստ LOT-ի ժամկետների՝ FEFO հերթականությամբ։</p></div><button type="button" class="icon-button close-button" @click="modal=false"><AppIcon name="xmark" /></button></div>
    <div class="form-grid"><label class="form-field">Վերադարձի ուղղություն *<select v-model="form.direction" class="form-control"><option value="branch_to_central">Մասնաճյուղ → Կենտրոն</option><option v-if="options.suppliers.length" value="central_to_supplier">Կենտրոն → Մատակարար</option></select></label><label v-if="form.direction==='branch_to_central'" class="form-field">Մասնաճյուղ *<select v-model="form.from_location" class="form-control" required><option value="">Ընտրել մասնաճյուղը</option><option v-for="branch in options.branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option></select></label><label v-else class="form-field">Մատակարար *<select v-model="form.supplier_id" class="form-control" required><option value="">Ընտրել մատակարարին</option><option v-for="supplier in options.suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></label></div>
    <div class="transfer-lines"><div class="section-label">Վերադարձվող ապրանքներ</div><div v-for="(line,index) in form.items" :key="index" class="return-item-row"><label class="form-field">Ապրանք *<select v-model="line.product_id" class="form-control" required><option value="">Ընտրել ապրանքը</option><option v-for="product in options.products" :key="product.id" :value="product.id">{{ product.code }} · {{ product.name }}</option></select></label><label class="form-field">Քանակ *<input v-model="line.qty" class="form-control" type="number" min="0.001" step="0.001" required></label><button v-if="form.items.length>1" type="button" class="icon-button danger remove-line" title="Հեռացնել տողը" @click="removeLine(index)"><AppIcon name="xmark" /></button></div><button type="button" class="secondary-button add-line" @click="addLine"><AppIcon name="add" /> Ավելացնել ապրանք</button></div>
    <label class="form-field return-reason">Պատճառ *<textarea v-model.trim="form.reason" class="form-control" minlength="3" maxlength="2000" required placeholder="Նշեք վերադարձի պատճառը"></textarea></label><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="modal=false">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Գրանցվում է…' : 'Գրանցել վերադարձը' }}</button></div>
  </form></div>
</template>
