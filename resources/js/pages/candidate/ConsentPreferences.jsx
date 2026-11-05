import React, {useEffect, useState} from 'react';
import axios from 'axios';
import {Link} from 'react-router-dom';
import {ShieldCheck, BriefcaseBusiness, MessageCircle, Phone, Mail, Smartphone, Check, ArrowUpRight, Loader2, Save, PauseCircle} from 'lucide-react';
import './consent-preferences.css';

const channels = [
 ['whatsapp_consent', 'WhatsApp', 'Job opportunities, interview reminders and placement updates on WhatsApp.', MessageCircle],
 ['call_consent', 'Phone calls', 'Calls from the EXOWORK team about opportunities and placement support.', Phone],
 ['sms_consent', 'SMS', 'Text messages about your job search and interview updates.', Smartphone],
 ['email_consent', 'Email', 'Job opportunities and updates sent to your registered email address.', Mail],
];
const preferences = c => Object.fromEntries(channels.map(([key]) => [key, Boolean(c[key])]));
export default function ConsentPreferences() {
 const [candidate,setCandidate]=useState(null),[draft,setDraft]=useState({}),[interest,setInterest]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const base=window.location.pathname.startsWith('/candidate2/')?'/candidate2':'/candidate';
 const load=async()=>{setError('');try{const {data}=await axios.get('/candidate-api/employment');setCandidate(data.candidate);setDraft(preferences(data.candidate));setInterest(data.candidate.job_interest_status||'no_response');}catch{setError('Your preferences could not be loaded. Please try again.');}};
 useEffect(()=>{load();},[]);
 const dirty=candidate&&(interest!==candidate.job_interest_status||channels.some(([key])=>draft[key]!==Boolean(candidate[key])));
 const save=async()=>{
  if(busy||!dirty)return;setBusy(true);setError('');setNotice('');
  let saved=candidate;
  const config={headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content}};
  try{
   if(channels.some(([key])=>draft[key]!==Boolean(saved[key]))){await axios.post('/candidate-api/employment/respond',{kind:'preferences',...draft},config);saved={...saved,...draft};setCandidate(saved);}
   if(interest!==saved.job_interest_status){await axios.post('/candidate-api/employment/respond',{kind:'interest',status:interest},config);saved={...saved,job_interest_status:interest};setCandidate(saved);}
   setNotice('Your preferences have been saved. You can change them at any time.');
  }catch(e){setError(e.response?.data?.message||'Some changes could not be saved. Please try again.');}finally{setBusy(false);}
 };
 return <div className="cp-page">
  <header className="cp-heading"><span className="cp-eyebrow">YOUR CHOICES, YOUR CONTROL</span><h1>Consent & preferences</h1><p>Choose whether you’re looking for work and how EXOWORK can contact you.</p></header>
  {error&&<div className="cp-feedback cp-error" role="alert">{error}{!candidate&&<button onClick={load}>Try again</button>}</div>}
  {notice&&<div className="cp-feedback cp-success" role="status"><Check size={18}/>{notice}</div>}
  {!candidate&&!error&&<div className="cp-loading" role="status"><Loader2 className="cp-spin"/> Loading your preferences…</div>}
  {candidate&&<><div className="cp-layout"><div className="cp-sections">
   <section className="cp-card"><div className="cp-section-heading"><span className="cp-icon"><BriefcaseBusiness size={21}/></span><div><h2>Your job search</h2><p>Let our team know what works for you right now.</p></div></div>
    <fieldset className="cp-options" disabled={busy}><legend>Are you currently looking for a job?</legend>{[
     ['interested','Yes, I’m looking for work','I’d like the team to consider me for relevant opportunities.',BriefcaseBusiness],
     ['not_interested','Not looking right now','Let the team know I’m not currently interested in a new job.',PauseCircle],
    ].map(([value,title,description,Icon])=><label key={value} className={'cp-option '+(interest===value?'is-selected':'')}><input type="radio" name="job-interest" value={value} checked={interest===value} onChange={()=>{setInterest(value);setNotice('');}}/><Icon size={21}/><span><strong>{title}</strong><small>{description}</small></span><span className="cp-radio" aria-hidden="true">{interest===value&&<span/>}</span></label>)}</fieldset>
    <p className="cp-note">This updates your job-search status. Communication permissions are managed separately below.</p>
   </section>
   <section className="cp-card"><div className="cp-section-heading"><span className="cp-icon cp-green"><MessageCircle size={21}/></span><div><h2>How we contact you</h2><p>Allow the channels you’re comfortable using.</p></div></div>
    <div className="cp-channels">{channels.map(([key,label,description,Icon])=><div className="cp-channel" key={key}><Icon size={21}/><div><h3 id={key+'-label'}>{label}</h3><p id={key+'-description'}>{description}</p></div><button type="button" className={'cp-switch '+(draft[key]?'is-on':'')} role="switch" aria-checked={draft[key]} aria-labelledby={key+'-label'} aria-describedby={key+'-description'} disabled={busy} onClick={()=>{setDraft(d=>({...d,[key]:!d[key]}));setNotice('');}}><span/></button></div>)}</div>
    <p className="cp-note">You can also reply <strong>STOP</strong> to any WhatsApp message to opt out of WhatsApp updates.</p>
   </section>
  </div><aside className="cp-aside"><section className="cp-control"><ShieldCheck size={28}/><h2>You’re in control</h2><p>Your permission is optional. Enable a channel only if you agree to receive the updates described here.</p><ul><li><Check size={16}/>Choose each channel separately</li><li><Check size={16}/>Change your choices at any time</li><li><Check size={16}/>Save to apply your changes</li></ul><Link to={base+'/privacy'}>Privacy & account <ArrowUpRight size={16}/></Link></section><section className="cp-profile-help"><h3>Need to update your details?</h3><p>Keep your phone number, email and job preferences current in your profile.</p><Link to={base+'/profile'}>Go to my profile <ArrowUpRight size={16}/></Link></section></aside></div>
  <footer className="cp-savebar"><div><span className={'cp-save-dot '+(dirty?'pending':'')}/><span>{busy?'Saving your preferences…':dirty?'You have unsaved changes':'All preferences are up to date'}</span></div><div><button className="cp-discard" disabled={!dirty||busy} onClick={()=>{setDraft(preferences(candidate));setInterest(candidate.job_interest_status);setNotice('');}}>Discard changes</button><button className="cp-save" disabled={!dirty||busy} onClick={save}>{busy?<Loader2 size={17} className="cp-spin"/>:<Save size={17}/>} {busy?'Saving…':'Save preferences'}</button></div></footer></>}
 </div>;
}
