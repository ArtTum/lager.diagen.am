<script setup>
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import Pagination from '@/components/Pagination.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import ExportActions from '@/components/ExportActions.vue';
import { formatDisplayDate, isDateValue } from '@/dateUtils';
import StatusBadge from '@/components/StatusBadge.vue';

const route = useRoute();
const result = ref(null);
const busy = ref(false);
const error = ref('');
const search = ref('');
let timer;
let listRequestVersion = 0;
const page = computed(() => Number(route.params.page || route.path.slice(1)) || 1);
const title = computed(() => route.meta.title || 'Տվյալներ');
const endpoint = computed(() => `pages/${route.path.slice(1)}`);

async function load(pageNo = 1, pageSize = result.value?.pagination.per_page || 15) {
    const version = ++listRequestVersion;
    busy.value = true;
    error.value = '';
    try {
        const response = await api.get(endpoint.value, { params: { page: pageNo, per_page: pageSize, search: search.value || undefined } });
        if (version === listRequestVersion) result.value = response.data;
    } catch (e) {
        if (version === listRequestVersion) error.value = e.response?.data?.message || 'Տվյալները չհաջողվեց բեռնել։';
    } finally { if (version === listRequestVersion) busy.value = false; }
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); });
watch(endpoint, () => { search.value = ''; result.value = null; load(1); });
onMounted(() => load());
onBeforeUnmount(() => { listRequestVersion += 1; clearTimeout(timer); });

function display(value, key) {
    if (value === null || value === undefined || value === '') return '—';
    if (/(?:_at|_on|_date)$/.test(key) || key === 'date' || isDateValue(value)) return formatDisplayDate(value);
    if (['quantity', 'qty', 'min_qty', 'stock_value', 'unit_cost'].includes(key) && !Number.isNaN(Number(value))) {
        return new Intl.NumberFormat('hy-AM', { maximumFractionDigits: 3 }).format(Number(value));
    }
    return String(value);
}
useLiveRefresh(() => load(result.value?.pagination.current_page || 1), { isBusy: busy });
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ՊԱՀԵՍՏԱՅԻՆ ՀԱՄԱԿԱՐԳ</p><h1>{{ title }}</h1><p class="muted">Որոնեք և դիտեք հասանելի գրառումները՝ ըստ ձեր պահեստի և իրավասությունների։</p></div></div>
    <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
    <section class="table-card">
        <div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Որոնել ցանկում…"></label><div class="list-count">Ընդամենը՝ <b>{{ result?.pagination.total ?? '…' }}</b></div><ExportActions :page="route.path.slice(1)" :search="search" :disabled="busy" /></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th v-for="(label, key) in result?.columns || {}" :key="key">{{ label }}</th></tr></thead><tbody>
            <tr v-for="row in result?.data || []" :key="row.id || row.movement_no || row.request_no || row.receipt_no || row.inventory_no"><td v-for="(label, key) in result?.columns || {}" :key="key"><StatusBadge v-if="key === 'active'" workflow="activity" :status="Number(row[key]) ? 'active' : 'inactive'" :show-description="false" /><StatusBadge v-else-if="key === 'status'" :workflow="route.path.slice(1)" :status="row[key]" /><strong v-else-if="key === 'name' || key.endsWith('_no')">{{ display(row[key], key) }}</strong><span v-else>{{ display(row[key], key) }}</span></td></tr>
            <tr v-if="!busy && result && !result.data.length"><td :colspan="Object.keys(result.columns).length" class="table-empty">{{ search ? 'Որոնմանը համապատասխան գրառում չկա։' : 'Գրառումներ դեռ չկան։' }}</td></tr>
            <tr v-if="busy && !result"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
        </tbody></table></div>
        <Pagination v-if="result" :pagination="result.pagination" :busy="busy" @page-change="load" @per-page-change="load(1, $event)" />
    </section>
</template>
