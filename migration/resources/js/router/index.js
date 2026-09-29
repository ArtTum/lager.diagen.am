import { createRouter, createWebHistory } from 'vue-router';
import api from '@/services/api';
import Login from '@/views/auth/Login.vue';
import Dashboard from '@/views/dashboard/Index.vue';
import Suppliers from '@/views/suppliers/Index.vue';
import PageTable from '@/views/PageTable.vue';
import CatalogTable from '@/views/CatalogTable.vue';
import Transfers from '@/views/transfers/Index.vue';
import Requests from '@/views/requests/Index.vue';
import Purchasing from '@/views/purchasing/Index.vue';
import Stock from '@/views/stock/Index.vue';
import ComingSoon from '@/views/ComingSoon.vue';

const routes = [
    { path: '/', redirect: '/dashboard' },
    { path: '/login', component: Login, meta: { guest: true } },
    { path: '/dashboard', component: Dashboard, meta: { permission: 'dashboard.view', title: 'Գլխավոր վահանակ' } },
    { path: '/suppliers', component: Suppliers, meta: { permission: 'suppliers.view', title: 'Մատակարարներ' } },
    ...[
        ['products', 'Ապրանքներ'], ['branches', 'Մասնաճյուղեր'], ['purchases', 'Գնումների պատվերներ'],
        ['receipts', 'Մուտքեր'], ['stock', 'Ընդհանուր մնացորդ'], ['requests', 'Պահանջագրեր'],
        ['movements', 'Պահեստի շարժ'], ['inventory', 'Գույքագրում'], ['expiry', 'Ժամկետների վերահսկում'],
        ['returns', 'Վերադարձներ'], ['transfers', 'Տեղափոխումներ'], ['notifications', 'Ծանուցումներ'],
        ['reports', 'Հաշվետվություններ'], ['users', 'Օգտատերեր'], ['roles', 'Դերեր և իրավունքներ'],
        ['audit', 'Գործողությունների պատմություն'],
    ].map(([path, title]) => ({ path: `/${path}`, component: path === 'transfers' ? Transfers : path === 'requests' ? Requests : ['purchases', 'receipts'].includes(path) ? Purchasing : path === 'stock' ? Stock : (['branches', 'products', 'users', 'roles'].includes(path) ? CatalogTable : (['notifications', 'reports'].includes(path) ? ComingSoon : PageTable)), meta: { title, permission: `${path}.view` } })),
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

const router = createRouter({ history: createWebHistory(), routes });
let user = null;
let userLoaded = false;

export function currentUser() { return user; }
export function setCurrentUser(value) {
    user = value;
    userLoaded = true;
    window.dispatchEvent(new CustomEvent('lager:user', { detail: value }));
}

window.addEventListener('lager:unauthorized', () => {
    setCurrentUser(null);
    if (router.currentRoute.value.path !== '/login') router.replace('/login');
});

router.beforeEach(async (to) => {
    const token = localStorage.getItem('lagerAuthToken');
    if (!token) {
        setCurrentUser(null);
        return to.meta.guest ? true : '/login';
    }
    if (!userLoaded) {
        try {
            const response = await api.get('auth/me');
            setCurrentUser(response.data.data);
        } catch {
            localStorage.removeItem('lagerAuthToken');
            setCurrentUser(null);
        }
        userLoaded = true;
    }
    if (!user) return to.meta.guest ? true : '/login';
    if (to.meta.guest) return '/dashboard';
    if (to.meta.permission && !user.permissions?.[to.meta.permission]) return '/dashboard';
    return true;
});

export default router;
