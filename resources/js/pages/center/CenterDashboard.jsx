import React,{useEffect,useMemo,useState}from'react';
import axios from'axios';
import{useNavigate}from'react-router-dom';
import{Users,UserCheck,CalendarDays,Award,Plus,ArrowRight,AlertTriangle,Layers3,TrendingUp,CheckCircle2,RefreshCw}from'lucide-react';
import{Stat,Panel,Status}from'../../components/UI';

const empty={center:{name:'',code:'',status:'pending',placement_commitment_percent:null},metrics:{candidates:0,available:0,batches:0,interviews:0,interviews_tomorrow:0,joined:0,pending_joins:0,incomplete_profiles:0,ready_pool:0},batches:[],supply:[],jobs:[]};
const colors=['var(--color-primary-600)','var(--color-success)','var(--color-warning)','var(--color-brand-800)','var(--color-text-muted)'];
const number=value=>new Intl.NumberFormat('en-IN').format(Number(value)||0);
const date=value=>value?new Date(`${value}T00:00:00`).toLocaleDateString('en-IN',{day:'numeric',month:'short'}):'Date not set';

export default function CenterDashboard(){
 const nav=useNavigate(),[data,setData]=useState(empty),[loading,setLoading]=useState(true),[error,setError]=useState('');
 const load=()=>{setLoading(true);setError('');axios.get('/center-api/dashboard').then(({data:response})=>setData({...empty,...response})).catch(e=>{if(e.response?.status===401)nav('/login',{replace:true});else setError(e.response?.data?.message||'Dashboard information could not be loaded. Try again.')}).finally(()=>setLoading(false))};
 useEffect(()=>{load()},[]);
 const navTo=path=>nav(path),m=data.metrics,c=data.center;
 const supplyTotal=useMemo(()=>data.supply.reduce((sum,row)=>sum+Number(row.candidates||0),0),[data.supply]);
 const conic=useMemo(()=>{if(!supplyTotal)return'conic-gradient(var(--color-border) 0 100%)';let start=0;const slices=data.supply.map((row,index)=>{const end=start+Number(row.candidates)/supplyTotal*100,segment=`${colors[index%colors.length]} ${start}% ${end}%`;start=end;return segment});return`conic-gradient(${slices.join(',')})`},[data.supply,supplyTotal]);
 if(loading)return <div className="center-dashboard center-dashboard-state" role="status">Loading your center dashboard…</div>;
 if(error)return <div className="center-dashboard center-dashboard-state center-dashboard-error" role="alert"><p>{error}</p><button className="btn ghost" onClick={load}><RefreshCw/>Try again</button></div>;
 const verified=['verified','active'].includes(c.status);
 const priorities=[
  m.pending_joins>0&&{icon:AlertTriangle,title:`${number(m.pending_joins)} joining confirmation${m.pending_joins===1?'':'s'} pending`,detail:'Update joining outcomes',action:'Resolve',path:'/center/placements'},
  m.interviews_tomorrow>0&&{icon:CalendarDays,title:`${number(m.interviews_tomorrow)} interview${m.interviews_tomorrow===1?'':'s'} tomorrow`,detail:'Review the upcoming schedule',action:'View schedule',path:'/center/interviews'},
  m.incomplete_profiles>0&&{icon:Users,title:`${number(m.incomplete_profiles)} incomplete profile${m.incomplete_profiles===1?'':'s'}`,detail:'Missing resume or consent details',action:'Review profiles',path:'/center/candidates'},
 ].filter(Boolean);

 return <div className="center-dashboard">
  <section className={`role-hero center-hero ${verified?'is-verified':'is-pending'}`}><div><span>{c.name} · {c.code}</span><h1>Turn training into placement outcomes.</h1><p>{m.available?`Your center has ${number(m.available)} candidates available now.`:'Your center dashboard is connected. Candidate and placement activity will appear here as records are added.'} {verified?'Review the latest activity below.':'Your center profile is awaiting verification.'}</p><div><button className="btn light" onClick={()=>navTo('/center/candidates/new')}><Plus/>Register candidate</button><button className="btn transparent" onClick={()=>navTo('/center/pipeline')}>View 30-day pipeline <ArrowRight/></button></div></div><div className="center-badge"><CheckCircle2/><b>{verified?'VERIFIED':'PENDING REVIEW'}</b><small>{c.placement_commitment_percent!=null?`Placement commitment ${c.placement_commitment_percent}%`:'Center profile review'}</small></div></section>
  <div className="stat-grid"><Stat icon={Users} label="Total candidates" value={number(m.candidates)} meta={`Across ${number(m.batches)} training batches`} tone="blue"/><Stat icon={UserCheck} label="Available now" value={number(m.available)} meta={`${number(m.ready_pool)} completed and job ready`} tone="green"/><Stat icon={CalendarDays} label="Upcoming interviews" value={number(m.interviews)} meta={`${number(m.interviews_tomorrow)} scheduled tomorrow`} tone="amber"/><Stat icon={Award} label="Joined" value={number(m.joined)} meta={`${number(m.pending_joins)} joining confirmations pending`} tone="green"/></div>
  <div className="center-priority">{priorities.length?priorities.map((item,index)=>{const Icon=item.icon;return <div key={item.title}><Icon/><span><b>{item.title}</b><small>{item.detail}</small></span><button onClick={()=>navTo(item.path)}>{item.action}</button></div>}):<div className="center-priority-clear"><CheckCircle2/><span><b>No urgent confirmations</b><small>New interviews and profile tasks will appear here.</small></span></div>}</div>
  <div className="role-dashboard-grid"><Panel title="Candidate readiness by batch" sub="Candidate counts and job-ready share from your active batches"><div className="batch-list">{data.batches.map(batch=><button key={batch.id} onClick={()=>navTo('/center/batches')}><span><Layers3/><b>{batch.code}</b></span><div><b>{batch.job_role}</b><small>{number(batch.candidate_count)} candidates · ends {date(batch.ends_on)}</small></div><i><em style={{width:`${batch.readiness_percent}%`}}/></i><strong>{batch.readiness_percent}%</strong><ArrowRight/></button>)}{!data.batches.length&&<div className="workspace-empty">No training batches yet. Create a batch to start tracking readiness.</div>}</div></Panel>
   <Panel title="30-day supply forecast" sub="Registered candidates in batches ending within 30 days"><div className="supply-donut" style={{background:conic}}><div><b>{number(supplyTotal)}</b><span>candidates</span></div></div>{data.supply.length?<ul className="legend-list">{data.supply.map((row,index)=><li key={row.industry}><i style={{background:colors[index%colors.length]}}/>{row.industry}<b>{number(row.candidates)}</b></li>)}</ul>:<div className="center-dashboard-empty">No batch completions forecast in the next 30 days.</div>}<button className="btn ghost block" onClick={()=>navTo('/center/pipeline')}><TrendingUp/>Manage pipeline</button></Panel></div>
  <Panel title="Recommended employer requirements" sub="Published jobs with available, completed candidates from your center"><div className="recommended-strip">{data.jobs.map(job=><article key={job.id}><Status tone="active">Open</Status><h3>{job.title}</h3><p>{job.employer_name||'Employer'} · {[job.location,job.district,job.state].filter(Boolean).join(', ')||'Location not specified'} · {number(job.openings)} openings</p><div><b>{number(job.candidate_fit_count)}</b><span>available candidates in matching industry</span></div><button onClick={()=>navTo('/center/jobs')}>View requirement <ArrowRight/></button></article>)}{!data.jobs.length&&<div className="center-dashboard-empty">No published employer requirements are available right now.</div>}</div></Panel>
 </div>;
}

