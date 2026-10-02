import React, { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Panel, Status } from '../../components/UI.jsx';
import '../../data-tables.css';
import WhatsAppMessage from '../../components/WhatsAppMessage.jsx';

export const formatSchedule = value => value ? new Date(value).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Not scheduled';
export const csvCell = value => '"'+String(value ?? '').replace(/^[\s]*[=+@-]/, "'").replaceAll('"','""')+'"';

export function HiringOverview({ data, compact = false }) {
    const upcoming = data.interviews.filter(r => ['scheduled', 'confirmed'].includes(r.status)).sort((a,b) => new Date(a.scheduled_at)-new Date(b.scheduled_at));
    const cards = [
        ['Active jobs', data.summary.active_jobs, 'View hiring requirements', 'jobs'],
        ['Drafts to complete', data.summary.draft_jobs, 'Continue a saved requirement', 'jobs?status=draft'],
        ['Released candidates', data.summary.candidates, 'Review your candidate pool', 'talent'],
        ['Scheduled interviews', upcoming.length, 'View interview schedule', 'interviews'],
    ];
    return <>
        {!compact && <div className="employer-metrics">{cards.map(([label, count, detail, route]) => <Link key={label} to={`/employer/${route}`}><span>{label}</span><strong>{count}</strong><small>{detail} →</small></Link>)}</div>}
        <div className="employer-work-grid">
            <Panel title="Hiring priorities" sub="Keep your next hiring action in view.">
                <div className="employer-priorities">
                    {data.employer.status !== 'verified' && <Link to="/employer/onboarding"><b>Complete company verification</b><span>Review your company details and approval status →</span></Link>}
                    <Link to="/employer/jobs?status=draft"><b>{data.summary.draft_jobs} job drafts</b><span>Complete requirements and preview before publishing →</span></Link>
                    <Link to="/employer/joining"><b>{data.placements.filter(r => !r.joined_on && ['selected','joining_pending'].includes(r.status)).length} candidates awaiting joining</b><span>Track staff-confirmed outcomes and joining dates →</span></Link>
                </div>
            </Panel>
            <Panel title="Next interviews" sub="Interview times shown in India Standard Time." action={<Link className="text-link" to="/employer/interviews">View all →</Link>}>
                {upcoming.length ? upcoming.slice(0,3).map(r => <div className="employer-agenda" key={r.id}><div><b>{r.first_name} {r.last_name}</b><p>{r.title}</p><small>{formatSchedule(r.scheduled_at)} · {r.mode || 'Mode pending'}</small></div><Status tone={r.status}>{r.status}</Status></div>) : <div className="employer-quiet"><b>No interviews scheduled</b><p>EXOWORK will release candidate details here once interviews are scheduled.</p></div>}
            </Panel>
        </div>
    </>;
}

export function HiringTable({ page, data }) {
    const location = useLocation();
    const [query, setQuery] = useState(''), [job, setJob] = useState(new URLSearchParams(location.search).get('job') || ''), [status, setStatus] = useState(''), [current, setCurrent] = useState(1), [selected, setSelected] = useState([]);
    const interview = ['talent', 'interviews'].includes(page);
    const source = interview ? data.interviews : data.placements;
    // Keep one row per candidate and job, preserving distinct applications.
    const records = page === 'talent' ? Array.from(new Map(source.map(r => [r.application_id, r])).values()) : source;
    const rows = records.filter(r => (!job || String(r.job_post_id) === job) && (!status || r.status === status) && `${r.first_name} ${r.last_name} ${r.title}`.toLowerCase().includes(query.toLowerCase()));
    const pages = Math.max(1, Math.ceil(rows.length / 20)), activePage = Math.min(current, pages), visible = rows.slice((activePage-1)*20, activePage*20);
    const change = setter => e => { setter(e.target.value); setCurrent(1); setSelected([]); };
    const toggle = id => setSelected(s => s.includes(id) ? s.filter(x => x !== id) : [...s,id]);
    const exportRows = () => {
        const chosen = rows.filter(r => selected.includes(r.id));
        const fields = ['Candidate','Job','Status',interview ? 'Interview (IST)' : 'Expected joining',interview ? 'Mode / location' : 'Joined'];
        const lines = [fields, ...chosen.map(r => [`${r.first_name} ${r.last_name}`,r.title,r.status,interview ? formatSchedule(r.scheduled_at) : r.expected_joining_on,interview ? `${r.mode || ''} / ${r.location || ''}` : r.joined_on])];
        const url = URL.createObjectURL(new Blob(['\ufeff'+lines.map(row => row.map(csvCell).join(',')).join('\r\n')], {type:'text/csv;charset=utf-8'}));
        const a = document.createElement('a'); a.href=url; a.download=`exowork-${page}.csv`; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    return <Panel title={page === 'talent' ? 'Released candidate pool' : page === 'interviews' ? 'Interview schedule' : 'Selection and joining records'} sub="EXOWORK schedules interviews and confirms hiring outcomes. Records shown belong to your company.">
        <div className="employer-list-filters">
            <label>Search candidates<input placeholder="Search candidate name or job" value={query} onChange={change(setQuery)}/></label>
            <label>Job requirement<select value={job} onChange={change(setJob)}><option value="">All jobs</option>{data.jobs.map(j => <option key={j.id} value={j.id}>{j.title}</option>)}</select></label>
            <label>Status<select value={status} onChange={change(setStatus)}><option value="">All statuses</option>{[...new Set(records.map(r=>r.status))].map(s=><option key={s} value={s}>{s.replaceAll('_',' ')}</option>)}</select></label>
        </div>
        <div className="employer-bulk"><span aria-live="polite">{selected.length ? `${selected.length} selected` : `${rows.length} records`} · 20 per page</span><button className="btn ghost" disabled={!selected.length} onClick={exportRows}>Export selected</button>{selected.length > 0 && <button className="btn ghost" onClick={()=>setSelected([])}>Clear selection</button>}</div>
        {!visible.length ? <div className="employer-empty"><h3>{records.length ? 'No records match your filters' : 'No hiring records yet'}</h3><p>{records.length ? 'Try another name, job or status.' : 'Candidate details appear after EXOWORK schedules an interview for your job.'}</p>{records.length > 0 && <button className="btn ghost" onClick={()=>{setQuery('');setJob('');setStatus('');}}>Clear filters</button>}</div> : <div className="data-table"><table><thead><tr><th><input type="checkbox" aria-label="Select this page" checked={visible.every(r=>selected.includes(r.id))} onChange={e=>setSelected(s=>e.target.checked ? [...new Set([...s,...visible.map(r=>r.id)])] : s.filter(id=>!visible.some(r=>r.id===id)))}/></th><th>Candidate</th><th>Job requirement</th><th>{interview ? 'Interview (IST)' : 'Joining dates'}</th><th>{interview ? 'Mode and location' : 'Outcome'}</th><th>Status</th><th>WhatsApp</th></tr></thead><tbody>{visible.map(r=><tr key={r.id} className={selected.includes(r.id)?'selected':''}><td><input type="checkbox" aria-label={`Select ${r.first_name} ${r.last_name} for ${r.title}`} checked={selected.includes(r.id)} onChange={()=>toggle(r.id)}/></td><td><strong>{r.first_name} {r.last_name}</strong></td><td>{r.title}</td><td>{interview ? formatSchedule(r.scheduled_at) : <><div>Expected: {r.expected_joining_on || 'Pending'}</div><small>Joined: {r.joined_on || 'Not confirmed'}</small></>}</td><td>{interview ? <>{r.mode || 'Pending'}<small>{r.location || 'Location pending'}</small></> : r.joined_on ? 'Joining confirmed' : 'Staff confirmation'}</td><td><Status tone={r.status}>{r.status.replaceAll('_',' ')}</Status></td><td>{r.candidate_code && <WhatsAppMessage code={r.candidate_code} audience="employer" />}</td></tr>)}</tbody></table></div>}
        <div className="employer-pagination"><span>Page {activePage} of {pages}</span><button className="btn ghost" disabled={activePage===1} onClick={()=>setCurrent(activePage-1)}>Previous</button><button className="btn ghost" disabled={activePage===pages} onClick={()=>setCurrent(activePage+1)}>Next</button></div>
    </Panel>;
}
