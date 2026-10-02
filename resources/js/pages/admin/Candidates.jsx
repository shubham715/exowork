import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link } from 'react-router-dom';
import { PageHead, Status } from '../../components/UI';
import WhatsAppMessage from '../../components/WhatsAppMessage';

export default function Candidates() {
    const [search, setSearch] = useState(''), [page, setPage] = useState(1), [data, setData] = useState(null), [error, setError] = useState(''), [loading, setLoading] = useState(true), [refresh, setRefresh] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true); setError('');
        const timer = setTimeout(() => axios.get('/admin-api/candidates', { params: { search, page }, signal: controller.signal }).then(r => setData(r.data)).catch(e => { if (!axios.isCancel(e)) setError(e.response?.data?.message || 'Could not load candidates.'); }).finally(() => { if (!controller.signal.aborted) setLoading(false); }), 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [search, page, refresh]);
    return <>
        <PageHead title="Candidates" sub="Registered candidate profiles and WhatsApp updates." />
        <div className="data-card"><div className="filterbar"><input aria-label="Search candidates" placeholder="Search name, candidate code or WhatsApp number" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} /></div>
            {error ? <p role="alert">{error} <button className="btn ghost" onClick={() => setRefresh(x => x + 1)}>Retry</button></p> : loading ? <p role="status">Loading candidates…</p> : <>
                <div className="table-scroll"><table className="data-table"><thead><tr><th>Candidate</th><th>Training source</th><th>Industry / location</th><th>Availability</th><th>WhatsApp consent</th><th>Actions</th></tr></thead><tbody>{data?.data.map(c => <tr key={c.candidate_code}><td><Link to={`/admin/candidates/${c.candidate_code}`}><b>{c.first_name} {c.last_name}</b></Link><small>{c.candidate_code} · {c.whatsapp}</small></td><td>{c.training_center || 'Public registration'}</td><td>{c.preferred_industry}<small>{c.district}</small></td><td>{c.availability}</td><td><Status tone={c.whatsapp_consent ? 'active' : 'rejected'}>{c.whatsapp_consent ? 'Opted in' : 'Not opted in'}</Status></td><td><WhatsAppMessage code={c.candidate_code} /></td></tr>)}</tbody></table></div>
                {!data?.data.length && <div className="empty-state"><h3>No registered candidates found</h3><p>Register a candidate through the candidate or training center registration flow to test messaging.</p></div>}
                <footer className="table-footer"><span>{data?.total || 0} candidates · Page {data?.current_page || 1} of {data?.last_page || 1}</span><div><button disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button><button disabled={page >= (data?.last_page || 1)} onClick={() => setPage(page + 1)}>Next</button></div></footer>
            </>}
        </div>
    </>;
}
