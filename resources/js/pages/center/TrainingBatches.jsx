import React,{useEffect,useMemo,useState}from'react';

import{useNavigate}from'react-router-dom';

import{CalendarDays,CheckCircle2,Clock3,Plus,Search,Users,UserRound,AlertTriangle,ArrowRight,UserPlus}from'lucide-react';

import{Modal,PageHead,Status}from'../../components/UI';

import{useToast}from'../../App';

import axios from 'axios';

import{batchStage,daysUntil}from'./centerStore';

import'./batch-members.css';



const blank={code:'',sector:'',jobRole:'',trainer:'',trainerPhone:'',startDate:'',endDate:'',capacity:'',status:'Ongoing'};

export default function TrainingBatches(){

  const nav=useNavigate(),toast=useToast();

  const[batches,setBatches]=useState([]),[candidates,setCandidates]=useState([]),[query,setQuery]=useState(''),[status,setStatus]=useState('All');

  const[editing,setEditing]=useState(null),[form,setForm]=useState(null),[detail,setDetail]=useState(null),[assigning,setAssigning]=useState(null);

  const[centerIdentity,setCenterIdentity]=useState({}),[loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState('');

  const message=e=>Object.values(e.response?.data?.errors||{}).flat().join(' ')||e.response?.data?.message||'The operation failed. Please try again.';

  const load=async()=>{setLoading(true);setError('');try{const [b,c]=await Promise.all([axios.get('/center-api/batches'),axios.get('/center-api/candidates')]);setBatches(b.data.batches);setCandidates(c.data.candidates);setCenterIdentity({center:b.data.center.name,centerCode:b.data.center.code})}catch(e){setError(message(e));throw e}finally{setLoading(false)}};

  useEffect(()=>{load().catch(()=>{})},[]);

  const rows=useMemo(()=>batches.filter(b=>(status==='All'||batchStage(b)===status)&&[b.code,b.sector,b.jobRole,b.trainer].join(' ').toLowerCase().includes(query.toLowerCase())),[batches,query,status]);

  const openCreate=()=>{setEditing(null);setForm(blank)};

  const save=async()=>{

    if(busy)return;setBusy(true);setError('');

    try{await axios[editing?'put':'post'](`/center-api/batches${editing?`/${editing.id}`:''}`,form);setForm(null);await load();toast('Batch saved')}catch(e){setError(message(e))}finally{setBusy(false)}

  };

  const startAssigning=batch=>{setDetail(null);setAssigning(batch)};

  const applyAssignments=async selectedIds=>{

    if(busy)return;setBusy(true);setError('');

    try{await axios.post(`/center-api/batches/${assigning.id}/assign`,{candidates:selectedIds});setAssigning(null);await load();toast('Candidates assigned')}catch(e){setError(message(e))}finally{setBusy(false)}

  };

  const stats={total:batches.length,ongoing:batches.filter(b=>batchStage(b)==='Ongoing').length,soon:batches.filter(b=>{const d=daysUntil(b.endDate);return d>=0&&d<=15}).length,trainees:candidates.filter(c=>batches.some(b=>b.code===c.batch)).length};

  return <>

    <PageHead kicker="TRAINING OPERATIONS" title="Training batches" sub="Create actual batches, add registered candidates and track training through placement eligibility.">

      <button className="btn ghost" onClick={()=>nav('/center/candidates')}><Users/>View candidates</button><button className="btn primary" disabled={loading||!!error} onClick={openCreate}><Plus/>Create batch</button>

    </PageHead>

    {error&&<p role="alert">{error}<button type="button" onClick={()=>load().catch(()=>{})}>Retry</button></p>}

    {loading&&<p role="status">Loading batches…</p>}

    <div className="workspace-stats"><article><CalendarDays/><span>Total batches<b>{stats.total}</b></span></article><article><Clock3/><span>Ongoing<b>{stats.ongoing}</b></span></article><article><AlertTriangle/><span>Ending in 15 days<b>{stats.soon}</b></span></article><article><Users/><span>Linked candidates<b>{stats.trainees}</b></span></article></div>

    <section className="workspace-card"><div className="workspace-toolbar"><div className="search-box"><Search/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Search batch code, sector, job role or trainer…"/></div><select value={status} onChange={e=>setStatus(e.target.value)}><option>All</option><option>Upcoming</option><option>Ongoing</option><option>Completed</option></select></div>

      <div className="batch-table table-scroll data-table"><table><thead><tr><th>Actual batch</th><th>Trade & trainer</th><th>Training period</th><th>Candidates</th><th>Tracking</th><th aria-label="Actions"/></tr></thead><tbody>{rows.map(b=>{const members=candidates.filter(c=>c.batch===b.code),stage=batchStage(b),days=daysUntil(b.endDate);return <tr key={b.id} onClick={()=>setDetail(b)}><td><button type="button" className="table-record-link" onClick={()=>setDetail(b)}>{b.code}</button><small>{b.sector}</small></td><td><b>{b.jobRole}</b><small>{b.trainer} · {b.trainerPhone}</small></td><td><b>{formatDate(b.startDate)} – {formatDate(b.endDate)}</b><small>{stage==='Ongoing'?(days<=15?`${Math.max(days,0)} days to completion`:'Training in progress'):stage}</small></td><td><b>{members.length} / {b.capacity}</b><div className="mini-progress"><i style={{width:`${Math.min(100,members.length/b.capacity*100)}%`}}/></div></td><td><Status tone={stage.toLowerCase()}>{stage}</Status>{days>=0&&days<=15&&<small className="ready-note">Placement window active</small>}</td><td><button type="button" className="table-row-action" aria-label={`View batch ${b.code}`} onClick={()=>setDetail(b)}><ArrowRight/></button></td></tr>})}</tbody></table>{!rows.length&&<div className="workspace-empty">No batches match these filters.</div>}</div>

    </section>

    <Modal open={form!==null} onClose={()=>{if(!busy)setForm(null)}} title={editing?'Edit training batch':'Create training batch'} sub={`Candidates will be linked to ${centerIdentity.center} through this actual batch code.`}>{form&&<><div className="form-grid"><Field label="Actual batch code" value={form.code} onChange={v=>setForm({...form,code:v})}/><Field label="Sector" value={form.sector} onChange={v=>setForm({...form,sector:v})}/><Field label="Course / job role" value={form.jobRole} onChange={v=>setForm({...form,jobRole:v})}/><Field label="Trainer name" value={form.trainer} onChange={v=>setForm({...form,trainer:v})}/><Field label="Trainer contact" type="tel" value={form.trainerPhone} onChange={v=>setForm({...form,trainerPhone:v})}/><Field label="Candidate capacity" type="number" value={form.capacity} onChange={v=>setForm({...form,capacity:v})}/><Field label="Start date" type="date" value={form.startDate} onChange={v=>setForm({...form,startDate:v})}/><Field label="Expected end date" type="date" value={form.endDate} onChange={v=>setForm({...form,endDate:v})}/></div><div className="fixed-scope"><CheckCircle2/><span><b>{centerIdentity.center}</b><small>{centerIdentity.centerCode} · fixed from the logged-in center</small></span></div>{error&&<p role="alert">{error}</p>}<div className="modal-actions"><button className="btn ghost" disabled={busy} onClick={()=>setForm(null)}>Cancel</button><button className="btn primary" disabled={busy} onClick={save}>{busy?'Saving…':'Save batch'}</button></div></>}</Modal>

    <Modal open={!!detail} onClose={()=>setDetail(null)} title={detail?.code||''} sub={detail?`${detail.sector} · ${detail.jobRole}`:''}>{detail&&<BatchDetail batch={detail} candidates={candidates} onEdit={()=>{setEditing(detail);setForm({...detail});setDetail(null)}} onAssign={()=>startAssigning(detail)} onRegister={()=>nav(`/center/candidates/new?batch=${encodeURIComponent(detail.code)}`)}/>}</Modal>

    <Modal open={!!assigning} onClose={()=>setAssigning(null)} title={`Add existing candidates to ${assigning?.code||''}`} sub="Select registered candidates. Assignments are saved with an audit history.">{assigning&&<CandidatePicker batch={assigning} candidates={candidates} onCancel={()=>setAssigning(null)} error={error} busy={busy} onSave={applyAssignments}/>}</Modal>

  </>;

}

function BatchDetail({batch,candidates,onEdit,onAssign,onRegister}){const members=candidates.filter(c=>c.batch===batch.code),days=daysUntil(batch.endDate),seats=Math.max(0,batch.capacity-members.length);return <><div className="batch-detail-grid"><span>Training period<b>{formatDate(batch.startDate)} – {formatDate(batch.endDate)}</b></span><span>Trainer<b>{batch.trainer} · {batch.trainerPhone}</b></span><span>Capacity<b>{members.length} of {batch.capacity} · {seats} seats open</b></span><span>Placement eligibility<b>{days<0?'Training completed':days<=15?'Eligible window is active':`Opens in ${days-15} days`}</b></span></div><div className="section-heading"><h3 className="section-title">Assigned candidates</h3><button className="btn ghost small" disabled={!seats} onClick={onAssign}><UserPlus/>Add existing candidates</button></div><div className="batch-members">{members.map(c=><div key={c.id}><UserRound/><span><b>{c.firstName} {c.lastName}</b><small>{c.id} · {c.trainingStatus}{c.assignedAt?` · assigned ${formatDate(c.assignedAt.slice(0,10))}`:''}</small></span><Status tone={c.available==='Yes'?'active':'pending'}>{c.available}</Status></div>)}{!members.length&&<p>No candidates assigned yet. Add registered candidates or create a new profile.</p>}</div><div className="modal-actions"><button className="btn ghost" onClick={onEdit}>Edit batch</button><button className="btn ghost" onClick={onRegister}><Plus/>Register new candidate</button><button className="btn primary" disabled={!seats} onClick={onAssign}><UserPlus/>Add existing candidates</button></div></>}

function CandidatePicker({batch,candidates,onCancel,onSave,busy,error}){const[query,setQuery]=useState(''),[selected,setSelected]=useState([]),remaining=Math.max(0,Number(batch.capacity)-candidates.filter(c=>c.batch===batch.code).length),available=candidates.filter(c=>c.batch!==batch.code&&[c.id,c.firstName,c.lastName,c.whatsapp,c.batch,c.skills].join(' ').toLowerCase().includes(query.toLowerCase()));const toggle=id=>setSelected(rows=>rows.includes(id)?rows.filter(x=>x!==id):rows.length>=remaining?rows:[...rows,id]);return <>{error&&<p role="alert">{error}</p>}<div className="assignment-summary"><div><b>{selected.length}</b><span>selected</span></div><div><b>{remaining}</b><span>seats available</span></div><p>Adding a candidate who is already in another batch will move their active assignment here while preserving the previous batch in assignment history.</p></div><div className="candidate-picker-search search-box"><Search/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Search registered candidates by name, ID, mobile or skill…"/></div><div className="candidate-picker"><label className="picker-select-all"><input type="checkbox" checked={available.length>0&&available.every(c=>selected.includes(c.id))} onChange={e=>setSelected(e.target.checked?available.slice(0,remaining).map(c=>c.id):[])}/><b>Select all visible</b><small>Up to {remaining} candidates</small></label>{available.map(c=><label key={c.id} className={selected.includes(c.id)?'selected':''}><input type="checkbox" checked={selected.includes(c.id)} onChange={()=>toggle(c.id)}/><span className="picker-avatar">{c.firstName?.[0]}{c.lastName?.[0]}</span><span><b>{c.firstName} {c.lastName}</b><small>{c.id} · {mask(c.whatsapp)} · {c.skills||'Skills not added'}</small></span><em>{c.batch?<><b>Current batch</b>{c.batch}</>:'Unassigned'}</em></label>)}{!available.length&&<div className="workspace-empty">No eligible registered candidates found.</div>}</div><div className="modal-actions"><button className="btn ghost" onClick={onCancel}>Cancel</button><button className="btn primary" disabled={busy||!selected.length} onClick={()=>onSave(selected)}><UserPlus/>Add {selected.length||''} candidate{selected.length===1?'':'s'}</button></div></>}

const batchPlaceholders={

  'Actual batch code':'Enter a unique batch code',

  'Sector':'Enter the training sector',

  'Course / job role':'Enter the course or job role',

  'Trainer name':'Enter the trainer full name',

  'Trainer contact':'Enter a 10-digit mobile number',

  'Candidate capacity':'Enter the maximum candidate count',

};

function Field({label,value,onChange,type='text'}){return <label className="field-label"><span>{label} <span className="required-mark" aria-hidden="true">*</span></span><input required type={type} placeholder={batchPlaceholders[label]} min={type==='number'?1:undefined} max={type==='number'?100000:undefined} inputMode={type==='tel'?'numeric':undefined} maxLength={type==='tel'?10:undefined} value={value} onChange={e=>onChange(e.target.value)}/></label>}



function formatDate(value){return value?new Date(value+'T00:00:00').toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}):'—'}

function mask(value=''){return value.length>=4?`${value.slice(0,2)}••• ••${value.slice(-3)}`:value||'No mobile'}

