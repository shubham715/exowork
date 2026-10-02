import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, MapPin } from 'lucide-react';
import { Panel, Status } from '../../components/UI';
import WhatsAppMessage from '../../components/WhatsAppMessage';

export default function CandidateDetail() {
    const { id } = useParams(), [candidate, setCandidate] = useState(null), [error, setError] = useState(''), [refresh, setRefresh] = useState(0);
    useEffect(() => {
        const controller = new AbortController(); setCandidate(null); setError('');
        axios.get(`/admin-api/candidates/${encodeURIComponent(id)}`, { signal: controller.signal }).then(r => setCandidate(r.data.candidate)).catch(e => { if (!axios.isCancel(e)) setError(e.response?.status === 404 ? 'Candidate not found. Open a registered candidate from the candidate directory.' : e.response?.data?.message || 'Could not load this profile.'); });
        return () => controller.abort();
    }, [id, refresh]);
    return <>
        <Link className="back-link" to="/admin/candidates"><ArrowLeft />Back to candidates</Link>
        {error ? <p role="alert">{error} <button className="btn ghost" onClick={() => setRefresh(x => x + 1)}>Retry</button></p> : !candidate ? <p role="status">Loading candidate profile…</p> : <>
            <section className="profile-hero"><div className="profile-initials">{candidate.first_name[0]}{candidate.last_name[0]}</div><div className="profile-title"><Status>{candidate.availability}</Status><h1>{candidate.first_name} {candidate.last_name}</h1><p>{candidate.candidate_code} · {candidate.whatsapp}</p><span><MapPin />{candidate.district}, {candidate.state}</span></div><div className="profile-actions"><WhatsAppMessage code={candidate.candidate_code} onConsentChange={consent => setCandidate(c => ({ ...c, whatsapp_consent: consent }))} /></div></section>
            <div className="detail-layout"><main><Panel title="Candidate profile" sub="Details provided during registration"><div className="detail-grid">{[['Qualification', candidate.qualification], ['Industry', candidate.preferred_industry], ['Skills', candidate.skills], ['Experience', candidate.experience_type], ['Availability', candidate.availability], ['Training center', candidate.training_center || 'Public registration']].map(([label, value]) => <div key={label}><span>{label}</span><b>{value || 'Not provided'}</b></div>)}</div></Panel></main><aside><Panel title="WhatsApp consent"><Status tone={candidate.whatsapp_consent ? 'active' : 'rejected'}>{candidate.whatsapp_consent ? 'Opted in' : 'Not opted in'}</Status><p>Only candidates with recorded WhatsApp consent can receive messages. Open Send WhatsApp to view templates and delivery history.</p></Panel></aside></div>
        </>}
    </>;
}
