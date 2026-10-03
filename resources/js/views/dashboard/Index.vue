<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { RouterLink } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';

const data = ref(null);
const user = ref(currentUser());
const error = ref('');
const locationOptions = ref([]);
const selectedLocationId = ref(null);
const selectedLocation = ref(null);
const canSelectLocation = ref(false);
const dateLabel = new Intl.DateTimeFormat('hy-AM', { dateStyle: 'long' }).format(new Date());
const can = (permission) => Boolean(user.value?.permissions?.[permission]);
const showLocationSelector = computed(() => canSelectLocation.value && can('branches.view') && Number(user.value?.location_id) === 0);
const selectedLocationName = computed(() => locationOptions.value.find((location) => Number(location.id) === selectedLocationId.value)?.name
    || selectedLocation.value?.name || user.value?.branch?.name || 'Կենտրոնական պահեստ');

let requestVersion = 0;
const loading = ref(false);
async function load() {
    const version = ++requestVersion;
    loading.value = true;
    error.value = '';
    try {
        const params = showLocationSelector.value && selectedLocationId.value !== null ? { branch_id: selectedLocationId.value } : {};
        const response = await api.get('dashboard', { params });
        if (version !== requestVersion) return;
        const snapshot = response.data.data;
        selectedLocation.value = snapshot.selected_location || null;
        selectedLocationId.value = snapshot.selected_location ? Number(snapshot.selected_location.id) : null;
        canSelectLocation.value = Boolean(snapshot.can_select_location);
        locationOptions.value = canSelectLocation.value && Array.isArray(snapshot.location_options) ? snapshot.location_options : [];
        data.value = snapshot;
    } catch (e) {
        if (version === requestVersion) {
            data.value = null;
            error.value = e.response?.data?.message || 'Վահանակի տվյալները չհաջողվեց բեռնել։';
        }
    }
    finally { if (version === requestVersion) loading.value = false; }
}
function changeLocation() {
    if (!showLocationSelector.value) return;
    data.value = null;
    load();
}
function userScope(value) {
    return JSON.stringify([value?.id, value?.location_id, value?.branch?.id,
        Object.keys(value?.permissions || {}).filter((permission) => Boolean(value.permissions[permission])).sort()]);
}
const onUserChange = (event) => {
    const scopeChanged = userScope(user.value) !== userScope(event.detail);
    user.value = event.detail;
    if (!scopeChanged) return;
    requestVersion += 1;
    data.value = null; error.value = ''; locationOptions.value = [];
    selectedLocationId.value = null; selectedLocation.value = null; canSelectLocation.value = false;
    if (can('dashboard.view')) load();
    else loading.value = false;
};
onMounted(() => { load(); window.addEventListener('lager:user', onUserChange); });
onBeforeUnmount(() => { requestVersion += 1; window.removeEventListener('lager:user', onUserChange); });
useLiveRefresh(load, { isBusy: loading });

const formatNumber = (value, maximumFractionDigits = 0) => Number(value || 0).toLocaleString('hy-AM', { maximumFractionDigits });
const cards = computed(() => [
    { label: 'Ապրանքային տեսակներ', key: 'products', hint: 'Ապրանքներ՝ ընտրված պահեստում', tone: 'blue', icon: 'box', permission: 'stock.view' },
    { label: 'Պահեստի միավորներ', key: 'units', hint: 'Ընդհանուր ընթացիկ մնացորդ', tone: 'green', icon: 'boxes', permission: 'stock.view', decimals: 3 },
    { label: 'Պաշարի արժեք', key: 'stock_value', hint: 'Ըստ գրանցված ինքնարժեքի', tone: 'violet', icon: 'chart', permission: 'purchases.view', suffix: ' ֏' },
    { label: 'Ցածր մնացորդ', key: 'low_stock_products', hint: 'MIN շեմից ցածր ապրանքներ', tone: 'amber', icon: 'trendDown', permission: 'stock.view', to: '/stock' },
    { label: 'Մոտ ժամկետանց LOT', key: 'expiring_lots', hint: 'Առաջիկա 90 օրվա ընթացքում', tone: 'rose', icon: 'clock', permission: 'expiry.view', to: '/expiry' },
    { label: 'Բաց պահանջագրեր', key: 'open_requests', hint: 'Ընթացքում գտնվող պահանջագրեր', tone: 'blue', icon: 'plusFile', permission: 'requests.view', to: '/requests' },
].filter((card) => can(card.permission) && data.value && Object.hasOwn(data.value, card.key)));

const secondaryMetrics = computed(() => [
    { label: 'Զրոյական մնացորդ', key: 'zero_stock_products', icon: 'box', tone: 'rose', permission: 'stock.view', to: '/stock' },
    { label: 'Ժամկետանց LOT', key: 'expired_lots', icon: 'alert', tone: 'amber', permission: 'expiry.view', to: '/expiry' },
    { label: 'Չհաստատված պահանջագրեր', key: 'unapproved_requests', icon: 'clipboard', tone: 'violet', permission: 'requests.view', to: '/requests' },
    { label: 'Սպասվող ընդունում', key: 'awaiting_receipt_requests', icon: 'arrowDown', tone: 'green', permission: 'requests.view', to: '/requests' },
].filter((metric) => can(metric.permission) && data.value && Object.hasOwn(data.value, metric.key)));

const activity = computed(() => [
    { label: 'Մուտքեր', key: 'receipts', icon: 'arrowDown', tone: 'green', permission: 'receipts.view' },
    { label: 'Ելքեր', key: 'issues', icon: 'trendUp', tone: 'amber', permission: 'movements.view' },
    { label: 'Վերադարձներ', key: 'returns', icon: 'returns', tone: 'violet', permission: 'returns.view' },
    { label: 'Տեղափոխումներ', key: 'transfers', icon: 'transfers', tone: 'blue', permission: 'transfers.view' },
].filter((item) => can(item.permission) && data.value?.today && Object.hasOwn(data.value.today, item.key)));

const maxActivity = computed(() => Math.max(1, ...activity.value.map((item) => Number(data.value?.today?.[item.key] || 0))));
const branches = computed(() => can('branches.view') ? data.value?.branches || [] : []);
const userName = computed(() => user.value?.name?.trim().split(/\s+/)[0] || 'բարի գալուստ');
const todayLabel = computed(() => new Intl.DateTimeFormat('hy-AM', { dateStyle: 'full' }).format(new Date()));
</script>

<template>
    <div v-if="showLocationSelector" class="dashboard-scope-controls">
        <div class="dashboard-scope-copy">
            <span class="dashboard-scope-icon"><AppIcon name="branches" /></span>
            <div>
                <label for="dashboard-location">Պահեստ / մասնաճյուղ</label>
                <p>Ցուցանիշները՝ ըստ ընտրված պահեստի</p>
            </div>
        </div>
        <select id="dashboard-location" v-model.number="selectedLocationId" v-searchable-select class="form-control dashboard-location-select" @change="changeLocation">
            <option v-for="location in locationOptions" :key="location.id" :value="location.id">{{ location.name }}</option>
        </select>
    </div>
    <div v-if="error" class="alert-error dashboard-error" role="alert"><span>{{ error }}</span><button class="secondary-button" type="button" :disabled="loading" @click="load">Կրկին փորձել</button></div>
    <p v-if="loading" class="dashboard-load-status" role="status">«{{ selectedLocationName }}» տվյալները բեռնվում են…</p>

    <section class="dashboard-hero" :aria-busy="loading">
        <div class="dashboard-hero-copy">
            <p class="dashboard-hero-kicker"><span></span> ԴԻԱԳԵՆ ՊԼՅՈՒՍ · ՊԱՀԵՍՏԻ ՎԵՐԱՀՍԿՈՒՄ</p>
            <h1>Բարի գալուստ, {{ userName }}</h1>
            <p class="dashboard-hero-description">Պահեստի ընթացիկ պատկերը և օրվա հիմնական գործողությունները՝ մեկ տեղում։</p>
            <div class="dashboard-hero-meta"><span><AppIcon name="clock" /> {{ todayLabel }}</span><span>{{ selectedLocationName }}</span></div>
        </div>
        <div class="dashboard-hero-panel">
            <div class="dashboard-hero-panel-top"><span>ՊԱՀԵՍՏԻ ՄՆԱՑՈՐԴ</span><AppIcon name="boxes" /></div>
            <template v-if="can('stock.view') && data">
                <strong>{{ formatNumber(data.units, 3) }}</strong>
                <small>ընդհանուր միավոր · {{ formatNumber(data.products) }} ապրանքատեսակ</small>
            </template>
            <div v-else class="dashboard-hero-loading">{{ loading ? 'Բեռնվում է…' : error ? 'Տվյալները չեն բեռնվել' : 'Տվյալը հասանելի չէ' }}</div>
            <div class="dashboard-hero-actions">
                <RouterLink v-if="can('requests.create')" to="/requests" class="dashboard-hero-action dashboard-hero-action-primary"><AppIcon name="plusFile" /> Նոր պահանջագիր</RouterLink>
                <RouterLink v-if="can('stock.view')" to="/stock" class="dashboard-hero-action"><AppIcon name="boxes" /> Բացել մնացորդները</RouterLink>
                <RouterLink v-else-if="can('reports.view')" to="/reports" class="dashboard-hero-action"><AppIcon name="chart" /> Բացել հաշվետվությունները</RouterLink>
            </div>
        </div>
        <div class="dashboard-hero-orbit" aria-hidden="true"></div>
    </section>

    <section class="dashboard-metrics" aria-label="Պահեստի հիմնական ցուցանիշներ" :aria-busy="loading">
        <article v-for="card in cards" :key="card.key" class="dashboard-metric-card" :class="`tone-${card.tone}`">
            <div class="dashboard-metric-accent"></div>
            <div class="dashboard-metric-top"><span class="dashboard-metric-icon"><AppIcon :name="card.icon" /></span><span class="dashboard-metric-caption">ԸՆԹԱՑԻԿ</span></div>
            <p>{{ card.label }}</p>
            <strong>{{ formatNumber(data[card.key], card.decimals) }}{{ card.suffix || '' }}</strong>
            <small>{{ card.hint }}</small>
            <RouterLink v-if="card.to" :to="card.to" class="dashboard-card-link" :aria-label="`${card.label} բաժինը բացել`"><AppIcon name="arrowRight" /></RouterLink>
        </article>
        <template v-if="!data && !error"><article v-for="item in 4" :key="item" class="dashboard-metric-skeleton" aria-hidden="true"><span></span><i></i><i></i></article></template>
        <article v-else-if="!cards.length && data && !error" class="dashboard-metric-placeholder"><AppIcon name="dashboard" /><span>Ձեր դերի համար ցուցանիշներ հասանելի չեն։</span></article>
    </section>

    <section v-if="secondaryMetrics.length" class="dashboard-secondary-metrics" aria-label="Լրացուցիչ ցուցանիշներ">
        <RouterLink v-for="metric in secondaryMetrics" :key="metric.key" :to="metric.to" class="dashboard-secondary-card" :class="`tone-${metric.tone}`">
            <span class="dashboard-secondary-icon"><AppIcon :name="metric.icon" /></span>
            <span class="dashboard-secondary-copy"><small>{{ metric.label }}</small><strong>{{ formatNumber(data[metric.key]) }}</strong></span>
            <AppIcon name="arrowRight" class="dashboard-secondary-arrow" />
        </RouterLink>
    </section>

    <section v-if="activity.length" class="dashboard-lower-grid">
        <article class="dashboard-panel dashboard-activity-chart">
            <header class="dashboard-panel-header"><div><p class="eyebrow">ՕՐՎԱ ԳՈՐԾՈՂՈՒԹՅՈՒՆՆԵՐ</p><h2>Այսօրվա շարժը</h2></div><span class="dashboard-panel-date">{{ dateLabel }}</span></header>
            <div class="dashboard-activity-bars">
                <div v-for="item in activity" :key="item.key" class="dashboard-activity-row">
                    <span class="dashboard-activity-label"><i :class="`tone-${item.tone}`"><AppIcon :name="item.icon" /></i>{{ item.label }}</span>
                    <span class="dashboard-activity-track"><span :class="`tone-${item.tone}`" :style="{ width: `${(Number(data.today[item.key] || 0) / maxActivity) * 100}%` }"></span></span>
                    <strong>{{ formatNumber(data.today[item.key]) }}</strong>
                </div>
            </div>
            <p class="dashboard-panel-footnote">Գործողությունների քանակը՝ ըստ ձեր դերի հասանելի բաժինների։</p>
        </article>

        <article v-if="secondaryMetrics.length" class="dashboard-panel dashboard-attention-panel">
            <header class="dashboard-panel-header"><div><p class="eyebrow">ՈՒՇԱԴՐՈՒԹՅԱՆ ԿԵՏԵՐ</p><h2>Արագ վերահսկում</h2></div><span class="dashboard-attention-icon"><AppIcon name="alert" /></span></header>
            <RouterLink v-for="metric in secondaryMetrics.slice(0, 3)" :key="metric.key" :to="metric.to" class="dashboard-attention-row">
                <span :class="`dashboard-attention-mark tone-${metric.tone}`"><AppIcon :name="metric.icon" /></span><span>{{ metric.label }}</span><strong>{{ formatNumber(data[metric.key]) }}</strong><AppIcon name="arrowRight" class="dashboard-secondary-arrow" />
            </RouterLink>
            <p class="dashboard-panel-footnote">Ընտրեք ցուցանիշը՝ համապատասխան բաժինն անցնելու համար։</p>
        </article>
    </section>

    <section v-if="branches.length" class="dashboard-panel dashboard-branches">
        <header class="dashboard-panel-header"><div><p class="eyebrow">ՄԱՍՆԱՃՅՈՒՂԵՐ</p><h2>Պահեստների վիճակ</h2></div><span class="dashboard-panel-date">{{ branches.length }} ակտիվ մասնաճյուղ</span></header>
        <div class="table-scroll"><table class="data-table">
            <thead><tr>
                <th>Մասնաճյուղ</th><th v-if="can('stock.view')">Մնացորդ</th>
                <template v-if="can('requests.view')"><th>Բաց պահանջագիր</th><th>Չհաստատված</th><th>Սպասվող ընդունում</th></template>
                <th v-if="can('transfers.view')">Տեղափոխման ընդունում</th>
            </tr></thead>
            <tbody><tr v-for="branch in branches" :key="branch.branch_id">
                <td><strong>{{ branch.branch }}</strong></td><td v-if="can('stock.view')">{{ formatNumber(branch.stock_units, 3) }}</td>
                <template v-if="can('requests.view')"><td>{{ branch.open_requests }}</td><td>{{ branch.unapproved_requests }}</td><td>{{ branch.awaiting_receipt_requests }}</td></template>
                <td v-if="can('transfers.view')">{{ branch.awaiting_transfer_receipts }}</td>
            </tr></tbody>
        </table></div>
    </section>
</template>

<style scoped>
.dashboard-scope-controls { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 16px; padding: 16px 20px; border: 1px solid #e2e8f4; border-radius: 18px; background: linear-gradient(110deg, #fff, #fafbff); box-shadow: 0 4px 16px #17233d04; }
.dashboard-scope-copy { display: flex; align-items: center; gap: 12px; min-width: 0; }
.dashboard-scope-icon { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border: 1px solid #e0e5ff; border-radius: 13px; background: #eef1ff; color: #5568f3; }
.dashboard-scope-icon .app-icon { width: 19px; height: 19px; }
.dashboard-scope-controls label { display: block; color: #263653; font-size: 12px; font-weight: 700; line-height: 1.5; }
.dashboard-scope-copy p { margin: 4px 0 0; color: #8a97ad; font-size: 10px; line-height: 1.5; }
.dashboard-location-select { flex: 0 1 420px; min-width: 0; height: 48px; border-color: #dce3f1; border-radius: 12px; background-color: #fff; font: inherit; font-size: 13px; font-weight: 600; color: #263653; }
.dashboard-location-select:hover, .dashboard-location-select[aria-expanded="true"] { border-color: #9aa8ff; box-shadow: 0 0 0 3px #6879ff12; }
.dashboard-load-status { margin: 0 0 12px; color: #637089; font-size: 12px; }
.dashboard-error { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.dashboard-error button { margin-left: auto; white-space: nowrap; }
@media (max-width: 640px) {
    .dashboard-scope-controls { flex-direction: column; align-items: stretch; gap: 12px; padding: 14px; }
    .dashboard-scope-icon { width: 36px; height: 36px; border-radius: 11px; }
    .dashboard-location-select { flex: none; width: 100%; }
}
</style>
