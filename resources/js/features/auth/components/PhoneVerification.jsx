import React, {useEffect, useRef, useState} from 'react';
import axios from 'axios';
import {ArrowRight, ShieldCheck, CheckCircle2} from 'lucide-react';
import './phone-verification.css';
export const authHeaders = () => ({'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content, Accept: 'application/json'});
export function WhatsAppIcon(){return <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.52 3.48A11.85 11.85 0 0 0 12.05 0C5.5 0 .17 5.33.17 11.88c0 2.09.55 4.13 1.59 5.93L.07 24l6.34-1.66a11.9 11.9 0 0 0 5.64 1.43h.01c6.55 0 11.88-5.33 11.88-11.88 0-3.17-1.23-6.15-3.42-8.41ZM12.06 21.76a9.86 9.86 0 0 1-5.02-1.37l-.36-.21-3.76.98 1-3.66-.24-.38a9.85 9.85 0 0 1-1.51-5.24c0-5.45 4.44-9.89 9.89-9.89a9.83 9.83 0 0 1 6.99 2.9 9.83 9.83 0 0 1 2.89 7c0 5.45-4.44 9.87-9.88 9.87Zm5.42-7.4c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.44-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.49s1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.42.25-.7.25-1.29.17-1.42-.07-.12-.27-.2-.57-.35Z"/></svg>}
export function VerifiedNumber({phone}){return <div className="verified-number" role="status"><CheckCircle2 size={19}/><span>+91 {phone}</span><b>Verified on WhatsApp</b></div>}
export default function PhoneVerification({role,purpose,onVerified,initialPhone=''}){
 const [phone,setPhone]=useState(initialPhone),[challenge,setChallenge]=useState(''),[code,setCode]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState(''),[wait,setWait]=useState(0);
 const inputs=useRef([]), pending=useRef(false);
 useEffect(()=>{if(!wait)return;const t=setTimeout(()=>setWait(v=>Math.max(0,v-1)),1000);return()=>clearTimeout(t)},[wait]);
 useEffect(()=>{if(challenge)inputs.current[0]?.focus()},[challenge]);
 const send=async()=>{
  if(pending.current||wait>0)return;
  if(!/^[6-9][0-9]{9}$/.test(phone)){setError('Enter a valid 10-digit Indian mobile number.');return}
  pending.current=true;setBusy(true);setError('');setNotice('');
  try{const {data}=await axios.post('/auth/otp/send',{phone,role,purpose},{headers:authHeaders()});if(!data.challenge_id)throw new Error('The server did not return a verification session. Please try again.');setChallenge(data.challenge_id);setCode('');setWait(Number(data.retry_after)||60);setNotice(data.message||'Code sent on WhatsApp. Enter it below to continue.');inputs.current[0]?.focus()}
  catch(e){setError(e.response?.data?.message||e.message||'Unable to send a code. Please try again.');if(e.response?.status===429)setWait(Number(e.response.data?.retry_after)||Number(e.response.headers?.['retry-after'])||60)}
  finally{pending.current=false;setBusy(false)}
 };
 const verify=async()=>{if(pending.current||code.length!==6)return;pending.current=true;setBusy(true);setError('');setNotice('');try{const {data}=await axios.post('/auth/otp/verify',{challenge_id:challenge,code},{headers:authHeaders()});onVerified(data)}catch(e){setError(e.response?.data?.message||'Unable to verify your code. Please try again.')}finally{pending.current=false;setBusy(false)}};
 const enter=(index,value)=>{const digits=value.replace(/\D/g,'');if(!digits){setCode(c=>c.slice(0,index));return}const next=(code.slice(0,index)+digits+code.slice(index+digits.length)).slice(0,6);setCode(next);setError('');inputs.current[Math.min(index+digits.length,5)]?.focus()};
 return <section className="phone-verification" aria-busy={busy}>
  <span className="otp-icon"><WhatsAppIcon/></span><span className="section-kicker">SECURE WHATSAPP {purpose==='login'?'LOGIN':'VERIFICATION'}</span>
  <h2>{challenge?'Check your WhatsApp':purpose==='login'?'Welcome back':'Your journey starts here'}</h2>
  <p>{challenge?`Enter the six-digit code sent to +91 ${phone}. It expires in 5 minutes.`:'Use your WhatsApp number to receive a secure verification code.'}</p>
  <form onSubmit={e=>{e.preventDefault();challenge?verify():send()}} noValidate>
  {!challenge?<label>WhatsApp mobile number<div className="otp-phone"><span>+91</span><input aria-label="WhatsApp mobile number" autoComplete="tel-national" type="tel" inputMode="numeric" maxLength={10} value={phone} aria-invalid={!!error} onChange={e=>{setPhone(e.target.value.replace(/\D/g,''));setError('')}} placeholder="10-digit mobile number"/></div></label>:<fieldset className="otp-digits"><legend>Verification code</legend><div>{Array.from({length:6},(_,i)=><input key={i} ref={el=>inputs.current[i]=el} aria-label={`Code digit ${i+1}`} aria-describedby={error?'otp-error':undefined} aria-invalid={!!error} autoComplete={i===0?'one-time-code':'off'} inputMode="numeric" type="text" pattern="[0-9]*" value={code[i]||''} onFocus={e=>e.target.select()} onChange={e=>enter(i,e.target.value)} onPaste={e=>{e.preventDefault();enter(i,e.clipboardData.getData('text'))}} onKeyDown={e=>{if(e.key==='Backspace'&&!code[i]&&i>0){setCode(c=>c.slice(0,i-1));inputs.current[i-1]?.focus()}if(e.key==='ArrowLeft')inputs.current[Math.max(0,i-1)]?.focus();if(e.key==='ArrowRight')inputs.current[Math.min(5,i+1)]?.focus()}}/>)}</div></fieldset>}
  {error&&<div id="otp-error" role="alert" className="auth-notice error">{error}</div>}{notice&&<div role="status" className="auth-notice success"><CheckCircle2 size={18}/>{notice}</div>}
  <button type="submit" className="btn primary block whatsapp-button" disabled={busy||(challenge?code.length!==6:wait>0)}>{!challenge&&<WhatsAppIcon/>}{busy?'Please wait…':challenge?'Verify & continue':wait?`Try again in ${wait}s`:'Send code on WhatsApp'}{!busy&&<ArrowRight size={18}/>}</button>
  {challenge&&<div className="otp-actions"><button type="button" disabled={busy||wait>0} onClick={send}>{wait?`Resend code in ${wait}s`:'Resend code'}</button><button type="button" disabled={busy} onClick={()=>{setChallenge('');setCode('');setError('');setNotice('')}}>Change number</button></div>}
  </form><small className="otp-security"><ShieldCheck size={16}/>Your number keeps your account secure.</small>
 </section>
}
