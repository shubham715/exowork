import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, MapPin } from 'lucide-react';
import { Panel, Status, PageHead } from '../../components/UI';
import WhatsAppMessage from '../../components/WhatsAppMessage';
import CandidateAvatar from '../../components/CandidateAvatar';
import './candidate-admin.css';

const availability = value => ({ Yes: 'Available now', No: 'Not available', 'Available after training': 'Available after training' }[value] || value);
function Details({ fields }) {
    return <dl className="cd-fields">{fields.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{Array.isArray(value) ? value.join(', ') || 'Not provided' : value || 'Not provided'}</dd></div>)}</dl>;
}
export default function CandidateDetail() {
    const { id } = useParams(), [candidate, setCandidate] = useState(null), [error, setError] = useState(''), [refresh, setRefresh] = useState(0);
    useEffect(() => {
        const controller = new AbortController(); setCandidate(null); setError('');
        axios.get(`/admin-api/candidates/${encodeURIComponent(id)}`, { signal: controller.signal }).then(r => setCandidate(r.data.candidate)).catch(e => { if (!axios.isCancel(e)) setError(e.response?.status === 404 ? 'Candidate not found. Open a registered candidate from the candidate directory.' : e.response?.data?.message || 'Could not load this profile.'); });
        return () => controller.abort();
    }, [id, refresh]);
    return <div className="candidate-detail">
        <Link className="cd-back" to="/admin/candidates"><ArrowLeft size={16} />Back to candidates</Link>
        <PageHead title="Candidate profile" sub="Review registration details and work preferences." />
        {error ? <div className="cd-state" role="alert">{error}<button className="btn ghost" onClick={() => setRefresh(x => x + 1)}>Retry</button></div> : !candidate ? <div className="cd-state" role="status">Loading candidate profile…</div> : <>
            <section className="cd-hero">
                <CandidateAvatar candidate={candidate} className="cd-avatar" />
                <div className="cd-identity"><div className="cd-badges"><Status tone={candidate.availability === 'Yes' ? 'active' : 'pending'}>{availability(candidate.availability)}</Status><Status tone={candidate.profile_status === 'active' ? 'active' : 'rejected'}>{candidate.profile_status === 'active' ? 'Active profile' : 'Inactive profile'}</Status></div><h2>{candidate.first_name} {candidate.last_name}</h2><p>{candidate.candidate_code}</p><span><MapPin size={15} />{[candidate.district, candidate.state].filter(Boolean).join(', ') || 'Location not provided'}</span></div>
                <div className="cd-actions"><WhatsAppMessage code={candidate.candidate_code} disabled={candidate.profile_status !== 'active'} onConsentChange={consent => setCandidate(c => ({ ...c, whatsapp_consent: consent }))} /></div>
            </section>
            <div className="cd-layout"><div className="cd-main">
                <Panel title="Work preferences" sub="Skills and readiness for employment"><Details fields={[
                    ['Preferred industry', candidate.preferred_industry], ['Skills', candidate.skills], ['Experience', candidate.experience_type], ['Availability', availability(candidate.availability)], ['Expected monthly salary', candidate.expected_monthly_salary != null ? `₹${Number(candidate.expected_monthly_salary).toLocaleString('en-IN')}` : null], ['Preferred locations', candidate.preferred_locations], ['Willing to relocate', candidate.relocation_preference], ['Languages', candidate.languages],
                ]} /></Panel>
                <Panel title="Education and training" sub="Qualification and training enrollment"><Details fields={[
                    ['Qualification', candidate.qualification], ['Training center', candidate.training_center || 'Public registration'], ['Training status', candidate.training_status], ['Batch code', candidate.batch_code],
                ]} /></Panel>
            </div><aside className="cd-side"><Panel title="Contact details"><Details fields={[
                ['WhatsApp number', candidate.whatsapp], ['Email address', candidate.email], ['Registered', candidate.created_at ? new Date(candidate.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : null],
            ]} /></Panel><Panel title="WhatsApp consent"><Status tone={candidate.whatsapp_consent ? 'active' : 'rejected'}>{candidate.whatsapp_consent ? 'Opted in' : 'Not opted in'}</Status><p className="cd-note">Only candidates with recorded WhatsApp consent can receive messages. Open Send WhatsApp to view templates and delivery history.</p></Panel></aside></div>
        </>}
    </div>;
}
