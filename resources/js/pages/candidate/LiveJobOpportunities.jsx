import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { useLocation, useNavigate } from 'react-router-dom';
import { BriefcaseBusiness, MapPin, Users, Wallet } from 'lucide-react';
import { Modal, PageHead } from '../../components/UI.jsx';
import './live-opportunities.css';

async function track(id, type) {
    const event = { event_id: crypto.randomUUID(), type };
    // Retry the same event key, so an uncertain response cannot double count.
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const { data } = await axios.get('/auth/csrf-token');
            await axios.post(`/candidate-api/jobs/${id}/events`, event, { headers: { 'X-CSRF-TOKEN': data.csrf_token } });
            return;
        } catch (e) { if (e.response && e.response.status < 500) return; }
    }
}
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
    return <article ref={card} className="live-opportunity"><span className="live-opportunity-icon"><BriefcaseBusiness/></span><h3>{job.title}</h3><p><MapPin size={16}/>{job.district}, {job.state}</p><p><Wallet size={16}/>₹{Number(job.salary_min || 0).toLocaleString('en-IN')} – ₹{Number(job.salary_max || 0).toLocaleString('en-IN')}</p><p><Users size={16}/>{job.openings} openings · {job.job_type}</p><small>Verified opportunity</small><button className="btn primary" onClick={() => { track(job.id, 'click'); onOpen(job); }}>View job details</button>{onSave && <button className="btn ghost" onClick={()=>onSave(job)}>Save interest</button>}</article>;
}
export default function LiveJobOpportunities() {
    const location = useLocation(), navigate = useNavigate();
    const [jobs, setJobs] = useState(null), [error, setError] = useState(''), [query, setQuery] = useState(''), [selected, setSelected] = useState(null), [version, setVersion] = useState(0);
    useEffect(() => {
        let live = true; setError('');
        axios.get('/candidate-api/opportunities').then(({ data }) => { if (live) setJobs(data.jobs); }).catch(() => { if (live) setError('Could not load opportunities. Please try again.'); });
        return () => { live = false; };
    }, [version]);
    const rows = jobs?.filter(j => `${j.title} ${j.district} ${j.skills}`.toLowerCase().includes(query.toLowerCase()));
    const save = location.pathname.startsWith('/candidate2/') ? job => {
        try {
            const items = JSON.parse(localStorage.getItem('candidate2-interests') || '[]');
            const id = `job-${job.id}`;
            if (!items.some(item=>item.id===id)) {
                items.push({id, job_post_id:job.id, title:job.title, location:`${job.district}, ${job.state}`, salary:`₹${Number(job.salary_min || 0).toLocaleString('en-IN')} – ₹${Number(job.salary_max || 0).toLocaleString('en-IN')} / month`, type:job.job_type, openings:`${job.openings} openings`, match:null, stage:'Saved', availability:'Not selected', date:new Date().toLocaleDateString('en-IN'), employer:'Shared after confirmation', note:'This opportunity is saved for you to review.'});
                localStorage.setItem('candidate2-interests',JSON.stringify(items));
            }
            navigate('/candidate2/interests');
        } catch { setError('Could not save your interest. Check browser storage and try again.'); }
    } : null;
    return <div className="live-opportunities"><PageHead kicker="YOUR NEXT ROLE" title="Explore job opportunities" sub="Browse current jobs from verified employers."/>
        <input aria-label="Search opportunities" placeholder="Search jobs, skills or city" value={query} onChange={e=>setQuery(e.target.value)}/>
        {error && <p role="alert">{error} <button className="btn ghost" onClick={()=>setVersion(v=>v+1)}>Retry</button></p>}{!jobs && !error && <p role="status">Loading opportunities…</p>}
        {rows && <><p>{rows.length} current opportunities</p><div className="live-opportunity-grid">{rows.map(job=><Opportunity key={job.id} job={job} onOpen={setSelected} onSave={save}/>)}</div>{!rows.length && <p>No current jobs match your search.</p>}</>}
        <Modal open={!!selected} onClose={()=>setSelected(null)} title={selected?.title || 'Job details'} sub="Review the role and requirements.">{selected && <><p>{selected.description}</p><dl className="live-job-details">{[['Location',`${selected.district}, ${selected.state}`],['Education',selected.education],['Experience',selected.experience],['Skills',selected.skills],['Job type',selected.job_type],['Openings',selected.openings]].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value || 'Not specified'}</dd></div>)}</dl></>}</Modal>
    </div>;
}
