import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { useLocation, useNavigate } from 'react-router-dom';
import { BriefcaseBusiness, MapPin, Users, Wallet, Search, SlidersHorizontal, ArrowUpRight, ArrowLeft, CheckCircle2, FileText, UploadCloud, GraduationCap, Clock3, Building2, BadgeCheck, CalendarDays, X } from 'lucide-react';
import { Modal, PageHead } from '../../components/UI.jsx';
import './live-opportunities.css';

async function track(id, type) {
    const event = { event_id: crypto.randomUUID(), type };
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const { data } = await axios.get('/auth/csrf-token');
            await axios.post(`/candidate-api/jobs/${id}/events`, event, { headers: { 'X-CSRF-TOKEN': data.csrf_token } });
            return;
        } catch (e) { if (e.response && e.response.status < 500) return; }
    }
}
const money = job => job.salary_min || job.salary_max ? `₹${Number(job.salary_min || job.salary_max).toLocaleString('en-IN')}${job.salary_max && job.salary_min ? ` – ₹${Number(job.salary_max).toLocaleString('en-IN')}` : ''}` : 'Salary negotiable';
const skills = job => String(job.skills || '').split(/[,\n]/).map(s=>s.trim()).filter(Boolean);
const date = value => value ? new Date(value.slice(0,10)+'T00:00:00').toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'}) : 'Open until filled';
function Opportunity({ job, onOpen, onSave }) {
    const card = useRef(null);
    useEffect(() => {
        let recorded = false;
        const observer = new IntersectionObserver(entries => {
            if (!recorded && entries.some(entry => entry.isIntersecting && entry.intersectionRatio >= 0.5)) {
                recorded = true; track(job.id, 'impression'); observer.disconnect();
            }
        }, { threshold: 0.5 });
        observer.observe(card.current);
        return () => observer.disconnect();
    }, [job.id]);
    return <article ref={card} className="live-opportunity">
        <div className="op-card-top"><span className="live-opportunity-icon"><BriefcaseBusiness size={24}/></span><span className="op-verified"><BadgeCheck size={14}/> Verified employer</span></div>
        <div><span className="op-industry">{job.industry || 'Career opportunity'}</span><h3>{job.title}</h3><p><MapPin size={15}/>{[job.district,job.state].filter(Boolean).join(', ')}</p></div>
        <div className="op-salary"><strong>{money(job)}</strong>{(job.salary_min || job.salary_max) ? <span>/ {['monthly','fixed'].includes(String(job.salary_type || 'Monthly').toLowerCase()) ? 'month' : job.salary_type}</span> : null}</div>
        <div className="op-tags"><span><Clock3 size={13}/>{job.job_type || 'Job type unspecified'}</span>{job.workplace_type && <span><Building2 size={13}/>{job.workplace_type}</span>}<span>{job.experience || 'Experience unspecified'}</span></div>
        <p className="op-excerpt">{job.description}</p>
        <div className="op-skills">{skills(job).slice(0,3).map(s=><span key={s}>{s}</span>)}</div>
        <div className="op-card-bottom"><span><Users size={14}/>{job.openings} openings</span>{job.applied && <span className="op-applied"><CheckCircle2 size={14}/>Applied</span>}</div>
        <button className="btn op-details-button" onClick={() => { track(job.id, 'click'); onOpen(job); }}>View opportunity <ArrowUpRight size={17}/></button>
        {onSave && <button className="btn ghost" onClick={()=>onSave(job)}>Save interest</button>}
    </article>;
}
export default function LiveJobOpportunities() {
    const location = useLocation(), navigate = useNavigate();
    const [jobs, setJobs] = useState(null), [profileResume,setProfileResume] = useState(null), [error, setError] = useState(''), [query, setQuery] = useState(''), [selected, setSelected] = useState(null), [version, setVersion] = useState(0);
    const [filters,setFilters] = useState({city:'',type:'',workplace:'',experience:'',salary:'',status:''}), [sort,setSort] = useState('newest');
    const [step,setStep] = useState('details'), [resumeSource,setResumeSource] = useState('none'), [resume,setResume] = useState(null);
    const [answers, setAnswers] = useState({}), [answerErrors, setAnswerErrors] = useState({}), [submitting, setSubmitting] = useState(false), [applyError, setApplyError] = useState('');
    useEffect(() => {
        let live = true; setError('');
        axios.get('/candidate-api/opportunities').then(({ data }) => { if (live) {setJobs(data.jobs);setProfileResume(data.profile_resume);} }).catch(() => { if (live) setError('Could not load opportunities. Please try again.'); });
        return () => { live = false; };
    }, [version]);
    const openJob = job => { setSelected(job); setStep('details'); setAnswers({}); setAnswerErrors({}); setApplyError('');setResume(null);setResumeSource(profileResume?'profile':'none'); };
    const apply = async e => {
        e.preventDefault(); setSubmitting(true); setAnswerErrors({}); setApplyError('');
        try {
            const {data} = await axios.get('/auth/csrf-token');
            const body = new FormData();
            // Empty arrays must be explicitly serialized for jobs without questions.
            body.append('answers',JSON.stringify((selected.screening_questions || []).map((_,i)=>answers[i] ?? '')));
            body.append('resume_source',resumeSource);if(resumeSource==='upload' && resume) body.append('resume',resume);
            await axios.post(`/candidate-api/jobs/${selected.id}/apply`, body, {headers:{'X-CSRF-TOKEN':data.csrf_token}});
            setJobs(list=>list.map(j=>j.id===selected.id?{...j,applied:true}:j)); setSelected(j=>({...j,applied:true}));setStep('success');
        } catch(e) { setAnswerErrors(e.response?.data?.errors || {}); setApplyError(e.response?.status===404 ? 'This job is no longer accepting applications.' : e.response?.data?.message || 'Could not submit your application. Your answers are still here; please try again.'); }
        finally { setSubmitting(false); }
    };
    const options = key => [...new Set((jobs || []).map(j=>j[key]).filter(Boolean))].sort();
    const activeFilters = Object.entries(filters).filter(([,value])=>value);
    const rows = jobs?.filter(j => `${j.title} ${j.district} ${j.state} ${j.skills} ${j.industry}`.toLowerCase().includes(query.trim().toLowerCase()) && (!filters.city || j.district===filters.city) && (!filters.type || j.job_type===filters.type) && (!filters.workplace || j.workplace_type===filters.workplace) && (!filters.experience || j.experience===filters.experience) && (!filters.salary || (['monthly','fixed'].includes(String(j.salary_type || 'Monthly').toLowerCase()) && Number(j.salary_max || j.salary_min)>=Number(filters.salary))) && (!filters.status || (filters.status==='applied' ? j.applied : !j.applied))).sort((a,b)=>sort==='salary'?Number(b.salary_max || b.salary_min)-Number(a.salary_max || a.salary_min):sort==='deadline'?(a.application_deadline || '9999').localeCompare(b.application_deadline || '9999'):b.id-a.id);
    const reset = () => {setQuery('');setFilters({city:'',type:'',workplace:'',experience:'',salary:'',status:''});};
    const save = location.pathname.startsWith('/candidate2/') ? job => {
        try {
            const items = JSON.parse(localStorage.getItem('candidate2-interests') || '[]');
            const id = `job-${job.id}`;
            if (!items.some(item=>item.id===id)) {
                items.push({id, job_post_id:job.id, title:job.title, location:`${job.district}, ${job.state}`, salary:money(job), type:job.job_type, openings:`${job.openings} openings`, match:null, stage:'Saved', availability:'Not selected', date:new Date().toLocaleDateString('en-IN'), employer:'Shared after confirmation', note:'This opportunity is saved for you to review.'});
                localStorage.setItem('candidate2-interests',JSON.stringify(items));
            }
            navigate('/candidate2/interests');
        } catch { setError('Could not save your interest. Check browser storage and try again.'); }
    } : null;
    return <div className="live-opportunities">
        <div className="op-hero"><PageHead kicker="FIND YOUR NEXT CHAPTER" title="Work that moves you forward." sub="Explore opportunities, find your fit, and take the next step in your career."/><div className="op-hero-note"><BadgeCheck/><div><strong>Verified employers</strong><span>Real opportunities. A clearer path ahead.</span></div></div></div>
        <div className="op-search"><Search size={21}/><input aria-label="Search opportunities" placeholder="Job title, skill, industry or location" value={query} onChange={e=>setQuery(e.target.value)}/>{query && <button aria-label="Clear search" onClick={()=>setQuery('')}><X size={18}/></button>}</div>
        <div className="op-workspace"><aside className="op-filters"><div className="op-filter-title"><h3><SlidersHorizontal size={18}/>Refine your search</h3><button onClick={reset}>Reset</button></div>
            {[['city','Location','district','All locations'],['type','Job type','job_type','All job types'],['workplace','Workplace','workplace_type','All workplaces'],['experience','Experience','experience','All experience levels']].map(([key,label,field,placeholder])=><label key={key}>{label}<select value={filters[key]} onChange={e=>setFilters(f=>({...f,[key]:e.target.value}))}><option value="">{placeholder}</option>{options(field).map(v=><option key={v}>{v}</option>)}</select></label>)}
            <label>Monthly salary<select value={filters.salary} onChange={e=>setFilters(f=>({...f,salary:e.target.value}))}><option value="">Any salary</option><option value="15000">₹15,000 and above</option><option value="20000">₹20,000 and above</option><option value="30000">₹30,000 and above</option><option value="50000">₹50,000 and above</option></select></label>
            <label>Application status<select value={filters.status} onChange={e=>setFilters(f=>({...f,status:e.target.value}))}><option value="">All opportunities</option><option value="available">Not applied yet</option><option value="applied">Already applied</option></select></label>
            <div className="op-filter-note"><FileText size={22}/><strong>Ready for your next role?</strong><p>You can use your profile resume or upload a fresh copy when you apply.</p></div>
        </aside><section className="op-results" aria-label="Job opportunities">
            <div className="op-results-head"><div><h2>{rows ? `${rows.length} opportunities` : 'Opportunities'}</h2><p>Find a role that fits your skills and ambitions.</p></div><label>Sort by <select aria-label="Sort opportunities" value={sort} onChange={e=>setSort(e.target.value)}><option value="newest">Newest first</option><option value="salary">Highest salary</option><option value="deadline">Closing soon</option></select></label></div>
            {!!activeFilters.length && <div className="op-active-filters">{activeFilters.map(([key,value])=><button key={key} onClick={()=>setFilters(f=>({...f,[key]:''}))}>{key==='salary'?`Salary ≥ ₹${Number(value).toLocaleString('en-IN')}`:value}<X size={13}/></button>)}</div>}
            {error && <div role="alert" className="op-empty"><p>{error}</p><button className="btn primary" onClick={()=>setVersion(v=>v+1)}>Try again</button></div>}
            {!jobs && !error && <div role="status" className="op-loading">Loading your next opportunities…<div className="live-opportunity-grid">{[1,2,3,4].map(i=><div className="op-skeleton" key={i}/>)}</div></div>}
            {rows && <><div className="live-opportunity-grid">{rows.map(job=><Opportunity key={job.id} job={job} onOpen={openJob} onSave={save}/>)}</div>{!rows.length && <div className="op-empty"><Search size={32}/><h3>No matching opportunities</h3><p>Try a different keyword or broaden your filters.</p><button className="btn primary" onClick={reset}>Clear search and filters</button></div>}</>}
        </section></div>
        <Modal open={!!selected} className="op-dialog" onClose={()=>{if(!submitting)setSelected(null);}} title={step==='success'?'Application submitted':step==='apply'?'Your next step starts here':selected?.title || 'Job details'} sub={step==='apply'?'Complete your application before sending it for review.':'Explore the role. Make your next move.'}>
            {selected && (step==='success' ? <div className="op-success" role="status"><CheckCircle2 size={56}/><h3>You’re one step closer.</h3><p>Your application for <strong>{selected.title}</strong> has been submitted with your screening answers and selected resume.</p><button className="btn primary" onClick={()=>setSelected(null)}>Keep exploring</button></div> : step==='details' ? <>
                <div className="op-detail-banner"><span className="live-opportunity-icon"><BriefcaseBusiness/></span><div><span className="op-verified"><BadgeCheck size={15}/>Verified employer</span><p>{selected.industry || 'Career opportunity'} · {selected.district}</p></div><div className="op-detail-pay"><strong>{money(selected)}</strong><span>{selected.salary_type || 'Monthly'}</span></div></div>
                <div className="op-detail-facts">{[[MapPin,'Location',[selected.location,selected.district,selected.state].filter(Boolean).join(', ')],[Clock3,'Employment',selected.job_type],[Building2,'Workplace',selected.workplace_type],[GraduationCap,'Education',selected.education],[BriefcaseBusiness,'Experience',selected.experience],[Users,'Openings',selected.openings]].map(([Icon,label,value])=><div key={label}><Icon size={18}/><div><small>{label}</small><strong>{value || 'Not specified'}</strong></div></div>)}</div>
                {[['About the role',selected.description],['What you’ll do',selected.responsibilities],['Benefits and support',selected.benefits]].filter(([,value])=>value).map(([title,value])=><section className="op-detail-section" key={title}><h3>{title}</h3><p>{value}</p></section>)}
                {!!skills(selected).length && <section className="op-detail-section"><h3>Skills for this role</h3><div className="op-skills">{skills(selected).map(s=><span key={s}>{s}</span>)}</div></section>}
                <div className="op-detail-footer"><div><CalendarDays size={18}/><span>Apply by <strong>{date(selected.application_deadline)}</strong></span></div><button className="btn primary" disabled={selected.applied} onClick={()=>setStep('apply')}>{selected.applied?<><CheckCircle2 size={16}/>Already applied</>:<>Apply for this role<ArrowUpRight size={17}/></>}</button></div>
            </> : <form className="candidate-screening" onSubmit={apply}>
                <button className="op-back" type="button" disabled={submitting} onClick={()=>setStep('details')}><ArrowLeft size={16}/>Back to job details</button><div className="op-application-role"><BriefcaseBusiness size={20}/><div><strong>{selected.title}</strong><span>{selected.district} · {selected.job_type}</span></div></div>
                <fieldset disabled={submitting}><legend><span className="op-step">1</span>Choose your resume</legend><p>Your candidate profile is included with every application.</p><div className="op-resume-options">{[['profile',FileText,'Use profile resume',profileResume?'Attach the resume saved to your profile.':'No resume is available in your profile.'],['upload',UploadCloud,'Upload a latest resume','PDF, DOC or DOCX · up to 5 MB'],['none',Users,'Continue with my profile','Submit your candidate profile without a resume.']].map(([value,Icon,title,description])=><label className={resumeSource===value?'selected':''} key={value}><input type="radio" name="resume_source" value={value} checked={resumeSource===value} disabled={value==='profile' && !profileResume} onChange={()=>setResumeSource(value)}/><Icon size={21}/><span><strong>{title}</strong><small>{description}</small></span></label>)}</div>
                {resumeSource==='upload' && <label className="op-upload"><UploadCloud size={25}/><strong>{resume?.name || 'Choose a resume file'}</strong><input type="file" required accept=".pdf,.doc,.docx" onChange={e=>{const file=e.target.files[0];setResume(file || null);}}/></label>}
                {['resume','resume_source'].map(key=>answerErrors[key] && <p role="alert" key={key}>{answerErrors[key][0]}</p>)}</fieldset>
                <fieldset disabled={submitting}><legend><span className="op-step">2</span>Screening questions</legend><p>{selected.screening_questions?.length?'The employer has a few questions about your fit for this role.':'This employer has not added screening questions for this role.'}</p>
                {(selected.screening_questions || []).map((q,i)=><div className="candidate-screening-field" key={i}><label htmlFor={`answer-${i}`}>{i+1}. {q.question}<span>{q.required?'Required':'Optional'}</span></label>
                    {q.type==='yes_no' ? <select id={`answer-${i}`} required={q.required} value={answers[i] ?? ''} onChange={e=>setAnswers(a=>({...a,[i]:e.target.value}))}><option value="">Select your answer</option><option>Yes</option><option>No</option></select> : q.type==='number' ? <input id={`answer-${i}`} type="number" min="0" max="1000000000" step="any" required={q.required} value={answers[i] ?? ''} onChange={e=>setAnswers(a=>({...a,[i]:e.target.value}))}/> : <textarea id={`answer-${i}`} rows={3} maxLength={2000} placeholder="Share your answer…" required={q.required} value={answers[i] ?? ''} onChange={e=>setAnswers(a=>({...a,[i]:e.target.value}))}/>}
                    {answerErrors[`answers.${i}`] && <small role="alert">{answerErrors[`answers.${i}`][0]}</small>}
                </div>)}</fieldset>
                {applyError && <p role="alert">{applyError}</p>}<div className="op-submit"><p>Review your answers before submitting. Your profile, answers and chosen resume will be shared for application review.</p><button className="btn primary" type="submit" disabled={submitting}>{submitting?'Submitting…':<>Submit application<ArrowUpRight size={17}/></>}</button></div>
            </form>)}
        </Modal>
    </div>;
}

