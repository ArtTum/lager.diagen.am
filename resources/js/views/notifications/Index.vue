<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { createNotificationAudio } from '@/notificationAudio';
import { isCurrentNotificationSnapshot } from '@/notifications';

const route = useRoute(); const router = useRouter();
const items = ref([]); const unread = ref(0); const loading = ref(false); const error = ref('');
const soundEnabled = ref(localStorage.getItem('lagerNotificationSound') === 'on');
const filter = ref('unread');
const user = ref(currentUser());
let sessionVersion = 0;
let requestVersion = 0;
const markingKeys = ref(new Set());
const permitted = () => Boolean(user.value?.permissions?.['notifications.view']);
const notificationAudio = createNotificationAudio(() => window.AudioContext || window.webkitAudioContext, () => soundEnabled.value);
const onSoundChange = (event) => { soundEnabled.value = Boolean(event.detail); };
const visibleItems = computed(() => filter.value === 'all' ? items.value : items.value.filter(item => !item.read));
const { connected } = useLiveRefresh(refresh, { isBusy: loading, fallbackInterval: 45000 });
const onUserChange = (event) => {
  user.value = event.detail;
  sessionVersion += 1;
  requestVersion += 1;
  items.value = []; unread.value = 0; error.value = ''; loading.value = false;
  markingKeys.value.clear();
  refresh();
};

async function refresh() {
  if (!permitted()) { items.value = []; unread.value = 0; return; }
  const snapshot = { sessionVersion, requestVersion: ++requestVersion };
  loading.value = true;
  try {
    const response = await api.get('notifications');
    if (!isCurrentNotificationSnapshot(snapshot, sessionVersion, requestVersion, permitted())) return;
    const next = response.data.data || [];
    items.value = next; unread.value = Number(response.data.unread_count || 0); error.value = '';
  } catch (e) { if (isCurrentNotificationSnapshot(snapshot, sessionVersion, requestVersion, permitted())) error.value = e.response?.data?.message || 'Ծանուցումները չհաջողվեց բեռնել։'; }
  finally { if (isCurrentNotificationSnapshot(snapshot, sessionVersion, requestVersion, permitted())) loading.value = false; }
}

function toggleSound() {
  soundEnabled.value = !soundEnabled.value;
  localStorage.setItem('lagerNotificationSound', soundEnabled.value ? 'on' : 'off');
  window.dispatchEvent(new CustomEvent('lager:notification-sound', { detail: soundEnabled.value }));
  if (soundEnabled.value) void notificationAudio.play();
}

async function markRead(item) {
  if (item.read) { if (item.link) router.push(item.link); return; }
  if (markingKeys.value.has(item.key)) return;
  const currentSession = sessionVersion;
  markingKeys.value.add(item.key);
  try {
    await api.post('notifications/read', { key: item.key });
    if (currentSession !== sessionVersion || !permitted()) return;
    requestVersion += 1;
    loading.value = false;
    item.read = true; unread.value = Math.max(0, unread.value - 1);
    window.dispatchEvent(new CustomEvent('lager:data-changed'));
    if (item.link) router.push(item.link);
  } catch (e) { if (currentSession === sessionVersion && permitted()) error.value = e.response?.data?.message || 'Ծանուցումը չհաջողվեց նշել որպես կարդացված։'; }
  finally { if (currentSession === sessionVersion) markingKeys.value.delete(item.key); }
}

onMounted(() => { refresh(); window.addEventListener('lager:notification-sound', onSoundChange); window.addEventListener('lager:user', onUserChange); });
onBeforeUnmount(() => {
  sessionVersion += 1; requestVersion += 1;
  window.removeEventListener('lager:notification-sound', onSoundChange); window.removeEventListener('lager:user', onUserChange);
  void notificationAudio.close();
});
</script>

<template>
  <div class="page-heading"><div><p class="eyebrow">ԱԿՏԻՎ ԱԶԴԱՆՇԱՆՆԵՐ</p><h1>{{ route.meta.title }}</h1><p class="muted">Ցուցադրվում են միայն Ձեր մասնաճյուղին ու դերին համապատասխան պահեստային ազդանշանները։</p></div><button class="secondary-button notification-sound-toggle" type="button" :aria-pressed="soundEnabled" @click="toggleSound"><AppIcon :name="soundEnabled ? 'volumeOn' : 'volumeOff'" />{{ soundEnabled ? 'Ձայնը միացված է' : 'Միացնել ձայնը' }}</button></div>
  <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
  <section class="notification-panel">
    <header class="notification-panel-head"><div><span class="metric-icon" :class="unread ? 'amber' : 'green'"><AppIcon :name="unread ? 'alert' : 'success'" /></span><div><strong>{{ unread }} չկարդացված ծանուցում</strong><small>{{ loading ? 'Թարմացվում է…' : connected ? 'Փոփոխությունները ցուցադրվում են անմիջապես։' : 'Կապը վերականգնվում է․ պահուստային թարմացումը ակտիվ է։' }}</small></div></div><div class="notification-panel-actions"><button class="secondary-button" :class="{ selected: filter === 'unread' }" @click="filter='unread'">Չկարդացված</button><button class="secondary-button" :class="{ selected: filter === 'all' }" @click="filter='all'">Բոլորը</button><button class="icon-button" type="button" title="Թարմացնել" @click="refresh"><AppIcon name="refresh" /></button></div></header>
    <div v-if="!visibleItems.length" class="table-empty notification-empty-state">{{ filter === 'unread' ? 'Չկարդացված ծանուցումներ չկան։' : 'Այս պահին ակտիվ ծանուցում չկա։' }}</div>
    <ul v-else class="notification-list"><li v-for="item in visibleItems" :key="item.key" class="notification-row" :class="[{ 'is-read': item.read }, `tone-${item.tone}`]"><span class="notification-indicator" aria-hidden="true"></span><div class="notification-copy"><strong>{{ item.title }}</strong><small>{{ item.detail }}</small></div><button class="secondary-button notification-open" type="button" :disabled="markingKeys.has(item.key)" @click="markRead(item)">{{ item.read ? 'Բացել' : 'Նշել կարդացված և բացել' }}</button></li></ul>
  </section>
</template>
