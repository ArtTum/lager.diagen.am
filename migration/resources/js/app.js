import { createApp, defineAsyncComponent } from 'vue';
import App from './App.vue';
import AppIcon from './components/AppIcon.vue';
const DatePicker = defineAsyncComponent(() => import('./components/DatePicker.vue'));
import { searchableSelect } from './directives/searchableSelect';
import { installArmenianNativeValidation } from './services/armenianValidation';
import router from './router';
import './bootstrap';
import './services/api';
import './directives/searchableSelect.css';
import '../css/app.css';

installArmenianNativeValidation();

createApp(App).component('AppIcon', AppIcon).component('DatePicker', DatePicker).directive('searchable-select', searchableSelect).use(router).mount('#app');
