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
    if (error.response?.status === 401 && localStorage.getItem('lagerAuthToken')) {
        localStorage.removeItem('lagerAuthToken');
        window.dispatchEvent(new CustomEvent('lager:unauthorized'));
    }
    return Promise.reject(error);
});

export default api;
