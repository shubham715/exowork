import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter, Routes, Route } from 'react-router-dom';

const compiled = await build({ stdin: { contents: "export {default, MessageComposer} from './resources/js/pages/admin/CandidateNotifications.jsx'; export {default as Layout} from './resources/js/layouts/AdminLayout.jsx';", resolveDir: process.cwd(), loader: 'jsx' }, bundle: true, write: false, platform: 'node', format: 'cjs', loader: { '.css': 'empty' }, external: ['react', 'react-dom', 'react-router-dom', 'axios', 'lucide-react'], plugins: [{ name: 'notification-fixtures', setup(builder) {
    builder.onLoad({ filter: /CandidateNotifications\.jsx$/ }, async ({ path }) => {
        let source = await readFile(path, 'utf8');
        const start = source.indexOf('export function useNotificationCandidates('), end = source.indexOf('export default function CandidateNotifications', start);
        source = source.slice(0, start) + 'export function useNotificationCandidates(){return globalThis.cnDirectoryFixture;}\n' + source.slice(end);
        source = source.replace('[selected, setSelected] = useState({})', '[selected, setSelected] = useState(globalThis.cnSelectedFixture || {})').replace('[compose, setCompose] = useState(null)', '[compose, setCompose] = useState(globalThis.cnComposeFixture || null)');
        source = source.replace('[context, setContext] = useState(null), [contextLoading, setContextLoading] = useState(true)', '[context, setContext] = useState(globalThis.cnContextFixture), [contextLoading, setContextLoading] = useState(false)');
        source = source.replace('[preview, setPreview] = useState(null), [result, setResult] = useState(null)', '[preview, setPreview] = useState(globalThis.cnPreviewFixture || null), [result, setResult] = useState(globalThis.cnResultFixture || null)');
        source = source.replace("[templateKey, setTemplateKey] = useState('')", "[templateKey, setTemplateKey] = useState(globalThis.cnTemplateFixture || '')").replace('[selectedJob, setSelectedJob] = useState(null)', '[selectedJob, setSelectedJob] = useState(globalThis.cnJobFixture || null)');
        return { contents: source, loader: 'jsx' };
    });
} }] });
const module = { exports: {} };
new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(createRequire(import.meta.url), module, module.exports);
const { default: Page, MessageComposer, Layout } = module.exports;
const rows = [
    { id: 1, first_name: 'Asha', last_name: 'Sharma', candidate_code: 'EXO-CAN-000001', email: 'asha@example.com', whatsapp: '9876543211', registered_on: 'November 27, 2026', whatsapp_consent: true, email_consent: true },
    { id: 2, first_name: 'Rohan', last_name: 'Mehta', candidate_code: 'EXO-CAN-000002', email: 'rohan@example.com', whatsapp: '9876543212', registered_on: 'November 26, 2026', whatsapp_consent: true, email_consent: false },
    { id: 3, first_name: 'Priya', last_name: 'Verma', candidate_code: 'EXO-CAN-000003', email: 'priya@example.com', whatsapp: '9876543213', registered_on: 'November 25, 2026', whatsapp_consent: true, email_consent: true },
];
const fields = { name: 'Candidate full name', first_name: 'Candidate first name', last_name: 'Candidate last name', email: 'Email address', whatsapp: 'WhatsApp number', candidate_code: 'Candidate ID', qualification: 'Education / qualification', district: 'Home district', state: 'State', training_center: 'Training center', registered_on: 'Registration date', app_name: 'Application name' };
function setup() {
    globalThis.cnTemplateFixture = ''; globalThis.cnJobFixture = null;
    globalThis.cnDirectoryFixture = { data: { data: rows, total: 457, from: 1, to: 3, current_page: 1, last_page: 19, can_whatsapp: true, can_email: true, counts: { all: 457, whatsapp: 380, email: 245 } }, loading: false, error: '' };
    globalThis.cnSelectedFixture = {}; globalThis.cnComposeFixture = null; globalThis.cnPreviewFixture = null; globalThis.cnResultFixture = null;
    globalThis.cnContextFixture = { configured: true, fields, templates: [{ name: 'candidate_update', language: 'en', category: 'UTILITY', body: 'Hello {{1}}, we have an update for you.', parameter_count: 1 }] };
}
const renderPage = () => renderToStaticMarkup(React.createElement(MemoryRouter, { initialEntries: ['/admin/candidates/notifications'] }, React.createElement(Routes, null, React.createElement(Route, { path: '/admin', element: React.createElement(Layout) }, React.createElement(Route, { path: 'candidates/notifications', element: React.createElement(Page) })))));
const renderComposer = channel => renderToStaticMarkup(React.createElement(MessageComposer, { channel, recipients: rows.slice(0, 2), onClose() {}, onSubmitted() {} }));

test('directory shows contact details, formatted dates and bulk actions only after selection', () => {
    setup(); const plain = renderPage();
    for (const label of ['Asha Sharma', 'asha@example.com', '9876543211', 'November 27, 2026', 'Candidate notifications', 'Search candidates']) assert.ok(plain.includes(label), label);
    assert.ok(!plain.includes('Send bulk WhatsApp'));
    globalThis.cnSelectedFixture = { 1: rows[0], 2: rows[1] };
    const selected = renderPage(); assert.ok(selected.includes('Send bulk WhatsApp')); assert.ok(selected.includes('Send bulk email')); assert.ok(selected.includes('2 selected'));
});
test('email composer explains copyable personalization and requires content before proceeding', () => {
    setup(); const html = renderComposer('email');
    for (const text of ['Email content', 'Subject', '{{first_name}}', 'Candidate first name', 'Copy {{first_name}}', 'Proceed to preview']) assert.ok(html.includes(text), text);
    assert.match(html, /disabled=""[^>]*><[^>]*.*?Proceed to preview/s);
});
test('recipient preview renders exact individual content and disabled send for blocked recipients', () => {
    setup(); globalThis.cnPreviewFixture = { id: 'preview', ready: 0, skipped: 1, rows: [{ id: 1, name: 'Asha Sharma', recipient: 'asha@example.com', subject: 'Hello Asha', body: 'A personal update for Asha.', error: 'No recorded email consent.' }] };
    const html = renderComposer('email');
    for (const text of ['Hello Asha', 'A personal update for Asha.', 'No recorded email consent.', 'Will be skipped', 'Preview recipient']) assert.ok(html.includes(text), text);
    assert.ok(html.includes('Send to 0 recipients'));
});

test('job-opportunity template asks for one job and shows its actual values instead of manual variables', () => {
    setup(); globalThis.cnTemplateFixture = 'job_opportunity:en';
    globalThis.cnContextFixture.templates = [{ name: 'job_opportunity', language: 'en', category: 'MARKETING', body: 'Hello {{1}}, role {{2}}, location {{3}}, salary {{4}}, link {{5}}.', parameter_count: 5, requires_job: true, context_mode: 'automatic', system_key: 'job_opportunity', source_description: 'Candidate profile and selected job', labels: ['Candidate name', 'Job role'], inputs: [] }];
    globalThis.cnJobFixture = { id: 42, role: 'Warehouse Assistant', location: 'Jaipur', salary: 'INR 18,000 - 22,000', url: 'https://example.com/candidate/opportunities' };
    const html = renderComposer('whatsapp');
    for (const text of ['Job opportunity', 'Warehouse Assistant', 'Monthly salary', 'INR 18,000 - 22,000', 'Values filled automatically']) assert.ok(html.includes(text), text);
    assert.ok(!html.includes('Value source'));
});
test('career template requests its shared video URL while candidate values are automatic', () => {
    setup(); globalThis.cnTemplateFixture = 'career_intro:en';
    globalThis.cnContextFixture.templates = [{ name: 'career_intro', language: 'en', category: 'MARKETING', body: 'Hello {{1}}, watch {{2}} and visit {{3}}.', parameter_count: 3, context_mode: 'automatic', system_key: 'career_intro', source_description: 'Candidate profile and video link', labels: ['Candidate name', 'Opportunities URL'], inputs: [{ key: 'career_video_url', label: 'Career video URL', type: 'url' }] }];
    const html = renderComposer('whatsapp');
    assert.ok(html.includes('Career video URL')); assert.ok(html.includes('Values filled automatically')); assert.ok(!html.includes('Value source'));
});

if (process.env.CN_PREVIEW_DIR) {
    const out = process.env.CN_PREVIEW_DIR; await mkdir(out, { recursive: true });
    const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
    const css = '/build/' + manifest['resources/js/main.jsx'].css[0];
    const save = async (name, html) => writeFile(`${out}/${name}.html`, `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${css}"><title>Candidate messaging UI review</title></head><body>${html}</body></html>`);
    setup(); globalThis.cnSelectedFixture = { 1: rows[0], 2: rows[1] }; await save('directory', renderPage());
    globalThis.cnComposeFixture = { channel: 'email', recipients: rows.slice(0, 2) }; await save('email', renderPage());
    globalThis.cnComposeFixture = { channel: 'whatsapp', recipients: rows.slice(0, 2) };
    globalThis.cnPreviewFixture = { id: 'preview', ready: 2, skipped: 0, rows: [{ id: 1, name: 'Asha Sharma', recipient: '919876543211', body: 'Hello Asha,\n\nWe have an update about your candidate profile. Please contact the Exowork team for the next steps.\n\nThank you,\nExowork', error: null }, { id: 2, name: 'Rohan Mehta', recipient: '919876543212', body: 'Hello Rohan, your candidate profile is ready.', error: null }] };
    await save('whatsapp-preview', renderPage());
    setup(); globalThis.cnComposeFixture = { channel: 'whatsapp', recipients: rows.slice(0, 2) }; globalThis.cnTemplateFixture = 'job_opportunity:en';
    globalThis.cnContextFixture.templates = [{ name: 'job_opportunity', language: 'en', category: 'MARKETING', body: 'Hello {{1}},\n\nNew job opportunity from EXOWORK\nRole: {{2}}\nLocation: {{3}}\nMonthly salary: {{4}}\nReview the role: {{5}}', parameter_count: 5, requires_job: true, context_mode: 'automatic', system_key: 'job_opportunity', job_scope: 'live', source_description: 'Candidate profile and selected job', labels: ['Candidate name', 'Job role', 'Location', 'Salary', 'Opportunities URL'], inputs: [] }];
    globalThis.cnJobFixture = { id: 42, role: 'Warehouse Assistant', location: 'Jaipur', salary: 'INR 18,000 - 22,000', url: 'https://example.com/candidate/opportunities' };
    await save('job-template', renderPage());
    setup(); globalThis.cnComposeFixture = { channel: 'whatsapp', recipients: rows.slice(0, 2) }; globalThis.cnTemplateFixture = 'career_intro:en';
    globalThis.cnContextFixture.templates = [{ name: 'career_intro', language: 'en', category: 'MARKETING', body: 'Hello {{1}},\nWatch the introduction: {{2}}\nExplore opportunities: {{3}}', parameter_count: 3, context_mode: 'automatic', system_key: 'career_intro', source_description: 'Candidate profile, video URL and account link', auto_fields: [{number:1,label:'Candidate name'},{number:3,label:'Opportunities URL'}], inputs: [{ key: 'career_video_url', label: 'Career video URL', type: 'url' }] }];
    await save('career-template', renderPage());

}
