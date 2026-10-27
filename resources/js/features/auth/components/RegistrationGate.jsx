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
 if(!settings)return <AuthLayout><div role="status">{error||'Loading secure registration…'}{error&&<button onClick={load}>Retry</button>}</div></AuthLayout>;
 if(settings.registration_enabled&&!proof)return <AuthLayout><div className="login-form"><Link className="login-back" to="/register">Back to account types</Link><PhoneVerification role={role} purpose="registration" onVerified={setProof}/><p className="otp-alternative">Already have an account? <Link className="text-link" to="/login">Log in</Link></p></div></AuthLayout>;
 return React.cloneElement(children,{verifiedPhone:proof?.phone||'',verificationToken:proof?.verification_token||''});
}
