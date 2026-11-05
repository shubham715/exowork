import React, {createContext, useContext, useEffect, useRef, useState} from 'react';
import axios from 'axios';
import {ArrowLeft, LogIn, ShieldCheck} from 'lucide-react';
import './impersonation.css';

const SupportContext = createContext(null);
export function ImpersonationProvider({children}) {
    const [status,setStatus] = useState(null), [busy,setBusy] = useState(false), [error,setError] = useState('');
    const previous = useRef(false);
    useEffect(()=>{
        let alive=true, pending=false;
        const load=async()=>{
            if(pending)return;pending=true;
            try {
                const {data}=await axios.get('/auth/impersonation');if(!alive)return;
                const prefix=data.session?.type==='candidate'?'/candidate':data.session?.type==='center'?'/center':'/employer';
                if(data.active && !window.location.pathname.startsWith(prefix+'/')) {window.location.assign(prefix+'/dashboard');return;}
                if(previous.current && !data.active){window.location.assign(data.can_impersonate?'/admin/dashboard':'/admin225');return;}
                previous.current=data.active;setStatus(data);
            }catch(e){if(alive && previous.current && e.response?.status===401)window.location.assign('/admin225');}
            finally{pending=false;}
        };
        const focus=()=>{if(document.visibilityState==='visible')load();};
        load();const timer=setInterval(focus,30000);window.addEventListener('focus',focus);document.addEventListener('visibilitychange',focus);
        return()=>{alive=false;clearInterval(timer);window.removeEventListener('focus',focus);document.removeEventListener('visibilitychange',focus);};
    },[]);
    const stop=async()=>{if(busy)return;setBusy(true);setError('');try{const {data}=await axios.post('/auth/impersonation/stop');window.location.assign(data.destination);}catch(e){setError(e.response?.data?.message||'Could not return to admin. Try again.');if(e.response?.status===401)window.location.assign('/admin225');setBusy(false);}};
    const session=status?.session;
    return <SupportContext.Provider value={status}><div className={status?.active?'impersonation-active':''}>{status?.active&&<div className="impersonation-bar" role="region" aria-label="Admin support session"><ShieldCheck size={12}/><span>You are logged in as <b>{session.name}</b><span className="impersonation-organization">{session.organization?' · '+session.organization:''}</span></span><small>{session.expired?'Session expired':'View only'}</small><button disabled={busy} onClick={stop}><ArrowLeft size={12}/>{busy?'Returning…':'Back to admin panel'}</button></div>}{error&&<div className="impersonation-error" role="alert">{error}<button onClick={stop}>Retry</button></div>}{children}</div></SupportContext.Provider>;
}

export function ImpersonateButton({type,id,name,disabled=false}) {
    const status=useContext(SupportContext), [busy,setBusy]=useState(false), [error,setError]=useState('');
    if(!status?.can_impersonate || status.active)return null;
    const start=async()=>{if(busy)return;setBusy(true);setError('');try{const {data}=await axios.post('/auth/impersonation/start',{type,id});window.location.assign(data.destination);}catch(e){setError(e.response?.data?.message||'Could not open this account.');setBusy(false);}};
    return <span className="impersonate-action"><button type="button" className="impersonate-button" onClick={start} disabled={disabled||busy} aria-label={`Log in as ${name}`} title="Open a view-only support session"><LogIn size={13}/>{busy?'Opening…':'Log in as'}</button>{error&&<span role="alert" className="impersonate-action-error">{error}<button onClick={()=>setError('')} aria-label="Dismiss login error">×</button></span>}</span>;
}
