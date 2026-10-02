import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, MapPin, UsersRound, SkipForward } from 'lucide-react';
import { PageHead, Panel, Status } from '../../components/UI.jsx';
import WhatsAppMessage from '../../components/WhatsAppMessage.jsx';
import { csrf } from './EmployerRegistration.jsx';
import './employer.css';

export default function EligibleCandidates() {
    const { id } = useParams();
    const [data, setData] = useState(null), [page, setPage] = useState(1), [error, setError] = useState(''), [busy, setBusy] = useState(null), [version, setVersion] = useState(0), [loading, setLoading] = useState(true);
    useEffect(() => {
        let live = true; setLoading(true); setError('');
        axios.get(`/employer-api/jobs/${id}/candidates?page=${page}`).then(({ data }) => {
            if (!live) return;
            if (page > Math.max(1, Math.ceil(data.total / 20))) { setPage(Math.max(1, Math.ceil(data.total / 20))); return; }
            setData(data);
        }).catch(e => { if (live) setError(e.response?.data?.message || 'Could not load candidates.'); }).finally(() => { if (live) setLoading(false); });
        return () => { live = false; };
    }, [id, page, version]);
    const skip = async candidate => {
        setBusy(candidate.id); setError('');
        try { await axios.post(`/employer-api/jobs/${id}/candidates/${candidate.id}/skip`, {}, { headers: csrf() }); setVersion(n => n + 1); }
        catch (e) { setError(e.response?.data?.message || 'Could not skip this candidate.'); }
        finally { setBusy(null); }
    };
    return <div className="employer-live"><Link className="text-link" to="/employer/jobs"><ArrowLeft size={16}/> Back to posted jobs</Link>
        <PageHead kicker="JOB CANDIDATES" title="Eligible candidates" sub="Review profiles and invite candidates for this job."/>
        {error && <p role="alert" className="employer-error">{error}<button className="btn ghost" onClick={() => setVersion(n => n + 1)}>Retry</button></p>}
        {data && <><Panel title={data.job.title} sub="Job profile"><div className="eligible-job-facts"><Status tone={data.job.status}>{data.job.status}</Status><span><MapPin size={16}/>{data.job.district}, {data.job.state}</span><span><UsersRound size={16}/>{data.job.openings} openings</span><span>{data.job.job_type} · {data.job.workplace_type}</span></div><p>{data.job.description}</p><dl className="eligible-job-details">{[['Education',data.job.education],['Experience',data.job.experience],['Skills',data.job.skills],['Salary',`₹${Number(data.job.salary_min || 0).toLocaleString('en-IN')} – ₹${Number(data.job.salary_max || 0).toLocaleString('en-IN')}`],['Responsibilities',data.job.responsibilities],['Benefits',data.job.benefits],['Deadline',data.job.application_deadline]].map(([label,value]) => <div key={label}><dt>{label}</dt><dd>{value || 'Not specified'}</dd></div>)}</dl></Panel>
            <div className="eligible-heading"><h2>{data.total} eligible candidates</h2><p>Matched by qualification, experience, all listed skills, location or relocation preference, and salary. Free-text requirements use literal matches. Skip hides a profile for this job only.</p></div>
            {!loading && !data.candidates.length && <div className="employer-empty"><h3>No eligible candidates yet</h3><p>Profiles will appear as candidates meet the job requirements.</p></div>}
            {!loading && <div className="eligible-candidate-grid">{data.candidates.map(c => <article className="eligible-candidate-card" key={c.id}><header><span className="eligible-avatar">{c.first_name[0]}{c.last_name?.[0]}</span><div><h3>{c.first_name} {c.last_name}</h3><small>{c.candidate_code}</small></div></header><p><MapPin size={14}/>{c.district}, {c.state}</p><dl>{[['Qualification',c.qualification],['Experience',c.experience_type],['Skills',c.skills],['Expected salary',`₹${Number(c.expected_monthly_salary).toLocaleString('en-IN')} / month`],['Availability',c.availability]].map(([label,value]) => <div key={label}><dt>{label}</dt><dd>{value || 'Not provided'}</dd></div>)}</dl><footer><WhatsAppMessage code={c.candidate_code} audience="employer" jobId={id} label="Send WhatsApp invitation" disabled={!c.whatsapp_consent || !['active','published'].includes(data.job.status) || (data.job.application_deadline && data.job.application_deadline < new Date().toLocaleDateString('en-CA', {timeZone:'Asia/Kolkata'}))}/><button className="btn ghost" disabled={busy !== null} onClick={() => skip(c)}><SkipForward size={16}/>{busy === c.id ? 'Skipping…' : 'Skip'}</button></footer></article>)}</div>}
            <div className="employer-pagination"><span>Page {page} of {Math.max(1,Math.ceil(data.total/20))} · 20 per page</span><button className="btn ghost" disabled={loading || page===1} onClick={() => setPage(p=>p-1)}>Previous</button><button className="btn ghost" disabled={loading || page>=Math.ceil(data.total/20)} onClick={() => setPage(p=>p+1)}>Next</button></div></>}
        {loading && <p role="status">Loading eligible candidates…</p>}
    </div>;
}
