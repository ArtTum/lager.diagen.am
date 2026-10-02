<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import { emptyNotificationSnapshot, isCurrentNotificationSnapshot } from '@/notifications';
import { createNotificationAudio } from '@/notificationAudio';
import { useLiveRefresh } from '@/composables/useLiveRefresh';

const router = useRouter(); const user = ref(currentUser());
const items = ref([]); const unread = ref(0); const open = ref(false);
const soundEnabled = ref(localStorage.getItem('lagerNotificationSound') === 'on');
let previousKeys = null;
const loading = ref(false);
const markingKeys = new Set();
let sessionVersion = 0;
let requestVersion = 0;
const notificationAudio = createNotificationAudio(
  () => window.AudioContext || window.webkitAudioContext,
  () => soundEnabled.value,
);
const permitted = () => Boolean(user.value?.permissions?.['notifications.view']);
const onUserChange = (event) => {
  user.value = event.detail;
  sessionVersion += 1;
  requestVersion += 1;
  loading.value = false;
  markingKeys.clear();
  open.value = false;
  const empty = emptyNotificationSnapshot();
  previousKeys = empty.previousKeys;
  items.value = empty.items;
  unread.value = empty.unread;
  refresh(false);
};
const onSoundChange = (event) => { soundEnabled.value = Boolean(event.detail); };
useLiveRefresh(() => refresh(), { isBusy: loading, fallbackInterval: 45000 });

async function refresh(announce = true) {
  if (!permitted()) {
    const empty = emptyNotificationSnapshot();
    items.value = empty.items;
    unread.value = empty.unread;
    previousKeys = empty.previousKeys;
    return;
  }
  const currentSession = sessionVersion;
  const currentRequest = ++requestVersion;
  loading.value = true;
  const snapshot = { sessionVersion: currentSession, requestVersion: currentRequest };
  try {
    const response = await api.get('notifications');
    if (!isCurrentNotificationSnapshot(snapshot, sessionVersion, requestVersion, permitted())) return;
    const nextItems = response.data.data || []; const nextUnread = Number(response.data.unread_count || 0);
    const keys = new Set(nextItems.filter((item) => !item.read).map((item) => item.key));
    if (announce && previousKeys !== null && [...keys].some((key) => !previousKeys.has(key)) && soundEnabled.value) playTone();
    previousKeys = keys; items.value = nextItems; unread.value = nextUnread;
  } catch { /* Keep the shell usable if notification fetching is temporarily unavailable. */ }
  finally { if (currentSession === sessionVersion && currentRequest === requestVersion) loading.value = false; }
}

function playTone() { void notificationAudio.play(); }

function activateAudio() { void notificationAudio.activate(); }

function toggleSound() {
  soundEnabled.value = !soundEnabled.value;
  localStorage.setItem('lagerNotificationSound', soundEnabled.value ? 'on' : 'off');
  window.dispatchEvent(new CustomEvent('lager:notification-sound', { detail: soundEnabled.value }));
  if (soundEnabled.value) playTone();
}

function togglePopover() {
  open.value = !open.value;
  activateAudio();
}

async function openItem(item) {
  if (markingKeys.has(item.key)) return;
  const currentSession = sessionVersion;
  markingKeys.add(item.key);
  try {
    if (!item.read) {
      await api.post('notifications/read', { key: item.key });
      if (currentSession !== sessionVersion || !permitted()) return;
      requestVersion += 1;
      loading.value = false;
      item.read = true; unread.value = Math.max(0, unread.value - 1);
      window.dispatchEvent(new CustomEvent('lager:data-changed'));
    }
    if (currentSession !== sessionVersion || !permitted()) return;
    open.value = false; await router.push(item.link || '/notifications');
  } catch { /* The notifications page shows the underlying actionable alert. */ }
  finally { if (currentSession === sessionVersion) markingKeys.delete(item.key); }
}

onMounted(() => {
  window.addEventListener('lager:user', onUserChange);
  window.addEventListener('lager:notification-sound', onSoundChange);
  refresh(false);
});
onBeforeUnmount(() => {
  sessionVersion += 1;
  requestVersion += 1;
  window.removeEventListener('lager:user', onUserChange);
  window.removeEventListener('lager:notification-sound', onSoundChange);
  void notificationAudio.close();
});
</script>

<template>
  <div v-if="permitted()" class="notification-bell">
    <button class="topbar-icon-button notification-bell-trigger" type="button" aria-label="Ծանուցումներ" :aria-expanded="open" @click="togglePopover"><AppIcon name="bell" /><b v-if="unread" class="notification-badge">{{ unread > 99 ? '99+' : unread }}</b></button>
    <section v-if="open" class="notification-popover" aria-label="Ծանուցումներ">
      <header class="notification-popover-head"><div><strong>Ծանուցումներ</strong><small>{{ unread }} չկարդացված</small></div><div class="notification-popover-tools"><button type="button" class="sound-mini-toggle" :aria-pressed="soundEnabled" :title="soundEnabled ? 'Անջատել ձայնը' : 'Միացնել ձայնը'" @click="toggleSound"><AppIcon :name="soundEnabled ? 'volumeOn' : 'volumeOff'" /></button><RouterLink to="/notifications" @click="open=false">Բոլորը</RouterLink></div></header>
      <div v-if="!items.length" class="notification-popover-empty">Նոր ծանուցումներ չկան։</div>
      <button v-for="item in items.slice(0,8)" :key="item.key" type="button" class="notification-popover-item" :class="{ 'is-read': item.read }" @click="openItem(item)"><span class="notification-popover-dot" :class="`tone-${item.tone}`"></span><span><strong>{{ item.title }}</strong><small>{{ item.detail }}</small></span><i v-if="!item.read"></i></button>
    </section>
  </div>
</template>
