import React,{useState} from 'react';
import {NavLink,Outlet,useNavigate} from 'react-router-dom';
import {Bell,BriefcaseBusiness,CalendarDays,ChevronDown,FileText,Heart,HelpCircle,LockKeyhole,LogOut,Menu,Search,Settings,ShieldCheck,UserRound,UserCheck,X} from 'lucide-react';
import BrandLogo from '../components/BrandLogo.jsx';
import '../candidate2.css';

const links=[['Find jobs','opportunities',BriefcaseBusiness],['My interests','interests',Heart],['Interviews','interviews',CalendarDays],['Joining','joining',UserCheck],['My profile','profile',UserRound],['Resume','resume',FileText],['Preferences','consent',ShieldCheck],['Privacy','privacy',LockKeyhole]];

export default function Candidate2Layout(){
  const navigate=useNavigate();
  const [menu,setMenu]=useState(false),[account,setAccount]=useState(false),[query,setQuery]=useState('');
  function submitSearch(e){e.preventDefault();navigate('/candidate2/interests'+(query?'?q='+encodeURIComponent(query):''));setMenu(false)}
  return <div className="candidate2-shell">
    <header className="candidate2-header">
      <div className="candidate2-header-inner">
        <NavLink to="/candidate2/interests" className="candidate2-brand"><BrandLogo/><span>Candidate portal</span></NavLink>
        <form className="candidate2-search" onSubmit={submitSearch}><Search size={18}/><input aria-label="Search my interests" placeholder="Search your interests" value={query} onChange={e=>setQuery(e.target.value)}/><kbd>↵</kbd></form>
        <div className="candidate2-header-actions"><button className="candidate2-icon-button" aria-label="View interviews" title="View interviews" onClick={()=>navigate('/candidate2/interviews')}><Bell size={20}/><i/></button><div className="candidate2-account-wrap"><button className="candidate2-account" aria-expanded={account} onClick={()=>setAccount(!account)}><span>NK</span><b>Neha Kumari<small>Candidate</small></b><ChevronDown size={16}/></button>{account&&<div className="candidate2-account-menu"><NavLink to="profile" onClick={()=>setAccount(false)}><UserRound size={16}/> My profile</NavLink><NavLink to="privacy" onClick={()=>setAccount(false)}><Settings size={16}/> Account settings</NavLink><button onClick={()=>navigate('/login')}><LogOut size={16}/> Log out</button></div>}</div><button className="candidate2-mobile-toggle" aria-label={menu?'Close menu':'Open menu'} onClick={()=>setMenu(!menu)}>{menu?<X/>:<Menu/>}</button></div>
      </div>
    </header>
    <nav className={'candidate2-nav '+(menu?'open':'')} aria-label="Candidate navigation"><div className="candidate2-nav-inner">{links.map(([label,path,Icon])=><NavLink key={label} to={path} end className={({isActive})=>isActive?'active':''} onClick={()=>setMenu(false)}><Icon size={17}/><span>{label}</span></NavLink>)}<NavLink to="retention" onClick={()=>setMenu(false)} className={({isActive})=>isActive?'active':''}><HelpCircle size={17}/><span>Support</span></NavLink></div></nav>
    <main className="candidate2-main"><Outlet/></main>
  </div>
}
