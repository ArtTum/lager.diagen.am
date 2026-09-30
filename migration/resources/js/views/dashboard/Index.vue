<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '@/services/api';
import { currentUser } from '@/router';

const data = ref(null);
const user = ref(currentUser());
const error = ref('');
onMounted(async () => {
    try { data.value = (await api.get('dashboard')).data.data; }
    catch (e) { error.value = e.response?.data?.message || 'Վահանակի տվյալները չհաջողվեց բեռնել։'; }
});
onMounted(() => window.addEventListener('lager:user', (event) => { user.value = event.detail; }));
const cards = computed(() => [
    ['Ապրանքային տեսակներ', 'products', 'Տեսականիի ակտիվ գրառումներն ըստ ձեր պահեստի', 'blue', 'box'],
    ['Պահեստի միավորներ', 'units', 'Ընդհանուր հասանելի քանակ', 'violet', 'boxes'],
    ...(user.value?.permissions?.['purchases.view'] ? [['Պահեստի հաշվեկշիռ', 'stock_value', 'Մնացորդի արժեքը դրամով', 'green', 'chart']] : []),
    ['Ցածր մնացորդ', 'low_stock_products', 'MIN շեմից ցածր ապրանքներ', 'amber', 'trendDown'],
    ['Զրոյական մնացորդ', 'zero_stock_products', 'Ակտիվ ապրանքներ՝ առանց մնացորդի', 'rose', 'box'],
    ['Ժամկետանց LOT', 'expired_lots', 'Դուրսգրման կամ ստուգման ենթակա', 'rose', 'clock'],
    ['Մոտ ժամկետանց LOT', 'expiring_lots', 'Առաջիկա 90 օրվա ժամկետներ', 'amber', 'clock'],
    ['Բաց պահանջագրեր', 'open_requests', 'Չփակված պահանջագրեր', 'blue', 'plusFile'],
    ['Չհաստատված պահանջագրեր', 'unapproved_requests', 'Ստուգման փուլում կամ սպասում են', 'violet', 'clipboard'],
    ['Սպասվող ստացումներ', 'awaiting_receipt_requests', 'Ուղարկված՝ դեռ չընդունված', 'green', 'arrowDown'],
]);
const branches = computed(() => data.value?.branches || []);
const today = computed(() => data.value?.today || {});
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ՊԱՀԵՍՏԻ ԱՄՓՈՓՈՒՄ</p><h1>Գլխավոր վահանակ</h1><p class="muted">Օպերացիոն պատկերը՝ ըստ ձեր հասանելի պահեստի։</p></div><div class="date-chip"><span class="online-dot"></span>Աշխատանքային համակարգ</div></div>
    <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
    <div class="metric-grid"><article v-for="[label, key, hint, color, icon] in cards" :key="key" class="metric-card"><div class="metric-top"><span class="metric-icon" :class="color"><AppIcon :name="icon" /></span><span class="metric-trend">ԸՆԹԱՑԻԿ</span></div><p>{{ label }}</p><strong v-if="data" class="metric-value">{{ Number(data[key]).toLocaleString('hy-AM') }}</strong><div v-else class="metric-skeleton"></div><small>{{ hint }}</small></article></div>
    <section class="dashboard-activity"><header><div><p class="eyebrow">ՕՐՎԱ ԳՈՐԾՈՂՈՒԹՅՈՒՆՆԵՐ</p><h2>Այսօրվա շարժը</h2></div><span class="muted">{{ new Date().toLocaleDateString('hy-AM') }}</span></header><div class="activity-grid"><article><span class="activity-icon green"><AppIcon name="arrowDown" /></span><div><small>Մուտքեր</small><strong>{{ today.receipts ?? '—' }}</strong></div></article><article><span class="activity-icon amber"><AppIcon name="trendUp" /></span><div><small>Ելքեր</small><strong>{{ today.issues ?? '—' }}</strong></div></article><article><span class="activity-icon violet"><AppIcon name="returns" /></span><div><small>Վերադարձներ</small><strong>{{ today.returns ?? '—' }}</strong></div></article><article><span class="activity-icon blue"><AppIcon name="transfers" /></span><div><small>Տեղափոխումներ</small><strong>{{ today.transfers ?? '—' }}</strong></div></article></div></section>
    <section v-if="branches.length" class="dashboard-branches"><header><div><p class="eyebrow">ՄԱՍՆԱՃՅՈՒՂԵՐ</p><h2>Պահեստների վիճակ</h2></div><span class="muted">{{ branches.length }} ակտիվ մասնաճյուղ</span></header><div class="table-scroll"><table class="data-table"><thead><tr><th>Մասնաճյուղ</th><th>Մնացորդ</th><th>Բաց պահանջագիր</th><th>Չհաստատված</th><th>Սպասվող ընդունում</th><th>Տեղափոխման ընդունում</th></tr></thead><tbody><tr v-for="branch in branches" :key="branch.branch_id"><td><strong>{{ branch.branch }}</strong></td><td>{{ Number(branch.stock_units).toLocaleString('hy-AM',{maximumFractionDigits:3}) }}</td><td>{{ branch.open_requests }}</td><td>{{ branch.unapproved_requests }}</td><td>{{ branch.awaiting_receipt_requests }}</td><td>{{ branch.awaiting_transfer_receipts }}</td></tr><tr v-if="!branches.length"><td colspan="6" class="table-empty">Ակտիվ մասնաճյուղ չկա։</td></tr></tbody></table></div></section>
    <article class="welcome-card"><div class="welcome-art"><span class="art-square one"></span><span class="art-square two"></span><span class="art-square three"></span></div><div><span class="eyebrow">ԴԻԱԳԵՆ ՊԼՅՈՒՍ</span><h2>Մատակարարումից մինչև մասնաճյուղ՝ վերահսկելի մեկ հոսքով։</h2><p class="muted">Ընտրեք բաժինը ձախ ցանկից՝ ընթացիկ պահեստային աշխատանքը շարունակելու համար։</p></div></article>
</template>
