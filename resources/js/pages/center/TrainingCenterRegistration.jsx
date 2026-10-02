import React,{useEffect,useState}from'react';
import axios from'axios';
import{Link,useNavigate}from'react-router-dom';
import{ArrowLeft,ArrowRight,Building2,ShieldCheck}from'lucide-react';
import BrandLogo from'../../components/BrandLogo.jsx';

export default function TrainingCenterRegistration(){
 const nav=useNavigate(),[data,setData]=useState({center_name:'',email:'',phone:'',state:'',district:'',password:'',password_confirmation:''}),[states,setStates]=useState([]),[districts,setDistricts]=useState([]),[errors,setErrors]=useState({}),[busy,setBusy]=useState(false),[message,setMessage]=useState('');
 useEffect(()=>{axios.get('/api/master-data').then(({data:r})=>setStates(r.states||[])).catch(()=>setMessage('Location choices could not be loaded. Refresh the page and try again.'))},[]);
 useEffect(()=>{const state=states.find(x=>x.name===data.state);if(!state){setDistricts([]);return}axios.get(`/api/master-data/states/${state.slug}/districts`).then(({data:r})=>setDistricts(r.districts||[])).catch(()=>setDistricts([]))},[states,data.state]);
 const set=(key,value)=>{setData(d=>({...d,[key]:value}));setErrors(e=>({...e,[key]:undefined}));setMessage('')};
 const submit=async e=>{
  e.preventDefault();setBusy(true);setMessage('');setErrors({});
  const meta=document.querySelector('meta[name="csrf-token"]');
  const post=token=>axios.post('/auth/register/training-center',data,{headers:{'X-CSRF-TOKEN':token}});
  try{
   let response;
   try{response=await post(meta?.content)}catch(err){
    if(err.response?.status!==419)throw err;
    const fresh=await axios.get('/auth/csrf-token');
    if(meta)meta.content=fresh.data.csrf_token;
    response=await post(fresh.data.csrf_token);
   }
   if(meta&&response.data.csrf_token)meta.content=response.data.csrf_token;
   nav(response.data.destination);
  }catch(err){setErrors(err.response?.data?.errors||{});setMessage(err.response?.status===419?'Your session could not be restored. Refresh this page and try again.':err.response?.data?.message||'We could not create your account. Please check the details and try again.')}finally{setBusy(false)}
 };
 return <div className="candidate-register public-reg center-public-register"><header className="reg-top"><Link to="/login"><ArrowLeft/>Back to sign in</Link><Link className="reg-brand" to="/"><BrandLogo/></Link><span/></header><main><aside className="reg-aside"><span className="reg-kicker">TRAINING CENTER REGISTRATION</span><h1>Bring your center into the EXOWORK network.</h1><p>Create your secure account first. You can complete your center profile over multiple visits.</p><div className="privacy-note"><ShieldCheck/><p>Your progress is saved to your account. Sign in any time to continue.</p></div></aside><section className="reg-form"><div className="reg-form-head"><span>STEP 1 OF 2 · ACCOUNT</span><h2>Create your center account</h2><p>For coaching centers, training institutes and individual trainers.</p></div><form className="reg-fields" onSubmit={submit}>
  <Field name="center_name" label="Training center name" placeholder="Name used at this training location" data={data} set={set} errors={errors}/><Field name="email" label="Email" placeholder="you@example.com" type="email" data={data} set={set} errors={errors}/><Field name="phone" label="Mobile number" placeholder="10-digit mobile number" type="tel" data={data} set={set} errors={errors}/><SelectField name="state" label="State" value={data.state} set={(key,value)=>{set(key,value);setData(d=>({...d,state:value,district:''}));setErrors(e=>({...e,state:undefined,district:undefined}))}} options={states.map(x=>x.name)} placeholder="Choose a state" errors={errors}/><SelectField name="district" label="District" value={data.district} set={set} options={districts.map(x=>x.name)} placeholder={data.state?'Choose a district':'Select a state first'} disabled={!data.state||!districts.length} errors={errors}/><Field name="password" label="Create password" placeholder="At least 8 characters" type="password" data={data} set={set} errors={errors}/><Field name="password_confirmation" label="Confirm password" placeholder="Enter the same password again" type="password" data={data} set={set} errors={errors}/>
  {message&&<div className="reg-submit-error wide" role="alert">{message}</div>}<div className="center-register-footer wide"><span><ShieldCheck/>Secure account setup</span><button className="btn primary" disabled={busy}>{busy?'Creating account…':'Create account'}{!busy&&<ArrowRight/>}</button></div>
 </form></section></main></div>
}
function Field({name,label,type='text',placeholder,data,set,errors}){return <label className={`form-label reg-field ${errors[name]?'has-error':''}`}><span>{label}<em>*</em></span><input type={type} placeholder={placeholder} value={data[name]} onChange={e=>set(name,e.target.value)} autoComplete={name.includes('password')?'new-password':name==='email'?'email':'organization'} />{errors[name]&&<small className="reg-error">{errors[name][0]}</small>}</label>}
function SelectField({name,label,value,set,options,placeholder,errors,disabled=false}){return <label className={`form-label reg-field ${errors[name]?'has-error':''}`}><span>{label}<em>*</em></span><select value={value} disabled={disabled} onChange={e=>set(name,e.target.value)}><option value="">{placeholder}</option>{options.map(x=><option key={x} value={x}>{x}</option>)}</select>{errors[name]&&<small className="reg-error">{errors[name][0]}</small>}</label>}
