import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Bookmark, BriefcaseBusiness, ChevronRight, Coins, Download, CircleAlert, CheckCircle2, LoaderCircle, GraduationCap, CalendarCheck, LockKeyhole, MapPin, MessageCircle, Search, SlidersHorizontal, UsersRound, X } from 'lucide-react';
import { Modal, Status } from '../../components/UI.jsx';
import { csrf, useEmployerMasters } from './EmployerRegistration.jsx';
import './employer.css';
import './posted-jobs.css';
import './candidate-browse.css';

const facets = [
    ['qualification', 'Education'],
    ['experience_type', 'Experience'], ['availability', 'Availability'],
];
const errorMessage = error => {
    if (!error.response) return 'Could not reach the server. Check your connection and try again.';
    if (error.response.status >= 500) return 'The server could not complete this action. Please try again.';
    return Object.values(error.response.data?.errors || {}).flat().join(' ')
        || error.response.data?.message || 'Could not complete this action. Please try again.';
};

export default function EligibleCandidates() {
    const { id } = useParams();
    return <CandidateBrowse key={id} id={id}/>;
}

function CandidateBrowse({ id }) {
    const base = `/employer-api/jobs/${id}/candidates`;
    const [filters, setFilters] = useState({ scope: 'all' });
    const { master, districts, masterError } = useEmployerMasters(filters.state);
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [selected, setSelected] = useState([]);
    const [loading, setLoading] = useState(true);
    const [version, setVersion] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [sending, setSending] = useState(null);
    const [unlocking, setUnlocking] = useState([]);
    const [notice, setNotice] = useState('');
    const [creditDialog, setCreditDialog] = useState(null);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [showJob, setShowJob] = useState(false);
    const [profile, setProfile] = useState(null);
    const [reject, setReject] = useState(null);
    const candidates = data?.candidates ?? [];
    const selection = candidates.filter(candidate => selected.includes(candidate.id));
    const selectedLocked = selection.filter(candidate => !candidate.unlocked).length;
    const lastPage = Math.max(1, Math.ceil((data?.total ?? 0) / (data?.per_page ?? 20)));

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        const timer = setTimeout(async () => {
            try {
                const response = await axios.get(base, { params: { ...filters, page }, signal: controller.signal });
                if (!controller.signal.aborted) setData(response.data);
            } catch (e) {
                if (!controller.signal.aborted) setError(errorMessage(e));
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 180);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [base, filters, page, version]);

    useEffect(() => {
        if (!notice) return;
        const timeout = setTimeout(() => setNotice(''), 5000);
        return () => clearTimeout(timeout);
    }, [notice]);

    function updateFilters(next) {
        if (busy) return;
        setLoading(true);
        setFilters(next);
        setPage(1);
        setSelected([]);
        setError('');
    }

    function navigate(next) {
        setLoading(true);
        setPage(next);
        setSelected([]);
    }

    async function perform(action) {
        setBusy(true);
        setError('');
        setNotice('');
        try { await action(); }
        catch (e) {
            if (e.response?.data?.code === 'INSUFFICIENT_PROFILE_CREDITS') {
                const { balance, required } = e.response.data;
                setData(current => ({ ...current, balance }));
                setCreditDialog({ balance, required });
            } else {
                setError(errorMessage(e));
            }
        }
        finally { setBusy(false); }
    }

    async function mutate(path, values, feedback, after) {
        await perform(async () => {
            const { data: result } = await axios.post(base + path, values, { headers: csrf() });
            after?.();
            setNotice(feedback(result));
            setLoading(true);
            setVersion(value => value + 1);
        });
    }

    const unlock = ids => perform(async () => {
        setUnlocking(ids);
        try {
            const { data: result } = await axios.post(base + '/unlock', { candidate_ids: ids }, { headers: csrf() });
            const updated = new Map(result.candidates.map(candidate => [candidate.id, candidate]));
            setData(current => ({
                ...current, balance: result.balance,
                candidates: current.candidates.map(candidate => ({ ...candidate, ...updated.get(candidate.id) })),
            }));
            setNotice(result.unlocked ? `${result.unlocked} ${result.unlocked === 1 ? 'profile' : 'profiles'} unlocked.` : 'Profiles already unlocked.');
        } finally { setUnlocking([]); }
    });

    const invite = candidate => perform(async () => {
        setSending(candidate.id);
        try {
            const { data: result } = await axios.post(`${base}/${candidate.id}/invite`, {}, { headers: csrf() });
            const statuses = { queued: 'WhatsApp invitation queued.', submitting: 'WhatsApp invitation is being sent.',
                accepted: 'WhatsApp invitation sent.', delivered: 'WhatsApp invitation delivered.', read: 'WhatsApp invitation read.' };
            const failures = { failed: 'WhatsApp could not send this invitation. Contact your admin.', skipped: 'WhatsApp skipped this invitation. Check the candidate’s consent or ask your admin.' };
            if (statuses[result.status]) setNotice(statuses[result.status]);
            else setError(failures[result.status] ?? 'WhatsApp could not confirm the invitation status. Contact your admin.');
        } finally { setSending(null); }
    });

    const download = () => perform(async () => {
        try {
            const response = await axios.post(base + '/export', { candidate_ids: selection.map(c => c.id) }, { headers: csrf(), responseType: 'blob' });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = `job-${id}-candidates.csv`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (e) {
            if (e.response?.data instanceof Blob) {
                try { e.response.data = JSON.parse(await e.response.data.text()); } catch {}
            }
            throw e;
        }
    });

    function filterSelect(key, label) {
        const options = [...new Set([...(data.filter_options[key] ?? []), filters[key]].filter(Boolean))];
        return <label key={key}>{label}<select disabled={busy} value={filters[key] ?? ''} onChange={e => updateFilters({ ...filters, [key]: e.target.value })}>
            <option value="">All</option>{options.map(value => <option key={value}>{value}</option>)}
        </select></label>;
    }

    return <div className="employer-live employer-candidate-review candidate-browse">
        <Link className="candidate-back" to="/employer/jobs"><ArrowLeft size={15}/>Posted jobs</Link>
        <header className="browse-heading">
            <h1>Candidates</h1>
            {data && <button className="browse-credit" onClick={() => setCreditDialog({ balance: data.balance })}>
                <Coins size={16}/><span><strong>{data.balance.toLocaleString('en-IN')}</strong> credits left</span><ChevronRight size={14}/>
            </button>}
        </header>
        {data && <section className="browse-job" aria-label="Job summary">
            <span className="candidate-context-icon"><BriefcaseBusiness size={18}/></span>
            <div><div className="candidate-context-title"><h2>{data.job.title}</h2><Status tone={data.job.status}>{data.job.status}</Status></div>
                <p>{[data.job.district, data.job.state].filter(Boolean).join(', ')}</p></div>
            <button className="browse-job-details" onClick={() => setShowJob(true)}>Job details<ChevronRight size={15}/></button>
        </section>}
        <div className="browse-workspace">
            {data && <aside className={`browse-filters ${filtersOpen ? 'is-open' : ''}`} aria-label="Candidate filters">
                <div className="browse-filter-head">
                    <span><SlidersHorizontal size={16}/> Filters</span>
                    <button className="browse-filter-reset" disabled={busy || !Object.keys(filters).some(key => !['sort', 'scope'].includes(key) && filters[key])} onClick={() => updateFilters({ sort: filters.sort, scope: filters.scope || 'all' })}>Reset</button>
                    <button className="browse-filter-toggle" onClick={() => setFiltersOpen(value => !value)} aria-controls="browse-filter-fields" aria-expanded={filtersOpen}>{filtersOpen ? 'Hide' : 'Show'}</button>
                </div>
                <div className="browse-filter-fields" id="browse-filter-fields">
                    <label className="browse-search">Search<span><Search size={15}/><input disabled={busy} placeholder="Name or skill" value={filters.search ?? ''} onChange={e => updateFilters({ ...filters, search: e.target.value })}/></span></label>
                    <fieldset><legend>Location</legend>
                        <label>State
                            <select disabled={busy} value={filters.state ?? ''} onChange={event => updateFilters({ ...filters, state: event.target.value, district: '' })}>
                                <option value="">All states</option>
                                {master.states.map(state => <option key={state.id} value={state.name}>{state.name}</option>)}
                            </select>
                        </label>
                        <label>District
                            <select value={filters.district ?? ''} disabled={busy || !filters.state || !districts.length} onChange={event => updateFilters({ ...filters, district: event.target.value })}>
                                <option value="">{filters.state ? 'All districts' : 'Select a state first'}</option>
                                {districts.map(district => <option key={district.id} value={district.name}>{district.name}</option>)}
                            </select>
                        </label>
                        {masterError && <small role="alert">{masterError}</small>}
                        <label>Area<input disabled={busy} placeholder="Current locality" value={filters.area ?? ''} onChange={e => updateFilters({ ...filters, area: e.target.value })}/></label>
                    </fieldset>
                    <fieldset><legend>Background</legend>{facets.map(([key, label]) => filterSelect(key, label))}</fieldset>
                </div>
            </aside>}
            <section className="browse-results" aria-label="Candidate results" aria-busy={loading}>
                {data && <div className="browse-scope-tabs" role="group" aria-label="Candidate pool"><button disabled={busy} aria-pressed={(filters.scope || 'all') === 'all'} onClick={() => updateFilters({ ...filters, scope: 'all' })}>All candidates <span>{data.pool_total ?? data.total}</span></button><button disabled={busy} aria-pressed={filters.scope === 'matching'} onClick={() => updateFilters({ ...filters, scope: 'matching' })}>Job matches <span>{data.matching_total ?? 0}</span></button></div>}
                {data && <p className="browse-scope-note">{filters.scope === 'matching' ? 'Profiles matching the recorded job requirements.' : 'Browse the candidate pool and review suitability before shortlisting. Job matches are labeled.'}</p>}
                {data && <div className={`browse-selection ${!candidates.length ? 'is-standalone' : ''}`}>
                    <h2>{data.total} {data.total === 1 ? 'candidate' : 'candidates'}</h2>
                    <label className="browse-sort">Sort by<select disabled={busy} value={filters.sort ?? 'newest'} onChange={e => updateFilters({ ...filters, sort: e.target.value === 'newest' ? '' : e.target.value })}><option value="newest">Newest profiles</option><option value="name">Name A–Z</option></select></label>
                </div>}
                {data && !!candidates.length && <div className="browse-bulk">
                    <label><input type="checkbox" disabled={busy || loading} checked={selection.length === candidates.length} onChange={e => setSelected(e.target.checked ? candidates.map(c => c.id) : [])}/> Select page</label>
                    {!!selection.length && <><span>{selection.length} selected</span>{selectedLocked
                        ? <button className="btn primary" disabled={busy || loading} onClick={() => unlock(selection.map(c => c.id))}>{unlocking.length ? <><LoaderCircle className="browse-spin" size={15}/>Unlocking…</> : <><LockKeyhole size={15}/>Unlock selected ({selectedLocked} {selectedLocked === 1 ? 'credit' : 'credits'})</>}</button>
                        : <button className="btn primary" disabled={busy || loading} onClick={download}><Download size={15}/>Export CSV</button>}</>}
                </div>}
                {loading && !data && <p role="status">Loading candidates…</p>}
                {data && <>
                    {!loading && !candidates.length && <div className="posted-empty"><UsersRound size={30}/><h3>No candidates found</h3><p>{Object.values(filters).some(Boolean) ? 'Try changing your filters.' : filters.scope === 'matching' ? 'No recorded matches yet. Switch to All candidates to browse the pool.' : 'Active candidate profiles will appear here.'}</p></div>}
                    <div className="candidate-review-list">{candidates.map(c => {
                        const name = [c.first_name, c.last_name].filter(Boolean).join(' ');
                        const location = [c.district, c.state].filter(Boolean).join(', ');
                        const facts = [['Education', c.qualification, GraduationCap], ['Training course', c.training_course, GraduationCap], ['Experience', c.experience_type, BriefcaseBusiness], ['Availability', c.availability === 'Yes' ? 'Available now' : c.availability === 'No' ? 'Unavailable' : c.availability, CalendarCheck]].filter(([,value]) => value);
                        const skills = (c.skills || '').split(/[,;\n]+/).map(skill => skill.trim()).filter(Boolean);
                        const required = (data.job.skills || '').toLowerCase().split(/[,;\n]+/).map(skill => skill.trim()).filter(Boolean);
                        const checked = selected.includes(c.id);
                        return <article className={`candidate-review-card browse-card ${c.shortlisted ? 'browse-shortlisted' : ''} ${checked ? 'is-selected' : ''}`} key={c.id}>
                            <header>
                                <input type="checkbox" aria-label={`Select ${name}`} disabled={busy} checked={checked} onChange={e => setSelected(ids => e.target.checked ? [...ids, c.id] : ids.filter(value => value !== c.id))}/>
                                <span className="candidate-review-avatar">{c.first_name?.[0]}{c.last_name?.[0]}</span>
                                <div><h3>{name}</h3>{location && <p><MapPin size={13}/>{location}</p>}</div>
                                {c.job_match && <span className="candidate-eligible-badge"><CheckCircle2 size={13}/>Job match</span>}
                                {c.shortlisted && <span className="candidate-eligible-badge"><Bookmark size={13}/>Shortlisted</span>}
                            </header>
                            <div className="browse-card-body">
                                {!!facts.length && <dl className="browse-facts">{facts.map(([label, value, Icon]) => <div key={label}>
                                    <Icon size={16} aria-hidden="true"/>
                                    <div><dt>{label}</dt><dd>{value}</dd></div>
                                </div>)}</dl>}
                                {!!skills.length && <div className="browse-skills"><span>Skills</span>{[...new Set(skills)].map(skill => <span className={required.some(value => skill.toLowerCase().includes(value)) ? 'is-match' : ''} key={skill}>{skill}</span>)}</div>}
                            </div>
                            <footer className="browse-actions">
                                <div className="browse-contact">
                                    {c.unlocked ? <>
                                        <button className="btn ghost" onClick={() => setProfile(c)}>View profile</button>
                                        {c.has_resume ? <a className="btn ghost" href={`${base}/${c.id}/resume`}><Download size={15}/>Download resume</a>
                                            : <button type="button" className="btn ghost browse-no-resume" disabled><Download size={15}/>No resume available</button>}
                                        <button className="btn ghost" disabled={busy || loading || !data.can_invite || !c.whatsapp_consent} aria-busy={sending === c.id} onClick={() => invite(c)}>
                                            {sending === c.id ? <><LoaderCircle className="browse-spin" size={15}/>Sending…</> : <><MessageCircle size={15}/>Send WhatsApp</>}
                                        </button>
                                        {(!data.can_invite || !c.whatsapp_consent) && <small>{!data.can_invite ? 'Invitations are available for live jobs.' : 'WhatsApp consent unavailable.'}</small>}
                                    </> : <button className="browse-unlock" disabled={busy || loading} aria-busy={unlocking.includes(c.id)} onClick={() => unlock([c.id])}>
                                        {unlocking.includes(c.id) ? <><LoaderCircle className="browse-spin" size={16}/>Unlocking…</> : <><LockKeyhole size={16}/>Unlock profile <span>1 credit</span></>}</button>}
                                </div>
                                    <div className="browse-review">
                                        <button disabled={busy || loading} aria-pressed={c.shortlisted} onClick={() => mutate(`/${c.id}/shortlist`, { shortlisted: !c.shortlisted }, result => result.shortlisted ? 'Candidate shortlisted.' : 'Candidate removed from shortlist.')}><Bookmark size={14}/>{c.shortlisted ? 'Unshortlist' : 'Shortlist'}</button>
                                        <button disabled={busy || loading} onClick={() => setReject(c)}><X size={15}/>Reject</button>
                                    </div>
                            </footer>
                        </article>;
                    })}</div>
                    {lastPage > 1 && <div className="employer-pagination"><span>Page {data.page} of {lastPage}</span><button className="btn ghost" disabled={busy || data.page === 1} onClick={() => navigate(data.page - 1)}>Previous</button><button className="btn ghost" disabled={busy || data.page >= lastPage} onClick={() => navigate(data.page + 1)}>Next</button></div>}
                </>}
            </section>
        </div>
        {(notice || (error && !reject)) && <div className={`browse-feedback ${error ? 'is-error' : ''}`} role={error ? 'alert' : 'status'}>
            {error ? <CircleAlert size={18}/> : <CheckCircle2 size={18}/>}
            <span>{error || notice}</span>
            <button type="button" aria-label="Dismiss notification" onClick={() => { setError(''); setNotice(''); }}><X size={16}/></button>
        </div>}
        <Modal open={!!creditDialog} onClose={() => setCreditDialog(null)} title={creditDialog?.required ? 'Insufficient credits' : 'Job credits'} className="browse-credit-dialog">
            {creditDialog && <div className="browse-credit-info">
                <span className="browse-credit-icon"><Coins size={25}/></span>
                <p>{creditDialog.required ? `This unlock requires ${creditDialog.required} ${creditDialog.required === 1 ? 'credit' : 'credits'}. Contact your admin to buy more credits for this job.` : 'Use job credits to unlock candidate contact details and resumes.'}</p>
                <dl><div><dt>Available balance</dt><dd>{creditDialog.balance.toLocaleString('en-IN')}</dd></div><div><dt>Cost per profile unlock</dt><dd>1 credit</dd></div></dl>
                <button className="btn primary" onClick={() => setCreditDialog(null)}>Done</button>
            </div>}
        </Modal>
        <Modal open={showJob} onClose={() => setShowJob(false)} title={data?.job.title}>
            {data && <dl className="candidate-detail-list">{[['Description', data.job.description], ['Responsibilities', data.job.responsibilities], ['Education', data.job.education], ['Experience', data.job.experience], ['Required skills', data.job.skills], ['Benefits', data.job.benefits], ['Deadline', data.job.application_deadline]].filter(([,value]) => value).map(([label,value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>}
        </Modal>
        <Modal open={!!profile} onClose={() => setProfile(null)} title={[profile?.first_name, profile?.last_name].filter(Boolean).join(' ')} sub={profile?.candidate_code}>
            {profile && <dl className="candidate-detail-list">{[['Phone', profile.whatsapp], ['Email', profile.email], ['Area', profile.area], ['Experience details', profile.experience_details], ['Skills', profile.skills]].filter(([,value]) => value).map(([label,value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>}
        </Modal>
        <Modal open={!!reject} onClose={() => { if (!busy) setReject(null); }} title="Reject candidate?">
            <p>{reject?.first_name} {reject?.last_name} will be removed from this job’s candidate list.</p>
            {error && <p role="alert">{error}</p>}
            <div className="modal-actions"><button className="btn ghost" disabled={busy} onClick={() => setReject(null)}>Cancel</button><button className="btn danger" disabled={busy} onClick={() => mutate(`/${reject.id}/skip`, {}, () => 'Candidate rejected.', () => { setSelected(ids => ids.filter(value => value !== reject.id)); setReject(null); })}>Reject candidate</button></div>
        </Modal>
    </div>;
}
