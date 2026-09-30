<script setup>
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import {
  faArrowDown, faArrowLeft, faArrowRight, faArrowRightFromBracket,
  faArrowRightArrowLeft, faArrowTrendDown, faArrowTrendUp, faBarcode,
  faArrowsRotate, faBell, faBox, faBoxesStacked, faChartColumn, faChevronDown,
  faCircleCheck, faClipboardCheck, faClock, faClockRotateLeft, faFileCirclePlus,
  faGaugeHigh, faGear, faMagnifyingGlass, faPen, faPlus, faPrint, faRotateLeft, faShieldHalved,
  faSliders, faTrash, faTriangleExclamation, faTruckFast, faUser, faUsers, faVolumeHigh, faVolumeXmark, faWarehouse,
  faXmark,
} from '@fortawesome/free-solid-svg-icons';

defineProps({ name: { type: String, required: true } });

const icons = {
  add: faPlus,
  accountSettings: faGear,
  adjust: faSliders,
  alert: faTriangleExclamation,
  arrowDown: faArrowDown,
  arrowLeft: faArrowLeft,
  arrowRight: faArrowRight,
  barcode: faBarcode,
  bell: faBell,
  box: faBox,
  boxes: faBoxesStacked,
  branches: faWarehouse,
  chart: faChartColumn,
  chevronDown: faChevronDown,
  clipboard: faClipboardCheck,
  clock: faClock,
  dashboard: faGaugeHigh,
  edit: faPen,
  logout: faArrowRightFromBracket,
  movements: faArrowRightArrowLeft,
  plusFile: faFileCirclePlus,
  print: faPrint,
  profile: faUser,
  returns: faRotateLeft,
  refresh: faArrowsRotate,
  search: faMagnifyingGlass,
  shield: faShieldHalved,
  success: faCircleCheck,
  trendDown: faArrowTrendDown,
  trendUp: faArrowTrendUp,
  transfers: faTruckFast,
  trash: faTrash,
  users: faUsers,
  volumeOn: faVolumeHigh,
  volumeOff: faVolumeXmark,
  xmark: faXmark,
  history: faClockRotateLeft,
};
</script>

<template>
  <FontAwesomeIcon class="app-icon" :icon="icons[name] || icons.box" aria-hidden="true" />
</template>
