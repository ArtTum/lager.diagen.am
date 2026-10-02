import { createRouter, createWebHistory } from 'vue-router';
import api from '@/services/api';
import { firstAvailablePath, userContextChanged } from './access';

const Login = () => import('@/views/auth/Login.vue');
const Dashboard = () => import('@/views/dashboard/Index.vue');
const Suppliers = () => import('@/views/suppliers/Index.vue');
const PageTable = () => import('@/views/PageTable.vue');
const CatalogTable = () => import('@/views/CatalogTable.vue');
const Transfers = () => import('@/views/transfers/Index.vue');
const Requests = () => import('@/views/requests/Index.vue');
const Purchasing = () => import('@/views/purchasing/Index.vue');
const Stock = () => import('@/views/stock/Index.vue');
const StockMatrix = () => import('@/views/stock/Matrix.vue');
const ProductLabel = () => import('@/views/products/Label.vue');
const Inventory = () => import('@/views/inventory/Index.vue');
const Returns = () => import('@/views/returns/Index.vue');
const Movements = () => import('@/views/movements/Index.vue');
const Notifications = () => import('@/views/notifications/Index.vue');
const Reports = () => import('@/views/reports/Index.vue');
const Expiry = () => import('@/views/expiry/Index.vue');
const Audit = () => import('@/views/audit/Index.vue');
const DispatchDocument = () => import('@/views/requests/DispatchDocument.vue');
const NoAccess = () => import('@/views/NoAccess.vue');
const InventoryAct = () => import('@/views/inventory/Act.vue');

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
let userToken = null;
let contextRequestVersion = 0;

export function currentUser() { return user; }
export function setCurrentUser(value) {
    contextRequestVersion += 1;
    user = value;
    userLoaded = Boolean(value);
    userToken = value ? localStorage.getItem('lagerAuthToken') : null;
    window.dispatchEvent(new CustomEvent('lager:user', { detail: value }));
}

export async function refreshCurrentUser() {
    const token = localStorage.getItem('lagerAuthToken');
    if (!token) {
        setCurrentUser(null);
        return null;
    }
    const requestVersion = ++contextRequestVersion;
    const response = await api.get('auth/me');
    if (localStorage.getItem('lagerAuthToken') !== token || requestVersion !== contextRequestVersion) return currentUser();
    const freshUser = response.data.data;
    if (userContextChanged(user, freshUser)) setCurrentUser(freshUser);
    userLoaded = true;
    userToken = token;

    return freshUser;
}

window.addEventListener('lager:unauthorized', () => {
    setCurrentUser(null);
    if (router.currentRoute.value.path !== '/login') router.replace('/login');
});

window.addEventListener('storage', (event) => {
    if (event.key !== 'lagerAuthToken' && event.key !== null) return;
    setCurrentUser(null);
    if (localStorage.getItem('lagerAuthToken')) window.location.reload();
    else if (router.currentRoute.value.path !== '/login') router.replace('/login');
});

router.beforeEach(async (to) => {
    const token = localStorage.getItem('lagerAuthToken');
    if (!token) {
        setCurrentUser(null);
        return to.meta.guest ? true : '/login';
    }
    if (userToken !== token) setCurrentUser(null);
    if (!userLoaded) {
        try {
            await refreshCurrentUser();
        } catch (error) {
            // Network/server failures are retryable. Only invalidate the token
            // checked by this request, never a login completed while it waited.
            if (error.response?.status === 401 && localStorage.getItem('lagerAuthToken') === token) {
                localStorage.removeItem('lagerAuthToken');
                setCurrentUser(null);
            }
        }
    }
    if (!user) return to.meta.guest ? true : '/login';
    if (to.meta.guest) return firstAvailablePath(routes, user.permissions);
    if (to.meta.permission && !user.permissions?.[to.meta.permission]) return firstAvailablePath(routes, user.permissions);
    return true;
});

export default router;
