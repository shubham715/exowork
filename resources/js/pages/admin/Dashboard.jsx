import React,{useState}from'react';
import{useNavigate}from'react-router-dom';
import{Building2,CalendarDays,BriefcaseBusiness,UserCheck,ArrowUpRight,Clock3,MapPin,ChevronRight,TrendingUp,PhoneCall,Globe2}from'lucide-react';
import{Panel,Status}from'../../components/UI.jsx';

const summary=[
  {label:'Training partners',value:'86',meta:'72 active · 8 awaiting review',icon:Building2,tone:'purple',to:'/admin/centers'},
  {label:'Batches ending soon',value:'12',meta:'438 students · next 30 days',icon:CalendarDays,tone:'amber',to:'/admin/batches'},
  {label:'New job requirements',value:'34',meta:'12 today · 87 this month',icon:BriefcaseBusiness,tone:'blue',to:'/admin/jobs'},
  {label:'Interviews scheduled',value:'126',meta:'42 today · 7 need confirmation',icon:Clock3,tone:'teal',to:'/admin/interviews'},
  {label:'Placed in last 30 days',value:'201',meta:'+18 vs previous period',icon:UserCheck,tone:'green',to:'/admin/joining'},
];
const batches=[
  ['Sep 22','CNC Operator — B14','Rajasthan Skills Hub','Jaipur',38,31,'Placement ready'],
  ['Sep 25','Warehouse Associate — B08','Udaan Training Foundation','Ajmer',42,36,'Matching'],
  ['Oct 02','Retail Sales — B21','Future Skills Academy','Kota',35,24,'Needs attention'],
  ['Oct 06','Machine Operator — B11','Pragati Kaushal Kendra','Alwar',46,39,'Matching'],
];
const partnerPerformance=[['Rajasthan Skills Hub',92,78],['Pragati Kaushal',86,72],['Udaan Foundation',81,68],['Future Skills',74,54],['Navjeevan Center',68,46]];
const companies=[['Astro Components',38],['BlueRoute Logistics',29],['Nova Retail',24],['Vertex Auto',19]];
const locations=[['Jaipur',148,94],['Ajmer',96,62],['Kota',82,49],['Alwar',71,45]];

export default function Dashboard(){
  const nav=useNavigate();
  const[range,setRange]=useState('30 days');
  return <>
    <div className="dashboard-intro"><div><span>ADMIN COMMAND CENTER</span><h1>Placement operations overview</h1><p>Track training supply, employer demand, interviews, placements and post-joining outcomes.</p></div><div className="intro-actions"><button className="btn ghost" onClick={()=>nav('/admin/crm')}><PhoneCall/>Open follow-ups</button><button className="btn primary" onClick={()=>nav('/admin/reports')}>View reports <ArrowUpRight/></button></div></div>
    <div className="command-stats">{summary.map(({label,value,meta,icon:Icon,tone,to})=><button key={label} className="command-stat" onClick={()=>nav(to)}><span className={'stat-icon '+tone}><Icon/></span><div><label>{label}</label><strong>{value}</strong><small>{meta}</small></div><ChevronRight/></button>)}</div>
    <div className="ops-alert-strip"><div><span className="alert-dot"/><b>31 students need action today</b><small>12 unconfirmed interviews · 8 overdue calls · 11 joining risks</small></div><button onClick={()=>nav('/admin/crm')}>Review action queue <ChevronRight/></button></div>
    <div className="dashboard-grid primary-grid">
      <Panel title="Upcoming batch completions" sub="Plan matching before students become available" action={<button className="text-link" onClick={()=>nav('/admin/batches')}>All batches <ChevronRight/></button>}><div className="batch-list">{batches.map((b,i)=><button key={b[1]} onClick={()=>nav('/admin/batches/'+(i+1))}><time>{b[0]}</time><div className="batch-main"><b>{b[1]}</b><small>{b[2]} · <MapPin/> {b[3]}</small></div><div className="batch-count"><strong>{b[4]}</strong><small>students</small></div><div className="batch-ready"><strong>{b[5]}</strong><small>job ready</small></div><Status tone={b[6]==='Needs attention'?'high-risk':b[6]==='Matching'?'joining-pending':'active'}>{b[6]}</Status><ChevronRight/></button>)}</div></Panel>
      <Panel title="Placement pipeline" sub="Distinct students across active hiring" action={<select value={range} onChange={e=>setRange(e.target.value)}><option>7 days</option><option>30 days</option><option>3 months</option></select>}><div className="pipeline-bars">{[['Job ready',4250,100],['Matched',2180,74],['Interviewed',620,52],['Selected',310,38],['Joined',201,29]].map(([name,value,width])=><button key={name} onClick={()=>nav(name==='Job ready'?'/admin/candidates':name==='Matched'?'/admin/jobs':name==='Interviewed'?'/admin/interviews':'/admin/joining')}><span><b>{name}</b><strong>{value.toLocaleString()}</strong></span><i><em style={{width:width+'%'}}/></i></button>)}</div><div className="conversion-note"><TrendingUp/><span><b>4.7% readiness-to-join conversion</b><small>+0.8% compared with previous {range}</small></span></div></Panel>
    </div>
    <div className="dashboard-grid analytics-grid">
      <Panel title="Partner & batch performance" sub="Placement rate by training partner"><div className="rank-chart">{partnerPerformance.map(([name,ready,placed],i)=><button key={name} onClick={()=>nav('/admin/centers')}><span className="rank">{i+1}</span><div><b>{name}</b><i><em style={{width:ready+'%'}}/><strong style={{width:placed+'%'}}/></i></div><span className="rate">{placed}%</span></button>)}</div><div className="chart-legend"><span><i className="ready"/>Job ready</span><span><i className="placed"/>Placed</span></div></Panel>
      <Panel title="Top hiring companies" sub="Students joined in the last 90 days"><div className="company-chart">{companies.map(([name,value],i)=><button key={name} onClick={()=>nav('/admin/employers')}><span>{i+1}</span><div><b>{name}</b><i><em style={{width:value/38*100+'%'}}/></i></div><strong>{value}</strong></button>)}</div></Panel>
      <Panel title="Sector placement mix" sub="Share of 201 placements"><div className="sector-donut"><div className="donut"><span><b>201</b><small>placements</small></span></div><ul>{[['Manufacturing','38%','blue'],['Logistics','27%','teal'],['Retail','21%','purple'],['IT-ITES','14%','amber']].map(x=><li key={x[0]}><i className={x[2]}/><span>{x[0]}</span><b>{x[1]}</b></li>)}</ul></div></Panel>
    </div>
    <div className="dashboard-grid bottom-grid">
      <Panel title="Location performance" sub="Active batch supply vs placements"><div className="location-table"><div><b>Location</b><b>Batch students</b><b>Placed</b><b>Rate</b></div>{locations.map(x=><button key={x[0]} onClick={()=>nav('/admin/reports')}><span><MapPin/>{x[0]}</span><b>{x[1]}</b><b>{x[2]}</b><strong>{Math.round(x[2]/x[1]*100)}%</strong></button>)}</div></Panel>
      <Panel title="Unplaced alumni" sub="Completed batches requiring intervention" action={<button className="text-link" onClick={()=>nav('/admin/batches')}>View cohort <ChevronRight/></button>}><div className="unplaced-summary"><strong>286</strong><span>students remain unplaced across <b>19 completed batches</b></span></div><div className="age-buckets">{[['0–30 days',104],['31–60 days',83],['61–90 days',56],['90+ days',43]].map(x=><button key={x[0]} onClick={()=>nav('/admin/batches')}><span>{x[0]}</span><b>{x[1]}</b></button>)}</div></Panel>
      <Panel title="Website traffic" sub="Candidate and partner acquisition" action={<select value={range} onChange={e=>setRange(e.target.value)}><option>7 days</option><option>30 days</option><option>3 months</option></select>}><div className="traffic-head"><Globe2/><div><strong>18,420</strong><span>visits · <b>+12.4%</b></span></div></div><div className="spark-bars">{[38,54,46,70,58,82,74,91,63,88,78,96].map((h,i)=><i key={i} style={{height:h+'%'}}/>)}</div><div className="traffic-foot"><span>1,284 registrations</span><b>6.9% conversion</b></div></Panel>
    </div>
  </>
}
