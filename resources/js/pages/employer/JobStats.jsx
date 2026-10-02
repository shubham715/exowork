import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Modal } from '../../components/UI.jsx';

export default function JobStats({ job, onClose }) {
    const [range, setRange] = useState(30), [rows, setRows] = useState(null), [error, setError] = useState('');
    useEffect(() => {
        let live = true;
        setRows(null); setError('');
        axios.get(`/employer-api/jobs/${job.id}/stats?days=${range}`).then(({ data }) => { if (live) setRows(data.days); })
            .catch(() => { if (live) setError('Could not load statistics. Change the date range to retry.'); });
        return () => { live = false; };
    }, [job.id, range]);
    const impressions = rows?.reduce((n, r) => n + r.impressions, 0) || 0, clicks = rows?.reduce((n, r) => n + r.clicks, 0) || 0;
    const maximum = Math.max(1, ...(rows || []).flatMap(r => [r.impressions, r.clicks]));
    const points = key => rows.map((r, i) => `${45 + i * 610 / Math.max(1, rows.length - 1)},${210 - r[key] / maximum * 175}`).join(' ');
    return <Modal open onClose={onClose} title="Job performance" sub={job.title} className="job-stats-dialog">
        <label className="field-label">Date range<select value={range} onChange={e => setRange(Number(e.target.value))}>{[7, 30, 90].map(n => <option key={n} value={n}>Last {n} days</option>)}</select></label>
        {error && <p role="alert">{error}</p>}{!rows && !error && <p role="status">Loading daily statistics…</p>}
        {rows && <><div className="job-stats-totals"><div><strong>{impressions}</strong><span>Impressions</span></div><div><strong>{clicks}</strong><span>Clicks</span></div><div><strong>{impressions ? (clicks / impressions * 100).toFixed(1) : '0'}%</strong><span>Click-through rate</span></div></div>
            <div className="job-chart-legend"><span>● Impressions</span><span>● Clicks</span></div>
            <svg className="job-stats-chart" viewBox="0 0 700 250" role="img" aria-label={`Daily chart: ${impressions} impressions and ${clicks} clicks. Exact counts are in the table below.`}>
                {[0, 0.5, 1].map(t => <g key={t}><line x1="45" x2="655" y1={210 - t * 175} y2={210 - t * 175} stroke="#e5e7eb"/><text x="5" y={214 - t * 175} fontSize="11" fill="#667085">{Math.round(maximum * t)}</text></g>)}
                <polyline points={points('impressions')} fill="none" stroke="#5470db" strokeWidth="3"/><polyline points={points('clicks')} fill="none" stroke="#16a078" strokeWidth="3"/>
                <text x="45" y="240" fontSize="11">{rows[0].date}</text><text x="655" y="240" textAnchor="end" fontSize="11">{rows.at(-1).date}</text>
            </svg>{!impressions && !clicks && <p>No candidate activity recorded in this date range.</p>}
            <details className="job-stats-table"><summary>View daily counts</summary><table><thead><tr><th>Date (IST)</th><th>Impressions</th><th>Clicks</th></tr></thead><tbody>{rows.map(r => <tr key={r.date}><td>{r.date}</td><td>{r.impressions}</td><td>{r.clicks}</td></tr>)}</tbody></table></details>
            <p className="job-stats-help">An impression is recorded when a candidate sees a job card. A click is recorded when they open its details. Tracking starts with this update; earlier activity is unavailable.</p></>}
    </Modal>;
}
