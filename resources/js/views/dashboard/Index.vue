<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { RouterLink } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';

const data = ref(null);
const user = ref(currentUser());
const error = ref('');
const dateLabel = new Intl.DateTimeFormat('hy-AM', { dateStyle: 'long' }).format(new Date());

let requestVersion = 0;
const loading = ref(false);
async function load() {
    const version = ++requestVersion;
    loading.value = true;
    try {
        const response = await api.get('dashboard');
        if (version === requestVersion) { data.value = response.data.data; error.value = ''; }
    } catch (e) { if (version === requestVersion) error.value = e.response?.data?.message || 'Վահանակի տվյալները չհաջողվեց բեռնել։'; }
    finally { if (version === requestVersion) loading.value = false; }
}
const onUserChange = (event) => { user.value = event.detail; };
onMounted(() => { load(); window.addEventListener('lager:user', onUserChange); });
onBeforeUnmount(() => { requestVersion += 1; window.removeEventListener('lager:user', onUserChange); });
useLiveRefresh(load, { isBusy: loading });

const can = (permission) => Boolean(user.value?.permissions?.[permission]);
const formatNumber = (value, maximumFractionDigits = 0) => Number(value || 0).toLocaleString('hy-AM', { maximumFractionDigits });
const cards = computed(() => [
    { label: 'Ապրանքային տեսակներ', key: 'products', hint: 'Ապրանքներ՝ ձեր պահեստում', tone: 'blue', icon: 'box', permission: 'stock.view' },
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
    <div v-if="error" class="alert-error" role="alert">{{ error }}</div>

    <section class="dashboard-hero">
        <div class="dashboard-hero-copy">
            <p class="dashboard-hero-kicker"><span></span> ԴԻԱԳԵՆ ՊԼՅՈՒՍ · ՊԱՀԵՍՏԻ ՎԵՐԱՀՍԿՈՒՄ</p>
            <h1>Բարի գալուստ, {{ userName }}</h1>
            <p class="dashboard-hero-description">Պահեստի ընթացիկ պատկերը և օրվա հիմնական գործողությունները՝ մեկ տեղում։</p>
            <div class="dashboard-hero-meta"><span><AppIcon name="clock" /> {{ todayLabel }}</span><span>{{ user?.branch?.name || 'Կենտրոնական պահեստ' }}</span></div>
        </div>
        <div class="dashboard-hero-panel">
            <div class="dashboard-hero-panel-top"><span>ՊԱՀԵՍՏԻ ՄՆԱՑՈՐԴ</span><AppIcon name="boxes" /></div>
            <template v-if="can('stock.view') && data">
                <strong>{{ formatNumber(data.units, 3) }}</strong>
                <small>ընդհանուր միավոր · {{ formatNumber(data.products) }} ապրանքատեսակ</small>
            </template>
            <div v-else class="dashboard-hero-loading">Տվյալը հասանելի չէ</div>
            <div class="dashboard-hero-actions">
                <RouterLink v-if="can('requests.create')" to="/requests" class="dashboard-hero-action dashboard-hero-action-primary"><AppIcon name="plusFile" /> Նոր պահանջագիր</RouterLink>
                <RouterLink v-if="can('stock.view')" to="/stock" class="dashboard-hero-action"><AppIcon name="boxes" /> Բացել մնացորդները</RouterLink>
                <RouterLink v-else-if="can('reports.view')" to="/reports" class="dashboard-hero-action"><AppIcon name="chart" /> Բացել հաշվետվությունները</RouterLink>
            </div>
        </div>
        <div class="dashboard-hero-orbit" aria-hidden="true"></div>
    </section>

    <section class="dashboard-metrics" aria-label="Պահեստի հիմնական ցուցանիշներ">
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
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Մասնաճյուղ</th><th>Մնացորդ</th><th>Բաց պահանջագիր</th><th>Չհաստատված</th><th>Սպասվող ընդունում</th><th>Տեղափոխման ընդունում</th></tr></thead><tbody><tr v-for="branch in branches" :key="branch.branch_id"><td><strong>{{ branch.branch }}</strong></td><td>{{ formatNumber(branch.stock_units, 3) }}</td><td>{{ branch.open_requests }}</td><td>{{ branch.unapproved_requests }}</td><td>{{ branch.awaiting_receipt_requests }}</td><td>{{ branch.awaiting_transfer_receipts }}</td></tr><tr v-if="!branches.length"><td colspan="6" class="table-empty">Ակտիվ մասնաճյուղ չկա։</td></tr></tbody></table></div>
    </section>
</template>
