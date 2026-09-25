import React,{useRef,useState}from'react';
import{Download,Printer,Camera,Eye,GripVertical,Plus,Save,FileText,CheckCircle2,MapPin,Phone,Mail}from'lucide-react';
import{PageHead}from'../../components/UI';
import{useToast}from'../../App';

export default function ResumeBuilder(){
  const toast=useToast(),[photo,setPhoto]=useState(true),[template,setTemplate]=useState('modern'),[accent,setAccent]=useState('#315be8'),[summary,setSummary]=useState('Motivated and safety-conscious Machine Operator trained in production processes, 5S and basic quality inspection. Ready to contribute to a high-performing manufacturing team.'),[skills,setSkills]=useState(['Machine operation','5S & workplace safety','Basic measurements','Quality inspection','Team collaboration']),ref=useRef();
  const addSkill=()=>setSkills([...skills,'New skill']);
  return <>
    <PageHead kicker="PROFILE-TO-RESUME" title="Resume builder" sub="Create a professional resume from your verified EXOWORK profile."><button className="btn ghost" onClick={()=>window.print()}><Printer/>Print</button><button className="btn primary" onClick={()=>toast('Resume PDF prepared for download')}><Download/>Download PDF</button></PageHead>
    <div className="resume-builder">
      <aside className="resume-quality resume-readiness-bar">
        <div className="readiness-score-block"><h3>Resume readiness</h3><div className="resume-score"><b>94%</b><i><em/></i></div></div>
        <ul><li><CheckCircle2/>Contact details complete</li><li><CheckCircle2/>Skills added</li><li><CheckCircle2/>Training verified</li><li><CheckCircle2/>Education included</li><li><CheckCircle2/>Privacy controls reviewed</li></ul>
        <div className="resume-tip"><FileText/><p><b>Recruiter tip</b>Keep your summary short and use skills you can demonstrate in an interview.</p></div>
      </aside>
      <aside className="resume-tools">
        <section><h3>Template</h3><p>Choose how your resume will look.</p><div className="template-options">{['modern','classic','compact'].map(x=><button key={x} className={template===x?'active':''} onClick={()=>setTemplate(x)}><span className={'template-mini '+x}/><b>{x}</b></button>)}</div></section>
        <section><h3>Photo & privacy</h3><div className="resume-setting"><span><Camera/></span><div><b>Show profile photo</b><small>{photo?'Included on resume':'Hidden from resume'}</small></div><label className="switch"><input type="checkbox" checked={photo} onChange={e=>setPhoto(e.target.checked)}/><i/></label></div><p className="tool-note">Your photo remains optional and does not affect matching eligibility.</p></section>
        <section><h3>Accent color</h3><div className="color-options">{['#315be8','#176f63','#7c4fc5','#b35b36','#172443'].map(c=><button key={c} aria-label={'Use accent color '+c} style={{background:c}} className={accent===c?'active':''} onClick={()=>setAccent(c)}/>)}</div></section>
        <section><h3>Sections</h3>{['Professional summary','Skills','Training','Education','Languages'].map(x=><div className="section-order" key={x}><GripVertical/><span>{x}</span><Eye/></div>)}</section>
        <button className="btn ghost block" onClick={()=>toast('Resume draft saved')}><Save/>Save resume draft</button>
      </aside>
      <main className="resume-preview-wrap"><div className={'resume-document '+template} ref={ref} style={{'--resume-accent':accent}}>
        <header>{photo&&<div className="resume-photo">NK</div>}<div><h1>Neha Kumari</h1><h2>Machine Operator</h2><p><MapPin/> Jaipur, Rajasthan <Phone/> +91 98••• ••548 <Mail/> neha@example.com</p></div></header>
        <section><h3>Professional summary</h3><textarea value={summary} onChange={e=>setSummary(e.target.value)}/></section>
        <section><h3>Core skills</h3><div className="resume-skills">{skills.map((x,i)=><input key={i} value={x} onChange={e=>setSkills(skills.map((s,j)=>j===i?e.target.value:s))}/>)}</div><button className="resume-add" onClick={addSkill}><Plus/>Add skill</button></section>
        <div className="resume-columns"><section><h3>Training</h3><article><b>Machine Operator - NSQF Level 4</b><span>ABC Skill Development Center</span><small>Batch MAN-26-08 · 320 hours · 2026</small><p>Hands-on training in machine setup, safe operation, 5S, measurements and basic quality checks.</p></article></section><section><h3>Education</h3><article><b>Senior Secondary (12th)</b><span>Rajasthan Board</span><small>Completed 2023</small></article></section></div>
        <section><h3>Additional information</h3><div className="resume-info"><span><b>Languages</b>Hindi (fluent), English (basic)</span><span><b>Preferred location</b>Jaipur, Ajmer</span><span><b>Relocation</b>Within Rajasthan</span><span><b>Availability</b>After 30 Sep 2026</span></div></section>
        <footer><span>EXOWORK ID · EXO-CAN-102548</span><span><CheckCircle2/>Profile information verified</span></footer>
      </div></main>
    </div>
  </>;
}
