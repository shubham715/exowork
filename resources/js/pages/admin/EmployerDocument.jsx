import React,{useState} from 'react';
import {FileText,File,Image,ExternalLink,Download,ShieldCheck,ImageOff} from 'lucide-react';
export function EmployerDocument({record}) {
    const [failed,setFailed]=useState(false);
    const type=(record.document_type||record.document_name?.split('.').pop()||'file').toLowerCase();
    const image=['jpg','jpeg','png'].includes(type), Icon=image?Image:type==='pdf'?FileText:File;
    if(!record.document_url)return <div className="ea-document-empty"><ShieldCheck size={28}/><b>No verification proof</b><p>This employer has not uploaded a document yet.</p></div>;
    const preview=record.document_url+(record.document_url.includes('?')?'&':'?')+'preview=1';
    return <div className="ea-document-card"><a className={'ea-document-preview '+(image?'is-image':'')} href={preview} target="_blank" rel="noopener noreferrer" title="Open verification document in a new tab" aria-label={`Open ${record.document_name||'verification document'} in a new tab`}>{image&&!failed?<img src={preview} alt="Employer verification document" onError={()=>setFailed(true)}/>:<div>{failed?<ImageOff size={40}/>:<Icon size={44}/>}<span>{failed?'Preview unavailable':type.toUpperCase()}</span></div>}<span className="ea-document-open"><ExternalLink size={16}/>Open document</span></a><div className="ea-document-meta"><b>{record.document_name||'Verification document'}</b><span>{image?'Image document':type==='pdf'?'PDF document':'Supporting document'} · Opens in a new tab</span></div><a className="btn ghost" href={record.document_url}><Download size={16}/>Download proof</a></div>;
}
