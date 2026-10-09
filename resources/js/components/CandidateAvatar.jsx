import React, { useState } from 'react';

export default function CandidateAvatar({ candidate, className = 'ca-avatar' }) {
    const [failedUrl, setFailedUrl] = useState(null);
    const name = [candidate.first_name, candidate.last_name].filter(Boolean).join(' ');
    return <span className={className}>{candidate.photo_url && failedUrl !== candidate.photo_url
        ? <img src={candidate.photo_url} alt={`${name} profile`} loading="lazy" onError={() => setFailedUrl(candidate.photo_url)} />
        : `${candidate.first_name?.[0] || ''}${candidate.last_name?.[0] || ''}`}</span>;
}
