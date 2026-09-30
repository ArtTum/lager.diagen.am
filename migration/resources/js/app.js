import { createApp } from 'vue';
import App from './App.vue';
import AppIcon from './components/AppIcon.vue';
import router from './router';
import './bootstrap';
import './services/api';
import '../css/app.css';

createApp(App).component('AppIcon', AppIcon).use(router).mount('#app');
