<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '@/services/api';
import { currentUser } from '@/router';

const props = defineProps({
  page: { type: String, required: true },
  search: { type: String, default: '' },
  barcode: { type: String, default: '' },
  threshold: { type: String, default: '' },
  filters: { type: Object, default: () => ({}) },
  endpoint: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
});

const exporting = ref(false);
const error = ref('');
const user = ref(currentUser());
const canExport = computed(() => Boolean(user.value?.permissions?.[`${props.page}.export`]));
const updateUser = (event) => { user.value = event.detail; };

onMounted(() => window.addEventListener('lager:user', updateUser));
onBeforeUnmount(() => window.removeEventListener('lager:user', updateUser));

async function download(format) {
  if (exporting.value || props.disabled) return;
  exporting.value = true;
  error.value = '';
  try {
    const response = await api.get(props.endpoint || `pages/${props.page}/export`, {
      params: { ...props.filters, search: props.search || undefined, barcode: props.barcode || undefined, threshold: props.threshold || undefined, format },
      responseType: 'blob',
    });
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `diagen-${props.page}.${format}`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (exception) {
    error.value = exception.response?.data?.message || 'Արտահանումը չհաջողվեց։ Կրկին փորձեք։';
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <div v-if="canExport" class="export-actions">
    <button class="secondary-button compact-action" type="button" :disabled="disabled || exporting" @click="download('csv')">
      {{ exporting ? 'Պատրաստվում է…' : 'CSV' }}
    </button>
    <button class="secondary-button compact-action" type="button" :disabled="disabled || exporting" @click="download('xlsx')">Excel</button>
    <span v-if="error" class="export-error" role="alert">{{ error }}</span>
  </div>
</template>
