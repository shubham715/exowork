import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, MapPin, UsersRound, SkipForward, BriefcaseBusiness, GraduationCap, Wallet, Clock3, Check, Search, ArrowUpRight, ChevronDown } from 'lucide-react';
import { PageHead, Modal, Status } from '../../components/UI.jsx';
import WhatsAppMessage from '../../components/WhatsAppMessage.jsx';
import { csrf } from './EmployerRegistration.jsx';
import './employer.css';
import './posted-jobs.css';

export default function EligibleCandidates() {
    const { id } = useParams();
    const [details, setDetails] = useState(false), [profile, setProfile] = useState(null), [query, setQuery] = useState(''), [sort, setSort] = useState('newest');
    const [data, setData] = useState(null), [page, setPage] = useState(1), [error, setError] = useState(''), [busy, setBusy] = useState(null), [version, setVersion] = useState(0), [loading, setLoading] = useState(true);
    useEffect(() => {
        let live = true; setLoading(true); setError('');
        axios.get(`/employer-api/jobs/${id}/candidates`, {params:{page,search:query,sort}}).then(({ data }) => {
            if (!live) return;
            if (page > Math.max(1, Math.ceil(data.total / 20))) { setPage(Math.max(1, Math.ceil(data.total / 20))); return; }
            setData(data);
        }).catch(e => { if (live) setError(e.response?.data?.message || 'Could not load candidates.'); }).finally(() => { if (live) setLoading(false); });
        return () => { live = false; };
    }, [id, page, version, query, sort]);
    const skip = async candidate => {
        setBusy(candidate.id); setError('');
        try { await axios.post(`/employer-api/jobs/${id}/candidates/${candidate.id}/skip`, {}, { headers: csrf() }); setVersion(n => n + 1); }
        catch (e) { setError(e.response?.data?.message || 'Could not skip this candidate.'); }
        finally { setBusy(null); }
    };
    const invitationDisabled = data && (!['active','published'].includes(data.job.status) || (data.job.application_deadline && data.job.application_deadline < new Date().toLocaleDateString('en-CA', {timeZone:'Asia/Kolkata'})));
    const money = value => value ? '₹'+Number(value).toLocaleString('en-IN') : 'Not specified';
    return <div className="employer-live employer-candidate-review"><Link className="candidate-back" to="/employer/jobs"><ArrowLeft size={15}/>Posted jobs</Link>
        <PageHead kicker="FIND YOUR NEXT HIRE" title="Eligible candidates" sub="Review people who meet this job’s requirements and invite them to apply."/>
        {error && <p role="alert" className="employer-error">{error}<button className="btn ghost" onClick={() => setVersion(n => n + 1)}>Retry</button></p>}
        {data && <>
            <section className="candidate-job-context" aria-label="Job summary"><span className="candidate-context-icon"><BriefcaseBusiness size={23}/></span><div><div className="candidate-context-title"><h2>{data.job.title}</h2><Status tone={data.job.status}>{data.job.status}</Status></div><p><MapPin size={13}/>{data.job.district}, {data.job.state}<span>·</span>{data.job.openings} openings<span>·</span>{money(data.job.salary_min)} – {money(data.job.salary_max)}</p><div className="candidate-context-requirements"><span><GraduationCap size={13}/>{data.job.education}</span><span><Clock3 size={13}/>{data.job.experience}</span><span>{data.job.skills}</span></div></div><button className="btn ghost" onClick={()=>setDetails(true)}>Job details<ArrowUpRight size={14}/></button></section>
            <div className="candidate-review-toolbar"><div><h2>{data.total} eligible candidates</h2><p>Skip removes a candidate from this job only.</p></div><label className="posted-search"><Search size={16}/><input aria-label="Search eligible candidates" placeholder="Search name or skills" value={query} onChange={e=>{setQuery(e.target.value);setPage(1);}}/></label><label className="posted-sort">Sort by<select value={sort} onChange={e=>{setSort(e.target.value);setPage(1);}}><option value="newest">Newest profiles</option><option value="salary">Expected salary: low to high</option><option value="name">Name A–Z</option></select></label></div>
            {!loading && !data.candidates.length && <div className="posted-empty"><UsersRound size={32}/><h3>{query ? 'No candidates match your search' : 'No eligible candidates yet'}</h3><p>{query ? 'Try another name or skill.' : 'New profiles will appear here when they meet your job requirements.'}</p>{query && <button className="btn ghost" onClick={()=>setQuery('')}>Clear search</button>}</div>}
            {!loading && <div className="candidate-review-list">{data.candidates.map(c => <article className="candidate-review-card" key={c.id}>
                <header><span className="candidate-review-avatar">{c.first_name?.[0]}{c.last_name?.[0]}</span><div><button className="candidate-profile-name" onClick={()=>setProfile(c)}>{c.first_name} {c.last_name}</button><p><MapPin size={12}/>{c.district}, {c.state}<span>·</span>{c.candidate_code}</p></div><span className="candidate-eligible-badge"><Check size={12}/>Meets requirements</span></header>
                <div className="candidate-review-content"><div className="candidate-review-main"><div className="candidate-review-facts"><span><GraduationCap size={16}/><span><small>Qualification</small><b>{c.qualification}</b></span></span><span><BriefcaseBusiness size={16}/><span><small>Experience</small><b>{c.experience_type}</b></span></span><span><Wallet size={16}/><span><small>Expected / month</small><b>{money(c.expected_monthly_salary)}</b></span></span><span><Clock3 size={16}/><span><small>Availability</small><b>{c.availability || 'Not provided'}</b></span></span></div><div className="candidate-skill-tags">{c.skills.split(/[,;\n]+/).filter(Boolean).slice(0,5).map((skill,i)=><span key={i}>{skill.trim()}</span>)}{c.skills.split(/[,;\n]+/).filter(Boolean).length>5 && <button onClick={()=>setProfile(c)}>More skills</button>}</div></div>
                    <div className="candidate-review-actions"><WhatsAppMessage code={c.candidate_code} audience="employer" jobId={id} label="Send WhatsApp invitation" disabled={!c.whatsapp_consent || invitationDisabled}/><div><button className="candidate-view-profile" onClick={()=>setProfile(c)}>View profile<ArrowUpRight size={13}/></button><button className="candidate-skip" disabled={busy !== null} onClick={() => skip(c)}><SkipForward size={14}/>{busy === c.id ? 'Skipping…' : 'Skip'}</button></div>{!c.whatsapp_consent && <small>WhatsApp consent unavailable</small>}{invitationDisabled && <small>Invitations available for live jobs</small>}</div>
                </div>
            </article>)}</div>}
            <div className="employer-pagination"><span>Page {page} of {Math.max(1,Math.ceil(data.total/20))} · 20 per page</span><button className="btn ghost" disabled={loading || page===1} onClick={() => setPage(p=>p-1)}>Previous</button><button className="btn ghost" disabled={loading || page>=Math.ceil(data.total/20)} onClick={() => setPage(p=>p+1)}>Next</button></div>
            <Modal open={details} onClose={()=>setDetails(false)} title={data.job.title} sub="Your posted job"><dl className="candidate-detail-list">{[['Description',data.job.description],['Responsibilities',data.job.responsibilities],['Education',data.job.education],['Experience',data.job.experience],['Required skills',data.job.skills],['Benefits',data.job.benefits],['Deadline',data.job.application_deadline]].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value || 'Not specified'}</dd></div>)}</dl></Modal>
        </>}
        <Modal open={!!profile} onClose={()=>setProfile(null)} title={profile ? profile.first_name+' '+profile.last_name : 'Candidate profile'} sub={profile?.candidate_code}>{profile && <dl className="candidate-detail-list">{[['Location',profile.district+', '+profile.state],['Qualification',profile.qualification],['Experience',profile.experience_type],['Experience details',profile.experience_details],['Skills',profile.skills],['Expected monthly salary',money(profile.expected_monthly_salary)],['Availability',profile.availability]].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value || 'Not provided'}</dd></div>)}</dl>}</Modal>
        {loading && <p role="status">Loading eligible candidates…</p>}
    </div>;
}
