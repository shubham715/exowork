import assert from 'node:assert/strict';
import { test } from 'node:test';
import axios from 'axios';

const meta = { content: 'initial' };
globalThis.window = { location: { href: 'http://localhost/admin', origin: 'http://localhost' } };
globalThis.document = { querySelector: () => meta };
await import('../../resources/js/bootstrap.js');

const response = (config, data = {}) => ({ config, data, status: 200, headers: {}, statusText: 'OK' });
const reject = (config, status) => Promise.reject(new axios.AxiosError('Rejected', undefined, config, undefined, { status, config }));

test('synchronizes rotated auth tokens and overrides stale form headers', async () => {
    meta.content = 'old';
    axios.defaults.adapter = async config => {
        if (config.url === '/auth/login') return response(config, { csrf_token: 'rotated' });
        assert.equal(config.headers.get('X-CSRF-TOKEN'), 'rotated');
        return response(config);
    };
    await axios.post('/auth/login', {});
    await axios.patch('/admin-api/settings', {}, { headers: { 'X-CSRF-TOKEN': 'old' } });
    assert.equal(meta.content, 'rotated');
});

test('concurrent stale submissions share one refresh and preserve upload data', async () => {
    meta.content = 'expired';
    let refreshes = 0;
    const upload = new FormData();
    upload.append('name', 'Candidate');
    axios.defaults.adapter = async config => {
        if (config.url === '/auth/csrf-token') {
            refreshes++;
            await new Promise(resolve => setTimeout(resolve, 10));
            return response(config, { csrf_token: 'fresh' });
        }
        if (!config._csrfRetried) return reject(config, 419);
        assert.equal(config.headers.get('X-CSRF-TOKEN'), 'fresh');
        if (config.url === '/upload') assert.equal(config.data, upload);
        return response(config);
    };
    await Promise.all([axios.post('/upload', upload), axios.delete('/record')]);
    assert.equal(refreshes, 1);
});

test('persistent mismatch stops after one retry', async () => {
    let submissions = 0;
    axios.defaults.adapter = async config => {
        if (config.url === '/auth/csrf-token') return response(config, { csrf_token: 'fresh' });
        submissions++;
        return reject(config, 419);
    };
    await assert.rejects(axios.put('/record', {}));
    assert.equal(submissions, 2);
});

test('does not retry unrelated failures or send tokens to other origins', async () => {
    let requests = 0;
    axios.defaults.adapter = async config => {
        requests++;
        if (config.url.startsWith('https://')) assert.equal(config.headers.get('X-CSRF-TOKEN'), undefined);
        return reject(config, config.url.startsWith('https://') ? 419 : 422);
    };
    await assert.rejects(axios.post('/record', {}));
    await assert.rejects(axios.post('https://example.com/record', {}));
    assert.equal(requests, 2);
});
