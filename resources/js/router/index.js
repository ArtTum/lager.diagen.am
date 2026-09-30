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
import StockMatrix from '@/views/stock/Matrix.vue';
import ProductLabel from '@/views/products/Label.vue';
import Inventory from '@/views/inventory/Index.vue';
import Returns from '@/views/returns/Index.vue';
import Movements from '@/views/movements/Index.vue';
import Notifications from '@/views/notifications/Index.vue';
import Reports from '@/views/reports/Index.vue';
import Expiry from '@/views/expiry/Index.vue';
import Audit from '@/views/audit/Index.vue';
import DispatchDocument from '@/views/requests/DispatchDocument.vue';
import NoAccess from '@/views/NoAccess.vue';
import InventoryAct from '@/views/inventory/Act.vue';
import { firstAvailablePath, userContextChanged } from './access';

const routes = [
    { path: '/', redirect: '/dashboard' },
    { path: '/login', component: Login, meta: { guest: true } },
    { path: '/no-access', component: NoAccess, meta: { title: 'Հասանելիություն' } },
    { path: '/dashboard', component: Dashboard, meta: { permission: 'dashboard.view', title: 'Գլխավոր վահանակ' } },
    { path: '/suppliers', component: Suppliers, meta: { permission: 'suppliers.view', title: 'Մատակարարներ' } },
    { path: '/stock/matrix', component: StockMatrix, meta: { permission: 'stock.view', title: 'Պահեստների մնացորդների մատրիցա' } },
    { path: '/products/:product/label', component: ProductLabel, meta: { permission: 'products.view', title: 'Ապրանքի պիտակ' } },
    { path: '/requests/:request/dispatch', component: DispatchDocument, meta: { permission: 'requests.view', title: 'Բաշխման փաստաթուղթ' } },
    { path: '/inventory/:session/act', component: InventoryAct, meta: { permission: 'inventory.view', title: 'Գույքագրման ակտ' } },
    ...[
        ['products', 'Ապրանքներ'], ['branches', 'Մասնաճյուղեր'], ['purchases', 'Գնումների պատվերներ'],
        ['receipts', 'Մուտքեր'], ['stock', 'Ընդհանուր մնացորդ'], ['requests', 'Պահանջագրեր'],
        ['movements', 'Պահեստի շարժ'], ['inventory', 'Գույքագրում'], ['expiry', 'Ժամկետների վերահսկում'],
        ['returns', 'Վերադարձներ'], ['transfers', 'Տեղափոխումներ'], ['notifications', 'Ծանուցումներ'],
        ['reports', 'Հաշվետվություններ'], ['users', 'Օգտատերեր'], ['roles', 'Դերեր և իրավունքներ'],
        ['audit', 'Գործողությունների պատմություն'],
    ].map(([path, title]) => ({ path: `/${path}`, component: path === 'transfers' ? Transfers : path === 'requests' ? Requests : ['purchases', 'receipts'].includes(path) ? Purchasing : path === 'stock' ? Stock : path === 'inventory' ? Inventory : path === 'returns' ? Returns : path === 'movements' ? Movements : path === 'notifications' ? Notifications : path === 'reports' ? Reports : path === 'expiry' ? Expiry : path === 'audit' ? Audit : (['branches', 'products', 'users', 'roles'].includes(path) ? CatalogTable : PageTable), meta: { title, permission: `${path}.view` } })),
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

const router = createRouter({ history: createWebHistory(), routes });
let user = null;
let userLoaded = false;
let contextRequestVersion = 0;

export function currentUser() { return user; }
export function setCurrentUser(value) {
    user = value;
    userLoaded = true;
    window.dispatchEvent(new CustomEvent('lager:user', { detail: value }));
}

export async function refreshCurrentUser() {
    const token = localStorage.getItem('lagerAuthToken');
    if (!token) return null;
    const requestVersion = ++contextRequestVersion;
    const response = await api.get('auth/me');
    if (localStorage.getItem('lagerAuthToken') !== token || requestVersion !== contextRequestVersion) return currentUser();
    const freshUser = response.data.data;
    if (userContextChanged(user, freshUser)) setCurrentUser(freshUser);
    userLoaded = true;

    return freshUser;
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
            await refreshCurrentUser();
        } catch {
            localStorage.removeItem('lagerAuthToken');
            setCurrentUser(null);
        }
        userLoaded = true;
    }
    if (!user) return to.meta.guest ? true : '/login';
    if (to.meta.guest) return firstAvailablePath(routes, user.permissions);
    if (to.meta.permission && !user.permissions?.[to.meta.permission]) return firstAvailablePath(routes, user.permissions);
    return true;
});

export default router;
