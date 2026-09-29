<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';

const route = useRoute();
const result = ref(null);
const busy = ref(false);
const error = ref('');
const search = ref('');
let timer;
const page = computed(() => Number(route.params.page || route.path.slice(1)) || 1);
const title = computed(() => route.meta.title || 'Տվյալներ');
const endpoint = computed(() => `pages/${route.path.slice(1)}`);

async function load(pageNo = 1) {
    busy.value = true;
    error.value = '';
    try {
        const response = await api.get(endpoint.value, { params: { page: pageNo, search: search.value || undefined } });
        result.value = response.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'Տվյալները չհաջողվեց բեռնել։';
    } finally { busy.value = false; }
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); });
watch(endpoint, () => { search.value = ''; result.value = null; load(1); });
onMounted(() => load());

function display(value, key) {
    if (value === null || value === undefined || value === '') return '—';
    if (key === 'active') return Number(value) ? 'Ակտիվ' : 'Ապաակտիվ';
    if (['quantity', 'qty', 'min_qty', 'stock_value', 'unit_cost'].includes(key) && !Number.isNaN(Number(value))) {
        return new Intl.NumberFormat('hy-AM', { maximumFractionDigits: 3 }).format(Number(value));
    }
    return String(value);
}
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ՊԱՀԵՍՏԱՅԻՆ ՀԱՄԱԿԱՐԳ</p><h1>{{ title }}</h1><p class="muted">Որոնեք և դիտեք հասանելի գրառումները՝ ըստ ձեր պահեստի և իրավասությունների։</p></div></div>
    <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
    <section class="table-card">
        <div class="table-toolbar"><label class="search-input"><span>⌕</span><input v-model="search" class="form-control" placeholder="Որոնել ցանկում…"></label><div class="list-count">Ընդամենը՝ <b>{{ result?.pagination.total ?? '…' }}</b></div></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th v-for="(label, key) in result?.columns || {}" :key="key">{{ label }}</th></tr></thead><tbody>
            <tr v-for="row in result?.data || []" :key="row.id || row.movement_no || row.request_no || row.receipt_no || row.inventory_no"><td v-for="(label, key) in result?.columns || {}" :key="key"><span v-if="key === 'active'" class="status-pill" :class="{ inactive: !Number(row[key]) }">{{ display(row[key], key) }}</span><strong v-else-if="key === 'name' || key.endsWith('_no')">{{ display(row[key], key) }}</strong><span v-else>{{ display(row[key], key) }}</span></td></tr>
            <tr v-if="!busy && result && !result.data.length"><td :colspan="Object.keys(result.columns).length" class="table-empty">{{ search ? 'Որոնմանը համապատասխան գրառում չկա։' : 'Գրառումներ դեռ չկան։' }}</td></tr>
            <tr v-if="busy && !result"><td colspan="8" class="table-empty">Բեռնվում է…</td></tr>
        </tbody></table></div>
        <div v-if="result" class="pagination"><span>Ընդամենը՝ {{ result.pagination.total }} գրառում</span><div class="pagination-controls"><button :disabled="result.pagination.current_page <= 1 || busy" @click="load(result.pagination.current_page - 1)">← Նախորդ</button><span>Էջ {{ result.pagination.current_page }} / {{ result.pagination.last_page }}</span><button :disabled="result.pagination.current_page >= result.pagination.last_page || busy" @click="load(result.pagination.current_page + 1)">Հաջորդ →</button></div></div>
    </section>
</template>
