import React,{useEffect,useState} from 'react';
import axios from 'axios';
import {Link} from 'react-router-dom';
import AuthLayout from './AuthLayout.jsx';
import PhoneVerification from './PhoneVerification.jsx';
export default function RegistrationGate({role,children}){
 const [settings,setSettings]=useState(null),[proof,setProof]=useState(null),[error,setError]=useState('');
 const load=()=>{setError('');axios.get('/auth/settings').then(({data})=>setSettings(data)).catch(()=>setError('Unable to load registration options. Please try again.'))};
 useEffect(load,[]);
 useEffect(()=>setProof(null),[role]);
 if(!settings)return <AuthLayout><div className="login-form"><div className={error?'auth-notice error':'auth-notice loading'} role={error?'alert':'status'}>{error||'Loading secure registration…'}</div>{error&&<button type="button" className="btn ghost block" onClick={load}>Retry</button>}</div></AuthLayout>;
 if(settings.registration_enabled&&!proof)return <AuthLayout><div className="login-form"><Link className="login-back" to="/register">Back to account types</Link><PhoneVerification role={role} purpose="registration" onVerified={setProof}/><div className="otp-alternative"><Link className="btn ghost" to="/register">Change account type</Link><Link className="btn dark" to="/login">Log in</Link></div></div></AuthLayout>;
 return React.cloneElement(children,{verifiedPhone:proof?.phone||'',verificationToken:proof?.verification_token||''});
}
