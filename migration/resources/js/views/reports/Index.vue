<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';

const route = useRoute();
const user = ref(currentUser());
const today = new Date();
const dateInput = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const filters = reactive({
  report_type: 'stock_by_location',
  from: dateInput(new Date(today.getFullYear(), today.getMonth(), 1)),
  to: dateInput(today),
  branch_id: '',
  product_id: '',
  supplier_id: '',
  category_id: '',
  lot_no: '',
  movement_type: '',
  actor_id: '',
});
const result = ref(null);
const loading = ref(false);
const exporting = ref(false);
const error = ref('');
const page = ref(1);

const rows = computed(() => result.value?.data || []);
const columns = computed(() => Object.entries(result.value?.columns || {}));
const reportTypes = computed(() => Object.entries(result.value?.report_types || {}));
const filterOptions = computed(() => result.value?.filters || {});
const isCentralUser = computed(() => Number(user.value?.location_id || 0) === 0);
const canSeeCosts = computed(() => Boolean(user.value?.permissions?.['purchases.view']));
const canExport = computed(() => Boolean(user.value?.permissions?.['reports.export']));
const selectedReport = computed(() => result.value?.report_types?.[filters.report_type] || 'Հաշվետվություն');
const showBranchFilter = computed(() => isCentralUser.value && ['stock_by_location', 'branch_stock', 'low_stock', 'item_value', 'receipts', 'issues', 'movements', 'product_movement', 'branch_expense', 'expired_lots', 'near_expiry', 'returns', 'inventory_differences', 'branch_requests', 'rejected_requests'].includes(filters.report_type));
const showSupplierFilter = computed(() => Boolean(filterOptions.value.suppliers) && ['stock_by_location', 'branch_stock', 'low_stock', 'item_value', 'receipts', 'issues', 'movements', 'product_movement', 'branch_expense', 'supplier_purchases', 'purchases_by_period', 'expired_lots', 'near_expiry', 'returns'].includes(filters.report_type));
const showLotFilter = computed(() => ['receipts', 'issues', 'movements', 'product_movement', 'expired_lots', 'near_expiry'].includes(filters.report_type));
const showMovementTypeFilter = computed(() => ['issues', 'movements', 'product_movement'].includes(filters.report_type));
const showActorFilter = computed(() => ['issues', 'movements', 'product_movement'].includes(filters.report_type));
const showCategoryFilter = computed(() => ['stock_by_location', 'central_stock', 'branch_stock', 'item_value', 'low_stock', 'issues', 'movements', 'product_movement'].includes(filters.report_type));
const showDateFilter = computed(() => !['stock_by_location', 'central_stock', 'branch_stock', 'item_value', 'low_stock', 'expired_lots', 'near_expiry', 'average_usage'].includes(filters.report_type));
const costColumns = ['value', 'unit_cost', 'average_unit_cost', 'used_cost'];

async function load(nextPage = 1) {
  loading.value = true;
  error.value = '';

  try {
    const response = await api.get('reports', {
      params: {
        ...filters,
        branch_id: showBranchFilter.value ? filters.branch_id : '',
        page: nextPage,
      },
    });
    result.value = response.data;
    page.value = nextPage;
  } catch (exception) {
    error.value = exception.response?.data?.message || 'Հաշվետվությունը չհաջողվեց բեռնել։';
  } finally {
    loading.value = false;
  }
}

async function exportReport(format) {
  exporting.value = true;
  error.value = '';

  try {
    const response = await api.get('reports/export', {
      params: {
        ...filters,
        branch_id: showBranchFilter.value ? filters.branch_id : '',
        format,
      },
      responseType: 'blob',
    });
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `diagen-${filters.report_type}.${format}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (exception) {
    error.value = exception.response?.data?.message || `${format.toUpperCase()} ֆայլը չհաջողվեց ներբեռնել։`;
  } finally {
    exporting.value = false;
  }
}

function printReport() {
  window.print();
}

function formatCell(key, value) {
  if (value === null || value === undefined || value === '') return '—';
  if (costColumns.includes(key)) return `${Number(value || 0).toLocaleString('hy-AM')} ֏`;
  return value;
}

watch(() => filters.report_type, () => {
  filters.supplier_id = '';
  filters.category_id = '';
  filters.product_id = '';
  filters.lot_no = '';
  filters.movement_type = '';
  filters.actor_id = '';
  load(1);
});

const onUserChange = (event) => {
  user.value = event.detail;
  load(1);
};

onMounted(() => {
  user.value = currentUser();
  load();
  window.addEventListener('lager:user', onUserChange);
});

onBeforeUnmount(() => window.removeEventListener('lager:user', onUserChange));
</script>

<template>
  <div class="reports-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">ՊԱՀԵՍՏԱՅԻՆ ՎԵՐԼՈՒԾՈՒԹՅՈՒՆ</p>
        <h1>{{ route.meta.title }}</h1>
        <p class="muted">Պաշարների, մուտքերի, ելքերի, գնումների և մասնաճյուղերի գործընթացների հաշվետվություններ։</p>
      </div>
    </div>

    <div v-if="error" class="alert-error" role="alert">{{ error }}</div>

    <section class="report-summary-grid">
      <article class="report-summary-card"><span class="metric-icon blue"><AppIcon name="boxes" /></span><div><strong>{{ result?.summary?.quantity ?? '—' }}</strong><small>Պաշար՝ միավոր</small></div></article>
      <article v-if="canSeeCosts" class="report-summary-card"><span class="metric-icon violet">֏</span><div><strong>{{ Number(result?.summary?.value || 0).toLocaleString('hy-AM') }} ֏</strong><small>Պաշարի արժեք</small></div></article>
      <article class="report-summary-card"><span class="metric-icon amber">!</span><div><strong>{{ result?.summary?.below_minimum ?? '—' }}</strong><small>Նվազագույնից ցածր</small></div></article>
      <article class="report-summary-card"><span class="metric-icon green"><AppIcon name="plusFile" /></span><div><strong>{{ result?.summary?.open_requests ?? '—' }}</strong><small>Բաց պահանջագիր</small></div></article>
    </section>

    <section class="table-card report-results-card">
      <header class="report-section-head">
        <div><h2>{{ selectedReport }}</h2><p class="muted">Տվյալները սահմանափակվում են ձեր դերով և պահեստի հասանելիությամբ։</p></div>
        <div v-if="canExport" class="report-export-actions">
          <button class="secondary-button" :disabled="exporting || loading" @click="exportReport('csv')">Ներբեռնել CSV</button>
          <button class="secondary-button" :disabled="exporting || loading" @click="exportReport('xlsx')">Ներբեռնել Excel</button>
          <button class="secondary-button" :disabled="loading" @click="printReport">Տպել / PDF</button>
        </div>
      </header>

      <form class="report-filter-grid" @submit.prevent="load(1)">
        <label class="form-field">Հաշվետվություն
          <select v-model="filters.report_type" class="form-control">
            <option v-for="[key, title] in reportTypes" :key="key" :value="key">{{ title }}</option>
          </select>
        </label>
        <label v-if="showDateFilter" class="form-field">Սկսած<input v-model="filters.from" class="form-control" type="date"></label>
        <label v-if="showDateFilter" class="form-field">Մինչև<input v-model="filters.to" class="form-control" type="date"></label>
        <label v-if="showBranchFilter" class="form-field">Մասնաճյուղ
          <select v-model="filters.branch_id" class="form-control"><option value="">Բոլոր պահեստները</option><option value="0">Կենտրոնական պահեստ</option><option v-for="branch in filterOptions.branches || []" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select>
        </label>
        <label v-if="!['supplier_purchases', 'purchases_by_period'].includes(filters.report_type)" class="form-field">Ապրանք
          <select v-model="filters.product_id" class="form-control"><option value="">Բոլոր ապրանքները</option><option v-for="product in filterOptions.products || []" :key="product.id" :value="product.id">{{ product.code }} · {{ product.name }}</option></select>
        </label>
        <label v-if="showSupplierFilter" class="form-field">Մատակարար
          <select v-model="filters.supplier_id" class="form-control"><option value="">Բոլոր մատակարարները</option><option v-for="supplier in filterOptions.suppliers || []" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select>
        </label>
        <label v-if="showLotFilter" class="form-field">LOT<input v-model.trim="filters.lot_no" class="form-control" maxlength="100" placeholder="Որոնել LOT-ով"></label>
        <label v-if="showMovementTypeFilter" class="form-field">Գործողության տեսակ
          <select v-model="filters.movement_type" class="form-control"><option value="">Բոլոր գործողությունները</option><option v-for="type in filterOptions.movement_types || []" :key="type" :value="type">{{ type }}</option></select>
        </label>
        <label v-if="showActorFilter" class="form-field">Աշխատակից
          <select v-model="filters.actor_id" class="form-control"><option value="">Բոլոր աշխատակիցները</option><option v-for="actor in filterOptions.actors || []" :key="actor.id" :value="actor.id">{{ actor.name }}</option></select>
        </label>
        <label v-if="showCategoryFilter" class="form-field">Ապրանքային խումբ
          <select v-model="filters.category_id" class="form-control"><option value="">Բոլոր խմբերը</option><option v-for="category in filterOptions.categories || []" :key="category.id" :value="category.id">{{ category.name }}</option></select>
        </label>
        <button class="primary-button report-apply" :disabled="loading">{{ loading ? 'Բեռնվում է…' : 'Կիրառել ֆիլտրերը' }}</button>
      </form>

      <div class="table-toolbar"><span class="list-count">Գրառումներ՝ <b>{{ result?.pagination.total ?? '—' }}</b></span></div>
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th v-for="[key, label] in columns" :key="key">{{ label }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, index) in rows" :key="`${row.document_no || row.code}-${row.branch_name || row.supplier || ''}-${index}`">
              <td v-for="[key] in columns" :key="key">{{ formatCell(key, row[key]) }}</td>
            </tr>
            <tr v-if="!loading && result && !rows.length"><td :colspan="Math.max(columns.length, 1)" class="table-empty">Ընտրված պայմաններին համապատասխան տվյալ չկա։</td></tr>
            <tr v-if="loading && !result"><td :colspan="Math.max(columns.length, 1)" class="table-empty">Հաշվետվությունը բեռնվում է…</td></tr>
          </tbody>
        </table>
      </div>
      <footer v-if="result" class="pagination"><span>Էջ {{ result.pagination.current_page }} / {{ result.pagination.last_page }}</span><div class="pagination-controls"><button :disabled="page <= 1 || loading" @click="load(page - 1)">Նախորդ</button><span>{{ result.pagination.total }} արդյունք</span><button :disabled="page >= result.pagination.last_page || loading" @click="load(page + 1)">Հաջորդ</button></div></footer>
    </section>
  </div>
</template>
