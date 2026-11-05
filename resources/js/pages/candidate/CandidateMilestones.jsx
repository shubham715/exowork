import React from 'react';
import {Link} from 'react-router-dom';
import {UserRoundCheck, FileCheck2, GraduationCap, BriefcaseBusiness, BadgeCheck, Building2, ArrowUpRight, ArrowRight, Check, Sparkles, CalendarDays} from 'lucide-react';
import './candidate-milestones.css';

const definitions = [['Registered',UserRoundCheck],['Profile ready',FileCheck2],['Training',GraduationCap],['Applying',BriefcaseBusiness],['Hired',BadgeCheck],['Working',Building2]];
const training = {completed:'Training completed',upcoming:'Yet to start',in_progress:'Training in progress',finishing_soon:'Finishing within 15 days',awaiting_confirmation:'Completion to be confirmed',cancelled:'Batch cancelled',not_required:'Not enrolled'};
const formatDate = value => value ? new Date(value+'T00:00:00').toLocaleDateString('en-IN',{day:'numeric',month:'short'}) : '';

export default function CandidateMilestones({journey, profile, summary}) {
    if (!journey) return null;
    const percent = Math.round(journey.completed_steps / journey.total_steps * 100);
    const next = profile.completion < 100 ? {title:'A stronger profile opens more doors.',description:'Add your missing details so the right opportunities can find you.',label:'Complete my profile',path:'profile'}
        : summary.check_ins_due ? {title:'How is your new role going?',description:'Share a quick employment check-in. Your team is here to support you.',label:'Share an update',path:'retention'}
        : journey.working ? {title:'You made it. Keep growing.',description:'Your employment journey continues. Stay connected and let us know how you are doing.',label:'My employment check-ins',path:'retention'}
        : journey.hired ? {title:'Your next chapter is almost here.',description:'Review your selection details and keep your joining plans up to date.',label:'View joining details',path:'joining'}
        : summary.applications ? {title:'Your opportunities are in motion.',description:'Follow your applications and keep an eye on interview updates.',label:'Track my applications',path:'interests'}
        : {title:'Ready to take your next step?',description:'Explore opportunities that match your skills and start your job search.',label:'Explore opportunities',path:'opportunities'};
    const details = ['Your journey has started',`${profile.completion}% profile complete`,training[journey.training_state],summary.applications?`${summary.applications} application${summary.applications===1?'':'s'} submitted`:'Your next opportunity awaits',journey.hired?'Selection recorded':'A new chapter ahead',journey.working?'Joined / employed':'Build a lasting career'];
    const current = journey.steps.findIndex((done,i)=>!done && !(i===2 && journey.training_state==='not_required'));
    return <section className="cm-journey" aria-labelledby="cm-title">
        <header className="cm-header"><div className="cm-header-copy"><span className="cm-eyebrow"><Sparkles size={13}/> YOUR CAREER, TAKING SHAPE</span><h2 id="cm-title">Small steps. A brighter future.</h2><p>Every milestone brings you closer to where you want to be.</p></div><div className="cm-progress"><div className="cm-progress-ring" style={{'--cm-progress':`${percent}%`}} role="progressbar" aria-label="Career milestones completed" aria-valuemin={0} aria-valuemax={journey.total_steps} aria-valuenow={journey.completed_steps}><span>{percent}<small>%</small></span></div><div><b>{journey.completed_steps} of {journey.total_steps} milestones</b><span>{percent===100?'Look how far you have come':'Your progress, one step at a time'}</span></div></div></header>
        <div className="cm-timeline-wrap"><ol className="cm-timeline" aria-label="Your career milestones">{definitions.map(([label,Icon],i)=>{const skipped=i===2 && journey.training_state==='not_required',done=journey.steps[i],active=i===current;return <li key={label} className={`${done?'cm-done':''} ${active?'cm-current':''} ${skipped?'cm-skipped':''} ${i===2?'cm-training-'+journey.training_state:''}`} aria-current={active?'step':undefined}><span className="cm-connector" aria-hidden="true"/><span className="cm-step-number">0{i+1}</span><span className="cm-node"><Icon size={23}/>{done&&<i><Check size={10}/></i>}</span><strong>{label}</strong><small>{details[i]}</small><span className="cm-step-status">{skipped?'Optional':done?'Completed':active?'In focus':'Ahead of you'}</span></li>;})}</ol></div>
        {journey.batch_code && <div className="cm-batch"><GraduationCap size={15}/><b>{journey.batch_code}</b><span className={'cm-training-tag '+journey.training_state}>{training[journey.training_state]}</span>{journey.ends_on && <span className="cm-batch-date"><CalendarDays size={13}/>{journey.training_state==='completed'?'Batch end':'Scheduled end'}: {formatDate(journey.ends_on)}</span>}<Link to="/candidate/profile" aria-label="View your training details"><ArrowUpRight size={15}/></Link></div>}
        <footer className="cm-next"><span className="cm-next-icon"><ArrowRight size={19}/></span><div><span>YOUR NEXT MOVE</span><h3>{next.title}</h3><p>{next.description}</p></div><Link to={'/candidate/'+next.path}>{next.label}<ArrowUpRight size={16}/></Link></footer>
    </section>;
}
