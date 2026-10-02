import React,{useEffect,useMemo,useState}from'react';
import axios from'axios';
import{Camera,MapPin,LocateFixed,ShieldCheck,Pencil,CheckCircle2,EyeOff,Save,BriefcaseBusiness,GraduationCap,Phone,LoaderCircle}from'lucide-react';
import{PageHead,Status}from'../../components/UI';
import{useToast}from'../../App';
import{useNavigate}from'react-router-dom';

const optionsFor=(masters,key)=>masters?.[key]||[];
const displayValue=(key,value)=>{
  if(Array.isArray(value))return value.join(', ')||'Not provided';
  if(key==='expected_monthly_salary'&&value)return `\u20B9${Number(value).toLocaleString('en-IN')}`;
  return value||'Not provided';
};
const preferredLocations=value=>Array.isArray(value)?value.join('; '):'';

export default function CandidateProfile(){
  const toast=useToast(),nav=useNavigate();
  const[profile,setProfile]=useState(null),[form,setForm]=useState({}),[masters,setMasters]=useState(null),[districts,setDistricts]=useState([]);
  const[edit,setEdit]=useState(false),[saving,setSaving]=useState(false),[loading,setLoading]=useState(true),[message,setMessage]=useState('');
  useEffect(()=>{let active=true;Promise.all([axios.get('/candidate-api/profile/details'),axios.get('/api/master-data')]).then(([profileResponse,masterResponse])=>{if(!active)return;const candidate=profileResponse.data.candidate;setProfile(candidate);setForm({...candidate});setMasters(masterResponse.data)}).catch(error=>{if(!active)return;if(error.response?.status===401)nav('/login',{replace:true});else setMessage('Your profile could not be loaded. Please try again.')}).finally(()=>{if(active)setLoading(false)});return()=>{active=false}},[nav]);
  const stateOption=masters?.states.find(x=>x.id===Number(form.state_id));
  useEffect(()=>{let active=true;if(!stateOption){setDistricts([]);return()=>{active=false}}axios.get(`/api/master-data/states/${encodeURIComponent(stateOption.slug)}/districts`).then(({data})=>{if(active)setDistricts(data.districts||[])}).catch(()=>{if(active)setDistricts([])});return()=>{active=false}},[stateOption?.slug]);
  const set=(key,value)=>setForm(current=>({...current,[key]:value}));
  const strength=useMemo(()=>{if(!profile)return 0;const values=[profile.first_name,profile.last_name,profile.whatsapp,profile.gender,profile.qualification,profile.current_location,profile.skills,profile.experience_type,profile.expected_monthly_salary,profile.preferred_industry,profile.languages?.length];return Math.round(values.filter(Boolean).length/values.length*100)},[profile]);
  const stateChoices=optionsFor(masters,'states'),qualificationChoices=optionsFor(masters,'qualifications'),experienceChoices=optionsFor(masters,'experience_levels'),industryChoices=optionsFor(masters,'industries');
  const submit=async()=>{
    setSaving(true);setMessage('');
    try{
      const qualification=qualificationChoices.find(x=>x.id===Number(form.qualification_option_id));
      const experience=experienceChoices.find(x=>x.id===Number(form.experience_level_option_id));
      const industry=industryChoices.find(x=>x.id===Number(form.industry_option_id));
      const languages=(Array.isArray(form.languages)?form.languages:[]).map(x=>String(x).trim()).filter(Boolean);
      const languageIds=languages.map(name=>optionsFor(masters,'languages').find(x=>x.name.toLowerCase()===name.toLowerCase())?.id).filter(Boolean);
      const payload={...form,qualification:qualification?.name||form.qualification,qualification_option_id:qualification?.id||form.qualification_option_id,experience_type:experience?.name||form.experience_type,experience_level_option_id:experience?.id||form.experience_level_option_id,preferred_industry:industry?.name||form.preferred_industry,industry_option_id:industry?.id||form.industry_option_id,languages,language_option_ids:languageIds,preferred_locations:Array.isArray(form.preferred_locations)?form.preferred_locations:[],district_is_custom:Boolean(form.district_is_custom),district_id:form.district_is_custom?null:form.district_id};
      const{data}=await axios.patch('/candidate-api/profile',payload);setProfile(data.candidate);setForm({...data.candidate});setEdit(false);toast('Profile changes saved');
    }catch(error){const errors=error.response?.data?.errors||{};const detail=Object.values(errors).flat().join(' ');setMessage(detail||error.response?.data?.message||'Profile changes could not be saved. Please try again.')}finally{setSaving(false)}
  };
  const field=(label,key,type='text',choices=null,wide=false,readOnly=false)=><label className={wide?'wide':''} key={key}>{label}{edit?(choices?<select value={form[key]??''} onChange={e=>set(key,e.target.value)}><option value="">Select {label.toLowerCase()}</option>{choices.map(choice=><option key={choice.id??choice} value={choice.id??choice}>{choice.name??choice}</option>)}</select>:<input type={type} value={displayValue(key,form[key])==='Not provided'?'':Array.isArray(form[key])?form[key].join(', '):form[key]??''} readOnly={readOnly} onChange={e=>!readOnly&&set(key,e.target.value)}/>):<b>{displayValue(key,form[key])}</b>}</label>;
  if(loading)return <div className="profile-loading"><LoaderCircle className="spin"/>Loading your profile…</div>;
  if(!profile)return <div className="profile-error">{message||'Your profile is unavailable.'}</div>;
  const status=profile.availability==='Yes'?'Available for work':profile.availability==='Available after training'?'Available after training':'Not currently available';
  const languageText=Array.isArray(form.languages)?form.languages.join(', '):'';
  return <>
    <PageHead kicker="CANDIDATE PROFILE" title="My profile" sub="Keep your information accurate to improve job matches.">
      <button className="btn primary" disabled={saving} onClick={()=>edit?submit():setEdit(true)}>{edit?<><Save/>{saving?'Saving…':'Save changes'}</>:<><Pencil/>Edit profile</>}</button>
      {edit&&<button className="btn ghost" disabled={saving} onClick={()=>{setForm({...profile});setEdit(false);setMessage('')}}>Cancel</button>}
    </PageHead>
    {message&&<div className="profile-error" role="alert">{message}</div>}
    <section className="candidate-profile-cover">
      <div className="candidate-photo"><div className="photo-person">{profile.initials||`${profile.first_name?.[0]||''}${profile.last_name?.[0]||''}`}</div></div>
      <div><Status tone={profile.availability==='No'?'pending':'active'}>{status}</Status><h1>{profile.full_name}</h1><p>{profile.candidate_code} · {profile.preferred_industry||'Candidate profile'}</p><span><MapPin/>{[profile.current_location,profile.district,profile.state].filter(Boolean).join(', ')||'Location not provided'}</span></div>
      <div className="cover-score"><b>{strength}%</b><span>Profile strength</span></div>
    </section>
    <div className="profile-control-strip">
      <div><span className="control-icon"><Camera/></span><div><b>Profile photograph</b><small>{profile.photo_added?'A photograph is securely stored':profile.photo_consent?'Photo sharing allowed · no image uploaded':'No photograph uploaded'}</small></div><Status tone={profile.photo_consent?'active':'pending'}>{profile.photo_consent?'Sharing enabled':'Sharing disabled'}</Status></div>
      <div><span className="control-icon"><LocateFixed/></span><div><b>Location-assisted matching</b><small>{form.location_consent?'Enabled for nearby job suggestions':'Disabled · manual locations used'}</small></div>{edit&&<label className="switch"><input type="checkbox" checked={Boolean(form.location_consent)} onChange={e=>set('location_consent',e.target.checked)}/><i/></label>}</div>
      <div><span className="control-icon"><ShieldCheck/></span><div><b>Aadhaar capture</b><small>{profile.aadhaar_added?'Encrypted Aadhaar is on file · value hidden':'Not added · optional'}</small></div><Status tone="pending"><EyeOff/> Restricted</Status></div>
    </div>
    <div className="profile-sections">
      <section className="panel"><header><div><h3>Personal & contact</h3><p>Your identity and communication information</p></div><CheckCircle2/></header><div className="panel-body profile-field-grid">
        {field('First name','first_name')}{field('Last name','last_name')}{field('WhatsApp mobile','whatsapp','text',null,false,true)}{field('Email address','email','email',null,false,true)}{field('Alternate mobile','alternate_mobile')}{field('Gender','gender','text',['Female','Male','Other','Prefer not to say'])}{field('Age','age','number')}
        {edit?<label>Highest qualification<select value={form.qualification_option_id??''} onChange={e=>set('qualification_option_id',Number(e.target.value))}><option value="">Select qualification</option>{qualificationChoices.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>:field('Highest qualification','qualification')}
        {edit?<><label>State<select value={form.state_id??''} onChange={e=>{const next=stateChoices.find(x=>x.id===Number(e.target.value));setForm(current=>({...current,state_id:next?.id||'',state:next?.name||'',district_id:'',district:'',district_is_custom:false}))}}><option value="">Select state</option>{stateChoices.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label><label>District{form.district_is_custom?<><input value={form.district||''} onChange={e=>set('district',e.target.value)}/><button type="button" className="text-link" onClick={()=>setForm(current=>({...current,district_is_custom:false,district_id:'',district:''}))}>Choose a listed district</button></>:<select value={form.district_id??''} onChange={e=>{if(e.target.value==='other')setForm(current=>({...current,district_id:null,district_is_custom:true,district:''}));else{const next=districts.find(x=>x.id===Number(e.target.value));setForm(current=>({...current,district_id:next?.id||'',district:next?.name||'',district_is_custom:false}))}}}><option value="">Select district</option>{districts.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}<option value="other">Other</option></select>}</label></>:<>{field('State','state')}{field('District','district')}</>}
        {field('Current location','current_location')}{field('Permanent location','permanent_location')}
      </div></section>
      <section className="panel"><header><div><h3>Employability preferences</h3><p>Used for transparent job matching</p></div><BriefcaseBusiness/></header><div className="panel-body profile-field-grid">
        {field('Specific skills','skills')}{edit?<label>Experience level<select value={form.experience_level_option_id??''} onChange={e=>set('experience_level_option_id',Number(e.target.value))}><option value="">Select experience</option>{experienceChoices.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>:field('Experience level','experience_type')}{field('Experience details','experience_details')}
        {edit?<label className="wide">Preferred job locations<textarea rows="2" value={preferredLocations(form.preferred_locations)} onChange={e=>set('preferred_locations',e.target.value.split(';').map(x=>x.trim()).filter(Boolean))}/><small>Separate locations with semicolons.</small></label>:field('Preferred job locations','preferred_locations','text',null,true)}
        {edit?<label>Willing to relocate<select value={form.relocation_preference??''} onChange={e=>set('relocation_preference',e.target.value)}>{['No','Yes - within district','Yes - within state','Yes - anywhere in India'].map(x=><option key={x}>{x}</option>)}</select></label>:field('Willing to relocate','relocation_preference')}
        {field('Expected monthly salary','expected_monthly_salary')}{edit?<label>Available for job<select value={form.availability??''} onChange={e=>set('availability',e.target.value)}>{['Yes','No','Available after training'].map(x=><option key={x}>{x}</option>)}</select></label>:field('Available for job','availability')}
        {edit?<label>Preferred industry<select value={form.industry_option_id??''} onChange={e=>set('industry_option_id',Number(e.target.value))}><option value="">Select industry</option>{industryChoices.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>:field('Preferred industry','preferred_industry')}
        {edit?<label className="wide">Languages<input value={languageText} onChange={e=>set('languages',e.target.value.split(',').map(x=>x.trim()).filter(Boolean))}/><small>Enter languages separated by commas.</small></label>:field('Languages','languages','text',null,true)}
        {edit&&<div className="wide"><label className="switch"><input type="checkbox" checked={Boolean(form.photo_consent)} onChange={e=>set('photo_consent',e.target.checked)}/><i/></label><span>Allow authorized hiring teams to view an uploaded photo</span></div>}
      </div></section>
      <section className="panel"><header><div><h3>Training information</h3><p>Training details saved to your candidate profile</p></div><GraduationCap/></header><div className="panel-body profile-field-grid">{field('Training partner','training_partner','text',null,false,true)}{field('Training center','training_center','text',null,false,true)}{field('Actual batch code','batch_code','text',null,false,true)}{field('Training status','training_status','text',null,false,true)}{field('Batch end date','batch_end_date','text',null,false,true)}</div></section>
      <section className="panel"><header><div><h3>Communication preferences</h3><p>Manage the channels used for candidate updates</p></div><Phone/></header><div className="panel-body profile-field-grid consent-profile-grid">{[['call_consent','Phone calls'],['sms_consent','SMS alerts'],['whatsapp_consent','WhatsApp messages'],['email_consent','Email updates']].map(([key,label])=><label key={key}>{label}<span>{edit?<input type="checkbox" checked={Boolean(form[key])} onChange={e=>set(key,e.target.checked)}/>:<b>{form[key]?'Allowed':'Disabled'}</b>}</span></label>)}</div></section>
    </div>
  </>;
}
