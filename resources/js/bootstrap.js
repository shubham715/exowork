import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const isLocal = (config) => new URL(config.url, config.baseURL || window.location.href).origin === window.location.origin;
const isMutation = (config) => !['get', 'head', 'options'].includes((config.method || 'get').toLowerCase());
const setToken = (token) => {
    if (typeof token !== 'string' || !token) return;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.content = token;
};
let tokenRefresh;

axios.interceptors.request.use((config) => {
    if (isLocal(config) && isMutation(config)) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (token) config.headers.set('X-CSRF-TOKEN', token);
    }
    return config;
});

axios.interceptors.response.use((response) => {
    if (isLocal(response.config)) setToken(response.data?.csrf_token);
    return response;
}, async (error) => {
    const config = error.config;
    if (error.response?.status !== 419 || !config || !isLocal(config) || !isMutation(config) || config._csrfRetried) {
        throw error;
    }

    // A CSRF rejection happens before the controller executes. Retry only once,
    // sharing the refresh when several forms encounter an expired token together.
    config._csrfRetried = true;
    tokenRefresh ||= axios.get('/auth/csrf-token').finally(() => { tokenRefresh = null; });
    const { data } = await tokenRefresh;
    if (typeof data.csrf_token !== 'string' || !data.csrf_token) throw error;
    config.headers.set('X-CSRF-TOKEN', data.csrf_token);
    return axios(config);
});
