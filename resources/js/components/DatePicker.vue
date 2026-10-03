<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { displayIsoDate, formatArmenianLongDate, formatArmenianMonth, parseDisplayDate, parseIsoDate, toIsoDate } from '../dateUtils';

const props = defineProps({
  modelValue: { type: String, default: '' },
  required: { type: Boolean, default: false },
  min: { type: String, default: '' },
  max: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  placeholder: { type: String, default: 'ՕՕ.ԱԱ.ՏՏՏՏ' },
  ariaLabel: { type: String, default: 'Ընտրել ամսաթիվը' },
});
const emit = defineEmits(['update:modelValue']);

const root = ref(null);
const input = ref(null);
const popover = ref(null);
const isOpen = ref(false);
const typedValue = ref(displayIsoDate(props.modelValue));
const month = ref(monthStart(parseIsoDate(props.modelValue) || new Date()));
const popoverStyle = ref({ top: '0px', left: '0px' });
const weekdayNames = ['Կիր', 'Երկ', 'Երք', 'Չրք', 'Հնգ', 'Ուրբ', 'Շբթ'];
let activeViewport;
const monthDays = computed(() => {
  const first = month.value;
  const offset = first.getDay();
  const start = new Date(first.getFullYear(), first.getMonth(), 1 - offset);
  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
    return {
      key: toIsoDate(date), date,
      inMonth: date.getMonth() === first.getMonth(),
      selected: toIsoDate(date) === props.modelValue,
      today: toIsoDate(date) === toIsoDate(new Date()),
      disabled: Boolean((props.min && toIsoDate(date) < props.min) || (props.max && toIsoDate(date) > props.max)),
    };
  });
});
const monthTitle = computed(() => formatArmenianMonth(month.value));

function monthStart(date) { return new Date(date.getFullYear(), date.getMonth(), 1); }
function formatLong(date) { return formatArmenianLongDate(date); }
function isAllowed(date) {
  const iso = toIsoDate(date);
  return (!props.min || iso >= props.min) && (!props.max || iso <= props.max);
}
function showDateError(message) { input.value?.setCustomValidity(message); }
function validateTypedValue() {
  const value = typedValue.value;
  const date = parseDisplayDate(value);
  showDateError(!value ? '' : !date ? 'Ամսաթիվը մուտքագրեք ՕՕ.ԱԱ.ՏՏՏՏ ձևաչափով։' : valueOfBoundary(toIsoDate(date)));
}
function onInput(event) {
  const value = event.target.value;
  const date = parseDisplayDate(value);
  if (!value) {
    showDateError('');
    emit('update:modelValue', '');
    return;
  }
  if (!date) {
    showDateError('Ամսաթիվը մուտքագրեք ՕՕ.ԱԱ.ՏՏՏՏ ձևաչափով։');
    return;
  }
  if (!isAllowed(date)) {
    const boundary = valueOfBoundary(toIsoDate(date));
    showDateError(boundary);
    return;
  }
  showDateError('');
  emit('update:modelValue', toIsoDate(date));
}
function valueOfBoundary(iso) {
  if (props.min && iso < props.min) return `Ամսաթիվը չի կարող լինել ${displayIsoDate(props.min)}-ից շուտ։`;
  if (props.max && iso > props.max) return `Ամսաթիվը չի կարող լինել ${displayIsoDate(props.max)}-ից ուշ։`;
  return '';
}
function updatePosition() {
  const trigger = root.value?.getBoundingClientRect();
  if (!trigger || !popover.value) return;
  const layoutWidth = document.documentElement.clientWidth || window.innerWidth;
  const layoutHeight = document.documentElement.clientHeight || window.innerHeight;
  const viewport = window.visualViewport;
  const leftEdge = Math.max(0, Math.min(viewport?.offsetLeft || 0, layoutWidth));
  const topEdge = Math.max(0, Math.min(viewport?.offsetTop || 0, layoutHeight));
  const rightEdge = Math.min(layoutWidth, leftEdge + (viewport?.width || layoutWidth));
  const bottomEdge = Math.min(layoutHeight, topEdge + (viewport?.height || layoutHeight));
  const width = Math.max(0, Math.min(360, rightEdge - leftEdge - 24));
  const maxHeight = Math.max(0, bottomEdge - topEdge - 16);
  // Measure after setting width: the calendar's square days shrink on narrow screens.
  popover.value.style.width = `${width}px`;
  const borderHeight = popover.value.offsetHeight - popover.value.clientHeight;
  const naturalHeight = popover.value.scrollHeight + Math.max(0, borderHeight);
  const height = Math.min(maxHeight, naturalHeight || maxHeight);
  const below = bottomEdge - trigger.bottom - 8;
  const above = trigger.top - topEdge - 8;
  const preferredTop = below >= height || below >= above ? trigger.bottom + 8 : trigger.top - height - 8;
  const top = Math.max(topEdge + 8, Math.min(preferredTop, bottomEdge - height - 8));
  const left = Math.max(leftEdge + 12, Math.min(trigger.left, rightEdge - width - 12));
  popoverStyle.value = { top: `${top}px`, left: `${left}px`, width: `${width}px`, maxHeight: `${maxHeight}px` };
}
function onOutside(event) {
  if (!root.value?.contains(event.target) && !event.target.closest?.('.date-picker-popover')) close();
}
function open() {
  if (props.disabled || isOpen.value) return;
  const selected = parseIsoDate(props.modelValue);
  month.value = monthStart(selected || new Date());
  isOpen.value = true;
  nextTick(updatePosition);
  document.addEventListener('pointerdown', onOutside, true);
  window.addEventListener('resize', updatePosition);
  window.addEventListener('scroll', updatePosition, true);
  activeViewport = window.visualViewport;
  activeViewport?.addEventListener('resize', updatePosition);
  activeViewport?.addEventListener('scroll', updatePosition);
}
function close() {
  isOpen.value = false;
  document.removeEventListener('pointerdown', onOutside, true);
  window.removeEventListener('resize', updatePosition);
  window.removeEventListener('scroll', updatePosition, true);
  activeViewport?.removeEventListener('resize', updatePosition);
  activeViewport?.removeEventListener('scroll', updatePosition);
  activeViewport = null;
}
function choose(date) {
  if (!isAllowed(date)) return;
  const iso = toIsoDate(date);
  typedValue.value = displayIsoDate(iso);
  showDateError('');
  emit('update:modelValue', iso);
  close();
}
function clearDate() {
  typedValue.value = '';
  showDateError('');
  emit('update:modelValue', '');
  close();
  input.value?.focus();
}
function chooseToday() { if (isAllowed(new Date())) choose(new Date()); }
function shiftMonth(amount) { month.value = new Date(month.value.getFullYear(), month.value.getMonth() + amount, 1); }
function keydown(event) {
  if (event.key === 'Escape' && isOpen.value) close();
  else if (event.key === 'ArrowDown' && !isOpen.value) { event.preventDefault(); open(); }
}

watch(() => props.modelValue, (value) => {
  typedValue.value = displayIsoDate(value);
  if (isOpen.value && parseIsoDate(value)) month.value = monthStart(parseIsoDate(value));
  validateTypedValue();
});
watch(() => [props.min, props.max], validateTypedValue);
onMounted(validateTypedValue);
onBeforeUnmount(close);
</script>

<template>
  <div ref="root" class="date-picker-control" :class="{ 'is-disabled': disabled }">
    <button class="date-picker-trigger" type="button" :disabled="disabled" :aria-label="ariaLabel" :aria-expanded="isOpen" @click="open"><svg class="date-picker-calendar-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16M8 14h2M14 14h2M8 18h2"/></svg></button>
    <input ref="input" v-model="typedValue" class="date-picker-input" type="text" inputmode="numeric" autocomplete="off" maxlength="10" pattern="\d{2}\.\d{2}\.\d{4}" :placeholder="placeholder" :required="required" :disabled="disabled" :aria-label="ariaLabel" @input="onInput" @keydown="keydown">
  </div>
  <Teleport to="body">
    <section v-if="isOpen" ref="popover" class="date-picker-popover" :style="popoverStyle" role="dialog" aria-label="Օրացույց">
      <header class="date-picker-head"><span class="date-picker-badge"><svg class="date-picker-calendar-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16M8 14h2M14 14h2M8 18h2"/></svg></span><div><small>ՕՐԱՑՈՒՅՑ</small><strong>{{ displayIsoDate(modelValue) || 'Ընտրեք ամսաթիվը' }}</strong></div><button class="date-picker-close" type="button" aria-label="Փակել օրացույցը" @click="close"><AppIcon name="xmark" /></button></header>
      <div class="date-picker-month"><button type="button" aria-label="Նախորդ ամիս" @click="shiftMonth(-1)"><AppIcon name="arrowLeft" /></button><strong>{{ monthTitle }}</strong><button type="button" aria-label="Հաջորդ ամիս" @click="shiftMonth(1)"><AppIcon name="arrowRight" /></button></div>
      <div class="date-picker-weekdays" role="row"><span v-for="day in weekdayNames" :key="day">{{ day }}</span></div>
      <div class="date-picker-days" role="grid"><button v-for="day in monthDays" :key="day.key" type="button" role="gridcell" :disabled="day.disabled" :aria-label="formatLong(day.date)" :aria-pressed="day.selected" :class="{ 'is-outside': !day.inMonth, 'is-selected': day.selected, 'is-today': day.today }" @click="choose(day.date)">{{ day.date.getDate() }}<i v-if="day.today"></i></button></div>
      <footer class="date-picker-footer"><button type="button" class="date-picker-clear" :disabled="!modelValue" @click="clearDate"><AppIcon name="xmark" /> Մաքրել</button><button type="button" @click="chooseToday"><svg class="date-picker-calendar-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16M8 14h2M14 14h2M8 18h2"/></svg> Այսօր</button></footer>
    </section>
  </Teleport>
</template>
