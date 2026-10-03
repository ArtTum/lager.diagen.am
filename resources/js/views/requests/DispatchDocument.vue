<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { RouterLink, useRoute } from 'vue-router';
import api from '@/services/api';
import { formatDisplayDate } from '@/dateUtils';
import { statusLabel } from '@/workflowStatus';

const route = useRoute();
const document = ref(null);
const error = ref('');
const loading = ref(true);
const urgencyLabels = { normal: 'Սովորական', high: 'Բարձր', urgent: 'Շտապ' };
const date = formatDisplayDate;
function printDocument() {
  if (!document.value) return;
  window.focus();
  window.print();
}

let requestVersion = 0;
async function load() {
  const version = ++requestVersion;
  loading.value = true;
  try {
    const response = await api.get(`requests/${route.params.request}/dispatch-document`);
    if (version === requestVersion) { document.value = response.data.data; error.value = ''; }
  } catch (e) { if (version === requestVersion) error.value = e.response?.data?.message || 'Բաշխման փաստաթուղթը չհաջողվեց բեռնել։'; }
  finally { if (version === requestVersion) loading.value = false; }
}
onMounted(load);
onBeforeUnmount(() => { requestVersion += 1; });
useLiveRefresh(load, { isBusy: loading });
</script>

<template>
  <main class="dispatch-page">
    <div class="page-heading no-print"><div><p class="eyebrow">ՊԱՀԱՆՋԱԳՐԻ ՓԱՍՏԱԹՈՒՂԹ</p><h1>Բաշխման փաստաթուղթ</h1><p class="muted">Ուղարկված ապրանքների և ստացման հաստատման ակտ։</p></div><div class="dispatch-actions"><RouterLink class="secondary-button" to="/requests">Վերադառնալ պահանջագրերին</RouterLink><button class="primary-button" type="button" :disabled="!document" @click="printDocument">Տպել / պահպանել PDF</button></div></div>
    <p v-if="loading" class="table-card dispatch-state">Բեռնում է փաստաթուղթը…</p>
    <p v-else-if="error" class="alert-error" role="alert">{{ error }}</p>
    <article v-else-if="document" class="dispatch-paper">
      <header class="dispatch-title"><p class="eyebrow">ԴԻԱԳԵՆ ՊԼՅՈՒՍ · ԲԱՇԽՄԱՆ ՓԱՍՏԱԹՈՒՂԹ</p><h1>{{ document.request_no }}</h1><p>Մասնաճյուղին ուղարկված ապրանքների և ստացման հաստատման ակտ</p></header>
      <section class="dispatch-meta">
        <div><small>Մասնաճյուղ</small><strong>{{ document.branch_name || '—' }}</strong></div>
        <div><small>Պահանջող աշխատակից</small><strong>{{ document.requester_name || '—' }}</strong></div>
        <div><small>Կարգավիճակ</small><strong>{{ statusLabel('requests', document.status) }}</strong></div>
        <div><small>Պահանջի ամսաթիվ</small><strong>{{ date(document.created_at) }}</strong></div>
        <div><small>Ուղարկող</small><strong>{{ document.sender_name || '—' }}</strong></div>
        <div><small>Ուղարկման ամսաթիվ</small><strong>{{ date(document.sent_at) }}</strong></div>
        <div><small>Ստացող</small><strong>{{ document.receiver_name || '—' }}</strong></div>
        <div><small>Ստացման ամսաթիվ</small><strong>{{ date(document.received_at) }}</strong></div>
        <div><small>Հրատապություն</small><strong>{{ urgencyLabels[document.urgency] || document.urgency }}</strong></div>
      </section>
      <p v-if="document.reason" class="dispatch-reason"><small>Պահանջի հիմնավորում</small>{{ document.reason }}</p>
      <div class="dispatch-table-wrap"><table class="data-table dispatch-table"><thead><tr><th>Կոդ</th><th>Ապրանք</th><th class="number-cell">Պահանջված</th><th class="number-cell">Ուղարկված</th><th>Միավոր</th></tr></thead><tbody><tr v-for="(item,index) in document.items" :key="`${item.code}-${index}`"><td>{{ item.code }}</td><td><strong>{{ item.name }}</strong></td><td class="number-cell">{{ item.requested_qty }}</td><td class="number-cell"><strong>{{ item.approved_qty }}</strong></td><td>{{ item.unit }}</td></tr><tr v-if="!document.items.length"><td colspan="5" class="table-empty">Փաստաթղթում ապրանքներ չկան։</td></tr></tbody></table></div>
      <footer class="dispatch-signatures"><div>Ուղարկող՝ <strong>{{ document.sender_name || '________________' }}</strong></div><div>Ստացող՝ <strong>{{ document.receiver_name || '________________' }}</strong></div></footer>
    </article>
  </main>
</template>

<style scoped>
.dispatch-page{max-width:1100px;margin:0 auto}.dispatch-actions{display:flex;align-items:center;gap:9px}.dispatch-paper{padding:30px;background:#fff;border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow)}.dispatch-title{padding-bottom:18px;border-bottom:1px solid var(--border)}.dispatch-title h1{margin:8px 0;font-size:23px}.dispatch-title>p:last-child{margin:0;color:#78859c}.dispatch-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 18px;margin:20px 0;border:1px solid var(--border);border-radius:12px;background:#fafbfe}.dispatch-meta>div{display:grid;gap:5px;padding:13px 14px;border-bottom:1px solid var(--border)}.dispatch-meta>div:nth-last-child(-n+3){border-bottom:0}.dispatch-meta small,.dispatch-reason small{font-size:10px;color:#8792a7}.dispatch-meta strong{font-size:12px}.dispatch-reason{display:grid;gap:5px;padding:12px 14px;border-left:3px solid #6578ff;background:#f6f7ff;color:#45516a;font-size:12px}.dispatch-table-wrap{overflow:auto;margin-top:20px}.dispatch-table{min-width:620px}.number-cell{text-align:right}.dispatch-signatures{display:grid;grid-template-columns:1fr 1fr;gap:50px;margin:55px 0 8px;padding-top:12px;border-top:1px solid #aab3c2;font-size:12px}.dispatch-state{padding:20px}
@media(max-width:700px){.dispatch-meta{grid-template-columns:repeat(2,minmax(0,1fr))}.dispatch-meta>div:nth-last-child(-n+3){border-bottom:1px solid var(--border)}.dispatch-meta>div:nth-last-child(-n+1){border-bottom:0}.dispatch-actions{flex-wrap:wrap}.dispatch-paper{padding:17px}.dispatch-signatures{gap:18px}}
@media print{@page{size:A4 portrait;margin:15mm}.no-print{display:none!important}.dispatch-page{max-width:none;margin:0}.dispatch-paper{padding:0;border:0;border-radius:0;box-shadow:none}.dispatch-meta{break-inside:avoid}.dispatch-table{font-size:10px}.dispatch-signatures{margin-top:45px}}
</style>
