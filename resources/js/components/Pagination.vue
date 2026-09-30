<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
  pagination: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  itemLabel: { type: String, default: 'գրառումից' },
});

const emit = defineEmits(['page-change', 'per-page-change']);
const pageSizes = [5, 10, 15, 25, 50, 100];
const sizeOpen = ref(false);
const sizeControl = ref(null);
const current = computed(() => Number(props.pagination.current_page || 1));
const last = computed(() => Math.max(1, Number(props.pagination.last_page || 1)));
const perPage = computed(() => Number(props.pagination.per_page || 15));
const total = computed(() => Number(props.pagination.total || 0));
const start = computed(() => total.value ? ((current.value - 1) * perPage.value) + 1 : 0);
const end = computed(() => Math.min(current.value * perPage.value, total.value));
const pages = computed(() => {
  if (last.value <= 7) return Array.from({ length: last.value }, (_, index) => index + 1);
  if (current.value <= 4) return [1, 2, 3, 4, 5, '…', last.value];
  if (current.value >= last.value - 3) return [1, '…', last.value - 4, last.value - 3, last.value - 2, last.value - 1, last.value];
  return [1, '…', current.value - 1, current.value, current.value + 1, '…', last.value];
});

function go(page) {
  const next = Math.min(last.value, Math.max(1, Number(page)));
  if (!props.busy && next !== current.value) emit('page-change', next);
}

function chooseSize(size) {
  sizeOpen.value = false;
  if (!props.busy && Number(size) !== perPage.value) emit('per-page-change', Number(size));
}

function closeOnOutsideClick(event) {
  if (sizeOpen.value && !sizeControl.value?.contains(event.target)) sizeOpen.value = false;
}

function closeOnEscape(event) {
  if (event.key === 'Escape') sizeOpen.value = false;
}

onMounted(() => {
  document.addEventListener('pointerdown', closeOnOutsideClick);
  document.addEventListener('keydown', closeOnEscape);
});
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeOnOutsideClick);
  document.removeEventListener('keydown', closeOnEscape);
});
</script>

<template>
  <footer v-if="total > 0" class="pagination pagination-modern">
    <span class="pagination-summary">Ցուցադրված է {{ start }}–{{ end }}՝ {{ total }} {{ itemLabel }}</span>
    <div class="pagination-tools">
      <div ref="sizeControl" class="page-size-control">
        <span>Տող / էջ</span>
        <div class="page-size-picker">
          <button type="button" class="page-size-trigger" :disabled="busy" aria-label="Տողերի քանակը մեկ էջում" :aria-expanded="sizeOpen" aria-haspopup="listbox" @click="sizeOpen=!sizeOpen">
            <span>{{ perPage }}</span><span class="page-size-chevron" aria-hidden="true"></span>
          </button>
          <div v-if="sizeOpen" class="page-size-menu" role="listbox" aria-label="Տողերի քանակը մեկ էջում">
            <button v-for="size in pageSizes" :key="size" type="button" role="option" :aria-selected="size===perPage" :class="{selected:size===perPage}" @click="chooseSize(size)">{{ size }} տող</button>
          </div>
        </div>
      </div>
      <nav class="pagination-controls pagination-pages" aria-label="Էջերի ընտրություն">
        <button type="button" :disabled="busy || current <= 1" aria-label="Առաջին էջ" @click="go(1)">«</button>
        <button type="button" :disabled="busy || current <= 1" aria-label="Նախորդ էջ" @click="go(current - 1)">‹</button>
        <template v-for="(page, index) in pages" :key="`${page}-${index}`">
          <span v-if="page === '…'" class="pagination-ellipsis" aria-hidden="true">…</span>
          <button v-else type="button" :disabled="busy" :class="{ active: page === current }" :aria-current="page === current ? 'page' : undefined" @click="go(page)">{{ page }}</button>
        </template>
        <button type="button" :disabled="busy || current >= last" aria-label="Հաջորդ էջ" @click="go(current + 1)">›</button>
        <button type="button" :disabled="busy || current >= last" aria-label="Վերջին էջ" @click="go(last)">»</button>
      </nav>
    </div>
  </footer>
</template>
