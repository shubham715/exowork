import React, { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { ArrowDown, ArrowLeft, ArrowRight, BriefcaseBusiness, CalendarCheck, Check, ChevronDown, ClipboardList, GraduationCap, Layers3, MapPin, ShieldCheck, Sparkles, Target, UserRound, Users } from "lucide-react";
import PublicHeader from "../../features/public-site/components/PublicHeader.jsx";
import PublicFooter from "../../features/public-site/components/PublicFooter.jsx";
import "./how-it-works.css";

const guides = {
  employer: {
    label: "Employers", tagline: "Build your hiring pipeline", icon: BriefcaseBusiness,
    heading: "Go from a hiring need to a confirmed joiner.",
    description: "Share exactly what the role needs, track interviews, and see what happens after a candidate is selected.",
    action: "Register as an employer", path: "/register/employer",
    steps: [
      [ShieldCheck, "Verify your company", "Create your account and complete your company profile. EXOWORK reviews the business details before your team can publish jobs.", "Company profile", "Awaiting review", ["Company and hiring contact", "Business verification details", "Publishing unlocked after approval"]],
      [ClipboardList, "Publish a clear requirement", "Add the location, openings, salary range, skills and screening questions. Save a draft while your team finalises the details.", "Machine Operator · Jaipur", "Example job", ["12 openings", "₹14,000–₹18,000 per month", "Rotational shifts"]],
      [CalendarCheck, "Review interview progress", "Track scheduled interviews in your workspace. Review the candidate details and application materials shared for each interview.", "Interview workspace", "Scheduled", ["See the interview date and mode", "Review shared applications", "Track the interview outcome"]],
      [Users, "Follow selection and joining", "Use your workspace to see who was selected and which candidates are expected to join.", "Joining tracker", "In progress", ["Selected candidates", "Expected joining dates", "Recorded joining outcomes"]],
    ],
    faqs: [
      ["Can I prepare a job before verification?", "Yes. You can create a draft in your workspace. Your company must be verified before you publish."],
      ["What should I prepare before publishing?", "Have your role description, location, openings, salary range, skill requirements and application deadline ready."],
    ],
  },
  candidate: {
    label: "Candidates", tagline: "Find your next opportunity", icon: UserRound,
    heading: "Find work that fits your skills and plans.",
    description: "Tell us what you can do and where you want to work, then follow the next steps from your own account.",
    action: "Create a candidate profile", path: "/register/candidate",
    steps: [
      [UserRound, "Build your profile", "Add your education, skills, work preferences and location. If a training center supports you, it may help you get set up.", "Candidate profile", "Getting started", ["Skills and qualifications", "Preferred work locations", "Availability and salary expectations"]],
      [Target, "Explore and respond", "Review available roles and show interest in the ones that fit. Answer any screening questions and share a resume when requested.", "Opportunity details", "Example match", ["Review the role and pay", "Check the location and requirements", "Submit interest from your account"]],
      [CalendarCheck, "Prepare for your interview", "When an interview is scheduled, check the time, location or joining instructions and respond from your account.", "Upcoming interview", "Scheduled", ["Check the date and time", "Review interview instructions", "Respond to the invitation"]],
      [Check, "Track your next step", "Follow your selection and joining updates. Keep your communication preferences current so you can choose how EXOWORK contacts you.", "Employment journey", "Follow-up", ["Review your joining information", "Update communication preferences", "Request support when available"]],
    ],
    faqs: [
      ["Can I register without a training center?", "Yes. You can create your own candidate profile and explore opportunities from your account."],
      ["Does registration guarantee a job?", "Hiring depends on available roles, your application and the employer’s selection process. Your account helps you track progress."],
    ],
  },
  "training-center": {
    label: "Training partners", tagline: "Track trainees through placement", icon: GraduationCap,
    heading: "Keep every batch in view, from training to joining.",
    description: "Set up your center, organise trainee records and follow placement milestones across your batches.",
    action: "Register a training center", path: "/register/training-center",
    steps: [
      [MapPin, "Set up your center", "Register your center and complete its contact and location information. EXOWORK reviews the profile for verification.", "Training center profile", "Awaiting review", ["Center contact details", "Location and address", "Profile verification"]],
      [Layers3, "Organise batches and trainees", "Create batches and add trainees individually or through the CSV import. Assign candidates to the right batch as records are added.", "Digital skills batch", "Example batch", ["Batch schedule", "Trainee roster", "Candidate assignments"]],
      [CalendarCheck, "Follow interview activity", "Use your workspace to see relevant opportunities and scheduled interviews for trainees your center supports.", "Center interview tracker", "Upcoming", ["Interview dates", "Candidate progress", "Upcoming joining activity"]],
      [Sparkles, "Track placement outcomes", "Follow selections and joining progress, and keep expected joining dates current where your workspace allows.", "Placement overview", "In progress", ["Candidates interviewed", "Candidates selected", "Candidates joined"]],
    ],
    faqs: [
      ["Can a center register independently?", "Yes. You can register a training center without a parent organisation."],
      ["Can we add a full batch of trainees?", "Yes. Use the center’s CSV import to upload candidate records, or add candidates individually."],
    ],
  },
};

export default function HowItWorks() {
  const [params, setParams] = useSearchParams();
  const requestedRole = params.get("role");
  const role = Object.hasOwn(guides, requestedRole) ? requestedRole : "employer";
  const guide = guides[role];
  const [selection, setSelection] = useState({ role, index: 0 });
  const index = selection.role === role ? selection.index : 0;
  const [Icon, title, description, preview, status, checklist] = guide.steps[index];
  const stepNumber = index + 1;

  useEffect(() => {
    document.title = "How EXOWORK works | Employers, candidates and training partners";
    window.scrollTo(0, 0);
    return () => { document.title = "EXOWORK"; };
  }, []);

  function selectRole(nextRole) {
    setSelection({ role: nextRole, index: 0 });
    setParams({ role: nextRole }, { preventScrollReset: true });
  }

  return <div className="ex-home ex-guide">
    <PublicHeader />
    <main>
      <section className="ex-guide-hero">
        <div className="ex-wrap">
          <div className="ex-guide-intro">
            <div className="ex-guide-kicker"><Sparkles size={16} /> THE EXOWORK PLAYBOOK</div>
            <h1>See how EXOWORK<br /><em>works for you.</em></h1>
            <p>Follow the steps for hiring, finding a job, or supporting a training batch. See what you’ll do, what EXOWORK reviews, and where to start.</p>
            <a className="ex-guide-jump" href="#guide-steps">Explore the guide <ArrowDown size={17} /></a>
          </div>
          <div className="ex-guide-roles" aria-label="Choose your EXOWORK guide">
            {Object.entries(guides).map(([key, item]) => <button type="button" key={key} aria-pressed={role === key} aria-controls="guide-steps" onClick={() => selectRole(key)}>
              <span className="ex-guide-role-icon"><item.icon size={23} aria-hidden="true" /></span>
              <span><strong>{item.label}</strong><small>{item.tagline}</small></span>
              <ArrowRight className="ex-guide-role-arrow" size={18} aria-hidden="true" />
            </button>)}
          </div>
        </div>
      </section>
      <section id="guide-steps" className="ex-guide-main">
        <div className="ex-wrap">
          <header className="ex-guide-section-head">
            <div><div className="ex-guide-kicker">GUIDE FOR {guide.label.toUpperCase()}</div><h2>{guide.heading}</h2><p>{guide.description}</p></div>
            <Link className="ex-btn grad" to={guide.path}>{guide.action} <ArrowRight size={17} /></Link>
          </header>
          <div className="ex-guide-board">
            <ol className="ex-guide-steps" aria-label="Guide steps">
              {guide.steps.map(([StepIcon, label], i) => <li key={label}>
                <button type="button" aria-current={index === i ? "step" : undefined} aria-controls="guide-detail" onClick={() => setSelection({ role, index: i })}>
                  <span>{i < index ? <Check size={17} /> : <StepIcon size={18} />}</span><span><small>STEP 0{i + 1}</small><strong>{label}</strong></span>
                </button>
              </li>)}
            </ol>
            <div id="guide-detail" className="ex-guide-detail">
              <div className="ex-guide-progress" role="progressbar" aria-label="Guide progress" aria-valuemin={0} aria-valuemax={guide.steps.length} aria-valuenow={stepNumber}>
                {guide.steps.map((step, i) => <span key={step[1]} className={i <= index ? "filled" : ""} />)}
              </div>
              <div key={role + index} className="ex-guide-stage" aria-live="polite">
                <div className="ex-guide-stage-copy"><small>STEP {stepNumber} OF {guide.steps.length}</small><h3>{title}</h3><p>{description}</p></div>
                <div className="ex-guide-preview">
                  <header><span>WORKSPACE PREVIEW</span><Icon size={18} aria-hidden="true" /></header>
                  <h4>{preview}</h4><span className="ex-guide-status">{status}</span>
                  <ul>{checklist.map(item => <li key={item}><Check size={15} aria-hidden="true" />{item}</li>)}</ul>
                </div>
              </div>
              <div className="ex-guide-controls">
                <button type="button" className="ex-btn ghost" disabled={index === 0} onClick={() => setSelection({ role, index: index - 1 })}><ArrowLeft size={16} /> Back</button>
                {stepNumber < guide.steps.length
                  ? <button type="button" className="ex-btn grad" onClick={() => setSelection({ role, index: index + 1 })}>Next step <ArrowRight size={17} /></button>
                  : <Link className="ex-btn grad" to={guide.path}>{guide.action} <ArrowRight size={17} /></Link>}
              </div>
            </div>
          </div>
        </div>
      </section>
      <section className="ex-guide-faq">
        <div className="ex-wrap ex-guide-faq-grid">
          <div><div className="ex-guide-kicker">BEFORE YOU REGISTER</div><h2>Good to know.</h2><p>Have another question? <a href="/#contact">Ask the EXOWORK team.</a></p></div>
          <div key={role}>{guide.faqs.map(([question, answer]) => <details key={question}><summary>{question}<ChevronDown size={19} aria-hidden="true" /></summary><p>{answer}</p></details>)}</div>
        </div>
      </section>
      <section className="ex-guide-close">
        <div className="ex-wrap"><div className="ex-guide-close-card"><div><h2>Ready to get started?</h2><p>Create your account and pick up at step one.</p></div><Link className="ex-btn light" to={guide.path}>{guide.action} <ArrowRight size={17} /></Link></div></div>
      </section>
    </main>
    <PublicFooter />
  </div>;
}
