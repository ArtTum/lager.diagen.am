<script setup>
import { onMounted, ref } from 'vue';
import api from '@/services/api';

const data = ref(null);
const error = ref('');
onMounted(async () => {
    try { data.value = (await api.get('dashboard')).data.data; }
    catch (e) { error.value = e.response?.data?.message || 'Վահանակի տվյալները չհաջողվեց բեռնել։'; }
});
const cards = [
    ['Ապրանքային տեսակներ', 'products', 'Տեսականիի ակտիվ գրառումներն ըստ ձեր պահեստի', 'blue'],
    ['Պահեստի միավորներ', 'units', 'Ընդհանուր հասանելի քանակ', 'violet'],
    ['Պահեստի հաշվեկշիռ', 'stock_value', 'Մնացորդի արժեքը դրամով', 'green'],
    ['Ցածր մնացորդ', 'low_stock_products', 'MIN շեմից ցածր ապրանքներ', 'amber'],
    ['Մոտ ժամկետանց LOT', 'expiring_lots', 'Առաջիկա 90 օրվա ժամկետներ', 'rose'],
    ['Բաց պահանջագրեր', 'open_requests', 'Սպասման և ուղարկման փուլերում', 'blue'],
];
</script>

<template>
    <div class="page-heading"><div><p class="eyebrow">ՊԱՀԵՍՏԻ ԱՄՓՈՓՈՒՄ</p><h1>Գլխավոր վահանակ</h1><p class="muted">Օպերացիոն պատկերը՝ ըստ ձեր հասանելի պահեստի։</p></div><div class="date-chip"><span class="online-dot"></span>Աշխատանքային համակարգ</div></div>
    <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
    <div class="metric-grid"><article v-for="[label, key, hint, color] in cards" :key="key" class="metric-card"><div class="metric-top"><span class="metric-icon" :class="color">▦</span><span class="metric-trend">LIVE</span></div><p>{{ label }}</p><strong v-if="data" class="metric-value">{{ Number(data[key]).toLocaleString('hy-AM') }}</strong><div v-else class="metric-skeleton"></div><small>{{ hint }}</small></article></div>
    <article class="welcome-card"><div class="welcome-art"><span class="art-square one"></span><span class="art-square two"></span><span class="art-square three"></span></div><div><span class="eyebrow">ԴԻԱԳԵՆ ՊԼՅՈՒՍ</span><h2>Մատակարարումից մինչև մասնաճյուղ՝ վերահսկելի մեկ հոսքով։</h2><p class="muted">Ընտրեք բաժինը ձախ ցանկից՝ ընթացիկ պահեստային աշխատանքը շարունակելու համար։</p></div></article>
</template>
