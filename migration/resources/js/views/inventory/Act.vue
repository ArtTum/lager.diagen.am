<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';

const route = useRoute();
const act = ref(null);
const loading = ref(true);
const error = ref('');

function printAct() { window.print(); }

onMounted(async () => {
  try {
    const response = await api.get(`inventory/${route.params.session}/act`);
    act.value = response.data.data;
  } catch (exception) {
    error.value = exception.response?.data?.message || 'Գույքագրման ակտը չհաջողվեց բացել։';
  } finally { loading.value = false; }
});
</script>

<template>
  <div class="inventory-act-page">
    <div class="inventory-act-toolbar"><RouterLink class="secondary-button" to="/inventory"><AppIcon name="arrowLeft" /> Գույքագրման ցանկ</RouterLink><button class="primary-button" :disabled="!act" @click="printAct">Տպել / պահպանել PDF</button></div>
    <p v-if="loading" class="table-empty">Ակտը բեռնվում է…</p>
    <div v-else-if="error" class="alert-error" role="alert">{{ error }}</div>
    <article v-else-if="act" class="inventory-act-document">
      <header><p class="eyebrow">ԴԻԱԳԵՆ ՊԼՅՈՒՍ · ՊԱՀԵՍՏԱՅԻՆ ՀԱՄԱԿԱՐԳ</p><h1>Գույքագրման ակտ</h1><strong>{{ act.inventory_no }}</strong></header>
      <section class="inventory-act-meta"><div><small>Պահեստ</small><b>{{ act.location }}</b></div><div><small>Սկսվել է</small><b>{{ act.started_at || '—' }}</b></div><div><small>Փակվել է</small><b>{{ act.closed_at || '—' }}</b></div><div><small>Գրանցող / հաստատող</small><b>{{ act.starter || '—' }} / {{ act.approver || '—' }}</b></div><div v-if="act.note"><small>Նշում</small><b>{{ act.note }}</b></div></section>
      <div class="table-scroll"><table class="data-table"><thead><tr><th>Կոդ</th><th>Ապրանք</th><th>LOT</th><th>Միավոր</th><th>Հաշվառված</th><th>Փաստացի</th><th>Տարբերություն</th><th>Պատճառ</th></tr></thead><tbody><tr v-for="(line,index) in act.lines" :key="`${line.code}-${line.lot_no}-${index}`"><td>{{ line.code }}</td><td>{{ line.product }}</td><td>{{ line.lot_no || '—' }}</td><td>{{ line.unit }}</td><td>{{ line.expected_qty }}</td><td>{{ line.counted_qty }}</td><td>{{ line.difference }}</td><td>{{ line.reason || '—' }}</td></tr><tr v-if="!act.lines.length"><td colspan="8" class="table-empty">Գույքագրման տողեր չկան։</td></tr></tbody></table></div>
      <footer><span>Կազմեց՝ {{ act.starter || '________________' }}</span><span>Հաստատեց՝ {{ act.approver || '________________' }}</span></footer>
    </article>
  </div>
</template>
