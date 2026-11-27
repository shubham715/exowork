import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { ArrowLeft, ArrowRight, Check, CheckCircle2, ChevronLeft, ChevronRight, CircleAlert, Copy, Eye, LoaderCircle, Mail, MessageCircle, RefreshCw, Search, Send, ShieldCheck, UsersRound, X } from 'lucide-react';
import { PageHead, Modal } from '../../components/UI.jsx';
import './candidate-notifications.css';

const base = '/admin-api/candidate-notifications';
const failure = e => Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Could not complete this request. Please try again.';
const fullName = c => [c.first_name, c.last_name].filter(Boolean).join(' ');
const initial = c => `${c.first_name?.[0] || ''}${c.last_name?.[0] || ''}`;
const count = n => Number(n || 0).toLocaleString('en-IN');
const stateLabel = state => ({ queued: 'Queued', pending: 'Queued', sending: 'Sending', submitting: 'Sending', accepted: 'Accepted by provider', sent: 'Sent', delivered: 'Delivered', read: 'Read', skipped: 'Skipped', failed: 'Failed', unknown: 'Check provider logs' }[state] || state);

export function useNotificationCandidates(params, version) {
    const [data, setData] = useState(null), [loading, setLoading] = useState(true), [error, setError] = useState('');
    const key = JSON.stringify(params);
    useEffect(() => {
        const controller = new AbortController(); setLoading(true); setError('');
        axios.get(base, { params: JSON.parse(key), signal: controller.signal }).then(({ data }) => { if (!controller.signal.aborted) setData(data); }).catch(e => { if (!controller.signal.aborted) setError(failure(e)); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [key, version]);
    return { data, loading, error };
}

export default function CandidateNotifications() {
    const [query, setQuery] = useState(''), [search, setSearch] = useState(''), [page, setPage] = useState(1), [size, setSize] = useState(25), [version, setVersion] = useState(0);
    const [selected, setSelected] = useState({}), [compose, setCompose] = useState(null), [notice, setNotice] = useState('');
    const { data, loading, error } = useNotificationCandidates({ search, page, per_page: size }, version);
    const rows = data?.data || [], selection = Object.values(selected), selectedCount = selection.length;
    useEffect(() => { const timer = setTimeout(() => { setSearch(query.trim()); setPage(1); }, 300); return () => clearTimeout(timer); }, [query]);
    useEffect(() => { if (data && !loading && page > data.last_page) setPage(Math.max(1, data.last_page)); }, [data, loading, page]);
    const toggle = (candidate, checked) => setSelected(current => {
        const next = { ...current };
        if (checked) { if (Object.keys(next).length >= 500) { setNotice('Select up to 500 recipients per send.'); return current; } next[candidate.id] = candidate; }
        else delete next[candidate.id];
        return next;
    });
    const selectPage = checked => {
        if (checked && new Set([...Object.keys(selected).map(Number), ...rows.map(c => c.id)]).size > 500) { setNotice('Select up to 500 recipients per send.'); return; }
        setSelected(current => { const next = { ...current }; rows.forEach(c => { if (checked) next[c.id] = c; else delete next[c.id]; }); return next; });
    };
    const start = (channel, recipients) => setCompose({ channel, recipients });
    return <div className="cn-page">
        <PageHead kicker="CANDIDATE COMMUNICATION" title="Candidate notifications" sub="Personal messages, sent thoughtfully. Reach candidates by WhatsApp or email."><button className="btn ghost" disabled={loading} onClick={() => setVersion(v => v + 1)}><RefreshCw size={16} />Refresh</button></PageHead>
        <div className="cn-stats">{[[UsersRound, 'All candidates', data?.counts?.all, 'blue'], [MessageCircle, 'WhatsApp opted in', data?.counts?.whatsapp, 'green'], [Mail, 'Email opted in', data?.counts?.email, 'violet']].map(([Icon, label, value, tone]) => <article key={label}><span className={`cn-stat-icon ${tone}`}><Icon size={20} /></span><div><span>{label}</span><strong>{loading && !data ? '—' : count(value)}</strong></div></article>)}</div>
        {notice && <div className="cn-notice" role="status"><CheckCircle2 size={17} /><span>{notice}</span><button aria-label="Dismiss notification" onClick={() => setNotice('')}><X size={16} /></button></div>}
        <section className="cn-directory">
            <div className="cn-directory-heading"><div><h2>Candidate directory <span>{count(data?.total)}</span></h2><p>Select recipients and review every message before sending.</p></div><label className="cn-search"><Search size={17} /><input aria-label="Search candidates" placeholder="Search name, email, number or ID" value={query} onChange={e => setQuery(e.target.value)} /></label></div>
            {selectedCount > 0 && <div className="cn-bulk"><div><span className="cn-selected-icon"><Check size={15} /></span><strong>{count(selectedCount)} selected</strong><button className="cn-text" onClick={() => setSelected({})}>Clear selection</button></div><div><button className="btn cn-whatsapp" disabled={!data?.can_whatsapp} onClick={() => start('whatsapp', selection)}><MessageCircle size={16} />Send bulk WhatsApp</button><button className="btn ghost" disabled={!data?.can_email} onClick={() => start('email', selection)}><Mail size={16} />Send bulk email</button></div></div>}
            {error ? <div className="cn-empty" role="alert"><CircleAlert size={25} /><p>{error}</p><button className="btn ghost" onClick={() => setVersion(v => v + 1)}>Try again</button></div> : loading ? <div className="cn-empty" role="status"><LoaderCircle className="cn-spin" size={24} />Loading candidates…</div> : !rows.length ? <div className="cn-empty"><UsersRound size={30} /><h3>No candidates found</h3><p>{search ? 'Try a different name, email or mobile number.' : 'Registered and imported candidates will appear here.'}</p></div> : <div className="cn-table-wrap"><table className="cn-table"><thead><tr><th className="cn-check-cell"><input type="checkbox" aria-label="Select this page" checked={rows.length > 0 && rows.every(c => selected[c.id])} ref={el => { if (el) el.indeterminate = rows.some(c => selected[c.id]) && !rows.every(c => selected[c.id]); }} onChange={e => selectPage(e.target.checked)} /></th><th>Candidate</th><th>Email address</th><th>WhatsApp number</th><th>Registered on</th><th className="cn-action-heading">Actions</th></tr></thead><tbody>{rows.map(c => <tr key={c.id} className={selected[c.id] ? 'is-selected' : ''}>
                <td className="cn-check-cell"><input type="checkbox" aria-label={`Select ${fullName(c)}`} checked={!!selected[c.id]} onChange={e => toggle(c, e.target.checked)} /></td>
                <td data-label="Candidate"><div className="cn-person"><span>{initial(c)}</span><div><strong>{fullName(c)}</strong><small>{c.candidate_code}</small></div></div></td>
                <td data-label="Email"><span className="cn-contact">{c.email || 'Not provided'}</span><small className={`cn-consent ${c.email_consent ? 'opted-in' : ''}`}>{c.email_consent ? 'Opted in' : 'No email consent'}</small></td>
                <td data-label="WhatsApp"><span className="cn-contact">{c.whatsapp || 'Not provided'}</span><small className={`cn-consent ${c.whatsapp_consent ? 'opted-in' : ''}`}>{c.whatsapp_consent ? 'Opted in' : 'No WhatsApp consent'}</small></td>
                <td data-label="Registered on" className="cn-date">{c.registered_on}</td>
                <td data-label="Actions"><div className="cn-row-actions"><button disabled={!data.can_whatsapp} onClick={() => start('whatsapp', [c])} title={`Send WhatsApp to ${fullName(c)}`}><MessageCircle size={15} /><span>WhatsApp</span></button><button disabled={!data.can_email} onClick={() => start('email', [c])} title={`Send email to ${fullName(c)}`}><Mail size={15} /><span>Email</span></button></div></td>
            </tr>)}</tbody></table></div>}
            <footer className="cn-pagination"><span>{data?.total ? `${data.from || 0}–${data.to || 0} of ${count(data.total)} candidates` : '0 candidates'}</span><div><label>Rows<select aria-label="Rows per page" value={size} onChange={e => { setSize(Number(e.target.value)); setPage(1); }}>{[25, 50, 100].map(n => <option key={n}>{n}</option>)}</select></label><button disabled={loading || page <= 1} aria-label="Previous page" onClick={() => setPage(p => p - 1)}><ChevronLeft size={17} /></button><span>{page} / {data?.last_page || 1}</span><button disabled={loading || page >= (data?.last_page || 1)} aria-label="Next page" onClick={() => setPage(p => p + 1)}><ChevronRight size={17} /></button></div></footer>
        </section>
        <p className="cn-page-note"><ShieldCheck size={14} />Selection stays with you across searches and pages. Up to 500 recipients per send.</p>
        {compose && <MessageComposer {...compose} onClose={() => setCompose(null)} onSubmitted={result => { setNotice(`${count(result.queued)} ${compose.channel === 'email' ? 'emails' : 'WhatsApp messages'} queued${result.skipped ? ` · ${count(result.skipped)} recipients skipped` : ''}.`); setSelected({}); }} />}
    </div>;
}

export function MessageComposer({ channel, recipients, onClose, onSubmitted }) {
    const whatsapp = channel === 'whatsapp', Icon = whatsapp ? MessageCircle : Mail;
    const [context, setContext] = useState(null), [contextLoading, setContextLoading] = useState(true), [contextVersion, setContextVersion] = useState(0);
    const [templateKey, setTemplateKey] = useState(''), [variables, setVariables] = useState([]), [subject, setSubject] = useState(''), [body, setBody] = useState('');
    const [preview, setPreview] = useState(null), [result, setResult] = useState(null), [activeIndex, setActiveIndex] = useState(0), [busy, setBusy] = useState(false), [error, setError] = useState(''), [copied, setCopied] = useState(''), [attempted, setAttempted] = useState(false);
    const lock = useRef(false), key = useRef(null), alive = useRef(true), notified = useRef(false), statusBusy = useRef(false);
    const template = context?.templates.find(t => `${t.name}:${t.language}` === templateKey);
    const [jobs, setJobs] = useState([]), [jobSearch, setJobSearch] = useState(''), [selectedJob, setSelectedJob] = useState(null), [jobsLoading, setJobsLoading] = useState(false), [jobError, setJobError] = useState('');
    const requiresJob = !!template?.requires_job || variables.some(variable => Object.hasOwn(context?.job_fields || {}, variable.field));
    const automatic = template?.context_mode === 'automatic' || !!template?.requires_job;
    const [contextInputs, setContextInputs] = useState({});
    useEffect(() => {
        if (!requiresJob) return;
        const controller = new AbortController(); setJobsLoading(true); setJobError('');
        const timer = setTimeout(() => {
            axios.get(base + '/jobs', { params: { search: jobSearch.trim(), template_key: template.system_key }, signal: controller.signal }).then(({ data }) => { if (!controller.signal.aborted) setJobs(data.jobs); }).catch(e => { if (!controller.signal.aborted) setJobError(failure(e)); }).finally(() => { if (!controller.signal.aborted) setJobsLoading(false); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [requiresJob, jobSearch, template?.system_key]);
    const current = preview?.rows[activeIndex];
    const step = result ? 2 : preview ? 1 : 0;
    useEffect(() => {
        alive.current = true; const controller = new AbortController(); setContextLoading(true); setError('');
        axios.get(base + '/context', { params: { channel }, signal: controller.signal }).then(({ data }) => { if (!controller.signal.aborted) setContext(data); }).catch(e => { if (!controller.signal.aborted) setError(failure(e)); }).finally(() => { if (!controller.signal.aborted) setContextLoading(false); });
        return () => { alive.current = false; controller.abort(); };
    }, [channel, contextVersion]);
    const refreshStatus = async () => {
        if (!preview || lock.current || statusBusy.current) return;
        statusBusy.current = true;
        try { const { data } = channel === 'email' && result?.rows.some(row => row.status === 'pending')
            ? await axios.post(`${base}/${preview.id}/process`, {}, { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content } })
            : await axios.get(`${base}/${preview.id}`); if (alive.current) setResult(data); }
        catch (e) { if (alive.current) setError(failure(e)); }
        finally { statusBusy.current = false; }
    };
    const pending = result?.rows.some(r => ['pending', 'queued', 'sending', 'submitting'].includes(r.status));
    useEffect(() => { if (!pending) return; const timer = setInterval(refreshStatus, channel === 'email' ? 1500 : 5000); return () => clearInterval(timer); }, [pending, preview?.id, result]);
    const run = async task => {
        if (lock.current) return;
        lock.current = true; setBusy(true); setError('');
        try { const { data } = await axios.get('/auth/csrf-token'); await task({ headers: { 'X-CSRF-TOKEN': data.csrf_token } }); }
        catch (e) { if (alive.current) setError(failure(e)); }
        finally { lock.current = false; if (alive.current) setBusy(false); }
    };
    const proceed = () => run(async config => {
        key.current ||= crypto.randomUUID();
        const payload = { channel, ids: recipients.map(c => c.id), request_key: key.current, ...(whatsapp ? { template_name: template.name, language: template.language, ...(requiresJob ? { job_id: selectedJob.id } : {}), ...(automatic ? { inputs: contextInputs } : { variables }) } : { subject, body }) };
        const { data } = await axios.post(base + '/preview', payload, config); if (alive.current) { setPreview(data); setActiveIndex(0); }
    });
    const send = () => { setAttempted(true); run(async config => {
        try {
            const { data } = await axios.post(`${base}/${preview.id}/send`, {}, config);
            if (alive.current) { setResult(data); if (!notified.current) { notified.current = true; onSubmitted(data); } }
        } catch (e) {
            if (e.response?.status >= 400 && e.response.status < 500 && e.response.status !== 408) setAttempted(false);
            throw e;
        }
    }); };
    const back = () => { setPreview(null); setError(''); key.current = null; };
    const copy = async token => { try { await navigator.clipboard.writeText(`{{${token}}}`); setCopied(token); } catch { setError('Clipboard unavailable. Select and copy the placeholder text.'); } };
    const sourceName = id => preview?.rows.find(r => r.id === id)?.name || recipients.find(c => c.id === id) && fullName(recipients.find(c => c.id === id)) || `Candidate ${id}`;
    return <Modal open className={`cn-composer ${whatsapp ? 'is-whatsapp' : 'is-email'}`} title={whatsapp ? 'Send WhatsApp notification' : 'Send email notification'} sub={`${count(recipients.length)} ${recipients.length === 1 ? 'recipient' : 'recipients'} selected · ${whatsapp ? 'Approved templates' : 'Personalized email'}`} onClose={() => { if (!busy) onClose(); }} footer={<>
        <span className="cn-compose-footer-note"><ShieldCheck size={15} />{result ? 'Delivery status is tracked' : 'Review before you send'}</span><div className="cn-compose-actions">
            {result ? <><button className="btn ghost" onClick={refreshStatus}><RefreshCw size={15} />Refresh status</button><button className="btn primary" onClick={onClose}>Done</button></> : <>
                <button className="btn ghost" disabled={busy || attempted} onClick={preview ? back : onClose}>{preview ? <><ArrowLeft size={15} />Edit message</> : 'Cancel'}</button>
                {!preview ? <button className={`btn ${whatsapp ? 'cn-whatsapp' : 'primary'}`} disabled={busy || contextLoading || !context?.configured || (whatsapp ? !template || (requiresJob && !selectedJob) || (automatic ? (requiresJob && !selectedJob) || (template.inputs || []).some(input => !(contextInputs[input.key] || '').trim()) : variables.some(v => v.field === 'custom' && !v.value.trim())) : !subject.trim() || !body.trim())} onClick={proceed}>{busy ? <LoaderCircle className="cn-spin" size={16} /> : <Eye size={16} />}Proceed to preview<ArrowRight size={15} /></button> : <button className={`btn ${whatsapp ? 'cn-whatsapp' : 'primary'}`} disabled={busy || !preview.ready} onClick={send}>{busy ? <LoaderCircle className="cn-spin" size={16} /> : <Send size={16} />}{attempted ? 'Check original submission' : `Send to ${count(preview.ready)} ${preview.ready === 1 ? 'recipient' : 'recipients'}`}</button>}
            </>}
        </div>
    </>}>
        <ol className="cn-steps" aria-label="Message progress">{['Compose', 'Preview recipients', 'Delivery results'].map((label, i) => <li key={label} className={i === step ? 'current' : i < step ? 'complete' : ''}><span>{i < step ? <Check size={13} /> : i + 1}</span>{label}</li>)}</ol>
        {error && <div className="cn-alert error" role="alert"><CircleAlert size={18} /><span>{error}</span>{!context && !contextLoading && <button onClick={() => setContextVersion(v => v + 1)}>Try again</button>}</div>}
        {contextLoading ? <div className="cn-empty" role="status"><LoaderCircle className="cn-spin" size={22} />Loading message options…</div> : !preview ? <>
            {context && !context.configured && <div className="cn-alert error"><CircleAlert size={18} /><span>{whatsapp ? 'WhatsApp is not configured. Complete communication settings to send.' : 'Email sending is not enabled. Complete SMTP settings to send.'}</span></div>}
            {context?.template_error && <div className="cn-alert error"><CircleAlert size={18} /><span>{context.template_error}</span><button onClick={() => setContextVersion(v => v + 1)}>Reload templates</button></div>}
            <div className="cn-compose-intro"><span className={`cn-channel-icon ${channel}`}><Icon size={21} /></span><div><h3>{whatsapp ? 'Choose your message template' : 'Write your email'}</h3><p>{whatsapp ? 'Choose a template, then personalize it with candidate or job details.' : 'Add placeholders to make every message personal.'}</p></div></div>
            {whatsapp ? <>
                <label className="field-label">Approved template<select value={templateKey} disabled={busy} onChange={e => { setTemplateKey(e.target.value); setSelectedJob(null); setJobSearch(''); setJobs([]); const t = context.templates.find(t => `${t.name}:${t.language}` === e.target.value); setContextInputs(Object.fromEntries((t?.inputs || []).map(input => [input.key, input.default || '']))); setVariables(Array.from({ length: t?.parameter_count || 0 }, (_, i) => ({ field: i === 0 ? 'name' : 'custom', value: '' }))); key.current = null; }}><option value="">Select a template</option>{context?.templates.map(t => <option key={`${t.name}:${t.language}`} value={`${t.name}:${t.language}`}>{t.name} · {t.language} · {t.category}</option>)}</select></label>
                {context?.configured && !context.template_error && !context.templates.length && <div className="cn-alert"><CircleAlert size={18} /><span>No supported approved templates found. Review your WhatsApp template library.</span></div>}
                {(template?.inputs || []).map(input => <label className="field-label cn-context-input" key={input.key}>{input.label}<input type={input.type || 'text'} value={contextInputs[input.key] || ''} maxLength={1024} placeholder={input.type === 'url' ? 'https://…' : ''} onChange={e => { setContextInputs(values => ({...values, [input.key]: e.target.value})); key.current = null; }} /></label>)}
                {requiresJob && <section className="cn-job-picker"><div className="cn-job-picker-heading"><h4>{(template.system_key === 'job_opportunity' || !automatic) ? 'Select a job opportunity' : 'Select the relevant job'}</h4><span>One job per message</span></div><p>{(template.system_key === 'job_opportunity' || !automatic) ? 'The selected job fills the role, location, salary and link for every recipient. Candidate names remain personalized.' : 'Each recipient’s related application, interview or placement is checked for this job. Missing records are shown in preview.'}</p>
                    <label className="field-label">Find a job<input value={jobSearch} onChange={e => setJobSearch(e.target.value)} placeholder="Search job title, location or job ID" /></label>
                    <label className="field-label">Job opportunity<select aria-label="Job opportunity" value={selectedJob?.id || ''} disabled={busy} onChange={e => { const job = jobs.find(j => String(j.id) === e.target.value) || (String(selectedJob?.id) === e.target.value ? selectedJob : null); setSelectedJob(job); key.current = null; }}><option value="">{jobsLoading ? 'Loading available jobs…' : (template.job_scope || 'live') === 'live' ? 'Select an active job' : 'Select a job'}</option>{[...(selectedJob && !jobs.some(j => j.id === selectedJob.id) ? [selectedJob] : []), ...jobs].map(job => <option key={job.id} value={job.id}>#{job.id} · {job.role} · {job.location}</option>)}</select></label>
                    {jobError && <div className="cn-alert error" role="alert">{jobError}</div>}{!jobsLoading && !jobError && !jobs.length && <small>{(template.job_scope || 'live') === 'live' ? 'No active jobs found. Try another search. Only live jobs from verified employers are listed.' : 'No jobs found. Try searching by job title, location or ID.'}</small>}
                    {selectedJob && <dl className="cn-job-values">{[['Candidate name', 'Personalized for each recipient'], ['Job role', selectedJob.role], ...((template.system_key === 'job_opportunity' || !automatic) ? [['Location', selectedJob.location], ['Monthly salary', selectedJob.salary], ['Opportunities link', selectedJob.url]] : [])].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>}
                </section>}
                {automatic && <section className="cn-auto-context"><div><CheckCircle2 size={17} /><h4>Values filled automatically</h4></div><p>{template.source_description}. Each recipient gets their own values.</p><div className="cn-auto-labels">{(template.auto_fields || (template.auto_labels || template.labels || []).map((label, i) => ({ number: i + 1, label }))).map(field => <span key={field.number}><code>{`{{${field.number}}}`}</code> {field.label}</span>)}</div><small>Missing or conflicting records are flagged in preview.</small></section>}
                {template && <><div className="cn-template-body"><span>TEMPLATE CONTENT · {template.language}</span><p>{template.body}</p></div>{!automatic && variables.length > 0 && <div className="cn-variable-heading"><h4>Personalize your variables</h4><span>Candidate fields are filled automatically</span></div>}<div className="cn-variables">{(automatic ? [] : variables).map((v, i) => <div className="cn-variable" key={i}><span className="cn-variable-number">{`{{${i + 1}}}`}</span><label className="field-label">Value source<select value={v.field} disabled={busy} onChange={e => { setVariables(all => all.map((item, j) => j === i ? { ...item, field: e.target.value } : item)); key.current = null; }}><optgroup label="Candidate details">{Object.entries(context.fields).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</optgroup>{Object.keys(context.job_fields || {}).length > 0 && <optgroup label="Selected job">{Object.entries(context.job_fields).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</optgroup>}<option value="custom">Custom value (same for everyone)</option></select></label>{v.field === 'custom' ? <label className="field-label">Custom value<input maxLength={1024} value={v.value} placeholder="Enter a shared value" onChange={e => { setVariables(all => all.map((item, j) => j === i ? { ...item, value: e.target.value } : item)); key.current = null; }} /></label> : <p><CheckCircle2 size={14} />{Object.hasOwn(context.job_fields || {}, v.field) ? 'Filled from the selected job' : 'Filled from each candidate’s profile'}</p>}</div>)}</div></>}
            </> : <div className="cn-email-compose"><div className="cn-email-editor"><label className="field-label">Subject<input maxLength={255} placeholder="An update from {{app_name}}" value={subject} onChange={e => { setSubject(e.target.value); key.current = null; }} /></label><label className="field-label">Email content<textarea rows={13} maxLength={30000} placeholder={'Hello {{first_name}},\n\nWrite or paste your message here.\n\nRegards,\n{{app_name}}'} value={body} onChange={e => { setBody(e.target.value); key.current = null; }} /></label><small>{count(body.length)} / 30,000 characters</small></div><aside className="cn-placeholder-panel"><h4>Make it personal</h4><p>Copy a placeholder and paste it into your subject or message.</p><table><thead><tr><th>Placeholder</th><th>Replaced with</th><th><span className="cn-sr-only">Copy</span></th></tr></thead><tbody>{Object.entries(context?.fields || {}).map(([key, label]) => <tr key={key}><td><code>{`{{${key}}}`}</code></td><td>{label}</td><td><button onClick={() => copy(key)} title={`Copy ${key}`} aria-label={`Copy {{${key}}}`}>{copied === key ? <Check size={14} /> : <Copy size={14} />}</button></td></tr>)}</tbody></table><span className="cn-copy-status" role="status">{copied ? `Copied {{${copied}}}` : 'Use these placeholders in your message'}</span></aside></div>}
            <p className="cn-compose-help"><ShieldCheck size={15} />The next step checks each recipient’s contact details, consent and personalized values.</p>
        </> : result ? <div className="cn-results"><div className="cn-result-hero"><span><CheckCircle2 size={30} /></span><h3>Submission recorded</h3><p>{count(result.queued)} {whatsapp ? 'WhatsApp messages' : 'emails'} queued{result.skipped ? ` · ${count(result.skipped)} recipients skipped` : ''}</p><small>Messages continue processing after you close this window. Provider acceptance is not delivery confirmation.</small></div><div className="cn-delivery-table"><table><thead><tr><th>Candidate</th><th>Status</th><th>Details</th></tr></thead><tbody>{result.rows.map(r => <tr key={r.id}><td>{sourceName(r.id)}</td><td><span className={`cn-delivery-status ${r.status}`}>{stateLabel(r.status)}</span></td><td>{r.error || '—'}</td></tr>)}</tbody></table></div></div> : <div className="cn-preview-stage">
            <div className="cn-preview-summary"><div><strong>{count(preview.ready)}</strong><span>Ready to send</span></div><div className={preview.skipped ? 'has-skips' : ''}><strong>{count(preview.skipped)}</strong><span>Will be skipped</span></div><p>{preview.skipped ? 'Recipients with missing details or consent will not receive a message.' : 'Every recipient has passed the sending checks.'}</p></div>
            {preview.job && <div className="cn-job-preview-context"><strong>#{preview.job.id} · {preview.job.role}</strong><span>{preview.job.location} · {preview.job.salary}</span></div>}
            <div className="cn-preview-toolbar"><label className="field-label">Preview for<select aria-label="Preview recipient" value={activeIndex} onChange={e => setActiveIndex(Number(e.target.value))}>{preview.rows.map((r, i) => <option key={r.id} value={i}>{r.name} {r.error ? '· Will be skipped' : ''}</option>)}</select></label><div><button aria-label="Previous recipient" disabled={activeIndex === 0} onClick={() => setActiveIndex(i => i - 1)}><ChevronLeft size={18} /></button><span>{activeIndex + 1} / {preview.rows.length}</span><button aria-label="Next recipient" disabled={activeIndex >= preview.rows.length - 1} onClick={() => setActiveIndex(i => i + 1)}><ChevronRight size={18} /></button></div></div>
            {current && <><div className="cn-recipient-line"><span><Icon size={16} />{current.recipient || 'Contact details missing'}</span><span className={`cn-preview-badge ${current.error ? 'skip' : ''}`}>{current.error ? 'Will be skipped' : 'Ready to send'}</span></div>{current.error && <div className="cn-alert"><CircleAlert size={18} /><span>{current.error}</span></div>}
                {whatsapp ? <div className="cn-whatsapp-preview"><div><span className="cn-chat-avatar"><MessageCircle size={19} /></span><div><b>EXOWORK</b><small>WhatsApp template preview</small></div></div><div className="cn-chat-body"><div className="cn-chat-bubble"><p>{current.body || 'No message can be generated for this recipient.'}</p><small>{template?.name} · {template?.language}</small></div></div></div> : <div className="cn-email-preview"><header><Mail size={18} /><div><span>To: {current.name} &lt;{current.recipient || 'No email'}&gt;</span><strong>{current.subject || 'Subject unavailable'}</strong></div></header><p>{current.body || 'No email can be generated for this recipient.'}</p></div>}
            </>}
        </div>}
    </Modal>;
}
