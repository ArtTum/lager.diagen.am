<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/services/api';

const route = useRoute(); const router = useRouter();
const items = ref([]); const unread = ref(0); const loading = ref(false); const error = ref('');
const soundEnabled = ref(localStorage.getItem('lagerNotificationSound') === 'on');
const filter = ref('unread'); let timer;
const onSoundChange = (event) => { soundEnabled.value = Boolean(event.detail); };
const visibleItems = computed(() => filter.value === 'all' ? items.value : items.value.filter(item => !item.read));

async function refresh() {
  loading.value = true;
  try {
    const response = await api.get('notifications');
    const next = response.data.data || [];
    items.value = next; unread.value = Number(response.data.unread_count || 0); error.value = '';
  } catch (e) { error.value = e.response?.data?.message || 'Ծանուցումները չհաջողվեց բեռնել։'; }
  finally { loading.value = false; }
}

async function playTone() {
  try {
    const Audio = window.AudioContext || window.webkitAudioContext;
    if (!Audio) return;
    const context = new Audio();
    if (context.state === 'suspended') await context.resume();
    const oscillator = context.createOscillator(); const gain = context.createGain();
    oscillator.type = 'sine'; oscillator.frequency.value = 740; gain.gain.setValueAtTime(0.0001, context.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + 0.025); gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.24);
    oscillator.connect(gain); gain.connect(context.destination); oscillator.start(); oscillator.stop(context.currentTime + 0.25);
    oscillator.onended = () => context.close().catch(() => {});
  } catch { /* Audio is optional and may be blocked by browser policy. */ }
}

function toggleSound() {
  soundEnabled.value = !soundEnabled.value;
  localStorage.setItem('lagerNotificationSound', soundEnabled.value ? 'on' : 'off');
  window.dispatchEvent(new CustomEvent('lager:notification-sound', { detail: soundEnabled.value }));
  if (soundEnabled.value) void playTone();
}

async function markRead(item) {
  if (item.read) return;
  try {
    await api.post('notifications/read', { key: item.key });
    item.read = true; unread.value = Math.max(0, unread.value - 1);
    if (item.link) router.push(item.link);
  } catch (e) { error.value = e.response?.data?.message || 'Ծանուցումը չհաջողվեց նշել որպես կարդացված։'; }
}

onMounted(() => { refresh(); timer = window.setInterval(refresh, 45000); });
onMounted(() => window.addEventListener('lager:notification-sound', onSoundChange));
onBeforeUnmount(() => { window.clearInterval(timer); window.removeEventListener('lager:notification-sound', onSoundChange); });
</script>

<template>
  <div class="page-heading"><div><p class="eyebrow">ԱԿՏԻՎ ԱԶԴԱՆՇԱՆՆԵՐ</p><h1>{{ route.meta.title }}</h1><p class="muted">Ցուցադրվում են միայն Ձեր մասնաճյուղին ու դերին համապատասխան պահեստային ազդանշանները։</p></div><button class="secondary-button notification-sound-toggle" type="button" :aria-pressed="soundEnabled" @click="toggleSound"><AppIcon :name="soundEnabled ? 'volumeOn' : 'volumeOff'" />{{ soundEnabled ? 'Ձայնը միացված է' : 'Միացնել ձայնը' }}</button></div>
  <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
  <section class="notification-panel">
    <header class="notification-panel-head"><div><span class="metric-icon" :class="unread ? 'amber' : 'green'"><AppIcon :name="unread ? 'alert' : 'success'" /></span><div><strong>{{ unread }} չկարդացված ծանուցում</strong><small>{{ loading ? 'Թարմացվում է…' : 'Ցանկը ինքնաշխատ թարմացվում է 45 վայրկյանը մեկ։' }}</small></div></div><div class="notification-panel-actions"><button class="secondary-button" :class="{ selected: filter === 'unread' }" @click="filter='unread'">Չկարդացված</button><button class="secondary-button" :class="{ selected: filter === 'all' }" @click="filter='all'">Բոլորը</button><button class="icon-button" type="button" title="Թարմացնել" @click="refresh"><AppIcon name="refresh" /></button></div></header>
    <div v-if="!visibleItems.length" class="table-empty notification-empty-state">{{ filter === 'unread' ? 'Չկարդացված ծանուցումներ չկան։' : 'Այս պահին ակտիվ ծանուցում չկա։' }}</div>
    <ul v-else class="notification-list"><li v-for="item in visibleItems" :key="item.key" class="notification-row" :class="[{ 'is-read': item.read }, `tone-${item.tone}`]"><span class="notification-indicator" aria-hidden="true"></span><div class="notification-copy"><strong>{{ item.title }}</strong><small>{{ item.detail }}</small></div><button class="secondary-button notification-open" type="button" @click="markRead(item)">{{ item.read ? 'Բացել' : 'Նշել կարդացված և բացել' }}</button></li></ul>
  </section>
</template>
