<script setup>
import Pagination from '@/components/Pagination.vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import ListFilterBar from '@/components/ListFilterBar.vue';
import { formatDisplayDate, isDateValue } from '@/dateUtils';

const route = useRoute();
const result = ref(null);
const busy = ref(false);
const error = ref('');
const search = ref('');
const expanded = ref(null);
const filters = reactive({ from: '', to: '' });
let timer;
const rows = computed(() => result.value?.data || []);

const entityLabels = {
  audit_logs: 'Գործողությունների պատմություն', branches: 'Պահեստ կամ մասնաճյուղ', categories: 'Ապրանքային խումբ',
  inventory_sessions: 'Գույքագրում', movements: 'Պահեստային շարժ', products: 'Ապրանք', purchase_orders: 'Գնման պատվեր',
  receipts: 'Ապրանքի մուտք', returns: 'Վերադարձ', roles: 'Դեր և իրավունքներ', stock_lots: 'Ապրանքի խմբաքանակ (LOT)',
  stock_requests: 'Պահանջագիր', suppliers: 'Մատակարար', transfers: 'Պահեստների տեղափոխում', users: 'Աշխատակից',
};
const fieldLabels = {
  active: 'Գործունեության կարգավիճակ', address: 'Հասցե', actor_id: 'Կատարող', approved_qty: 'Հաստատված քանակ',
  branch_id: 'Մասնաճյուղ', category_id: 'Ապրանքային խումբ', code: 'Կոդ', contact_name: 'Կոնտակտային անձ',
  contract_end: 'Պայմանագրի ավարտ', contract_no: 'Պայմանագրի համար', contract_start: 'Պայմանագրի սկիզբ',
  counted_qty: 'Հաշվարկված քանակ', created_at: 'Գրանցման ամսաթիվ', delivery_days: 'Առաքման ժամկետ',
  description: 'Նկարագրություն', difference: 'Տարբերություն', difference_reason: 'Տարբերության պատճառ', direction: 'Ուղղություն',
  email: 'Էլ. փոստ', expires_on: 'Պիտանի է մինչև', expiry_control: 'Ժամկետի վերահսկում', from: 'Ումից',
  from_branch: 'Ուղարկող պահեստ', from_location: 'Ուղարկող պահեստ', issue_type: 'Ելքի պատճառ', items: 'Ապրանքներ',
  inventory_no: 'Գույքագրման համար', line_count: 'Ապրանքների տողերի քանակ', location_id: 'Պահեստ', lot_no: 'LOT համար',
  max_qty: 'Առավելագույն մնացորդ', min_qty: 'Նվազագույն մնացորդ', movement_no: 'Շարժի համար', name: 'Անվանում',
  optimal_qty: 'Նպատակային մնացորդ', parent_id: 'Վերադաս ապրանքային խումբ', payment_terms: 'Վճարման պայմաններ',
  permissions: 'Դերի իրավունքներ', phone: 'Հեռախոս', product_id: 'Ապրանք', purchase_price: 'Գնման գին',
  quantity: 'Քանակ', qty: 'Քանակ', reason: 'Պատճառ', rejection_reason: 'Մերժման պատճառ', request_no: 'Պահանջագրի համար',
  return_no: 'Վերադարձի համար', role: 'Դեր', status: 'Կարգավիճակ', supplier_id: 'Մատակարար', tax_id: 'ՀՎՀՀ',
  title: 'Դերի անվանում', to: 'Ուր', to_branch: 'Ստացող պահեստ', to_location: 'Ստացող պահեստ', total_qty: 'Ընդհանուր քանակ',
  transfer_no: 'Տեղափոխման համար', unit: 'Չափման միավոր', unit_cost: 'Միավորի արժեք', urgency: 'Հրատապություն',
};
const statusLabels = {
  approved: 'Հաստատված', cancelled: 'Չեղարկված', closed: 'Փակված', collecting: 'Հավաքագրվում է', completed: 'Ավարտված', counted: 'Հաշվարկված',
  draft: 'Սևագիր', open: 'Բաց', partially_approved: 'Մասնակի հաստատված', pending: 'Սպասում է հաստատման', received: 'Ստացված',
  rejected: 'Մերժված', review: 'Ստուգման փուլում', sent: 'Ուղարկված', shipped: 'Առաքված',
};
const moduleLabels = {
  audit: 'Գործողությունների պատմություն', branches: 'Պահեստներ և մասնաճյուղեր', dashboard: 'Գլխավոր վահանակ', expiry: 'Ժամկետների վերահսկում',
  inventory: 'Գույքագրում', movements: 'Պահեստի շարժ', notifications: 'Ծանուցումներ', products: 'Ապրանքներ', purchases: 'Գնումներ',
  receipts: 'Ապրանքի մուտքեր', reports: 'Հաշվետվություններ', requests: 'Պահանջագրեր', returns: 'Վերադարձներ', roles: 'Դերեր և իրավունքներ',
  stock: 'Պահեստի մնացորդներ', suppliers: 'Մատակարարներ', transfers: 'Տեղափոխումներ', users: 'Աշխատակիցներ',
};
const permissionActionLabels = { approve: 'Հաստատել', create: 'Ստեղծել', delete: 'Ջնջել', edit: 'Փոփոխել', export: 'Արտահանել', view: 'Դիտել' };

async function load(page = 1, pageSize = result.value?.pagination.per_page || 15) {
  busy.value = true;
  error.value = '';
  try {
    const response = await api.get('pages/audit', { params: { ...filters, page, per_page: pageSize, search: search.value || undefined } });
    result.value = response.data;
  } catch (exception) {
    error.value = exception.response?.data?.message || 'Գործողությունների պատմությունը չհաջողվեց բեռնել։';
  } finally {
    busy.value = false;
  }
}

function resetFilters() {
  Object.assign(filters, { from: '', to: '' });
  load(1);
}

function parseData(value) {
  if (value === null || value === undefined || value === '') return null;
  if (typeof value === 'string') {
    try { return JSON.parse(value); } catch { return { description: value }; }
  }
  return value;
}

function sameValue(left, right) {
  return JSON.stringify(left) === JSON.stringify(right);
}

function fieldLabel(key) {
  return fieldLabels[key] || key.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

function permissionLabel(code) {
  const [module, action, ...rest] = String(code).split('.');
  if (!action) return String(code);
  return `${moduleLabels[module] || module} — ${permissionActionLabels[action] || action}${rest.length ? ` (${rest.join('.')})` : ''}`;
}

function displayValue(value, key = '') {
  if (value === null || value === undefined || value === '') return 'Նշված չէ';
  if (key === 'active') return value ? 'Ակտիվ' : 'Ապաակտիվ';
  if (typeof value === 'boolean') return value ? 'Այո' : 'Ոչ';
  if (key === 'status') return statusLabels[value] || value;
  if (key === 'urgency') return ({ normal: 'Սովորական', high: 'Բարձր', urgent: 'Շտապ' })[value] || value;
  if (key === 'direction') return ({ supplier: 'Դեպի մատակարար', branch: 'Դեպի մասնաճյուղ', central: 'Դեպի կենտրոնական պահեստ' })[value] || value;
  if (key === 'issue_type') return ({ consumption: 'Օգտագործում', damage: 'Վնասվածք', expired: 'Ժամկետանց', other: 'Այլ պատճառ' })[value] || value;
  if (key === 'location_id') return Number(value) === 0 ? 'Կենտրոնական պահեստ' : `Մասնաճյուղ №${value}`;
  if (isDateValue(value)) return formatDisplayDate(value);
  if (typeof value === 'number') {
    if (key === 'line_count') return `${value} ապրանքային տող`;
    return Number(value).toLocaleString('hy-AM', { maximumFractionDigits: 3 });
  }
  if (Array.isArray(value)) return `${value.length} գրառում`;
  if (typeof value === 'object') return `${Object.keys(value).length} դաշտ`;
  return String(value);
}

function auditChanges(row) {
  const before = parseData(row.before_data);
  const after = parseData(row.after_data);
  const beforeObject = before && typeof before === 'object' && !Array.isArray(before) ? before : {};
  const afterObject = after && typeof after === 'object' && !Array.isArray(after) ? after : {};
  const keys = [...new Set([...Object.keys(beforeObject), ...Object.keys(afterObject)])].filter((key) => key !== 'permissions');
  const changes = keys.filter((key) => !sameValue(beforeObject[key], afterObject[key])).map((key) => ({
    label: fieldLabel(key), before: displayValue(beforeObject[key], key), after: displayValue(afterObject[key], key),
  }));
  const beforePermissions = Array.isArray(before) ? before : Array.isArray(beforeObject.permissions) ? beforeObject.permissions : [];
  const afterPermissions = Array.isArray(after) ? after : Array.isArray(afterObject.permissions) ? afterObject.permissions : [];
  const beforeSet = new Set(beforePermissions);
  const afterSet = new Set(afterPermissions);

  return {
    changes,
    addedPermissions: [...afterSet].filter((permission) => !beforeSet.has(permission)).map(permissionLabel),
    removedPermissions: [...beforeSet].filter((permission) => !afterSet.has(permission)).map(permissionLabel),
    hasRecordedData: Boolean(before || after),
  };
}

function entityLabel(entity) {
  return entityLabels[entity] || String(entity || 'Գրառում').replaceAll('_', ' ');
}

function actionLabel(row) {
  const contextualLabels = {
    'Ապաակտիվացում': 'Գրառումն ապաակտիվացվեց',
    'Փոփոխություն': 'Գրառման տվյալները փոփոխվեցին',
    'Ստեղծում': 'Նոր գրառում ստեղծվեց',
  };
  return contextualLabels[row.action] || row.action || 'Գործողություն է կատարվել';
}

watch(search, () => {
  clearTimeout(timer);
  timer = setTimeout(() => load(1), 250);
});
onMounted(() => load());
</script>

<template>
  <div class="page-heading">
    <div><p class="eyebrow">ԳՈՐԾՈՂՈՒԹՅՈՒՆՆԵՐԻ ՊԱՏՄՈՒԹՅՈՒՆ</p><h1>{{ route.meta.title }}</h1><p class="muted">Տեսեք՝ ով, երբ և ինչ փոփոխություն է կատարել համակարգում։</p></div>
  </div>
  <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
  <section class="table-card audit-card">
    <div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model.trim="search" class="form-control" placeholder="Որոնել աշխատակցով, գործողությամբ կամ գրառմամբ"></label><span class="list-count">Գրանցումներ՝ <b>{{ result?.pagination.total ?? '…' }}</b></span></div>
    <ListFilterBar :model-value="filters" @change="filters[$event.key] = $event.value" :date-range="true" @apply="load(1)" @reset="resetFilters" />
    <div class="table-scroll"><table class="data-table audit-table">
      <thead><tr><th>Ամսաթիվ և ժամ</th><th>Աշխատակից</th><th>Կատարված գործողություն</th><th>Գրառում</th><th>Մանրամասներ</th></tr></thead>
      <tbody>
        <template v-for="row in rows" :key="row.id">
          <tr>
            <td>{{ formatDisplayDate(row.created_at) }}</td>
            <td><strong>{{ row.actor || 'Համակարգ' }}</strong></td>
            <td><strong class="audit-action">{{ actionLabel(row) }}</strong></td>
            <td><span class="audit-entity">{{ entityLabel(row.entity) }}</span><small class="cell-subtitle">Գրանցում №{{ row.entity_id ?? '—' }}</small></td>
            <td><button class="secondary-button compact-action" :aria-expanded="expanded === row.id" @click="expanded = expanded === row.id ? null : row.id">{{ expanded === row.id ? 'Փակել մանրամասները' : 'Տեսնել փոփոխությունը' }}</button></td>
          </tr>
          <tr v-if="expanded === row.id" class="audit-detail-row"><td colspan="5">
            <div class="audit-detail-panel">
              <header><div><span class="eyebrow">ՓՈՓՈԽՈՒԹՅԱՆ ՄԱՆՐԱՄԱՍՆԵՐ</span><h2>{{ actionLabel(row) }}</h2><p>{{ entityLabel(row.entity) }} · Գրանցում №{{ row.entity_id ?? '—' }}</p></div><span class="audit-detail-date">{{ formatDisplayDate(row.created_at) }}</span></header>
              <div v-if="auditChanges(row).changes.length" class="audit-change-list">
                <div class="audit-change-head"><span>Ինչ տվյալ է փոխվել</span><span>Նախկին արժեք</span><span>Նոր արժեք</span></div>
                <div v-for="change in auditChanges(row).changes" :key="change.label" class="audit-change-row"><strong>{{ change.label }}</strong><span>{{ change.before }}</span><span>{{ change.after }}</span></div>
              </div>
              <div v-if="auditChanges(row).addedPermissions.length || auditChanges(row).removedPermissions.length" class="audit-permission-changes">
                <section v-if="auditChanges(row).addedPermissions.length"><h3>Ավելացված իրավունքներ</h3><ul><li v-for="permission in auditChanges(row).addedPermissions" :key="permission">{{ permission }}</li></ul></section>
                <section v-if="auditChanges(row).removedPermissions.length"><h3>Հեռացված իրավունքներ</h3><ul><li v-for="permission in auditChanges(row).removedPermissions" :key="permission">{{ permission }}</li></ul></section>
              </div>
              <p v-if="!auditChanges(row).changes.length && !auditChanges(row).addedPermissions.length && !auditChanges(row).removedPermissions.length" class="audit-no-changes">{{ auditChanges(row).hasRecordedData ? 'Այս գործողության համար գրանցված տվյալների արժեքները չեն փոխվել։' : 'Այս գործողության համար փոփոխության լրացուցիչ տվյալներ չեն գրանցվել։' }}</p>
              <footer>Գրանցման հասցե՝ {{ row.ip_address || 'չի գրանցվել' }}</footer>
            </div>
          </td></tr>
        </template>
        <tr v-if="!busy && result && !rows.length"><td colspan="5" class="table-empty">{{ search ? 'Որոնմանը համապատասխան գրառում չկա։' : 'Գործողություններ դեռ գրանցված չեն։' }}</td></tr>
        <tr v-if="busy && !result"><td colspan="5" class="table-empty">Բեռնվում է…</td></tr>
      </tbody>
    </table></div>
    <Pagination v-if="result" :pagination="result.pagination" :busy="busy" @page-change="load" @per-page-change="load(1, $event)" />
  </section>
</template>
