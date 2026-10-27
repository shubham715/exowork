import {Link} from 'react-router-dom';
import {WhatsAppIcon} from '../../features/auth/components/PhoneVerification.jsx';
import React,{useEffect,useState} from 'react';
import axios from 'axios';
import {authHeaders} from '../../features/auth/components/PhoneVerification.jsx';
export default function AuthenticationSettings(){
 const [data,setData]=useState(null),[message,setMessage]=useState(''),[busy,setBusy]=useState(false);
 const load=()=>axios.get('/admin-api/authentication').then(({data})=>setData(data)).catch(()=>setMessage('Unable to load settings.'));
 useEffect(()=>{load()},[]);
 if(!data)return <div role="status">{message||'Loading settings…'}<button onClick={load}>Retry</button></div>;
 const set=(key,value)=>setData(d=>({...d,[key]:value}));
 const save=async()=>{setBusy(true);try{const r=await axios.put('/admin-api/authentication',{login_enabled:data.login_enabled,registration_enabled:data.registration_enabled},{headers:authHeaders()});setData(r.data);setMessage('Authentication settings saved.')}catch(e){setMessage(e.response?.data?.message||'Could not save settings.')}finally{setBusy(false)}};
 return <section className="data-card auth-settings"><h3>Login & registration</h3><p>Manage access for candidates, employers and training centers.</p>{[['login_enabled','WhatsApp login','Send a WhatsApp code with password login as an alternative. Disable to show mobile and password directly.'],['registration_enabled','WhatsApp verification for registration','Verify the number before opening account details. Disable to use the existing registration flow.']].map(([key,label,description])=><label className={`auth-setting-toggle ${key}`} key={key}><input type="checkbox" checked={data[key]} onChange={e=>set(key,e.target.checked)}/><WhatsAppIcon/><span><b>{label}</b><small>{description}</small></span></label>)}<div className="auth-template-location"><WhatsAppIcon/><div><b>OTP message template</b><p>Manage the verification message and provider template ID in <Link to="?tab=whatsapp-templates">WhatsApp templates</Link>.</p></div></div>{message&&<p role="status">{message}</p>}<button className="btn primary" disabled={busy} onClick={save}>{busy?'Saving…':'Save authentication settings'}</button></section>
}
