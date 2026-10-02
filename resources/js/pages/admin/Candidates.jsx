import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Link } from 'react-router-dom';
import { UsersRound, UserCheck, GraduationCap, MessageCircle, Ban, Search, SlidersHorizontal, RotateCcw, RefreshCw, Download, Eye, Pencil, Power, ArrowDownUp, ChevronLeft, ChevronRight, X, CheckCircle2, MapPin, Mail, Phone, Save } from 'lucide-react';
import { PageHead, Modal } from '../../components/UI.jsx';
import WhatsAppMessage from '../../components/WhatsAppMessage.jsx';
import '../../data-tables.css';
import './employer-admin.css';
import './candidate-admin.css';

export const emptyCandidateFilters = () => ({ availability: [], statuses: [], industries: [], states: [], qualifications: [], centers: [], training: [], consent: '', from: '', to: '' });
export function candidateListParams(filters, search, sort, direction, page, size) {
    return { ...Object.fromEntries(Object.entries(filters).filter(([, value]) => Array.isArray(value) ? value.length : value)), search: search || undefined, sort, direction, page, per_page: size };
}
const queryString = params => new URLSearchParams(Object.entries(params).flatMap(([key, value]) => value === undefined ? [] : Array.isArray(value) ? value.map(v => [`${key}[]`, v]) : [[key, value]])).toString();
const errorMessage = e => Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Candidate data could not be saved. Try again.';
const dateLabel = value => value ? new Date(value).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : 'Not recorded';
const availabilityLabel = value => ({ Yes: 'Available now', No: 'Not available', 'Available after training': 'After training' }[value] || value);
const currency = value => Number(value || 0).toLocaleString('en-IN');
const columns = [['Candidate', 'first_name'], ['Qualification / training', 'qualification'], ['Work preferences', 'preferred_industry'], ['Availability', 'availability'], ['Communication', null], ['Activity', 'applications_count'], ['Registered', 'created_at']];
const summaryCards = [['All candidates', 'all', UsersRound, 'all'], ['Available now', 'available', UserCheck, 'verified'], ['In training', 'training', GraduationCap, 'pending'], ['WhatsApp opted in', 'consented', MessageCircle, 'verified'], ['Inactive profiles', 'inactive', Ban, 'inactive']];

function Badge({ children, tone = 'inactive', icon: Icon = CheckCircle2 }) { return <span className={`ea-status-badge ea-status-${tone}`}><Icon size={14} />{children}</span>; }
export function useCandidateDirectory(params, refresh) {
    const [data, setData] = useState(null), [loading, setLoading] = useState(true), [error, setError] = useState('');
    const key = JSON.stringify(params);
    useEffect(() => {
        const controller = new AbortController(); setLoading(true); setError('');
        axios.get('/admin-api/candidates', { params: JSON.parse(key), signal: controller.signal }).then(r => { if (!controller.signal.aborted) setData(r.data); }).catch(e => { if (!controller.signal.aborted) setError(e.response?.status === 403 ? 'Your account needs candidate viewing permission.' : errorMessage(e)); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [key, refresh]);
    return { data, loading, error };
}

function FilterGroup({ label, values, selected, onChange }) {
    return <fieldset className="ea-filter-group"><legend>{label}<span>{selected.length ? `${selected.length} selected` : 'All'}</span></legend><div>{values.length ? values.map(value => <label key={value}><input type="checkbox" checked={selected.includes(value)} onChange={() => onChange(selected.includes(value) ? selected.filter(v => v !== value) : [...selected, value])} />{value === 'Yes' || value === 'No' || value === 'Available after training' ? availabilityLabel(value) : value}</label>) : <span className="ea-muted">No options available yet</span>}</div></fieldset>;
}

export default function Candidates() {
    const [query, setQuery] = useState(''), [search, setSearch] = useState(''), [filters, setFilters] = useState(emptyCandidateFilters), [draftFilters, setDraftFilters] = useState(emptyCandidateFilters), [filtersOpen, setFiltersOpen] = useState(false);
    const [page, setPage] = useState(1), [size, setSize] = useState(25), [sort, setSort] = useState('created_at'), [direction, setDirection] = useState('desc'), [refresh, setRefresh] = useState(0), [checked, setChecked] = useState([]);
    const [editing, setEditing] = useState(null), [draft, setDraft] = useState({}), [action, setAction] = useState(null), [busy, setBusy] = useState(false), [dialogError, setDialogError] = useState(''), [notice, setNotice] = useState('');
    const submitting = useRef(false);
    const params = candidateListParams(filters, search, sort, direction, page, size);
    const { data, loading, error } = useCandidateDirectory(params, refresh);
    const rows = data?.data || [], counts = data?.counts || {}, options = data?.filters || {}, canManage = data?.can_manage;
    const filterCount = Object.values(filters).reduce((n, value) => n + (Array.isArray(value) ? value.length : Number(!!value)), 0);
    const total = data?.total || 0, current = data?.current_page || 1, pages = data?.last_page || 1;
    useEffect(() => { setChecked([]); }, [JSON.stringify(params), refresh]);
    useEffect(() => { if (!loading && data && page > pages) setPage(pages); }, [loading, data, page, pages]);
    const reset = () => { setQuery(''); setSearch(''); setFilters(emptyCandidateFilters()); setPage(1); };
    const apply = next => { setFilters(next); setPage(1); };
    const changeSort = key => { setSort(key); setDirection(sort === key && direction === 'asc' ? 'desc' : 'asc'); setPage(1); };
    const chooseSummary = key => {
        const next = emptyCandidateFilters();
        if (key === 'available') next.availability = ['Yes'];
        if (key === 'training') next.training = ['Ongoing'];
        if (key === 'consented') next.consent = 'yes';
        if (key === 'inactive') next.statuses = ['inactive'];
        setQuery(''); setSearch(''); apply(next);
    };
    const summaryActive = key => key === 'all' ? !filterCount && !search : key === 'available' ? filters.availability.includes('Yes') : key === 'training' ? filters.training.includes('Ongoing') : key === 'consented' ? filters.consent === 'yes' : filters.statuses.includes('inactive');
    const exportUrl = ids => '/admin-api/candidates/export?' + queryString({ ...params, ...(ids?.length ? { ids } : {}) });
    const beginAction = (kind, ids) => { setDialogError(''); setAction({ kind, ids }); };
    const edit = c => { setEditing(c); setDraft({ availability: c.availability, skills: c.skills, expected_monthly_salary: c.expected_monthly_salary }); setDialogError(''); };
    const mutate = async (url, payload, method = 'post') => {
        if (submitting.current) return;
        submitting.current = true; setBusy(true); setDialogError('');
        try {
            const csrf = await axios.get('/auth/csrf-token');
            const { data: response } = await axios[method](url, payload, { headers: { 'X-CSRF-TOKEN': csrf.data.csrf_token } });
            setNotice(response.message); setEditing(null); setAction(null); setChecked([]); setRefresh(n => n + 1);
        } catch (e) { setDialogError(errorMessage(e)); }
        finally { submitting.current = false; setBusy(false); }
    };
    return <div className="employer-admin candidate-admin">
        <PageHead title="Candidates" sub="Manage candidate profiles, explore work readiness and keep every follow-up in view."><button className="btn ghost" disabled={loading} onClick={() => setRefresh(n => n + 1)}><RefreshCw />Refresh</button><a className="btn ghost" href={exportUrl()} aria-disabled={loading || !!error || !total} onClick={e => { if (loading || error || !total) e.preventDefault(); }}><Download />Export CSV</a></PageHead>
        <div className="ea-summary">{summaryCards.map(([label, key, Icon, tone]) => <button key={key} aria-pressed={summaryActive(key)} className={`ea-summary-${tone} ${summaryActive(key) ? 'active' : ''}`} onClick={() => chooseSummary(key)}><span className="ea-summary-icon"><Icon size={18} /></span><span className="ea-summary-label">{label}</span><strong>{loading ? '—' : Number(counts[key] || 0).toLocaleString('en-IN')}</strong></button>)}</div>
        {notice && <div className="ea-notice" role="status"><span><CheckCircle2 size={16} />{notice}</span><button aria-label="Dismiss notification" onClick={() => setNotice('')}><X size={16} /></button></div>}
        <section className="ea-surface">
            <form className="ea-filters ea-toolbar" onSubmit={e => { e.preventDefault(); setSearch(query.trim()); setPage(1); }}><label className="ea-search">Search candidates<input value={query} onChange={e => setQuery(e.target.value)} placeholder="Name, candidate code, phone, email or skills" /></label><button className="btn ghost" type="submit"><Search />Search</button><button className="btn ghost" type="button" aria-haspopup="dialog" onClick={() => { setDraftFilters({ ...filters }); setFiltersOpen(true); }}><SlidersHorizontal />Filters{filterCount > 0 && <span className="ea-filter-count">{filterCount}</span>}</button><button className="btn ghost" type="button" onClick={reset}><RotateCcw />Reset</button></form>
            {(filterCount > 0 || search) && <div className="ca-filter-chips">{search && <button onClick={() => { setSearch(''); setQuery(''); setPage(1); }}>Search: {search}<X size={12} /></button>}{Object.entries(filters).flatMap(([key, values]) => (Array.isArray(values) ? values : values ? [values] : []).map(value => <button key={`${key}:${value}`} onClick={() => apply({ ...filters, [key]: Array.isArray(values) ? values.filter(v => v !== value) : '' })}>{key === 'consent' ? `WhatsApp: ${value === 'yes' ? 'opted in' : 'not opted in'}` : key === 'from' || key === 'to' ? `${key}: ${value}` : availabilityLabel(value)}<X size={12} /></button>))}<span>Stats show all registered profiles</span></div>}
            {checked.length > 0 && <div className="ea-bulk"><b>{checked.length} selected</b><a className="btn ghost" href={exportUrl(checked)}><Download />Export selected</a>{canManage && <><button className="btn ghost" onClick={() => beginAction('activate', checked)}><UserCheck />Activate</button><button className="btn ghost" onClick={() => beginAction('disable', checked)}><Power />Deactivate</button></>}<button className="btn ghost" onClick={() => setChecked([])}><X />Clear selection</button></div>}
            <div className="ea-table-caption"><span><b>{total.toLocaleString('en-IN')}</b> matching candidates <span className="ea-muted">· Application and WhatsApp counts are lifetime totals</span></span><label>Rows per page<select aria-label="Rows per page" value={size} onChange={e => { setSize(Number(e.target.value)); setPage(1); }}>{[25, 50, 100].map(n => <option key={n}>{n}</option>)}</select></label></div>
            {error ? <div className="ea-empty" role="alert">{error}<button className="btn ghost" onClick={() => setRefresh(n => n + 1)}>Retry</button></div> : loading ? <div className="ea-empty" role="status">Loading candidates…</div> : !rows.length ? <div className="ea-empty"><UsersRound size={28} /><div><h2>{counts.all ? 'No candidates match these filters' : 'No registered candidates yet'}</h2><p>{counts.all ? 'Try a different search or reset the filters.' : 'New profiles from candidate and training center registration will appear here.'}</p></div>{counts.all > 0 && <button className="btn ghost" onClick={reset}>Reset filters</button>}</div> : <div className="data-table ea-table ca-table"><table><thead><tr><th className="ea-select-cell"><input type="checkbox" aria-label="Select candidates on this page" checked={rows.length > 0 && rows.every(c => checked.includes(c.id))} ref={el => { if (el) el.indeterminate = rows.some(c => checked.includes(c.id)) && !rows.every(c => checked.includes(c.id)); }} onChange={e => setChecked(e.target.checked ? rows.map(c => c.id) : [])} /></th>{columns.map(([label, key]) => <th key={label} aria-sort={key ? sort === key ? direction === 'asc' ? 'ascending' : 'descending' : 'none' : undefined}>{key ? <button onClick={() => changeSort(key)}>{label}<ArrowDownUp size={13} />{sort === key && <span>{direction === 'asc' ? '↑' : '↓'}</span>}</button> : label}</th>)}<th>Actions</th></tr></thead><tbody>{rows.map(c => <tr key={c.id} className={checked.includes(c.id) ? 'ea-selected' : ''}>
                <td className="ea-select-cell"><input type="checkbox" aria-label={`Select ${c.first_name} ${c.last_name}`} checked={checked.includes(c.id)} onChange={() => setChecked(ids => ids.includes(c.id) ? ids.filter(id => id !== c.id) : [...ids, c.id])} /></td>
                <td><div className="ca-person"><span className="ca-avatar">{c.first_name?.[0]}{c.last_name?.[0]}</span><div><Link className="table-record-link" to={`/admin/candidates/${c.candidate_code}`}>{c.first_name} {c.last_name}</Link><small>{c.candidate_code}</small><small><MapPin size={12} />{[c.district, c.state].filter(Boolean).join(', ') || 'Location not provided'}</small></div></div></td>
                <td><b>{c.qualification}</b><small>{c.training_center || 'Public registration'}{c.batch_code ? ` · ${c.batch_code}` : ''}</small><small>{c.training_status || 'Not recorded'}</small></td>
                <td><b>{c.preferred_industry}</b><small>{c.experience_type} · ₹{currency(c.expected_monthly_salary)}/month</small><small className="ca-skills" title={c.skills}>{c.skills || 'Skills not provided'}</small></td>
                <td><Badge tone={c.availability === 'Yes' ? 'verified' : c.availability === 'Available after training' ? 'pending' : 'inactive'} icon={c.availability === 'Yes' ? UserCheck : GraduationCap}>{availabilityLabel(c.availability)}</Badge><small><span className={`ca-account ${c.profile_status === 'active' ? 'active' : ''}`}>{c.profile_status === 'active' ? 'Active profile' : 'Inactive profile'}</span></small></td>
                <td><div className="ca-contact"><Phone size={12} /><span>{c.whatsapp}</span></div>{c.email && <div className="ca-contact ca-email" title={c.email}><Mail size={12} /><span>{c.email}</span></div>}<small><Badge tone={c.whatsapp_consent ? 'verified' : 'inactive'} icon={MessageCircle}>{c.whatsapp_consent ? 'WhatsApp opted in' : 'No WhatsApp consent'}</Badge></small></td>
                <td className="ea-number"><b>{c.applications_count || 0} applications</b><small>{c.whatsapp_count || 0} WhatsApp messages</small></td><td className="ea-date">{dateLabel(c.created_at)}<small>Updated {dateLabel(c.updated_at)}</small></td>
                <td><div className="ea-row-actions ca-row-actions"><Link className="table-row-action" to={`/admin/candidates/${c.candidate_code}`} aria-label={`View ${c.first_name} ${c.last_name}`} title="View candidate profile"><Eye /></Link>{canManage && <><button className="table-row-action" onClick={() => edit(c)} aria-label={`Edit work preferences for ${c.first_name}`} title="Edit work preferences"><Pencil /></button><button className="table-row-action" onClick={() => beginAction(c.profile_status === 'active' ? 'disable' : 'activate', [c.id])} aria-label={`${c.profile_status === 'active' ? 'Deactivate' : 'Activate'} ${c.first_name}`} title={c.profile_status === 'active' ? 'Deactivate profile' : 'Activate profile'}><Power /></button></>}</div>{canManage && <WhatsAppMessage code={c.candidate_code} disabled={c.profile_status !== 'active'} />}</td>
            </tr>)}</tbody></table></div>}
            <footer className="ea-pagination"><span>{total ? `${data?.from || 0}–${data?.to || 0} of ${total.toLocaleString('en-IN')}` : '0 candidates'}</span><div><button className="btn ghost" disabled={loading || current <= 1} onClick={() => setPage(current - 1)} aria-label="Previous page"><ChevronLeft /></button><span>Page {current} of {pages}</span><button className="btn ghost" disabled={loading || current >= pages} onClick={() => setPage(current + 1)} aria-label="Next page"><ChevronRight /></button></div></footer>
        </section>
        <Modal open={filtersOpen} onClose={() => setFiltersOpen(false)} title="Filter candidates" sub="Choose the profiles you want to work with." className="ea-filter-sidebar ea-drawer-enter" footer={<><button className="btn ghost" onClick={() => setDraftFilters(emptyCandidateFilters())}><RotateCcw size={16} />Clear filters</button><button className="btn primary" disabled={!!(draftFilters.from && draftFilters.to && draftFilters.from > draftFilters.to)} onClick={() => { apply(draftFilters); setFiltersOpen(false); }}><CheckCircle2 size={16} />Apply filters</button></>}>
            {[['Availability', 'availability', ['Yes', 'Available after training', 'No']], ['Profile status', 'statuses', ['active', 'inactive']], ['Training status', 'training', ['Ongoing', 'Completed', 'Not enrolled']], ['Qualification', 'qualifications', options.qualifications || []], ['Preferred industry', 'industries', options.industries || []], ['State', 'states', options.states || []], ['Training center', 'centers', options.centers || []]].map(([label, key, values]) => <FilterGroup key={key} label={label} values={values} selected={draftFilters[key]} onChange={value => setDraftFilters(f => ({ ...f, [key]: value }))} />)}
            <label className="field-label">WhatsApp consent<select value={draftFilters.consent} onChange={e => setDraftFilters(f => ({ ...f, consent: e.target.value }))}><option value="">All candidates</option><option value="yes">Opted in</option><option value="no">Not opted in</option></select></label>
            <div className="ea-filter-dates"><label className="field-label">Registered from<input type="date" value={draftFilters.from} max={draftFilters.to || undefined} onChange={e => setDraftFilters(f => ({ ...f, from: e.target.value }))} /></label><label className="field-label">Registered to<input type="date" value={draftFilters.to} min={draftFilters.from || undefined} onChange={e => setDraftFilters(f => ({ ...f, to: e.target.value }))} /></label></div>{draftFilters.from && draftFilters.to && draftFilters.from > draftFilters.to && <p className="ea-error" role="alert">Choose an end date on or after the start date.</p>}
        </Modal>
        <Modal open={!!editing} onClose={() => { if (!busy) setEditing(null); }} title="Edit work preferences" sub={editing ? `${editing.first_name} ${editing.last_name} · ${editing.candidate_code}` : ''} footer={<><button className="btn ghost" disabled={busy} onClick={() => setEditing(null)}>Cancel</button><button className="btn primary" disabled={busy} onClick={() => mutate(`/admin-api/candidates/${editing.candidate_code}`, draft, 'patch')}><Save size={16} />{busy ? 'Saving…' : 'Save preferences'}</button></>}>
            <p className="ea-edit-note">Update work readiness and salary expectations for matching.</p><label className="field-label">Availability<select value={draft.availability || ''} onChange={e => setDraft(d => ({ ...d, availability: e.target.value }))}>{['Yes', 'No', 'Available after training'].map(v => <option key={v} value={v}>{availabilityLabel(v)}</option>)}</select></label><label className="field-label">Expected monthly salary (INR)<input type="number" min="0" max="10000000" value={draft.expected_monthly_salary ?? ''} onChange={e => setDraft(d => ({ ...d, expected_monthly_salary: e.target.value }))} /></label><label className="field-label">Skills<textarea maxLength={2000} value={draft.skills || ''} onChange={e => setDraft(d => ({ ...d, skills: e.target.value }))} /></label>{dialogError && <p className="ea-error" role="alert">{dialogError}</p>}
        </Modal>
        <Modal open={!!action} onClose={() => { if (!busy) setAction(null); }} title={`${action?.kind === 'disable' ? 'Deactivate' : 'Activate'} candidate profiles`} sub={`${action?.ids.length || 0} profile(s) selected`} footer={<><button className="btn ghost" disabled={busy} onClick={() => setAction(null)}>Cancel</button><button className="btn primary" disabled={busy} onClick={() => mutate('/admin-api/candidates/actions', { ids: action.ids, action: action.kind })}><Power size={16} />{busy ? 'Updating…' : action?.kind === 'disable' ? 'Deactivate profiles' : 'Activate profiles'}</button></>}><p>{action?.kind === 'disable' ? 'Deactivated candidates cannot sign in or receive new WhatsApp messages. Their profile and hiring history remain available for review.' : 'These candidates will be able to sign in again. WhatsApp sending still requires their recorded consent.'}</p>{dialogError && <p className="ea-error" role="alert">{dialogError}</p>}</Modal>
    </div>;
}
