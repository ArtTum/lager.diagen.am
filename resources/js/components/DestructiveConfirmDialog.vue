<script setup>
import { nextTick, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps({
    title: { type: String, required: true },
    entity: { type: String, default: '' },
    description: { type: String, required: true },
    confirmLabel: { type: String, default: 'Ջնջել' },
    busyLabel: { type: String, default: 'Կատարվում է…' },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['confirm', 'cancel']);
const cancelButton = ref(null);
watch(() => props.title, async (title) => {
    if (!title) return;
    await nextTick();
    cancelButton.value?.focus();
}, { immediate: true });
</script>

<template>
    <Teleport to="body">
        <div class="modal-backdrop destructive-confirm-backdrop" @click.self="!busy && emit('cancel')" @keydown.esc="!busy && emit('cancel')">
            <section class="modal-card destructive-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="destructive-confirm-title" aria-describedby="destructive-confirm-description">
                <div class="destructive-confirm-icon" aria-hidden="true"><AppIcon name="alert" /></div>
                <h2 id="destructive-confirm-title">{{ title }}</h2>
                <p v-if="entity" class="destructive-confirm-entity">{{ entity }}</p>
                <p id="destructive-confirm-description" class="destructive-confirm-description">{{ description }}</p>
                <p v-if="error" class="form-error" role="alert">{{ error }}</p>
                <div class="destructive-confirm-actions">
                    <button ref="cancelButton" type="button" class="secondary-button" :disabled="busy" @click="emit('cancel')">Չեղարկել</button>
                    <button type="button" class="destructive-confirm-submit" :disabled="busy" @click="emit('confirm')">{{ busy ? busyLabel : confirmLabel }}</button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
