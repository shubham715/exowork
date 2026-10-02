import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';

const result = await build({ entryPoints: ['resources/js/pages/admin/Candidates.jsx'], bundle: true, write: false, platform: 'node', format: 'cjs', loader: { '.css': 'empty' }, external: ['react', 'react-dom', 'react-router-dom', 'axios', 'lucide-react'], plugins: [{ name: 'directory-fixture', setup(builder) {
    builder.onLoad({ filter: /pages[\\/]admin[\\/]Candidates\.jsx$/ }, async ({ path }) => {
        let source = await readFile(path, 'utf8');
        const start = source.indexOf('export function useCandidateDirectory('), end = source.indexOf('\nfunction FilterGroup', start);
        source = source.slice(0, start) + 'export function useCandidateDirectory(){ return globalThis.candidateDirectoryFixture; }\n' + source.slice(end);
        source = source.replace('[filtersOpen, setFiltersOpen] = useState(false)', '[filtersOpen, setFiltersOpen] = useState(globalThis.candidateFiltersOpen || false)');
        return { loader: 'jsx', contents: source };
    });
} }] });
const module = { exports: {} };
new Function('require', 'module', 'exports', result.outputFiles[0].text)(createRequire(import.meta.url), module, module.exports);
const { default: Candidates, candidateListParams, emptyCandidateFilters } = module.exports;
const fixture = { data: [{ id: 1, candidate_code: 'EXO-CAN-000001', first_name: 'Asha', last_name: 'Sharma', whatsapp: '9876543211', email: 'asha@example.com', qualification: 'ITI', district: 'Jaipur', state: 'Rajasthan', preferred_industry: 'Manufacturing', availability: 'Yes', profile_status: 'active', training_center: 'ABC Skill Center', training_status: 'Completed', experience_type: 'Fresher', skills: 'Machine operation', expected_monthly_salary: 18000, whatsapp_consent: true, applications_count: 3, whatsapp_count: 2, created_at: '2026-10-02T09:00:00Z' }], total: 1, current_page: 1, last_page: 1, from: 1, to: 1, can_manage: true, counts: { all: 1250, available: 450, training: 220, consented: 1000, inactive: 10 }, filters: { states: ['Rajasthan'], industries: ['Manufacturing'], qualifications: ['ITI'], centers: ['ABC Skill Center'] } };
function render(data = fixture, filtersOpen = false, extra = {}) {
    globalThis.candidateDirectoryFixture = { data, loading: false, error: '', ...extra };
    globalThis.candidateFiltersOpen = filtersOpen;
    return renderToStaticMarkup(React.createElement(MemoryRouter, null, React.createElement(Candidates)));
}
test('candidate directory uses employer styling and renders actual stats, profile details and actions', () => {
    const html = render();
    for (const text of ['candidate-admin', 'ea-summary', 'Available now', 'WhatsApp opted in', '1,250', 'Asha Sharma', 'ABC Skill Center', 'Manufacturing', '18,000', '3 applications', '2 WhatsApp messages', 'Send WhatsApp', 'Export CSV', 'Rows per page']) assert.ok(html.includes(text), text);
    assert.match(html, /aria-sort="descending"/);
    assert.match(html, /\/admin\/candidates\/EXO-CAN-000001/);
});
test('filter drawer exposes meaningful candidate filters and real database options', () => {
    const html = render(fixture, true);
    for (const text of ['Filter candidates', 'Qualification', 'Preferred industry', 'Training center', 'Profile status', 'WhatsApp consent', 'Registered from', 'Registered to', 'Rajasthan', 'ABC Skill Center', 'Apply filters']) assert.ok(html.includes(text), text);
});
test('view-only accounts do not see profile mutations or WhatsApp sending', () => {
    const html = render({ ...fixture, can_manage: false });
    assert.doesNotMatch(html, /Send WhatsApp|Edit work preferences for|Deactivate Asha/);
    assert.match(html, /View Asha Sharma/);
});
test('filtered empty state and request parameters preserve filter meaning', () => {
    assert.match(render({ ...fixture, data: [], total: 0 }), /No candidates match these filters/);
    const params = candidateListParams({ ...emptyCandidateFilters(), states: ['Rajasthan'], consent: 'no', from: '2026-10-01' }, 'Asha', 'first_name', 'asc', 2, 50);
    assert.deepEqual(params, { states: ['Rajasthan'], consent: 'no', from: '2026-10-01', search: 'Asha', sort: 'first_name', direction: 'asc', page: 2, per_page: 50 });
});
