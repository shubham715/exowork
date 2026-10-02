import React, {useEffect, useState, useRef, useId} from 'react';

import axios from 'axios';

import {Link, useLocation} from 'react-router-dom';

import {BarChart3, Download, RefreshCw, Eye, Pencil, ChevronLeft, ChevronRight, ArrowDownUp, SlidersHorizontal, Power, CalendarDays, CheckCircle2, XCircle, Save, X, Search, RotateCcw, Building2, MapPin, UserRound, ShieldCheck, BriefcaseBusiness, Mail, CircleAlert, Clock3, UsersRound, Ban} from 'lucide-react';

import {PageHead, Modal} from '../../components/UI.jsx';

import {csrf, useEmployerMasters} from '../employer/EmployerRegistration.jsx';

import {statusLabels, dateLabel} from './employer-admin-utils.js';

import '../../data-tables.css';

import './employer-admin.css';

import {EmployerDocument} from './EmployerDocument.jsx';

const fields = [['name','Display name',true],['legal_name','Legal company name',true],['industry','Industry'],['website','Website'],['gstin','GSTIN'],['contact_name','Contact name'],['contact_designation','Designation'],['contact_department','Department'],['contact_phone','Contact phone'],['state','State'],['district','District'],['pincode','Pincode'],['address','Address'],['description','Company description']];

const columns=[['Employer','legal_name'],['Industry / location','industry'],['Contact','contact_name'],['Status','status'],['Jobs','jobs_count'],['Hires','hires_count'],['Registered','created_at']];

const statusIcons={pending:Clock3,verified:CheckCircle2,rejected:XCircle,inactive:Ban,all:UsersRound};
function EmployerStatus({status}) {const Icon=statusIcons[status]||CircleAlert;return <span className={`ea-status-badge ea-status-${status}`}><Icon size={14}/>{statusLabels[status]||status}</span>;}
const actionIcons={approve:CheckCircle2,reject:XCircle,activate:CheckCircle2,disable:Power,save:Save};

function ActionIcon({action}) {const Icon=actionIcons[action]||CheckCircle2;return <Icon size={16}/>;}

function DetailSection({icon:Icon,title,items}) {

    return <section className="ea-profile-section"><h3><Icon size={18}/>{title}</h3><dl className="ea-profile-details">{items.map(([label,value,wide])=><div key={label} className={wide?'wide':''}><dt>{label}</dt><dd>{value||'Not provided'}</dd></div>)}</dl></section>;

}

function ProfileEditField({field,draft,setDraft,master,districts}) {

    const [key,label,required]=field;

    return <label className={'field-label '+(['address','description'].includes(key)?'wide':'')}><span>{label}{required&&<span className="required-mark"> *</span>}</span>{['state','district','industry'].includes(key)?<select value={draft[key]} onChange={e=>setDraft({...draft,[key]:e.target.value,...(key==='state'?{district:''}:{})})}><option value="">Select {label.toLowerCase()}</option>{[...new Set([draft[key],...(key==='state'?master.states:key==='district'?districts:master.industries).map(v=>v.name)].filter(Boolean))].map(v=><option key={v}>{v}</option>)}</select>:['address','description'].includes(key)?<textarea value={draft[key]} onChange={e=>setDraft({...draft,[key]:e.target.value})} placeholder={`Enter ${label.toLowerCase()}`}/>:<input type={key==='website'?'url':key==='contact_phone'?'tel':'text'} inputMode={key==='pincode'?'numeric':undefined} value={draft[key]} required={required} onChange={e=>setDraft({...draft,[key]:key==='gstin'?e.target.value.toUpperCase():e.target.value})} placeholder={key==='website'?'https://company.example':`Enter ${label.toLowerCase()}`}/>}</label>;

}

function RegistrationDateField({label,value,onChange,min,max}) {

    const input=useRef(null), id=useId(), [open,setOpen]=useState(false),[month,setMonth]=useState(()=>new Date().getMonth()),[year,setYear]=useState(()=>new Date().getFullYear());

    const dateValue=(y,m,d)=>`${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;

    const allowed=date=>(!min||date>=min)&&(!max||date<=max);

    const toggleCalendar=()=>{if(!open){const date=value?new Date(`${value}T00:00:00`):new Date();setMonth(date.getMonth());setYear(date.getFullYear());}setOpen(!open);};

    const choose=date=>{onChange(date);setOpen(false);input.current?.focus();};

    const move=offset=>{const date=new Date(year,month+offset,1);setMonth(date.getMonth());setYear(date.getFullYear());};

    const firstDay=(new Date(year,month,1).getDay()+6)%7, days=new Date(year,month+1,0).getDate(), today=dateValue(new Date().getFullYear(),new Date().getMonth(),new Date().getDate());

    const years=Array.from({length:106},(_,i)=>new Date().getFullYear()-100+i);if(!years.includes(year))years.push(year);years.sort((a,b)=>a-b);

    return <div className="ea-date-field" onKeyDown={e=>{if(open&&e.key==='Escape'){e.stopPropagation();setOpen(false);input.current?.focus();}}}><label className="field-label" htmlFor={id}>{label}</label><div className="ea-date-control"><input ref={input} id={id} type="date" value={value} min={min} max={max} onChange={e=>onChange(e.target.value)}/><button type="button" onClick={toggleCalendar} aria-expanded={open} aria-controls={`${id}-calendar`} aria-label={`Open calendar for ${label.toLowerCase()}`} title="Choose date"><CalendarDays size={18}/></button></div>

    {open&&<div className="ea-calendar" id={`${id}-calendar`} role="group" aria-label={`Calendar for ${label.toLowerCase()}`}><div className="ea-calendar-head"><button type="button" aria-label="Previous month" onClick={()=>move(-1)}><ChevronLeft size={16}/></button><select aria-label="Calendar month" value={month} onChange={e=>setMonth(Number(e.target.value))}>{Array.from({length:12},(_,i)=><option key={i} value={i}>{new Date(2000,i,1).toLocaleDateString('en-IN',{month:'long'})}</option>)}</select><select aria-label="Calendar year" value={year} onChange={e=>setYear(Number(e.target.value))}>{years.map(y=><option key={y}>{y}</option>)}</select><button type="button" aria-label="Next month" onClick={()=>move(1)}><ChevronRight size={16}/></button></div><div className="ea-calendar-grid">{['Mo','Tu','We','Th','Fr','Sa','Su'].map(day=><span key={day}>{day}</span>)}{Array.from({length:firstDay},(_,i)=><span key={`blank-${i}`}/>)}{Array.from({length:days},(_,i)=>{const date=dateValue(year,month,i+1);return <button type="button" key={date} disabled={!allowed(date)} aria-label={`Choose ${date}`} aria-pressed={value===date} className={value===date?'selected':today===date?'today':''} onClick={()=>choose(date)}>{i+1}</button>;})}</div><div className="ea-calendar-footer"><button type="button" onClick={()=>choose('')}>Clear</button><button type="button" disabled={!allowed(today)} onClick={()=>choose(today)}>Today</button><button type="button" onClick={()=>setOpen(false)}>Close</button></div></div>}

    </div>;

}

function FilterDrawer({open,...props}) {

    const [present,setPresent]=useState(open);

    useEffect(()=>{if(open){setPresent(true);return;}const timer=setTimeout(()=>setPresent(false),240);return()=>clearTimeout(timer);},[open]);

    return <Modal {...props} open={open||present} className={`ea-filter-sidebar ${open?'ea-drawer-enter':'ea-drawer-exit'}`}/>;

}

const emptyFilters=()=>({status:[],industry:[],state:[],access:[],from:'',to:''});

export function employerListParams(filters,search,sort,direction,page,size) {

    return {search:search||undefined,statuses:filters.status.length?filters.status:undefined,industries:filters.industry.length?filters.industry:undefined,states:filters.state.length?filters.state:undefined,access:filters.access.length?filters.access:undefined,from:filters.from||undefined,to:filters.to||undefined,sort,direction,page,per_page:size};

}

export default function EmployerOrganizations() {

    const route=useLocation();

    const [rows,setRows]=useState([]),[loading,setLoading]=useState(true),[error,setError]=useState(''),[canManage,setCanManage]=useState(false);

    const [query,setQuery]=useState(''),[status,setStatus]=useState(new URLSearchParams(route.search).get('status')?[new URLSearchParams(route.search).get('status')]:[]),[industry,setIndustry]=useState([]),[state,setState]=useState([]),[from,setFrom]=useState(''),[to,setTo]=useState('');

    const [applied,setApplied]=useState(()=>({...emptyFilters(),status:new URLSearchParams(route.search).get('status')?[new URLSearchParams(route.search).get('status')]:[]})),[search,setSearch]=useState(''),[refresh,setRefresh]=useState(0),[meta,setMeta]=useState({total:0,current_page:1,last_page:1,from:null,to:null}),[counts,setCounts]=useState({all:0}),[options,setOptions]=useState({industries:[],states:[]});

    const [sort,setSort]=useState('created_at'),[direction,setDirection]=useState('desc'),[page,setPage]=useState(1),[size,setSize]=useState(25);

    const [selected,setSelected]=useState(null),[mode,setMode]=useState('view'),[draft,setDraft]=useState({}),[remarks,setRemarks]=useState(''),[busy,setBusy]=useState(false),[dialogError,setDialogError]=useState(''),[success,setSuccess]=useState(null);

    const {master,districts,masterError}=useEmployerMasters(draft.state);

    const [filtersOpen,setFiltersOpen]=useState(false),[access,setAccess]=useState([]),[checked,setChecked]=useState([]),[action,setAction]=useState(null),[actionIds,setActionIds]=useState([]),[actionRemarks,setActionRemarks]=useState(''),[actionError,setActionError]=useState('');

    const toggle=(values,value)=>values.includes(value)?values.filter(v=>v!==value):[...values,value];

    const reset=()=>{setQuery('');setSearch('');setStatus([]);setIndustry([]);setState([]);setAccess([]);setFrom('');setTo('');setApplied(emptyFilters());setPage(1);};

    const resetDraft=()=>{setStatus([]);setIndustry([]);setState([]);setAccess([]);setFrom('');setTo('');};

    const closeFilters=()=>setFiltersOpen(false);

    const openFilters=()=>{setStatus([...applied.status]);setIndustry([...applied.industry]);setState([...applied.state]);setAccess([...applied.access]);setFrom(applied.from);setTo(applied.to);setFiltersOpen(true);};

    const applyFilters=()=>{if(from&&to&&from>to)return;setApplied({status,industry,state,access,from,to});setSearch(query.trim());setPage(1);closeFilters();};

    const load=()=>setRefresh(value=>value+1);

    const submitting=useRef(false);
    const beginAction=(kind,ids=checked)=>{
        if(submitting.current)return;
        const record=selected;setSelected(null);setAction(kind);setActionIds(ids);
        setActionRemarks(ids.length===1&&record?.id===ids[0]?remarks:'');setActionError('');
    };
    const runAction=async()=>{
        if(submitting.current)return;
        if(action==='reject'&&!actionRemarks.trim()){setActionError('Enter a reason so the employer knows what to change.');return;}
        submitting.current=true;setBusy(true);setActionError('');
        try{
            await axios.post('/admin-api/employers/actions',{ids:actionIds,action,remarks:actionRemarks.trim()||null},{headers:csrf()});
            const count=actionIds.length, single=count===1;
            const messages={approve:[single?'Employer approved':'Employers approved',single?'The employer can now publish jobs.':`${count} employers can now publish jobs.`],reject:[single?'Profile rejected':'Profiles rejected',single?'Your reason has been saved for the employer to review and make changes.':`Your reason has been saved for all ${count} employers to review and make changes.`],activate:[single?'Profile activated':'Profiles activated',single?'The employer is active and awaiting review.':`${count} employers are active and awaiting review.`],disable:[single?'Profile disabled':'Profiles disabled',single?'Workspace access is disabled and published jobs are paused.':`Workspace access is disabled and published jobs are paused for ${count} employers.`]};
            setAction(null);setSelected(null);setChecked([]);setSuccess({action,title:messages[action][0],message:messages[action][1],count});load();window.dispatchEvent(new Event('admin-review-updated'));
        }catch(e){setActionError(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'The action could not be saved. Please try again.');}
        finally{submitting.current=false;setBusy(false);}
    };
    useEffect(()=>setChecked([]),[applied,search,page,size,sort,direction]);

    useEffect(()=>{setApplied(f=>({...f,status:new URLSearchParams(route.search).get('status')?[new URLSearchParams(route.search).get('status')]:[]}));setPage(1);},[route.search]);

    useEffect(()=>{

        const controller=new AbortController();setLoading(true);setError('');

        axios.get('/admin-api/employers',{params:employerListParams(applied,search,sort,direction,page,size),signal:controller.signal})

        .then(({data})=>{if(controller.signal.aborted)return;setRows(data.employers.map(e=>({...e,legal_name:e.legal_name||e.name,jobs_count:Number(e.jobs_count),hires_count:Number(e.hires_count)})));setCanManage(data.can_manage);setMeta(data.pagination);setCounts(data.counts);setOptions(data.filters);if(page>data.pagination.last_page)setPage(data.pagination.last_page);})

        .catch(e=>{if(e.code!=='ERR_CANCELED')setError(e.response?.status===403?'Your account needs employer viewing permission. Sign in with an authorized admin account.':e.response?.data?.message||'Employers could not be loaded.');})

        .finally(()=>{if(!controller.signal.aborted)setLoading(false);});

        return()=>controller.abort();

    },[applied,search,sort,direction,page,size,refresh]);

    const open=(record,edit=false)=>{setSelected(record);setMode(edit?'edit':'view');setDraft(Object.fromEntries(fields.map(([key])=>[key,record[key]||''])));setRemarks(record.review_remarks||'');setDialogError('');};

    useEffect(()=>{const id=new URLSearchParams(route.search).get('review');if(!id)return;const controller=new AbortController();axios.get(`/admin-api/employers/${id}`,{signal:controller.signal}).then(({data})=>open(data.employer)).catch(e=>{if(e.code!=='ERR_CANCELED')setError(e.response?.data?.message||'Employer could not be loaded.');});return()=>controller.abort();},[route.search]);

    const invalidRange=from&&to&&from>to;

    const current=meta.current_page,pages=meta.last_page,visible=rows;

    const params=employerListParams(applied,search,sort,direction,page,size);

    const exportUrl='/admin-api/employers/export?'+new URLSearchParams(Object.entries(params).flatMap(([key,value])=>value===undefined?[]:Array.isArray(value)?value.map(v=>[`${key}[]`,v]):[[key,value]])).toString();

    const activeFilterCount=applied.status.length+applied.industry.length+applied.state.length+applied.access.length+Number(!!applied.from)+Number(!!applied.to);

    const changeSort=key=>{setPage(1);setSort(key);setDirection(sort===key&&direction==='asc'?'desc':'asc');};

    const save=async()=>{
        if(submitting.current||!selected)return;
        submitting.current=true;setBusy(true);setDialogError('');
        try{
            await axios.patch(`/admin-api/employers/${selected.id}`,Object.fromEntries(Object.entries(draft).map(([k,v])=>[k,v.trim()||null])),{headers:csrf()});
            setSelected(null);setSuccess({action:'save',title:'Profile updated',message:'Company information has been saved.',count:1});load();window.dispatchEvent(new Event('admin-review-updated'));
        }catch(e){setDialogError(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'The profile could not be saved. Please try again.');}
        finally{submitting.current=false;setBusy(false);}
    };
    return <div className="employer-admin">

        <PageHead title="Employers" sub="Manage company profiles, review verification and track hiring activity."><button className="btn ghost" onClick={load} disabled={loading}><RefreshCw size={16}/>Refresh</button><a className="btn ghost" aria-disabled={loading||!!error||!meta.total} href={exportUrl} onClick={e=>{if(loading||error||!meta.total)e.preventDefault();}}><Download size={16}/>Export CSV</a><Link className="btn primary" to="/admin/employers/stats"><BarChart3 size={16}/>View stats</Link></PageHead>



        <div className="ea-summary">{[['All employers','all'],['Awaiting review','pending'],['Approved','verified'],['Rejected','rejected'],['Inactive','inactive']].map(([label,key])=>{const Icon=statusIcons[key],active=key==='all'?!applied.status.length:applied.status.includes(key);return <button key={key} aria-pressed={active} className={`ea-summary-${key} ${active?'active':''}`} onClick={()=>{setApplied(f=>({...f,status:key==='all'?[]:[key]}));setPage(1);}}><span className="ea-summary-icon"><Icon size={18}/></span><span className="ea-summary-label">{label}</span><strong>{loading?'—':counts[key]||0}</strong></button>;})}</div>

        <section className="ea-surface">

            <form className="ea-filters ea-toolbar" onSubmit={e=>{e.preventDefault();setSearch(query.trim());setPage(1);}}><label className="ea-search">Search employers<input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Company, code, contact, email or phone"/></label><button type="submit" className="btn ghost"><Search size={16}/>Search</button><button type="button" className="btn ghost" aria-haspopup="dialog" onClick={openFilters}><SlidersHorizontal size={16}/>Filters{activeFilterCount>0&&<span className="ea-filter-count">{activeFilterCount}</span>}</button><button type="button" className="btn ghost" onClick={reset}><RotateCcw size={16}/>Reset</button></form>

            {canManage&&checked.length>0&&<div className="ea-bulk"><b>{checked.length} selected</b><button className="btn ghost" disabled={busy||rows.some(e=>checked.includes(e.id)&&e.status==='inactive')} title="Approve selected active profiles" onClick={()=>beginAction('approve')}><CheckCircle2 size={16}/>Approve</button><button className="btn ghost" disabled={busy||rows.some(e=>checked.includes(e.id)&&e.status==='inactive')} title="Reject selected active profiles" onClick={()=>beginAction('reject')}><XCircle size={16}/>Reject</button><button className="btn ghost" disabled={busy||rows.some(e=>checked.includes(e.id)&&e.status!=='inactive')} title="Select inactive profiles to activate" onClick={()=>beginAction('activate')}><CheckCircle2 size={16}/>Activate</button><button className="btn ghost" disabled={busy} onClick={()=>beginAction('disable')}><Power size={16}/>Disable profiles</button><button className="btn ghost" disabled={busy} onClick={()=>setChecked([])}><X size={16}/>Clear selection</button></div>}

            <div className="ea-table-caption"><span><b>{meta.total}</b> matching employers <span className="ea-muted">· Job and hire counts are lifetime totals</span></span><label>Rows per page<select value={size} onChange={e=>{setSize(Number(e.target.value));setPage(1);}}>{[25,50,100].map(n=><option key={n}>{n}</option>)}</select></label></div>

            {error?<div className="ea-empty" role="alert">{error}<button className="btn ghost" onClick={load}>Retry</button></div>:loading?<div className="ea-empty" role="status">Loading employers…</div>:!rows.length?<div className="ea-empty">{counts.all?'No employers match these filters. Try clearing your search or resetting the filters.':'No employers registered yet. New company profiles will appear here for review.'}</div>:<div className="data-table ea-table"><table><thead><tr>{canManage&&<th className="ea-select-cell"><input type="checkbox" aria-label="Select employers on this page" checked={visible.length>0&&visible.every(e=>checked.includes(e.id))} ref={el=>{if(el)el.indeterminate=visible.some(e=>checked.includes(e.id))&&!visible.every(e=>checked.includes(e.id));}} onChange={event=>setChecked(event.target.checked?[...new Set([...checked,...visible.map(e=>e.id)])]:checked.filter(id=>!visible.some(e=>e.id===id)))}/></th>}{columns.map(([label,key])=><th key={key} aria-sort={sort===key?(direction==='asc'?'ascending':'descending'):'none'}><button onClick={()=>changeSort(key)}>{label}<ArrowDownUp size={13}/>{sort===key?<span>{direction==='asc'?'↑':'↓'}</span>:null}</button></th>)}<th>Actions</th></tr></thead><tbody>{visible.map(e=><tr key={e.id} className={checked.includes(e.id)?'ea-selected':''}>{canManage&&<td className="ea-select-cell"><input type="checkbox" aria-label={`Select ${e.legal_name}`} checked={checked.includes(e.id)} onChange={()=>setChecked(toggle(checked,e.id))}/></td>}<td><button className="table-record-link" onClick={()=>open(e)}>{e.legal_name}</button><small>{e.code}</small></td><td>{e.industry||'Not provided'}<small>{[e.district,e.state].filter(Boolean).join(', ')||'Location not provided'}</small></td><td>{e.contact_name||'No contact name'}<small>{e.email||'No email'}</small><small>{e.contact_phone||e.phone||'No phone'}</small></td><td><EmployerStatus status={e.status}/><small>{e.document_url?'Proof uploaded':'Proof missing'}</small></td><td className="ea-number">{e.jobs_count}</td><td className="ea-number">{e.hires_count}</td><td className="ea-date">{dateLabel(e.created_at)}</td><td><div className="ea-row-actions"><button className="table-row-action" title="View and review" aria-label={`View and review ${e.legal_name}`} onClick={()=>open(e)}><Eye/></button>{canManage&&e.status==='pending'&&<><button className="table-row-action ea-approve-action" title="Approve profile" data-tooltip="Approve profile" aria-label={`Approve ${e.legal_name}`} onClick={()=>beginAction('approve',[e.id])}><CheckCircle2/></button><button className="table-row-action ea-reject-action" title="Reject profile" data-tooltip="Reject profile" aria-label={`Reject ${e.legal_name}`} onClick={()=>beginAction('reject',[e.id])}><XCircle/></button></>}{canManage&&<button disabled={e.status==='inactive'} className="table-row-action" title="Edit profile" aria-label={`Edit ${e.legal_name}`} onClick={()=>open(e,true)}><Pencil/></button>}{canManage&&<button className="table-row-action" title={e.status==='inactive'?'Activate profile':'Disable profile'} aria-label={`${e.status==='inactive'?'Activate':'Disable'} ${e.legal_name}`} onClick={()=>beginAction(e.status==='inactive'?'activate':'disable',[e.id])}><Power/></button>}</div></td></tr>)}</tbody></table></div>}

            <footer className="ea-pagination"><span>{meta.total?`${meta.from||0} - ${meta.to||0} of ${meta.total}`:'0 employers'}</span><div><button className="btn ghost" disabled={loading||current===1} onClick={()=>setPage(current-1)} aria-label="Previous page"><ChevronLeft size={16}/></button><span>Page {current} of {pages}</span><button className="btn ghost" disabled={loading||current>=pages} onClick={()=>setPage(current+1)} aria-label="Next page"><ChevronRight size={16}/></button></div></footer>

        </section>

        <FilterDrawer open={filtersOpen} onClose={closeFilters} title="Filter employers" sub="Choose multiple values in each group. Click Apply filters to update the list." footer={<><button className="btn ghost" onClick={resetDraft}><RotateCcw size={16}/>Reset filters</button><button className="btn primary" disabled={!!invalidRange} onClick={applyFilters}><SlidersHorizontal size={16}/>Apply filters</button></>}>

            {[['Profile access',access,setAccess,[['active','Active'],['inactive','Inactive / disabled']]],['Status',status,setStatus,Object.entries(statusLabels)],['Industry',industry,setIndustry,options.industries.map(v=>[v,v])],['State',state,setState,options.states.map(v=>[v,v])]].map(([label,values,setValues,options])=><fieldset className="ea-filter-group" key={label}><legend>{label}<span>{values.length?`${values.length} selected`:'All'}</span></legend><div>{options.map(([value,text])=><label key={value}><input type="checkbox" checked={values.includes(value)} onChange={()=>setValues(toggle(values,value))}/>{text}</label>)}{!options.length&&<p>No options available.</p>}</div></fieldset>)}

            <div className="ea-filter-dates"><RegistrationDateField label="Registered from" value={from} max={to||undefined} onChange={setFrom}/><RegistrationDateField label="Registered to" value={to} min={from||undefined} onChange={setTo}/></div><p className="ea-date-hint">Choose dates from the calendar or type day, month and year. Both dates are included.</p>{invalidRange&&<p className="ea-error" role="alert">End date must be on or after start date.</p>}



        </FilterDrawer>

        <Modal open={!!action} onClose={()=>{if(!busy)setAction(null);}} title={`${({approve:'Approve',reject:'Reject',activate:'Activate',disable:'Disable'})[action]} ${actionIds.length} profile${actionIds.length===1?'':'s'}`} sub="Review the selected employers before saving." footer={<><button className="btn ghost" disabled={busy} onClick={()=>setAction(null)}><X size={16}/>Cancel</button><button className="btn primary" disabled={busy||!actionIds.length||actionIds.length>100} onClick={runAction}><ActionIcon action={action}/>{busy?'Saving...':({approve:'Approve profile'+(actionIds.length===1?'':'s'),reject:'Reject profile'+(actionIds.length===1?'':'s'),activate:'Activate profile'+(actionIds.length===1?'':'s'),disable:'Disable profile'+(actionIds.length===1?'':'s')})[action]}</button></>}>

            <p className="ea-edit-note">{action==='disable'?'Disabled employers cannot access their workspace. Published jobs will be paused.':action==='activate'?'Inactive profiles return to awaiting review. Review and approve them before they can publish jobs.':action==='approve'?'Approve the selected profiles as an administrator. Employers can publish jobs after approval.':'Rejection pauses published jobs. Explain the changes employers must make.'}</p><ul className="ea-action-list">{rows.filter(e=>actionIds.includes(e.id)).map(e=><li key={e.id}>{e.legal_name}<div className="ea-action-meta"><span className="ea-employer-code">{e.code}</span><EmployerStatus status={e.status}/></div></li>)}</ul>{actionIds.length>100&&<p className="ea-error">Select up to 100 profiles per action.</p>}{action==='reject'&&<label className="field-label">Reason for rejection *<textarea required disabled={busy} maxLength={255} value={actionRemarks} onChange={e=>setActionRemarks(e.target.value)} placeholder="Explain required profile changes"/></label>}{actionError&&<p className="ea-error" role="alert">{actionError}</p>}

        </Modal>

        <Modal className="ea-success-dialog" open={!!success} onClose={()=>setSuccess(null)} title={success?.title||'Action saved'} sub="Employer management" footer={<button className="btn primary" onClick={()=>setSuccess(null)}><CheckCircle2 size={16}/>Done</button>}>
            {success&&<div className="ea-success-content"><span className="ea-success-icon"><CheckCircle2 size={36}/></span><h3>{success.title}</h3><p>{success.message}</p><div className="ea-success-result"><ActionIcon action={success.action}/><span>{success.count} {success.count===1?'profile':'profiles'} updated successfully</span></div></div>}
        </Modal>
        <Modal className="ea-profile-dialog" open={!!selected} onClose={()=>{if(!busy)setSelected(null);}} title={mode==='edit'?'Edit employer profile':'Employer profile'} sub={mode==='edit'?'Update company information and contact details.':'Company information, verification proof and hiring activity.'} footer={<><button className="btn ghost" disabled={busy} onClick={()=>setSelected(null)}><X size={16}/>Close</button>{canManage&&selected&&(mode==='edit'?<button className="btn primary" disabled={busy} onClick={()=>save()}><Save size={16}/>{busy?'Saving…':'Save profile'}</button>:<><button className="btn ghost" disabled={busy} onClick={()=>beginAction(selected.status==='inactive'?'activate':'disable',[selected.id])}><Power size={16}/>{selected.status==='inactive'?'Activate':'Disable'}</button><button className="btn ghost" disabled={busy||selected.status==='inactive'} onClick={()=>setMode('edit')}><Pencil size={16}/>Edit profile</button><button className="btn ghost ea-reject-button" disabled={busy||selected.status==='inactive'} onClick={()=>beginAction('reject',[selected.id])}><XCircle size={16}/>Reject profile</button><button className="btn primary" disabled={busy||selected.status==='verified'||selected.status==='inactive'} onClick={()=>beginAction('approve',[selected.id])}><CheckCircle2 size={16}/>{busy?'Saving…':'Approve employer'}</button></>)}</>}>

            {selected&&<><div className="ea-profile-identity"><span className="ea-company-avatar"><Building2 size={24}/></span><div><h3>{selected.legal_name||selected.name}</h3><p>{selected.code} · {selected.industry||'Industry not provided'}</p></div><EmployerStatus status={selected.status}/></div>

            <div className="ea-profile-layout"><div className="ea-profile-main">{mode==='edit'?<><div className="ea-profile-warning"><CircleAlert size={18}/><p>Changes require a new approval and pause published jobs. Account email and login phone are managed separately.</p></div>{masterError&&<p className="ea-error" role="alert">{masterError}</p>}{[[Building2,'Company details',['name','legal_name','industry','website','gstin','description']],[MapPin,'Registered address',['state','district','pincode','address']],[UserRound,'Primary contact',['contact_name','contact_designation','contact_department','contact_phone']]].map(([Icon,title,keys])=><section className="ea-profile-section" key={title}><h3><Icon size={18}/>{title}</h3><div className="ea-edit-grid">{keys.map(key=><ProfileEditField key={key} field={fields.find(f=>f[0]===key)} draft={draft} setDraft={setDraft} master={master} districts={districts}/>)}</div></section>)}</>:<><DetailSection icon={Building2} title="Company details" items={[

                ['Display name',selected.name],['Industry',selected.industry],['Website',/^https?:\/\//i.test(selected.website||'')?<a href={selected.website} target="_blank" rel="noopener noreferrer">{selected.website}</a>:selected.website],['GSTIN',selected.gstin],['About the company',selected.description,true]

            ]}/><DetailSection icon={MapPin} title="Registered address" items={[["Address",selected.address,true],['District',selected.district],['State',selected.state],['Pincode',selected.pincode]]}/><DetailSection icon={UserRound} title="Primary contact" items={[["Contact name",selected.contact_name],['Designation',selected.contact_designation],['Department',selected.contact_department],['Phone',selected.contact_phone||selected.phone],['Email',selected.email,true]]}/><section className="ea-profile-section"><h3><ShieldCheck size={18}/>Review decision</h3><p className="ea-section-help">Check the company details and proof before approving. Include a reason when rejecting.</p><label className="field-label">Review remarks<textarea value={remarks} disabled={busy||!canManage} maxLength={255} onChange={e=>setRemarks(e.target.value)} placeholder="Enter approval notes or explain required changes"/></label></section></>}

            </div><aside className="ea-profile-aside"><section className="ea-profile-section"><h3><ShieldCheck size={18}/>Verification document</h3><EmployerDocument key={selected.document_url} record={selected}/><p className="ea-section-help">Review the available details and document. Administrators can approve profiles even when some details are missing.</p></section><section className="ea-profile-section"><h3><BriefcaseBusiness size={18}/>Hiring activity</h3><div className="ea-profile-activity"><div><strong>{selected.jobs_count||0}</strong><span>Published jobs</span></div><div><strong>{selected.hires_count||0}</strong><span>Candidates joined</span></div></div></section><DetailSection icon={ShieldCheck} title="Account history" items={[["Registered",dateLabel(selected.created_at)],['Approved on',dateLabel(selected.verified_at)],['Terms accepted',dateLabel(selected.terms_accepted_at)],['Privacy accepted',dateLabel(selected.privacy_accepted_at)]]}/></aside></div>{dialogError&&<p className="ea-error" role="alert">{dialogError}</p>}</>}

        </Modal>



    </div>;

}



