<script setup>
import Pagination from '@/components/Pagination.vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import ExportActions from '@/components/ExportActions.vue';
import ListFilterBar from '@/components/ListFilterBar.vue';
import { formatDisplayDate } from '@/dateUtils';

const route = useRoute();
const user = ref(currentUser());
const result = ref(null);
const busy = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const search = ref('');
const lineSearch = ref('');
const filters = reactive({ status: '', from: '', to: '' });
const selected = ref(null);
const dialog = ref('');
const suppliers = ref([]);
const locations = computed(() => result.value?.locations || []);
const rows = computed(() => result.value?.data || []);
const filterSelects = [{ key: 'status', label: 'Կարգավիճակ', allLabel: 'Բոլոր փուլերը', options: [{ value: 'open', label: 'Հաշվարկման փուլում' }, { value: 'counted', label: 'Սպասում է հաստատման' }, { value: 'closed', label: 'Փակված' }] }];
const visibleLines = computed(() => {
  const lines = selected.value?.lines || [];
  const query = lineSearch.value.trim().toLocaleLowerCase('hy-AM');
  if (!query) return lines;
  return lines.filter((line) => [line.product?.code, line.product?.name, line.lot?.lot_no, line.expected_qty, line.product?.unit]
    .filter(Boolean).join(' ').toLocaleLowerCase('hy-AM').includes(query));
});
const can = (code) => Boolean(user.value?.permissions?.[code]);
const start = reactive({ location_id: '', note: '' });
const counts = reactive({});
let debounce;

async function load(page = 1, pageSize = result.value?.pagination.per_page || 15) {
  busy.value = true;
  error.value = '';
  try {
    const response = await api.get('inventory', { params: { ...filters, page, per_page: pageSize, search: search.value || undefined } });
    result.value = response.data;
  } catch (e) {
    error.value = e.response?.data?.message || 'Գույքագրման ցանկը չհաջողվեց բեռնել։';
  } finally { busy.value = false; }
}
function resetFilters() { Object.assign(filters, { status: '', from: '', to: '' }); load(1); }
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
  lineSearch.value = '';
  try {
    const response = await api.get(`inventory/${row.id}`);
    selected.value = response.data.data.session;
    suppliers.value = response.data.data.suppliers;
    if (!selected.value.lines?.length) {
      selected.value = null;
      error.value = 'Այս գույքագրումը պահպանվել է առանց ապրանքային տողերի, ուստի սկզբնական մնացորդները համեմատել հնարավոր չէ։ Սկսեք նոր գույքագրում։';
      return;
    }
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
  error.value = '';
  if (dialog.value === 'count') {
    const missingLine = (selected.value?.lines || []).find((line) => {
      const value = counts[line.id]?.counted_qty;
      return value === '' || value === null || value === undefined || !Number.isFinite(Number(value)) || Number(value) < 0;
    });
    if (missingLine) {
      lineSearch.value = [missingLine.product?.code, missingLine.lot?.lot_no].filter(Boolean).join(' ');
      error.value = 'Որոնումը միայն տողերը գտնելու համար է․ ներկայացնելուց առաջ լրացրեք բոլոր տողերի փաստացի քանակը։ Բաց թողնված տողը ցուցադրված է։';
      return;
    }
  }
  saving.value = true;
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
  <section class="table-card"><div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Որոնել համարով կամ պահեստով…"></label><div class="list-count">Գրառումներ՝ <b>{{ result?.pagination.total ?? '…' }}</b></div><ExportActions page="inventory" endpoint="inventory/export" :search="search" :filters="filters" :disabled="busy" /></div>
    <ListFilterBar :model-value="filters" @change="filters[$event.key] = $event.value" :selects="filterSelects" :date-range="true" @apply="load(1)" @reset="resetFilters" />
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Համար</th><th>Պահեստ</th><th>Տողեր</th><th>Սկսել է</th><th>Ամսաթիվ</th><th>Կարգավիճակ</th><th>Գործողություն</th></tr></thead><tbody>
      <tr v-for="row in rows" :key="row.id"><td><strong>{{ row.inventory_no }}</strong></td><td>{{ row.location?.name || 'Կենտրոնական պահեստ' }}</td><td>{{ row.counted_lines_count }}/{{ row.lines_count }}</td><td>{{ row.starter?.name || '—' }}</td><td>{{ formatDisplayDate(row.started_at) }}</td><td><span class="workflow-status" :class="`state-${row.status}`">{{ status(row.status) }}</span></td><td><div class="table-actions"><button v-if="['open','counted'].includes(row.status) && row.lines_count > 0 && can('inventory.edit')" class="secondary-button compact-action" @click="openCount(row)">{{ row.status === 'counted' ? 'Դիտել հաշվարկը' : 'Լրացնել քանակները' }}</button><span v-else-if="['open','counted'].includes(row.status) && row.lines_count === 0" class="workflow-status state-warning" title="Այս գրառման սկզբնական մնացորդները պահպանված չեն։ Սկսեք նոր գույքագրում։">Տողերը բացակայում են</span><button v-if="row.status==='counted' && row.lines_count > 0 && can('inventory.approve')" class="primary-button compact-action" @click="selected=row;dialog='approve';error=''">Անկախ հաստատել</button><RouterLink v-if="row.status==='closed' && can('inventory.view')" class="secondary-button compact-action" :to="`/inventory/${row.id}/act`">Տպել ակտը</RouterLink></div></td></tr>
      <tr v-if="!busy && result && !rows.length"><td colspan="7" class="table-empty">Գույքագրման գրառումներ չկան։ Սկսեք առաջին գույքագրումը։</td></tr><tr v-if="busy && !result"><td colspan="7" class="table-empty">Բեռնվում է…</td></tr>
    </tbody></table></div><Pagination v-if="result" :pagination="result.pagination" :busy="busy" @page-change="load" @per-page-change="load(1, $event)" /></section>

  <div v-if="dialog==='start'" class="modal-backdrop" @click.self="dialog=''"><form class="modal-card" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">ԳՈՒՅՔԱԳՐՄԱՆ ՍԿԻԶԲ</p><h2>Սկսել նոր գույքագրում</h2><p>Մնացորդները կֆիքսվեն հենց այս պահին՝ LOT առ LOT։</p></div><button type="button" class="icon-button close-button" @click="dialog=''"><AppIcon name="xmark" /></button></div><div class="form-grid"><label class="form-field span-2">Պահեստ *<select v-searchable-select v-model="start.location_id" class="form-control" required><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }}</option></select></label><label class="form-field span-2">Նշում<textarea v-model.trim="start.note" class="form-control"></textarea></label></div><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="dialog=''">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Սկսվում է…' : 'Սկսել գույքագրումը' }}</button></div></form></div>

  <div v-if="dialog==='count' && selected" class="modal-backdrop" @click.self="dialog=''"><form class="modal-card inventory-modal" @submit.prevent="save"><div class="modal-header"><div><p class="eyebrow">{{ selected.inventory_no }}</p><h2>Փաստացի հաշվարկ</h2><p>Յուրաքանչյուր տողի տարբերության դեպքում նշեք պատճառը։ Նոր LOT-ի տվյալները լրացվում են, եթե ապրանքի հաշվառված քանակը զրո է։</p></div><button type="button" class="icon-button close-button" @click="dialog=''"><AppIcon name="xmark" /></button></div><div v-if="!selected.lines?.length" class="inventory-empty-state" role="alert"><span class="metric-icon amber"><AppIcon name="alert" /></span><div><strong>Այս գույքագրումը տողեր չունի</strong><p>Սկզբնական մնացորդները չեն պահպանվել, ուստի դրանց հիման վրա հաշվարկ ներկայացնել հնարավոր չէ։ Փակեք այս պատուհանը և սկսեք նոր գույքագրում։</p></div></div><div v-else class="inventory-count-content"><div class="inventory-count-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model.trim="lineSearch" class="form-control" placeholder="Որոնել ապրանքով, կոդով կամ LOT-ով…"></label><span class="list-count">Գտնվել է <b>{{ visibleLines.length }}</b> / {{ selected.lines.length }} տող</span></div><div class="inventory-lines"><article v-for="line in visibleLines" :key="line.id" class="inventory-line"><div class="inventory-product"><strong>{{ line.product.code }} · {{ line.product.name }}</strong><small>LOT {{ line.lot?.lot_no || '—' }} · Հաշվառված՝ {{ line.expected_qty }} {{ line.product.unit }}</small></div><label class="form-field">Փաստացի քանակ *<input v-model="counts[line.id].counted_qty" class="form-control" type="number" min="0" step="0.001" required></label><label class="form-field">Տարբերության պատճառ<input v-model.trim="counts[line.id].reason" class="form-control" placeholder="Պարտադիր է, եթե կա տարբերություն"></label>
      <div v-if="!line.lot_id" class="inventory-new-lot"><strong>Նոր LOT-ի տվյալներ</strong><label class="form-field">LOT համար<input v-model.trim="counts[line.id].lot_no" class="form-control" maxlength="100"></label><label class="form-field">Պիտանի է մինչև<DatePicker v-model="counts[line.id].expires_on" /></label><label class="form-field">Մատակարար<select v-searchable-select v-model="counts[line.id].supplier_id" class="form-control"><option value="">Նշված չէ</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></label><label v-if="can('purchases.view')" class="form-field">Միավորի արժեք<input v-model="counts[line.id].unit_cost" class="form-control" type="number" min="0" step="0.01"></label><label class="form-field">Պահեստային տեղ<input v-model.trim="counts[line.id].bin_location" class="form-control"></label></div>
    </article><div v-if="!visibleLines.length" class="table-empty">Որոնմամբ համապատասխան տող չի գտնվել։</div></div></div><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="dialog=''">Փակել</button><button class="primary-button" :disabled="saving || !selected.lines?.length">{{ saving ? 'Պահպանվում է…' : 'Ներկայացնել հաստատման' }}</button></div></form></div>

  <div v-if="dialog==='approve' && selected" class="modal-backdrop" @click.self="dialog=''"><section class="modal-card confirm-card"><div class="metric-icon amber"><AppIcon name="alert" /></div><h2>Անկախ հաստատե՞լ գույքագրումը</h2><p><b>{{ selected.inventory_no }}</b></p><p class="muted">Հաստատողը պետք է տարբերվի գույքագրումը սկսած աշխատակցից։ Հաստատման ժամանակ մնացորդները կրկին կհամեմատվեն մեկնարկային վիճակի հետ։</p><p v-if="error" class="form-error">{{ error }}</p><div class="modal-actions"><button class="secondary-button" @click="dialog=''">Չեղարկել</button><button class="primary-button" :disabled="saving" @click="approve">{{ saving ? 'Հաստատվում է…' : 'Հաստատել և փակել' }}</button></div></section></div>
</template>
