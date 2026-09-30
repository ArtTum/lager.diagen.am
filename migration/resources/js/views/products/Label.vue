<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import api from '@/services/api';

const route = useRoute();
const data = ref(null);
const error = ref('');
function printLabel() { window.print(); }
onMounted(async () => {
  try { data.value = (await api.get(`products/${route.params.product}/label`)).data.data; }
  catch (e) { error.value = e.response?.data?.message || 'Պիտակի տվյալները չհաջողվեց բեռնել։'; }
});
</script>

<template>
  <main class="product-label-page"><div class="page-heading no-print"><div><p class="eyebrow">ԱՊՐԱՆՔԻ ՊԻՏԱԿ</p><h1>{{ data?.product?.name || 'Բեռնում է…' }}</h1><p class="muted">Տպման չափը՝ 70 × 42 մմ։</p></div><div class="stock-actions"><RouterLink class="secondary-button" to="/products">Վերադառնալ ապրանքներին</RouterLink><button class="primary-button" type="button" :disabled="!data?.barcode_svg" @click="printLabel">Տպել պիտակը</button></div></div>
    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>
    <section v-else-if="data" class="product-label-preview"><strong>{{ data.product.name }}</strong><div class="product-label-meta">{{ data.product.code }}<span v-if="data.product.category"> · {{ data.product.category }}</span><span v-if="data.product.package"> · {{ data.product.package }}</span></div><div v-if="data.barcode_svg" class="product-barcode" v-html="data.barcode_svg"></div><p v-else class="form-error">{{ data.barcode_error }}</p></section>
  </main>
</template>

<style scoped>
.product-label-page{max-width:1100px;margin:0 auto}.product-label-preview{width:264px;min-height:158px;padding:12px;border:1px solid #cfd5e2;border-radius:12px;background:#fff;display:grid;align-content:center;gap:8px;box-shadow:0 12px 36px #16233a14}.product-label-preview strong{font-size:15px;line-height:1.25;color:#1d2b43}.product-label-meta{font-size:10px;color:#596982}.product-barcode :deep(.barcode-svg){display:block;width:100%;height:82px;background:#fff}
@media print{@page{size:70mm 42mm;margin:3mm}.no-print{display:none!important}.product-label-page{margin:0}.product-label-preview{width:64mm;min-height:34mm;padding:2mm;border:0;border-radius:0;box-shadow:none;gap:2mm}.product-label-preview strong{font:700 12px Arial,sans-serif;color:#111}.product-label-meta{font:8px Arial,sans-serif;color:#333}.product-barcode :deep(.barcode-svg){width:100%;height:17mm}}
</style>
