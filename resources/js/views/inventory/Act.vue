<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import { formatDisplayDate } from '@/dateUtils';

const route = useRoute();
const act = ref(null);
const loading = ref(true);
const pdfBusy = ref(false);
const error = ref('');

async function printAct() {
  if (!act.value || loading.value || pdfBusy.value) return;
  pdfBusy.value = true;
  error.value = '';
  try {
    const response = await api.get(`inventory/${route.params.session}/act/pdf`, { responseType: 'blob' });
    const url = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `inventory-act-${route.params.session}.pdf`;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (exception) {
    const payload = exception.response?.data;
    if (payload instanceof Blob) {
      try {
        const body = JSON.parse(await payload.text());
        error.value = body.message || 'PDF-ը չհաջողվեց ներբեռնել։';
      } catch { error.value = 'PDF-ը չհաջողվեց ներբեռնել։ Փորձեք կրկին։'; }
    } else error.value = exception.response?.data?.message || 'PDF-ը չհաջողվեց ներբեռնել։ Փորձեք կրկին։';
  } finally { pdfBusy.value = false; }
}

let requestVersion = 0;
async function load() {
  const version = ++requestVersion;
  loading.value = true;
  try {
    const response = await api.get(`inventory/${route.params.session}/act`);
    if (version === requestVersion) { act.value = response.data.data; error.value = ''; }
  } catch (exception) {
    if (version === requestVersion) error.value = exception.response?.data?.message || 'Գույքագրման ակտը չհաջողվեց բացել։';
  } finally { if (version === requestVersion) loading.value = false; }
}
onMounted(load);
onBeforeUnmount(() => { requestVersion += 1; });
useLiveRefresh(load, { isBusy: () => loading.value || pdfBusy.value });
</script>

<template>
  <div class="inventory-act-page">
    <div class="inventory-act-toolbar"><RouterLink class="secondary-button" to="/inventory"><AppIcon name="arrowLeft" /> Գույքագրման ցանկ</RouterLink><button class="primary-button" type="button" :disabled="!act || loading || pdfBusy" @click="printAct">{{ pdfBusy ? 'PDF-ը պատրաստվում է…' : 'Տպել / պահպանել PDF' }}</button></div>
    <p v-if="error && act" class="alert-error" role="alert">{{ error }}</p>
    <p v-if="loading" class="table-empty">Ակտը բեռնվում է…</p>
    <div v-else-if="error" class="alert-error" role="alert">{{ error }}</div>
    <article v-else-if="act" class="inventory-act-document">
      <header><p class="eyebrow">ԴԻԱԳԵՆ ՊԼՅՈՒՍ · ՊԱՀԵՍՏԱՅԻՆ ՀԱՄԱԿԱՐԳ</p><h1>Գույքագրման ակտ</h1><strong>{{ act.inventory_no }}</strong></header>
      <section class="inventory-act-meta"><div><small>Պահեստ</small><b>{{ act.location }}</b></div><div><small>Սկսվել է</small><b>{{ formatDisplayDate(act.started_at) }}</b></div><div><small>Փակվել է</small><b>{{ formatDisplayDate(act.closed_at) }}</b></div><div><small>Գրանցող / հաստատող</small><b>{{ act.starter || '—' }} / {{ act.approver || '—' }}</b></div><div v-if="act.note"><small>Նշում</small><b>{{ act.note }}</b></div></section>
      <div class="table-scroll"><table class="data-table"><thead><tr><th>Կոդ</th><th>Ապրանք</th><th>LOT</th><th>Միավոր</th><th>Հաշվառված</th><th>Փաստացի</th><th>Տարբերություն</th><th>Պատճառ</th></tr></thead><tbody><tr v-for="(line,index) in act.lines" :key="`${line.code}-${line.lot_no}-${index}`"><td>{{ line.code }}</td><td>{{ line.product }}</td><td>{{ line.lot_no || '—' }}</td><td>{{ line.unit }}</td><td>{{ line.expected_qty }}</td><td>{{ line.counted_qty }}</td><td>{{ line.difference }}</td><td>{{ line.reason || '—' }}</td></tr><tr v-if="!act.lines.length"><td colspan="8" class="table-empty">Գույքագրման տողեր չկան։</td></tr></tbody></table></div>
      <footer><span>Կազմեց՝ {{ act.starter || '________________' }}</span><span>Հաստատեց՝ {{ act.approver || '________________' }}</span></footer>
    </article>
  </div>
</template>
