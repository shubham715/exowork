import BrandLogo from '../components/BrandLogo.jsx';
import React, {useEffect, useRef, useState} from 'react';
import axios from 'axios';
import {NavLink, Outlet, useLocation, useNavigate} from 'react-router-dom';
import {LayoutDashboard, Users, Building2, BriefcaseBusiness, CalendarDays, UserCheck, HeartHandshake, BarChart3, Settings, Bell, ChevronDown, LogOut, Menu, X, Layers3, MessageCircle, Waypoints, ShieldCheck, RefreshCw} from 'lucide-react';
import './admin-review.css';
const groups = [
  ['Overview', [[LayoutDashboard,'Dashboard','dashboard']]],
  ['Training partners', [[Building2,'Partners & training centers','centers'],[Layers3,'Training batches','batches'],[BarChart3,'Batch statistics','batch-stats']]],
  ['Candidates / students', [[Users,'All candidates / students','candidates'],[UserCheck,'Register candidate','candidates/new']]],
  ['Employers', [[Building2,'Employer directory','employers'],[BarChart3,'Employer statistics','employers/stats'],[BriefcaseBusiness,'Jobs & requirements','jobs']]],
  ['Placement operations', [[Waypoints,'Candidate matching','matching'],[CalendarDays,'Interviews','interviews'],[UserCheck,'Selection & joining','joining'],[HeartHandshake,'Retention & follow-up','retention']]],
  ['Communication', [[MessageCircle,'Calls & follow-ups','crm'],[MessageCircle,'WhatsApp templates','whatsapp/templates'],[MessageCircle,'WhatsApp message logs','whatsapp/logs']]],
  ['Website management', [[MessageCircle,'Website enquiries','enquiries'],[Layers3,'Homepage banners','hero-slides']]],
  ['Administration', [[BarChart3,'Reports & analytics','reports'],[Layers3,'Data management','data-management'],[Settings,'Platform settings','settings']]],
];
const matchesPath = (pathname, to) => pathname === '/admin/'+to || pathname.startsWith('/admin/'+to+'/');
function NavigationGroup({heading,items,pathname,queue,onNavigate}) {
  const active=items.some(([, ,to])=>matchesPath(pathname,to));
  const [expanded,setExpanded]=useState(true);
  const id='admin-nav-'+heading.toLowerCase().replace(/[^a-z]+/g,'-');
  useEffect(()=>{if(active)setExpanded(true)},[pathname,active]);
  const visible=items.filter(([, ,to])=>!queue?.permissions||!['centers','employers'].includes(to.split('/')[0])||queue.permissions[to.split('/')[0]]);
  if(!visible.length)return null;
  return <section className={'nav-group admin-nav-group'+(active?' has-active':'')}>
    <button className="admin-nav-heading" aria-expanded={expanded} aria-controls={id} onClick={()=>setExpanded(v=>!v)}><span>{heading}</span><ChevronDown size={14} style={{transform:expanded?'rotate(0deg)':'rotate(-90deg)'}}/></button>
    <div id={id} hidden={!expanded}>{visible.map(([Icon,label,to])=><NavLink key={to} to={to} end={items.some(([, ,other])=>other.startsWith(to+'/'))} onClick={onNavigate} className={({isActive})=>isActive?'active':''}><Icon size={18}/><span>{label}</span>{queue?.counts?.[to]>0&&<em className="admin-pending-count" aria-label={`${queue.counts[to]} profiles pending verification`}>{queue.counts[to]}</em>}</NavLink>)}</div>
  </section>;
}
export default function AdminLayout() {
  const [open,setOpen]=useState(false),[profile,setProfile]=useState(false),[notifications,setNotifications]=useState(false),[queue,setQueue]=useState(null),[error,setError]=useState(''),[reading,setReading]=useState(false),[logoutError,setLogoutError]=useState('');
  const nav=useNavigate(),loc=useLocation(),notificationRef=useRef(null),requesting=useRef(false),mounted=useRef(true);
  const load = async () => {
    if(requesting.current)return;
    requesting.current=true;
    try {const {data}=await axios.get('/admin-api/review-notifications');if(mounted.current){setQueue(data);setError('');}}
    catch(e){if(mounted.current)setError(e.response?.status===403?'Your account cannot view verification notifications.':'Verification counts could not be refreshed.');}
    finally{requesting.current=false;}
  };
  useEffect(()=>{
    mounted.current=true;load();
    const refresh=()=>{if(document.visibilityState==='visible')load();};
    const timer=setInterval(refresh,30000);
    window.addEventListener('focus',refresh);document.addEventListener('visibilitychange',refresh);window.addEventListener('admin-review-updated',refresh);
    return()=>{mounted.current=false;clearInterval(timer);window.removeEventListener('focus',refresh);document.removeEventListener('visibilitychange',refresh);window.removeEventListener('admin-review-updated',refresh);};
  },[]);
  useEffect(()=>{setNotifications(false);setProfile(false);load();},[loc.pathname]);
  useEffect(()=>{
    if(!notifications)return;
    const outside=e=>{if(!notificationRef.current?.contains(e.target))setNotifications(false);};
    const escape=e=>{if(e.key==='Escape'){setNotifications(false);notificationRef.current?.querySelector('button')?.focus();}};
    document.addEventListener('pointerdown',outside);document.addEventListener('keydown',escape);
    return()=>{document.removeEventListener('pointerdown',outside);document.removeEventListener('keydown',escape);};
  },[notifications]);
  const markRead=async items=>{
    if(!items.length||reading)return;
    setReading(true);
    try{const {data}=await axios.post('/admin-api/review-notifications/read',{items:items.map(({kind,id,version})=>({kind,id,version}))},{headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content}});setQueue(data);setError('');}
    catch{setError('Could not mark notifications as read. Try again.');}
    finally{setReading(false);}
  };
  const title=groups.flatMap(x=>x[1]).sort((a,b)=>b[2].length-a[2].length).find(x=>matchesPath(loc.pathname,x[2]))?.[1]||'Operations';
  const name=queue?.user?.name||'Administrator',initials=name.split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase();
  return <div className="admin-shell"><aside className={'sidebar '+(open?'mobile-open':'')}><div className="brand"><BrandLogo/><button className="mobile-close" aria-label="Close sidebar" onClick={()=>setOpen(false)}><X size={18}/></button></div><nav aria-label="Admin navigation">{groups.map(([heading,items])=><NavigationGroup key={heading} heading={heading} items={items} pathname={loc.pathname} queue={queue} onNavigate={()=>setOpen(false)}/>)}</nav><div className="admin-review-sidebar"><ShieldCheck/><div><b>Profile verification</b><small>{queue?`${queue.counts.centers+queue.counts.employers} profiles awaiting review`:'Review company and center profiles'}</small></div></div></aside><main className="admin-main"><header className="topbar"><div className="top-title"><button className="menu-btn" aria-label="Open sidebar" onClick={()=>setOpen(true)}><Menu/></button><small>EXOWORK CONTROL CENTER</small><h2>{title}</h2></div><div className="top-actions"><div className="admin-notifications" ref={notificationRef}><button className="circle-btn" aria-label={`Admin notifications${queue?`, ${queue.unread_count} unread`:''}`} aria-expanded={notifications} aria-controls="admin-notification-list" onClick={()=>{setNotifications(v=>!v);setProfile(false);load();}}><Bell size={18}/>{queue?.unread_count>0&&<span className="admin-notification-count">{queue.unread_count>99?'99+':queue.unread_count}</span>}</button>{notifications&&<section id="admin-notification-list" className="admin-notification-panel" aria-label="Admin notifications"><header><div><h3>Admin inbox</h3><p>{queue?`${queue.unread_count} unread · ${queue.counts.centers+queue.counts.employers} pending profiles`:'Loading notifications…'}</p></div><button className="admin-notification-refresh" aria-label="Refresh notifications" onClick={load}><RefreshCw size={18}/></button></header>{error&&<p className="admin-notification-error" role="alert">{error}<button onClick={load}>Retry</button></p>}<div className="admin-notification-items">{queue?.items?.map(item=><NavLink className={item.unread?'unread':''} key={`${item.kind}-${item.id}-${item.version}`} to={item.url} onClick={()=>{if(item.unread)markRead([item]);setNotifications(false);}}><span className="admin-notification-icon"><Building2 size={18}/></span><div><b>{item.name}</b><p>{item.kind==='candidates'?'New candidate registered':`${item.kind==='centers'?'Training center':'Employer'} awaiting verification`}</p><small>{new Date(item.version.replace(' ','T')+'Z').toLocaleString('en-IN',{timeZone:'Asia/Kolkata',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})} IST · Open review</small></div>{item.unread&&<span className="admin-unread-dot" aria-label="Unread"/>}</NavLink>)}{queue&&!queue.items.length&&!error&&<p className="admin-notification-empty">No new registrations or profiles are awaiting attention.</p>}</div><footer><button disabled={reading||!queue?.items.some(i=>i.unread)} onClick={()=>markRead(queue.items.filter(i=>i.unread))}>{reading?'Saving…':'Mark displayed as read'}</button></footer></section>}</div><div className="profile-wrap"><button className="profile-btn" aria-expanded={profile} onClick={()=>{setProfile(!profile);setNotifications(false);}}><span>{initials}</span><div><b>{name}</b><small>Administration</small></div><ChevronDown size={15}/></button>{profile&&<div className="profile-menu"><div><b>{name}</b><small>{queue?.user?.email}</small></div><NavLink to="settings"><Settings size={16}/>Account settings</NavLink><button onClick={async()=>{try{await axios.post('/auth/logout',{}, {headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content}});nav('/admin225',{replace:true});}catch{setLogoutError('Could not sign out. Try again.');}}}><LogOut size={16}/>Log out</button>{logoutError&&<p role="alert">{logoutError}</p>}</div>}</div></div></header><div className="admin-page"><Outlet/></div></main></div>;
}
