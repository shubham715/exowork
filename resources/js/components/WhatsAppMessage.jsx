import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { MessageCircle, RefreshCw } from 'lucide-react';
import { Modal, Status } from './UI.jsx';
import './whatsapp-message.css';

const failure = e => Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Request failed. Check your connection and try again.';
export default function WhatsAppMessage({ code, audience = 'admin', onConsentChange }) {
    const [open, setOpen] = useState(false), [context, setContext] = useState(null), [loading, setLoading] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState(''), [choice, setChoice] = useState(''), [values, setValues] = useState([]), [submitted, setSubmitted] = useState(false);
    const requestId = useRef(null), requestVersion = useRef(0), submitting = useRef(false), ambiguous = useRef(false);
    const endpoint = `/${audience}-api/candidates/${encodeURIComponent(code)}/whatsapp`;
    const template = context?.templates.find(t => `${t.name}:${t.language}` === choice);
    const load = async () => {
        const version = ++requestVersion.current;
        setLoading(true); setError('');
        try {
            const { data } = await axios.get(endpoint);
            if (version !== requestVersion.current) return;
            setContext(data); onConsentChange?.(data.candidate.consent);
        } catch (e) { if (version === requestVersion.current) setError(failure(e)); }
        finally { if (version === requestVersion.current) setLoading(false); }
    };
    useEffect(() => {
        if (!open) return;
        load();
        return () => { ++requestVersion.current; };
    }, [open, endpoint]);
    const send = async () => {
        if (!template || submitting.current || submitted) return;
        submitting.current = true; setBusy(true); setError(''); setNotice('');
        requestId.current ||= crypto.randomUUID();
        try {
            const csrf = await axios.get('/auth/csrf-token');
            const { data } = await axios.post(endpoint, { request_id: requestId.current, template_name: template.name, language: template.language, parameters: values }, { headers: { 'X-CSRF-TOKEN': csrf.data.csrf_token } });
            setNotice(data.notice); setSubmitted(true); ambiguous.current = false;
            await load();
        } catch (e) {
            setError(failure(e));
            ambiguous.current = !e.response || e.response.status >= 500;
            if (!ambiguous.current) requestId.current = null;
        } finally { submitting.current = false; setBusy(false); }
    };
    const close = () => { if (!busy) setOpen(false); };
    const start = () => {
        // Preserve the original payload and key across reopening after an ambiguous error.
        if (!ambiguous.current) { setContext(null); setChoice(''); setValues([]); setNotice(''); setError(''); setSubmitted(false); requestId.current = null; }
        setOpen(true);
    };
    const preview = template?.body.replace(/\{\{(\d+)\}\}/g, (match, n) => values[Number(n) - 1] || match);
    return <>
        <button type="button" className="btn whatsapp-button" onClick={start}><MessageCircle size={16} />Send WhatsApp</button>
        <Modal open={open} onClose={close} title="Send WhatsApp message" sub="Send an approved template from EXOWORK’s business number." className="whatsapp-dialog">
            {loading && <p role="status">Loading WhatsApp configuration and templates…</p>}
            {error && <p className="whatsapp-error" role="alert">{error}</p>}
            {notice && <p className="whatsapp-notice" role="status">{notice}</p>}
            {context && <>
                <div className="whatsapp-recipient"><b>{context.candidate.name}</b><span>{context.candidate.code}</span><Status tone={context.candidate.consent ? 'active' : 'rejected'}>{context.candidate.consent ? 'WhatsApp consent recorded' : 'No WhatsApp consent'}</Status></div>
                {!context.configured && <p className="whatsapp-error">WhatsApp setup is incomplete. Configure: {context.missing.join(', ')}. Follow docs/whatsapp-setup.html.</p>}
                {context.template_error && <p className="whatsapp-error" role="alert">{context.template_error}</p>}
                {!context.candidate.consent && <p className="whatsapp-error">Sending is blocked until the candidate grants WhatsApp consent.</p>}
                <label className="field-label">Approved template<select value={choice} disabled={busy || submitted || ambiguous.current} onChange={e => { setChoice(e.target.value); const t = context.templates.find(t => `${t.name}:${t.language}` === e.target.value); setValues(Array(t?.parameter_count || 0).fill('')); requestId.current = null; }}><option value="">Select a template and language</option>{context.templates.map(t => <option key={`${t.name}:${t.language}`} value={`${t.name}:${t.language}`}>{t.name} · {t.language} · {t.category}</option>)}</select></label>
                {context.configured && !context.template_error && !context.templates.length && <p>No supported approved templates found. Create a template with numbered body variables and no media or buttons in WhatsApp Manager, then refresh.</p>}
                {template && <>{values.map((value, i) => <label className="field-label" key={i}>Variable {`{{${i + 1}}}`}<input value={value} maxLength={1024} required disabled={busy || submitted || ambiguous.current} placeholder={i === 0 ? 'For example: candidate name' : 'Enter the template variable'} onChange={e => { setValues(v => v.map((x, n) => n === i ? e.target.value : x)); requestId.current = null; }} /></label>)}<div className="whatsapp-preview"><small>BODY PREVIEW · {template.category}</small><p>{preview}</p></div><small>Meta charges according to category and recipient market. API acceptance does not guarantee delivery.</small></>}
                <div className="modal-actions"><button className="btn ghost" onClick={load} disabled={busy || loading}><RefreshCw size={14} />Refresh status</button><button className="btn primary whatsapp-button" onClick={send} disabled={busy || loading || submitted || !context.configured || !context.candidate.consent || !template || values.some(v => !v.trim() || /[\r\n\t]/.test(v))}>{busy ? 'Submitting…' : submitted ? 'Submission recorded' : ambiguous.current ? 'Check original submission' : 'Send WhatsApp'}</button></div>
                <h3>Recent messages</h3>
                {context.messages.length ? <ul className="whatsapp-history">{context.messages.map(m => <li key={m.id}><div><b>{m.template_name}</b><small>{m.language} · {new Date(m.created_at).toLocaleString('en-IN')}{m.error_code ? ` · Error ${m.error_code}` : ''}</small></div><Status tone={m.status}>{m.status}</Status></li>)}</ul> : <p>No WhatsApp messages recorded yet.</p>}
                <p className="whatsapp-help">Refresh to see delivery updates. For “unknown” or “submitting”, check WhatsApp Manager before sending again. Candidates can reply STOP to withdraw consent.</p>
            </>}
            {!context && !loading && <button className="btn ghost" onClick={load}>Retry</button>}
        </Modal>
    </>;
}
