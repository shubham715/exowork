import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useLocation } from 'react-router-dom';
import { BriefcaseBusiness, MapPin, UsersRound, CalendarDays, Eye, Copy, Pencil, Pause, Play, X, SlidersHorizontal, Search, CheckCircle2, ChevronRight, MoreHorizontal, RotateCcw } from 'lucide-react';
import { Modal, Status } from '../../components/UI.jsx';
import { csrf } from './EmployerRegistration.jsx';
import JobStats from './JobStats.jsx';
import '../admin/employer-admin.css';
import './posted-jobs.css';

const emptyFilters = () => ({ location: '', from: '', to: '', attention: '' });
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
const isLive = job => ['active', 'published'].includes(job.status);
const dateLabel = value => value ? new Date(value.slice(0, 10) + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '';

export default function PostedJobs({ data, setData, setError }) {
    const location = useLocation();
    const [tab, setTab] = useState(new URLSearchParams(location.search).get('status') || 'all');
    const [query, setQuery] = useState(''), [sort, setSort] = useState('newest'), [page, setPage] = useState(1);
    const [filters, setFilters] = useState(emptyFilters), [draft, setDraft] = useState(emptyFilters), [drawer, setDrawer] = useState(false);
    const [stats, setStats] = useState(null), [busy, setBusy] = useState(null);
    useEffect(() => setTab(new URLSearchParams(location.search).get('status') || 'all'), [location.search]);
    useEffect(() => setPage(1), [tab, query, filters, sort]);
    const count = status => data.jobs.filter(j => status === 'all' || (status === 'active' ? isLive(j) : j.status === status)).length;
    const rows = data.jobs.filter(j => (tab === 'all' || (tab === 'active' ? isLive(j) : j.status === tab))
        && `${j.title} ${j.id} ${j.district || ''}`.toLowerCase().includes(query.toLowerCase())
        && (!filters.location || j.district === filters.location)
        && (!filters.from || (j.created_at || '').slice(0, 10) >= filters.from)
        && (!filters.to || (j.created_at || '').slice(0, 10) <= filters.to)
        && (!filters.attention || (filters.attention === 'applications' ? Number(j.applications_count) > 0
            : filters.attention === 'closing' ? isLive(j) && j.application_deadline >= today() && j.days_remaining <= 7
            : isLive(j) && j.application_deadline && j.application_deadline < today())));
    rows.sort((a, b) => sort === 'applications' ? (b.applications_count || 0) - (a.applications_count || 0)
        : sort === 'deadline' ? (a.application_deadline || '9999').localeCompare(b.application_deadline || '9999')
        : sort === 'oldest' ? a.id - b.id : b.id - a.id);
    const pages = Math.max(1, Math.ceil(rows.length / 20)), current = Math.min(page, pages);
    const visible = rows.slice((current - 1) * 20, current * 20);
    const filterCount = Object.values(filters).filter(Boolean).length;
    const reset = () => { setFilters(emptyFilters()); setQuery(''); setTab('all'); };
    const changeStatus = async (job, status) => {
        setBusy(job.id); setError('');
        try { setData((await axios.put(`/employer-api/jobs/${job.id}`, { ...job, status }, { headers: csrf() })).data); }
        catch (e) { setError(e.response?.data?.message || 'Could not update this job. Please try again.'); }
        finally { setBusy(null); }
    };
    return <div className="posted-jobs">
        <div className="posted-overview">
            <div><span className="posted-overview-icon"><BriefcaseBusiness size={19}/></span><div><strong>{count('active')}</strong><span>Active jobs</span></div></div>
            <div><span className="posted-overview-icon"><UsersRound size={19}/></span><div><strong>{data.jobs.filter(isLive).reduce((n, j) => n + Number(j.openings || 0), 0)}</strong><span>Advertised openings · active jobs</span></div></div>
            <div><span className="posted-overview-icon"><CalendarDays size={19}/></span><div><strong>{data.interviews.filter(i => ['scheduled', 'confirmed'].includes(i.status)).length}</strong><span>Scheduled interviews</span></div></div>
        </div>
        <section className="posted-management" aria-label="Manage your posted jobs">
            <div className="posted-tabs" role="group" aria-label="Job status">{[['all','All jobs'],['active','Active'],['paused','Paused'],['draft','Drafts'],['filled','Filled'],['closed','Closed']].map(([value,label]) => <button key={value} aria-pressed={tab === value} className={tab === value ? 'active' : ''} onClick={() => setTab(value)}>{label}<span>{count(value)}</span></button>)}</div>
            <div className="posted-toolbar"><label className="posted-search"><Search size={17}/><input aria-label="Search your posted jobs" placeholder="Search by job title, ID or location" value={query} onChange={e => setQuery(e.target.value)}/></label>
                <button className="btn ghost" aria-haspopup="dialog" onClick={() => { setDraft({ ...filters }); setDrawer(true); }}><SlidersHorizontal size={16}/>Filters{filterCount > 0 && <span className="posted-filter-count">{filterCount}</span>}</button>
                <label className="posted-sort">Sort by<select value={sort} onChange={e => setSort(e.target.value)}><option value="newest">Newest posted</option><option value="oldest">Oldest posted</option><option value="applications">Most applications</option><option value="deadline">Closing soonest</option></select></label>
            </div>
            {filterCount > 0 && <div className="posted-filter-chips">{Object.entries(filters).filter(([,v])=>v).map(([key,value])=><button key={key} onClick={()=>setFilters(f=>({...f,[key]:''}))}>{key==='attention'?{applications:'Has applications',closing:'Closing in 7 days',expired:'Deadline passed'}[value]:key==='from'?`Posted from ${dateLabel(value)}`:key==='to'?`Posted to ${dateLabel(value)}`:value}<X size={12}/></button>)}<button onClick={()=>setFilters(emptyFilters())}>Clear filters</button></div>}
        </section>
        <div className="posted-result-count">{rows.length} {rows.length === 1 ? 'job' : 'jobs'}{tab !== 'all' ? ` · ${tab === 'draft' ? 'Drafts' : tab.charAt(0).toUpperCase()+tab.slice(1)}` : ''}<span>20 per page</span></div>
        {!visible.length ? <div className="posted-empty"><BriefcaseBusiness size={32}/><h3>{data.jobs.length ? 'No jobs match your filters' : 'Your first hire starts with a job post'}</h3><p>{data.jobs.length ? 'Change the status or clear your filters to see more of your jobs.' : 'Post your vacancy to find eligible candidates and start inviting them.'}</p>{data.jobs.length ? <button className="btn ghost" onClick={reset}>Clear filters</button> : <Link className="btn primary" to="/employer/jobs/new">Post your first job</Link>}</div> : <div className="posted-card-list">{visible.map(job => {
            const interviews = data.interviews.filter(i => i.job_post_id === job.id && ['scheduled', 'confirmed'].includes(i.status)).length;
            const joined = data.placements.filter(p => p.job_post_id === job.id && p.joined_on).length;
            const active = isLive(job), expired = active && job.application_deadline && job.application_deadline < today();
            return <article className={`posted-job-card job-state-${active ? 'active' : job.status}`} key={job.id}>
                <header><span className="posted-job-icon"><BriefcaseBusiness size={23}/></span><div className="posted-job-identity"><div><Link to="/employer/jobs/new" state={{job}}>{job.title}</Link><Status tone={active ? 'active' : job.status}>{active ? 'Active' : job.status.charAt(0).toUpperCase()+job.status.slice(1)}</Status></div><p><MapPin size={13}/>{[job.district,job.state].filter(Boolean).join(', ') || 'Location not added'}<span>·</span>{job.job_type || 'Job type not added'}<span>·</span>Job #{job.id}</p></div>
                    <details className="posted-actions-menu"><summary aria-label={`More actions for ${job.title}`}><MoreHorizontal size={20}/></summary><div><Link to="/employer/jobs/new" state={{job}}><Pencil size={15}/>Edit job</Link><Link to="/employer/jobs/new" state={{template:job}}><Copy size={15}/>Duplicate job</Link>{active && <button disabled={busy !== null} onClick={()=>changeStatus(job,'paused')}><Pause size={15}/>Pause job</button>}{job.status==='paused' && <button disabled={busy !== null || data.employer.status!=='verified'} onClick={()=>changeStatus(job,'active')}><Play size={15}/>Resume job</button>}{(active || job.status==='paused') && <><button disabled={busy !== null} onClick={()=>changeStatus(job,'filled')}><CheckCircle2 size={15}/>Mark filled</button><button disabled={busy !== null} onClick={()=>changeStatus(job,'closed')}><X size={15}/>Close job</button></>}</div></details>
                </header>
                <div className="posted-job-numbers"><div><strong>{job.openings}</strong><span>Openings</span></div><div><strong>{job.applications_count ?? 0}</strong><span>Applications</span></div><Link to={`/employer/interviews?job=${job.id}`}><strong>{interviews}</strong><span>Interviews</span></Link><Link to={`/employer/joining?job=${job.id}`}><strong>{joined}<small> / {job.openings}</small></strong><span>Joined</span></Link><button onClick={()=>setStats(job)}><strong>{job.views_count ?? 0}</strong><span>Impressions · {job.clicks_count ?? 0} clicks</span></button></div>
                <footer><div className="posted-job-dates"><span>{job.created_at ? `Posted ${dateLabel(job.created_at)}` : job.status==='draft'?'Unpublished draft':'Saved job'}</span>{job.application_deadline && <span className={expired?'expired':''}><CalendarDays size={13}/>{expired?'Application deadline has passed':`Deadline ${dateLabel(job.application_deadline)}`}{active && !expired && job.days_remaining != null && <b>{job.days_remaining === 0 ? 'Closes today' : `${job.days_remaining} days left`}</b>}</span>}{job.status==='paused' && <span>Paused · hidden from candidates</span>}</div><div className="posted-job-cta"><button className="btn ghost" onClick={()=>setStats(job)}><Eye size={15}/>View stats</button>{job.status==='draft' ? <Link className="btn primary" to="/employer/jobs/new" state={{job}}><Pencil size={15}/>Continue draft</Link> : <Link className="btn primary" to={`/employer/jobs/${job.id}/candidates`}>View eligible candidates<ChevronRight size={15}/></Link>}</div></footer>
            </article>;
        })}</div>}
        <div className="employer-pagination"><span>Page {current} of {pages}</span><button className="btn ghost" disabled={current===1} onClick={()=>setPage(current-1)}>Previous</button><button className="btn ghost" disabled={current===pages} onClick={()=>setPage(current+1)}>Next</button></div>
        <Modal open={drawer} onClose={()=>setDrawer(false)} title="Filter your jobs" sub="Narrow down the vacancies posted by your company." className="ea-filter-sidebar ea-drawer-enter" footer={<><button className="btn ghost" onClick={()=>setDraft(emptyFilters())}><RotateCcw size={16}/>Clear filters</button><button className="btn primary" disabled={!!(draft.from && draft.to && draft.from > draft.to)} onClick={()=>{setFilters(draft);setDrawer(false);}}>Apply filters</button></>}>
            <label className="field-label">Job location<select value={draft.location} onChange={e=>setDraft(f=>({...f,location:e.target.value}))}><option value="">All job locations</option>{[...new Set(data.jobs.map(j=>j.district).filter(Boolean))].sort().map(v=><option key={v}>{v}</option>)}</select></label>
            <fieldset className="posted-attention"><legend>Hiring activity</legend>{[['','All jobs'],['applications','Has applications'],['closing','Closing in the next 7 days'],['expired','Application deadline passed']].map(([value,label])=><label key={value}><input type="radio" name="job-attention" checked={draft.attention===value} onChange={()=>setDraft(f=>({...f,attention:value}))}/>{label}</label>)}</fieldset>
            <div className="ea-filter-dates"><label className="field-label">Posted from<input type="date" value={draft.from} max={draft.to || undefined} onChange={e=>setDraft(f=>({...f,from:e.target.value}))}/></label><label className="field-label">Posted to<input type="date" value={draft.to} min={draft.from || undefined} onChange={e=>setDraft(f=>({...f,to:e.target.value}))}/></label></div>
            {draft.from && draft.to && draft.from > draft.to && <p role="alert">End date must be on or after the start date.</p>}
        </Modal>
        {stats && <JobStats job={stats} onClose={()=>setStats(null)}/>}
    </div>;
}
