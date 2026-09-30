<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import ExportActions from '@/components/ExportActions.vue';

const route = useRoute();
const user = ref(currentUser());
const result = ref(null);
const busy = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const search = ref('');
const selected = ref(null);
const dialog = ref('');
const suppliers = ref([]);
const locations = computed(() => result.value?.locations || []);
const rows = computed(() => result.value?.data || []);
const can = (code) => Boolean(user.value?.permissions?.[code]);
const start = reactive({ location_id: '', note: '' });
const counts = reactive({});
let debounce;

async function load(page = 1) {
  busy.value = true;
  error.value = '';
  try {
    const response = await api.get('inventory', { params: { page, search: search.value || undefined } });
    result.value = response.data;
  } catch (e) {
    error.value = e.response?.data?.message || 'Գույքագրման ցանկը չհաջողվեց բեռնել։';
  } finally { busy.value = false; }
}
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
onMounted(() => { load(); window.addEventListener('lager:user', (event) => { user.value = event.detail; }); });

function openStart() {
  if (!locations.value.length) { error.value = 'Ակտիվ պահեստ չի գտնվել։'; return; }
  start.location_id = String(locations.value[0].id);
  start.note = '';
  error.value = '';
  dialog.value = 'start';
}
async function openCount(row) {
  error.value = '';
  try {
    const response = await api.get(`inventory/${row.id}`);
    selected.value = response.data.data.session;
    suppliers.value = response.data.data.suppliers;
    Object.keys(counts).forEach((key) => delete counts[key]);
    for (const line of selected.value.lines) counts[line.id] = {
      counted_qty: line.counted_qty ?? '', reason: line.difference_reason || '',
      lot_no: line.counted_lot_no || '', expires_on: line.counted_expires_on?.slice(0, 10) || '',
      supplier_id: line.counted_supplier_id || '', bin_location: line.counted_bin_location || '', unit_cost: line.counted_unit_cost || '',
    };
    dialog.value = 'count';
  } catch (e) { error.value = e.response?.data?.message || 'Գույքագրման տողերը չհաջողվեց բացել։'; }
}
async function save() {
  if (saving.value) return;
  saving.value = true; error.value = '';
  try {
    if (dialog.value === 'start') {
      await api.post('inventory', { ...start, location_id: Number(start.location_id) });
      notice.value = 'Նոր գույքագրումը սկսվեց։';
    } else {
      const payload = Object.fromEntries(Object.entries(counts).map(([id, item]) => [id, {
        ...item,
        counted_qty: Number(item.counted_qty),
        supplier_id: item.supplier_id ? Number(item.supplier_id) : null,
        unit_cost: item.unit_cost === '' ? null : Number(item.unit_cost),
      }]));
      await api.put(`inventory/${selected.value.id}/count`, { counts: payload });
      notice.value = 'Փաստացի քանակները պահպանվեցին՝ անկախ հաստատման համար։';
    }
    dialog.value = ''; selected.value = null;
    await load(result.value?.pagination.current_page || 1);
    setTimeout(() => { notice.value = ''; }, 3500);
  } catch (e) { error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Գործողությունը չհաջողվեց։'; }
  finally { saving.value = false; }
}
async function approve() {
  if (!selected.value || saving.value) return;
  saving.value = true; error.value = '';
  try {
    await api.post(`inventory/${selected.value.id}/approve`);
    dialog.value = ''; selected.value = null;
    notice.value = 'Գույքագրումը անկախ ձևով հաստատվեց, տարբերությունները գրանցվեցին շարժերում։';
    await load(result.value?.pagination.current_page || 1);
    setTimeout(() => { notice.value = ''; }, 3500);
  } catch (e) { error.value = Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || 'Գույքագրումը չհաստատվեց։'; }
  finally { saving.value = false; }
}
function status(value) { return ({ open: 'Հաշվարկման փուլում', counted: 'Սպասում է հաստատման', closed: 'Փակված' })[value] || value; }
</script>

<template>
  <div class="page-heading"><div><p class="eyebrow">ՊԱՇԱՐԻ ՎԵՐԱՀՍԿՈՒՄ</p><h1>{{ route.meta.title }}</h1><p class="muted">Հաշվառեք LOT-երով մնացորդները և տարբերությունները կիրառեք միայն անկախ հաստատումից հետո։</p></div><button v-if="can('inventory.create')" class="primary-button" @click="openStart"><AppIcon name="add" /> Սկսել գույքագրում</button></div>
  <div v-if="error && !dialog" class="alert-error" role="alert">{{ error }}</div><div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
  <section class="table-card"><div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Որոնել համարով կամ պահեստով…"></label><div class="list-count">Գրառումներ՝ <b>{{ result?.pagination.total ?? '…' }}</b></div><ExportActions page="inventory" endpoint="inventory/export" :search="search" :disabled="busy" /></div>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Համար</th><th>Պահեստ</th><th>Տողեր</th><th>Սկսել է</th><th>Ամսաթիվ</th><th>Կարգավիճակ</th><th>Գործողություն</th></tr></thead><tbody>
      <tr v-for="row in rows" :key="row.id"><td><strong>{{ row.inventory_no }}</strong></td><td>{{ row.location?.name || 'Կենտրոնական պահեստ' }}</td><td>{{ row.counted_lines_count }}/{{ row.lines_count }}</td><td>{{ row.starter?.name || '—' }}</td><td>{{ row.started_at || '—' }}</td><td><span class="workflow-status" :class="`state-${row.status}`">{{ status(row.status) }}</span></td><td><div class="table-actions"><button v-if="['open','counted'].includes(row.status) && can('inventory.edit')" class="secondary-button compact-action" @click="openCount(row)">{{ row.status === 'counted' ? 'Դիտել հաշվարկը' : 'Լրացնել քանակները' }}</button><button v-if="row.status==='counted' && can('inventory.approve')" class="primary-button compact-action" @click="selected=row;dialog='approve';error=''">Անկախ հաստատել</button><RouterLink v-if="row.status==='closed' && can('inventory.view')" class="secondary-button compact-action" :to="`/inventory/${row.id}/act`">Տպել ակտը</RouterLink></div></td></tr>
      <tr v-if="!busy && result && !rows.length"><td colspan="7" class="table-empty">Գույքագրման գրառումներ չկան։ Սկսեք առաջին գույքագրումը։</td></tr><tr v-if="busy && !result"><td colspan="7" class="table-empty">Բեռնվում է…</td></tr>
    </tbody></table></div><div v-if="result" class="pagination"><span>Ընդամենը՝ {{ result.pagination.total }} գրառում</span><div class="pagination-controls"><button :disabled="result.pagination.current_page<=1 || busy" @click="load(result.pagination.current_page-1)">Նախորդ</button><span>Էջ {{ result.pagination.current_page }} / {{ result.pagination.last_page }}</span><button :disabled="result.pagination.current_page>=result.pagination.last_page || busy" @click="load(result.pagination.current_page+1)">Հաջորդ</button></div></div></section>

  <div v-if="dialog==='start'" class="modal-backdrop" @click.self="dialog=''"><form class="modal-card" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">ԳՈՒՅՔԱԳՐՄԱՆ ՍԿԻԶԲ</p><h2>Սկսել նոր գույքագրում</h2><p>Մնացորդները կֆիքսվեն հենց այս պահին՝ LOT առ LOT։</p></div><button type="button" class="icon-button close-button" @click="dialog=''"><AppIcon name="xmark" /></button></div><div class="form-grid"><label class="form-field span-2">Պահեստ *<select v-model="start.location_id" class="form-control" required><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select></label><label class="form-field span-2">Նշում<textarea v-model.trim="start.note" class="form-control"></textarea></label></div><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="dialog=''">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Սկսվում է…' : 'Սկսել գույքագրումը' }}</button></div></form></div>

  <div v-if="dialog==='count' && selected" class="modal-backdrop" @click.self="dialog=''"><form class="modal-card inventory-modal" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">{{ selected.inventory_no }}</p><h2>Փաստացի հաշվարկ</h2><p>Յուրաքանչյուր տողի տարբերության դեպքում նշեք պատճառը։ Նոր LOT-ի տվյալները լրացվում են, եթե ապրանքի հաշվառված քանակը զրո է։</p></div><button type="button" class="icon-button close-button" @click="dialog=''"><AppIcon name="xmark" /></button></div><div class="inventory-lines"><article v-for="line in selected.lines" :key="line.id" class="inventory-line"><div class="inventory-product"><strong>{{ line.product.code }} · {{ line.product.name }}</strong><small>LOT {{ line.lot?.lot_no || '—' }} · Հաշվառված՝ {{ line.expected_qty }} {{ line.product.unit }}</small></div><label class="form-field">Փաստացի քանակ *<input v-model="counts[line.id].counted_qty" class="form-control" type="number" min="0" step="0.001" required></label><label class="form-field">Տարբերության պատճառ<input v-model.trim="counts[line.id].reason" class="form-control" placeholder="Պարտադիր է, եթե կա տարբերություն"></label>
      <div v-if="!line.lot_id" class="inventory-new-lot"><strong>Նոր LOT-ի տվյալներ</strong><label class="form-field">LOT համար<input v-model.trim="counts[line.id].lot_no" class="form-control" maxlength="100"></label><label class="form-field">Պիտանի է մինչև<input v-model="counts[line.id].expires_on" class="form-control" type="date"></label><label class="form-field">Մատակարար<select v-model="counts[line.id].supplier_id" class="form-control"><option value="">Նշված չէ</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></label><label v-if="can('purchases.view')" class="form-field">Միավորի արժեք<input v-model="counts[line.id].unit_cost" class="form-control" type="number" min="0" step="0.01"></label><label class="form-field">Պահեստային տեղ<input v-model.trim="counts[line.id].bin_location" class="form-control"></label></div>
    </article></div><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="dialog=''">Փակել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Պահպանվում է…' : 'Ներկայացնել հաստատման' }}</button></div></form></div>

  <div v-if="dialog==='approve' && selected" class="modal-backdrop" @click.self="dialog=''"><section class="modal-card confirm-card"><div class="metric-icon amber">!</div><h2>Անկախ հաստատե՞լ գույքագրումը</h2><p><b>{{ selected.inventory_no }}</b></p><p class="muted">Հաստատողը պետք է տարբերվի գույքագրումը սկսած աշխատակցից։ Հաստատման ժամանակ մնացորդները կրկին կհամեմատվեն մեկնարկային վիճակի հետ։</p><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button class="secondary-button" @click="dialog=''">Չեղարկել</button><button class="primary-button" :disabled="saving" @click="approve">{{ saving ? 'Հաստատվում է…' : 'Հաստատել և փակել' }}</button></div></section></div>
</template>
