import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { build } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// Supply API state while retaining the real page, React hooks and UI components.
const result = await build({
    entryPoints: ['resources/js/pages/center/CenterWorkspace.jsx'],
    bundle: true, write: false, platform: 'node', format: 'cjs',
    external: ['react', 'react-dom', 'react-router-dom', 'axios', 'lucide-react'],
    plugins: [{ name: 'workspace-fixture', setup(builder) {
        builder.onLoad({ filter: /CenterWorkspace\.jsx$/ }, async ({ path }) => {
            const source = await readFile(path, 'utf8');
            const start = source.indexOf('function useWorkspace()');
            const end = source.indexOf('function State(', start);
            return { loader: 'jsx', contents: source.slice(0, start) +
                'function useWorkspace() { return globalThis.workspaceFixture; }\n' + source.slice(end) };
        });
    } }],
});
const module = { exports: {} };
new Function('require', 'module', 'exports', result.outputFiles[0].text)(createRequire(import.meta.url), module, module.exports);
const Workspace = module.exports.default;
function render(page, data, extra = {}) {
    globalThis.workspaceFixture = { data, loading: false, error: '', load() {}, ...extra };
    return renderToStaticMarkup(React.createElement(Workspace, { page }));
}
test('placements renders loading, empty and populated outcomes', () => {
    assert.match(render('placements', null, { loading: true }), /Loading center records/);
    assert.match(render('placements', { placements: [] }), /No records have been recorded/);
    const html = render('placements', { placements: [{ id: 1, full_name: 'Test Learner', status: 'joining_pending', expected_joining_on: '2026-10-04' }] });
    assert.match(html, /Test Learner/);
    assert.match(html, /Expected joining/);
});
test('performance renders its summary object without treating it as rows', () => {
    assert.match(render('performance', { performance: { candidates: 10, available: 8, interviewed: 4, selected: 3, joined: 2 } }), /Joined share of registered candidates/);
});
test('placements tolerates non-array records and shows request failures', () => {
    assert.match(render('placements', { placements: {} }), /No records have been recorded/);
    assert.match(render('placements', null, { error: 'Could not load center records.' }), /Try again/);
});
