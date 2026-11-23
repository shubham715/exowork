import React, { useRef, useState } from 'react';
import axios from 'axios';
import { UploadCloud, FileSpreadsheet, Check, ArrowRight, ArrowLeft, Download, ShieldCheck, AlertCircle, CheckCircle2, LoaderCircle, Search } from 'lucide-react';
import { Modal } from '../../components/UI.jsx';

const message = e => Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Import failed. Please try again.';
export default function CandidateImport({ onClose, onSaved }) {
    const [file, setFile] = useState(null), [upload, setUpload] = useState(null), [mapping, setMapping] = useState({}), [preview, setPreview] = useState(null), [busy, setBusy] = useState(false), [error, setError] = useState('');
    const [dragging, setDragging] = useState(false), [fieldSearch, setFieldSearch] = useState(''), [issuesOnly, setIssuesOnly] = useState(true);
    const [skipInvalid, setSkipInvalid] = useState(false);
    const lock = useRef(false), picker = useRef(null);
    const stage = preview ? 2 : upload ? 1 : 0;
    const mapped = Object.values(mapping).filter(Boolean).length;
    const run = async task => {
        if (lock.current) return;
        lock.current = true; setBusy(true); setError('');
        try { const csrf = await axios.get('/auth/csrf-token'); await task({ headers: { 'X-CSRF-TOKEN': csrf.data.csrf_token } }); }
        catch (e) { setError(message(e)); }
        finally { lock.current = false; setBusy(false); }
    };
    const load = (selectedFile, sheet) => run(async config => {
        const form = new FormData(); form.append('file', selectedFile); if (sheet) form.append('sheet', sheet);
        const { data } = await axios.post('/admin-api/candidates/import/upload', form, config);
        setUpload(data); setMapping(data.mapping); setPreview(null); setSkipInvalid(false); setFieldSearch('');
    });
    const selectFile = next => {
        if (!next || busy) return;
        if (!/\.(xlsx|csv)$/i.test(next.name)) { setError('Choose an Excel (.xlsx) or CSV file.'); return; }
        if (next.size > 10 * 1024 * 1024) { setError('Your file is larger than 10 MB. Please split it into smaller files.'); return; }
        setFile(next); setUpload(null); setPreview(null); load(next);
    };
    const payload = () => ({ token: upload.token, mapping });
    const previewImport = () => run(async config => { const { data } = await axios.post('/admin-api/candidates/import/preview', payload(), config); setPreview(data); setSkipInvalid(false); setIssuesOnly(data.invalid > 0); });
    return <Modal open className="ci-dialog" onClose={() => { if (!busy) onClose(); }} title="Import candidates" sub="Bring your candidate data into Exowork." footer={<>
        <span className="ci-footer-note"><ShieldCheck size={15} />{preview ? 'Nothing is saved until you confirm' : 'Your original data is preserved'}</span>
        <div className="ci-footer-actions"><button className="btn ghost" disabled={busy} onClick={preview ? () => setPreview(null) : onClose}>{preview ? <><ArrowLeft size={16} />Back to mapping</> : 'Cancel'}</button>
        {!upload && <button className="btn primary" disabled={busy} onClick={() => picker.current?.click()}>{busy ? <><LoaderCircle className="ci-spin" size={16} />Reading file…</> : <><UploadCloud size={16} />Choose file</>}</button>}
        {upload && !preview && <button className="btn primary" disabled={busy} onClick={previewImport}>{busy ? <><LoaderCircle className="ci-spin" size={16} />Checking candidates…</> : <>Review candidates<ArrowRight size={16} /></>}</button>}
        {preview && <button className="btn primary" disabled={busy || preview.add === 0 || (preview.invalid > 0 && !skipInvalid)} onClick={() => run(async config => { const { data } = await axios.post('/admin-api/candidates/import/confirm', { ...payload(), skip_invalid: skipInvalid }, config); onSaved(data.message); })}>{busy ? <><LoaderCircle className="ci-spin" size={16} />Saving…</> : <><Check size={16} />Import {preview.add} candidates</>}</button>}</div>
    </>}>
        <ol className="ci-steps" aria-label="Import progress">{['Upload file', 'Match columns', 'Review & import'].map((label, index) => <li key={label} className={index === stage ? 'current' : index < stage ? 'complete' : ''} aria-current={index === stage ? 'step' : undefined}><span>{index < stage ? <Check size={14} /> : index + 1}</span><b>{label}</b></li>)}</ol>
        <input ref={picker} className="ci-file-input" type="file" aria-label="Candidate file" accept=".xlsx,.csv" disabled={busy} onChange={e => { selectFile(e.target.files[0]); e.target.value = ''; }} />
        {!upload ? <div className="ci-upload-stage">
            <div className="ci-section-heading"><h3>Start with your candidate file</h3><p>Upload your spreadsheet. We’ll help you match the columns next.</p></div>
            <button type="button" disabled={busy} className={`ci-dropzone ${dragging ? 'is-dragging' : ''}`} onClick={() => picker.current?.click()} onDragOver={e => { e.preventDefault(); if (!busy) setDragging(true); }} onDragLeave={() => setDragging(false)} onDrop={e => { e.preventDefault(); setDragging(false); selectFile(e.dataTransfer.files[0]); }}>
                <span className="ci-upload-icon">{busy ? <LoaderCircle className="ci-spin" size={29} /> : <UploadCloud size={29} />}</span>
                <strong>{busy ? 'Reading your spreadsheet…' : 'Drag and drop your file here'}</strong><span>{busy ? file?.name : <>or <b>browse files</b> on your computer</>}</span><small>Excel (.xlsx) or CSV · Up to 10 MB</small>
            </button>
            <div className="ci-template"><span className="ci-template-icon"><FileSpreadsheet size={22} /></span><div><strong>Need a starting point?</strong><p>Use our template with ready-to-match column headers.</p></div><a href="/admin-api/candidates/sample"><Download size={16} />Download sample</a></div>
            <div className="ci-guidelines"><span><CheckCircle2 size={15} />Up to 5,000 candidates per file</span><span><CheckCircle2 size={15} />Name and mobile required</span><span><CheckCircle2 size={15} />Extra columns are kept</span></div>
        </div> : <>
            <div className="ci-file-summary"><span className="ci-template-icon"><FileSpreadsheet size={23} /></span><div><strong title={file?.name}>{file?.name}</strong><span>{upload.total.toLocaleString('en-IN')} candidates · {upload.headers.length} columns</span></div><button className="ci-text-button" disabled={busy} onClick={() => picker.current?.click()}>Replace file</button></div>
            {!preview ? <>
                <div className="ci-section-heading"><h3>Match your columns</h3><p>We’ve matched familiar headers. Review them below; unmatched columns are saved as extra data.</p></div>
                <div className="ci-mapping-toolbar"><label className="field-label">Worksheet<select disabled={busy} value={upload.sheet} onChange={e => load(file, e.target.value)}>{upload.sheets.map(sheet => <option key={sheet}>{sheet}</option>)}</select></label><label className="ci-column-search"><Search size={16} /><input aria-label="Find a column" placeholder="Find a column…" value={fieldSearch} onChange={e => setFieldSearch(e.target.value)} /></label></div>
                <div className="ci-table-caption"><span><b>{mapped}</b> matched to system fields</span><span>{upload.headers.length - mapped} stored as extra data</span></div>
                <div className="ci-mapping"><table><thead><tr><th>Column in your file</th><th className="ci-arrow-cell" aria-label="Maps to" /><th>Exowork field</th></tr></thead><tbody>{upload.headers.filter(header => header.toLowerCase().includes(fieldSearch.toLowerCase())).map(header => <tr key={header}><td><FileSpreadsheet size={15} />{header}</td><td className="ci-arrow-cell"><ArrowRight size={15} /></td><td><select aria-label={`Map ${header}`} disabled={busy} className={mapping[header] ? 'is-matched' : ''} value={mapping[header] || ''} onChange={e => setMapping(m => ({ ...m, [header]: e.target.value }))}><option value="">Store as extra data</option>{upload.fields.map(field => <option key={field} value={field}>{field.replaceAll('_', ' ')}</option>)}</select></td></tr>)}</tbody></table>{!upload.headers.some(header => header.toLowerCase().includes(fieldSearch.toLowerCase())) && <p className="ci-no-results">No columns match your search.</p>}</div>
                <p className="ci-inline-note"><ShieldCheck size={16} />Existing candidates won’t be overwritten. Missing age and salary stay blank; availability defaults to “No”.</p>
            </> : <section aria-live="polite" className="ci-review">
                <div className="ci-section-heading"><h3>Review your candidates</h3><p>We checked the file for missing information and duplicate records.</p></div>
                <div className="ci-review-stats"><div><span>Total candidates</span><strong>{preview.total}</strong></div><div className="is-valid"><span><CheckCircle2 size={15} />Ready to import</span><strong>{preview.add}</strong></div><div className={preview.invalid ? 'has-issues' : ''}><span><AlertCircle size={15} />Need attention</span><strong>{preview.invalid}</strong></div></div>
                <div className={`ci-review-notice ${preview.invalid ? 'has-issues' : 'is-valid'}`}>{preview.invalid ? <AlertCircle size={19} /> : <CheckCircle2 size={19} />}<div><strong>{preview.invalid ? 'A few rows need your attention' : 'Your candidates are ready to import'}</strong><p>{preview.invalid ? 'Correct the listed rows and replace the file, or skip them below to import the valid candidates.' : 'All rows passed validation. Confirm below to add these candidate profiles.'}</p></div>{preview.invalid > 0 && <button className="ci-text-button" disabled={busy} onClick={() => picker.current?.click()}>Replace file<UploadCloud size={15} /></button>}</div>
                {preview.invalid > 0 && <label className={`ci-skip-option ${skipInvalid ? 'is-selected' : ''}`}><input type="checkbox" checked={skipInvalid} disabled={busy || preview.add === 0} onChange={e => setSkipInvalid(e.target.checked)} /><span><strong>Skip {preview.invalid} invalid rows and import {preview.add} valid candidates</strong><small>{preview.add === 0 ? 'No valid candidates are available. Correct your file to continue.' : 'Only rows that passed validation will be saved. Skipped rows will not be imported.'}</small></span></label>}
                <div className="ci-table-caption"><span>Candidate validation</span>{preview.invalid > 0 && <label><input type="checkbox" checked={issuesOnly} onChange={e => setIssuesOnly(e.target.checked)} />Show issues only</label>}</div>
                <div className="ci-report"><table><thead><tr><th>Sheet row</th><th>Candidate</th><th>Result</th></tr></thead><tbody>{preview.rows.filter(row => !issuesOnly || row.errors.length).map(row => <tr key={row.row}><td><span className="ci-row-number">{row.row}</span></td><td>{row.name || 'Name missing'}{row.normalized && <small className="ci-normalized-summary">{[row.normalized.qualification, row.normalized.date_of_birth ? row.normalized.date_of_birth.split('-').reverse().join('-') : null, row.normalized.district].filter(Boolean).join(' · ')}</small>}</td><td>{row.errors.length ? <span className="ci-row-error"><AlertCircle size={14} />{row.errors.join(' ')}</span> : <span><span className="ci-row-ready"><CheckCircle2 size={14} />Ready to import</span>{row.warnings?.map(warning => <small key={warning} className="ci-normalized-summary">{warning}</small>)}</span>}</td></tr>)}</tbody></table></div>
            </section>}
        </>}
        {error && <div role="alert" className="ci-error"><AlertCircle size={18} /><span>{error}</span></div>}
    </Modal>;
}
