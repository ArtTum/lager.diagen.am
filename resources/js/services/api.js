import axios from 'axios';

const api = axios.create({
    baseURL: '/api/',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('lagerAuthToken');
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
});

api.interceptors.response.use((response) => response, (error) => {
    const token = localStorage.getItem('lagerAuthToken');
    const authorization = error.config?.headers?.get?.('Authorization') ?? error.config?.headers?.Authorization;
    // A delayed response from an expired session must not clear a newer login.
    if (error.response?.status === 401 && token
        && authorization === `Bearer ${token}`) {
        localStorage.removeItem('lagerAuthToken');
        window.dispatchEvent(new CustomEvent('lager:unauthorized'));
    }
    return Promise.reject(error);
});

export default api;
