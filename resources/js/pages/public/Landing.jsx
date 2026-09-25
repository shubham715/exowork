import React, { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import {
  ArrowUpRight,
  CalendarCheck,
  ClipboardList,
  MessageCircle,
  ShieldCheck,
  Target,
  TrendingUp,
  LockKeyhole,
  Undo2,
  ChevronLeft,
  ChevronRight,
  X,
} from "lucide-react";
import BrandLogo from "../../components/BrandLogo.jsx";
import "./landing-reference.css";

const journey = [
  [
    ClipboardList,
    "Join a training batch",
    "Your training center adds your course, trainer and start date so everything stays organised.",
    "/journey/1.png",
  ],
  [
    ShieldCheck,
    "Choose how we contact you",
    "You decide whether you want job updates and reminders before we send any messages.",
    "/journey/2.png",
  ],
  [
    MessageCircle,
    "Tell us when you're ready",
    "A simple WhatsApp message lets you say when you are ready to start looking for work.",
    "/journey/3.png",
  ],
  [
    Target,
    "Find suitable jobs",
    "We match your skills, preferred location, salary and availability with suitable openings.",
    "/journey/4.png",
  ],
  [
    CalendarCheck,
    "Attend the interview",
    "We confirm the interview, send a reminder and keep you updated about the employer's decision.",
    "/journey/5.png",
  ],
  [
    TrendingUp,
    "Get support after joining",
    "We check in after you join and connect you with support if you need help settling into the role.",
    "/journey/6.png",
  ],
];
const flow = [
  [
    "Batch completed",
    "Digital Marketing & Computer Applications · Sitapura Computer Center",
  ],
  ["Ready for work", "Skills, preferred location and expected salary added"],
  ["Matched with 3 jobs", "Three suitable opportunities found"],
  ["Interview confirmed", "Reminder sent one day before, in Hindi"],
  ["Started the new job", "Follow-up support begins after joining"],
];
const searches = [
  [
    "01",
    "Jobs near your location",
    "Find verified openings close to home or in places where you are willing to move.",
    "/jobs/nearby-jobs.png",
  ],
  [
    "02",
    "Jobs for freshers",
    "Explore entry-level jobs where you can begin your career and build experience.",
    "/jobs/fresher.png",
  ],
  [
    "03",
    "Work from home roles",
    "Explore remote and hybrid jobs from verified employers.",
    "/jobs/wfh-jobs.png",
  ],
  [
    "04",
    "Jobs for women",
    "Discover welcoming workplaces and jobs that suit your needs.",
    "/jobs/women-jobs.png",
  ],
  [
    "05",
    "Immediate joining jobs",
    "See jobs where employers are interviewing and hiring right now.",
    "/jobs/immidiate-joiner.png",
  ],
];
const roles = [
  ["Field Sales", "1,240 opportunities"],
  ["Customer Support", "980 opportunities"],
  ["Computer & Data Entry", "860 opportunities"],
  ["Retail & Counter Sales", "720 opportunities"],
  ["Warehouse Operations", "640 opportunities"],
  ["Digital Marketing", "520 opportunities"],
  ["Machine Operator", "480 opportunities"],
  ["Accounts & Finance", "390 opportunities"],
  ["Delivery & Logistics", "360 opportunities"],
  ["Office Administration", "310 opportunities"],
  ["Electrician & Technician", "280 opportunities"],
  ["Hospitality Staff", "240 opportunities"],
];
function Head({ k, t, children }) {
  return (
    <div className="ex-head ex-reveal">
      <div className="ex-kicker">
        <i />
        {k}
      </div>
      <h2>{t}</h2>
      {children && <p>{children}</p>}
    </div>
  );
}

function DiscoverySections() {
  const searchTrack = useRef(null);
  useEffect(() => {
    const track = searchTrack.current;
    const mobile = window.matchMedia("(max-width: 720px)");
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    if (!track || !mobile.matches || reducedMotion.matches) return;

    const advance = () => {
      const card = track.querySelector("a");
      if (!card) return;
      const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
      const step = card.getBoundingClientRect().width + gap;
      const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
      track.scrollTo({ left: atEnd ? 0 : track.scrollLeft + step, behavior: "smooth" });
    };

    let timer = window.setInterval(advance, 3200);
    const pause = () => window.clearInterval(timer);
    const resume = () => {
      window.clearInterval(timer);
      timer = window.setInterval(advance, 3200);
    };
    track.addEventListener("pointerdown", pause);
    track.addEventListener("pointerup", resume);
    track.addEventListener("pointercancel", resume);
    return () => {
      window.clearInterval(timer);
      track.removeEventListener("pointerdown", pause);
      track.removeEventListener("pointerup", resume);
      track.removeEventListener("pointercancel", resume);
    };
  }, []);

  const moveSearches = (direction) => {
    const track = searchTrack.current;
    if (!track) return;
    track.scrollBy({ left: direction * track.clientWidth * 0.82, behavior: "smooth" });
  };
  return (
    <>
      <section className="ex-discovery ex-alt">
        <div className="ex-wrap">
          <div className="ex-discovery-head">
            <div className="ex-compact-title">
              <span>Explore opportunities</span>
              <h2>Find the kind of job you want</h2>
              <p>
                Start with one of the job searches candidates use most often.
              </p>
            </div>
            <div className="ex-slider-controls" aria-label="Opportunity slider controls">
              <button type="button" onClick={() => moveSearches(-1)} aria-label="Previous opportunities"><ChevronLeft /></button>
              <button type="button" onClick={() => moveSearches(1)} aria-label="Next opportunities"><ChevronRight /></button>
            </div>
          </div>
          <div className="ex-search-cards ex-reveal" ref={searchTrack}>
            {searches.map(([n, t, d, image]) => (
              <a href="#contact" key={t}>
                <div className="ex-search-copy">
                  <small>TRENDING AT #{n}</small>
                  <h3>{t}</h3>
                  <p>{d}</p>
                  <b>Explore <ArrowUpRight /></b>
                </div>
                <img src={image} alt="" aria-hidden="true" />
              </a>
            ))}
          </div>
        </div>
      </section>
      <section className="ex-role-section">
        <div className="ex-wrap">
          <div className="ex-role-head">
            <div className="ex-hero-copy">
              <span>Roles hiring now</span>
              <h2>Popular jobs available now</h2>
            </div>
            <a href="#contact">
              View all opportunities <ArrowUpRight />
            </a>
          </div>
          <div className="ex-role-grid ex-reveal">
            {roles.map(([t, n]) => (
              <a href="#contact" key={t}>
                <div>
                  <h3>{t}</h3>
                  <p>{n}</p>
                </div>
                <ArrowUpRight />
              </a>
            ))}
          </div>
        </div>
      </section>
    </>
  );
}

function DashboardVisual() {
  return (
    <div className="ex-visual">
      <div className="ex-dash">
        <header>
          <span>
            <i />
            <i />
            <i />
          </span>
          <b>app.exowork.in/dashboard</b>
        </header>
        <div className="ex-dash-body">
          <aside>
            {[1, 2, 3, 4, 5].map((x, i) => (
              <i className={i ? "" : "active"} key={x} />
            ))}
          </aside>
          <div className="ex-dash-main">
            <div className="ex-stats">
              {[
                [482, "Job-ready candidates"],
                [96, "Interviews this week"],
                [317, "Placed this quarter"],
              ].map(([n, l]) => (
                <div key={l}>
                  <b>{n}</b>
                  <small>{l}</small>
                </div>
              ))}
            </div>
            <div className="ex-dash-chart">
              {[38, 52, 46, 68, 60, 82, 74].map((h, i) => (
                <i key={i} style={{ "--h": h + "%" }} />
              ))}
            </div>
            <div className="ex-people">
              {[
                [
                  "AR",
                  "Aarushi R. · Digital Marketing",
                  "Interview set",
                  "blue",
                ],
                ["MK", "Mohit K. · Computer Applications", "Joined", "green"],
                ["PS", "Priya S. · Social Media", "Matched", "amber"],
              ].map(([a, n, s, c]) => (
                <div key={a}>
                  <span className={c}>{a}</span>
                  <b>{n}</b>
                  <em className={c}>{s}</em>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
      <div className="ex-float one">
        <span>
          <MessageCircle />
        </span>
        <div>
          <b>Reminder sent</b>
          <small>Interview tomorrow, 11:00 AM</small>
        </div>
      </div>
      <div className="ex-float two">
        <b>92%</b>
        <small>Retained at day 30</small>
      </div>
    </div>
  );
}

function CareerProgression() {
  return (
    <section id="career-growth" className="ex-career-growth">
      <div className="ex-wrap">
        <div className="ex-career-panel ex-reveal">
          <div className="ex-career-copy">
            <span className="ex-career-label">EXOWORK Career Progression</span>
            <h2>Already working? Find your next better-paying role.</h2>
            <p>
              This service is for experienced employees who want to grow. An EXOWORK
              career expert reviews your experience, skills and current salary, speaks
              with you about your next move, and recommends only selected jobs with a
              higher pay scale.
            </p>
            <div className="ex-career-for"><b>Best for</b> Professionals with work experience looking for salary and role growth</div>
            <a href="#contact">Talk to a career expert <ArrowUpRight /></a>
          </div>
          <div className="ex-career-art">
            <img
              src="/premium.png"
              alt="Career progression from sharing experience and speaking with an expert to receiving selected better-paying jobs"
            />
          </div>
        </div>
      </div>
    </section>
  );
}
export default function Landing() {
  const [menu, setMenu] = useState(false),
    [sent, setSent] = useState(false);
  useEffect(() => {
    const io = new IntersectionObserver(
      (es) =>
        es.forEach((e) => {
          if (e.isIntersecting) {
            e.target.classList.add("in");
            io.unobserve(e.target);
          }
        }),
      { threshold: 0.14 },
    );
    document
      .querySelectorAll(".ex-reveal,.ex-dash-chart")
      .forEach((e) => io.observe(e));
    return () => io.disconnect();
  }, []);
  return (
    <div className="ex-home" id="top">
      <header className="ex-nav">
        <div className="ex-wrap ex-nav-inner">
          <a className="ex-brand" href="#top">
            <BrandLogo />
          </a>
          <button
            className={`ex-menu-backdrop ${menu ? "open" : ""}`}
            onClick={() => setMenu(false)}
            aria-label="Close menu"
            tabIndex={menu ? 0 : -1}
          />
          <nav className={`ex-links ${menu ? "open" : ""}`} aria-hidden={!menu}>
            <div className="ex-drawer-head">
              <BrandLogo />
              <button type="button" onClick={() => setMenu(false)} aria-label="Close menu">
                <X />
              </button>
            </div>
            <p className="ex-drawer-label">Explore EXOWORK</p>
            {[
              ["pipeline", "Candidate journey"],
              ["journey", "How it works"],
              ["audiences", "Who it's for"],
              ["automation", "Automation"],
              ["trust", "Trust & data"],
              ["contact", "Contact"],
            ].map(([i, t]) => (
              <a href={`#${i}`} onClick={() => setMenu(false)} key={i}>
                {t}
              </a>
            ))}
            <div className="ex-drawer-actions">
              <Link className="ex-btn ghost" to="/login" onClick={() => setMenu(false)}>
                Sign in
              </Link>
              <Link className="ex-btn grad" to="/register/candidate" onClick={() => setMenu(false)}>
                Get started
              </Link>
            </div>
          </nav>
          <div className="ex-nav-actions">
            <Link className="ex-btn ghost small" to="/login">
              Sign in
            </Link>
            <Link className="ex-btn grad small" to="/register/candidate">
              Get started
            </Link>
          </div>
          <button
            className={`ex-menu ${menu ? "active" : ""}`}
            onClick={() => setMenu(!menu)}
            aria-label={menu ? "Close menu" : "Open menu"}
            aria-expanded={menu}
          >
            <i />
            <i />
            <i />
          </button>
        </div>
      </header>
      <main>
        <section className="ex-hero">
          <div className="ex-hero-bg">
            <i className="b1" />
            <i className="b2" />
            <i className="b3" />
            <b />
          </div>
          <div className="ex-wrap ex-hero-grid">
            <div>
              <h1>
                From Opportunity to <em>Long-Term Employment.</em>
              </h1>
              <p className="ex-hero-sub">
                EXOWORK connects candidates, employers and training partners —
                helping people move from preparation and opportunity to joining,
                retention and long-term career growth.
              </p>
              <p className="ex-hero-sub ex-hero-supporting">
                We don't just help people to get jobs.
              </p>
              <p className="ex-hero-sub ex-hero-supporting ex-hero-closing">
                <strong>We help them build sustainable employment.</strong>
              </p>
              <div className="ex-actions">
                <a className="ex-btn grad" href="#contact">
                  Join EXOWORK
                </a>
                <a className="ex-btn ghost" href="#pipeline">
                  See how it works
                </a>
              </div>
            </div>
            <div className="ex-hero-image">
              <img
                src="/hero1.png"
                alt="EXOWORK candidates using the job opportunities platform"
              />
            </div>
          </div>
        </section>
        <DiscoverySections />
        <CareerProgression />
        <section id="pipeline">
          <div className="ex-wrap">
            <div className="ex-pipeline-layout">
              <div className="ex-pipeline-dashboard ex-reveal">
                <DashboardVisual />
              </div>
              <div className="ex-pipeline-roadmap">
                <Head
                  k="A clear journey from training to work"
                  t="See how a candidate finds and starts a job"
                >
                  Candidates always know what comes next, employers can follow
                  the hiring progress, and training centers can offer help at
                  the right time.
                </Head>
                <div className="ex-pipeline ex-reveal">
                  <header>
                    <span>Candidate EXO-CAN-100231</span>
                    <b>
                      <i />
                      Current status
                    </b>
                  </header>
                  <div className="ex-flow">
                    <i className="line" />
                    <i className="fill" />
                    <i className="traveler" />
                    {flow.map(([t, d], i) => (
                      <div
                        className={`ex-step ${i < 3 ? "active" : ""}`}
                        style={{ "--delay": i * 0.11 + "s" }}
                        key={t}
                      >
                        <strong>{i + 1}</strong>
                        <div>
                          <b>{t}</b>
                          <p>{d}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
        <section id="journey" className="ex-alt">
          <div className="ex-wrap">
            <Head
              k="How EXOWORK helps"
              t="Six simple steps from training to a new job"
            >
              Everyone stays informed along the way, so candidates get support,
              employers can hire with confidence and training centers can see
              the results.
            </Head>
            <div className="ex-journey ex-reveal">
              {journey.map(([Icon, t, d, image], i) => (
                <article key={t}>
                  <span>0{i + 1}</span>
                  <img className="ex-journey-art" src={image} alt="" />
                  <div className={i % 2 ? "green" : "blue"}>
                    <Icon />
                  </div>
                  <h3>{t}</h3>
                  <p>{d}</p>
                </article>
              ))}
            </div>
          </div>
        </section>
        <section id="audiences">
          <div className="ex-wrap">
            <Head
              k="Made for everyone involved"
              t="The right tools for candidates, employers and training centers"
            >
              Each person gets a simple, private view with only the information
              and actions they need.
            </Head>
            <div className="ex-audiences ex-reveal">
              {[
                [
                  "dark",
                  "For employers",
                  "Post a job and meet suitable candidates",
                  "Add the skills, location and salary for your role. We help you reach candidates who match what you need.",
                  [
                    "Complete verification once, then post jobs easily",
                    "View your jobs, candidates and hiring history in one place",
                    "See which roles are open, filled or closed",
                  ],
                  "Start hiring",
                ],
                [
                  "light",
                  "For training partners",
                  "Help more learners move into work",
                  "Add training batches and candidates, then quickly see who is ready for job opportunities.",
                  [
                    "Follow each candidate from training to joining",
                    "See placement progress for every center and batch",
                  ],
                  "Register a center",
                ],
                [
                  "soft",
                  "For candidates",
                  "One profile for jobs that suit you",
                  "Create your profile, choose where you want to work and receive useful updates in your preferred language.",
                  [
                    "Get clear WhatsApp updates at every step",
                    "Stay in control of your information and messages",
                  ],
                  "Find a role",
                ],
              ].map(([c, tag, t, d, list, go]) => (
                <article className={c} key={tag}>
                  <label>{tag}</label>
                  <h3>{t}</h3>
                  <p>{d}</p>
                  <ul>
                    {list.map((x) => (
                      <li key={x}>{x}</li>
                    ))}
                  </ul>
                  <a href="#contact">
                    {go}
                    <ArrowUpRight />
                  </a>
                </article>
              ))}
            </div>
          </div>
        </section>
        <section id="automation" className="ex-alt">
          <div className="ex-wrap ex-automation">
            <div className="ex-whatsapp-image ex-reveal">
              <img src="/whatsapp.png" alt="EXOWORK WhatsApp employment updates conversation" />
            </div>
            <div>
              <div className="ex-kicker">
                <i />
                Helpful updates, backed by real people
              </div>
              <h2 className="ex-auto-title">
                Get timely WhatsApp updates without losing the human touch.
              </h2>
              <p className="ex-auto-sub">
                Candidates receive useful reminders, and a real support person
                steps in whenever a question needs personal attention.
              </p>
              <div className="ex-features">
                {[
                  [
                    "01",
                    "You choose to receive updates",
                    "We only send WhatsApp messages after the candidate has agreed to receive them.",
                  ],
                  [
                    "02",
                    "Clear, timely updates",
                    "Candidates get helpful reminders at the right time, without unnecessary messages.",
                  ],
                  [
                    "03",
                    "Real help when it is needed",
                    "If a candidate has a question or concern, a support person follows up directly.",
                  ],
                ].map(([n, t, d]) => (
                  <div key={n}>
                    <span>{n}</span>
                    <div>
                      <b>{t}</b>
                      <p>{d}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </section>
        <section id="trust" className="ex-alt">
          <div className="ex-wrap">
            <Head
              k="Trust & data"
              t="Your information stays private and protected"
            >
              We protect personal details and give every candidate control over
              their information and communication preferences.
            </Head>
            <div className="ex-trust ex-reveal">
              {[
                [
                  ShieldCheck,
                  "Personal details are protected",
                  "Sensitive identity details are secured, partly hidden on screen and never included in regular messages or downloads.",
                ],
                [
                  Undo2,
                  "Stop messages at any time",
                  "Candidates can reply STOP or change their preferences whenever they no longer want updates.",
                ],
                [
                  LockKeyhole,
                  "Only the right people can see it",
                  "Employers see only their own jobs and shared candidates. Training centers see only their own batches.",
                ],
              ].map(([Icon, t, d]) => (
                <article key={t}>
                  <span>
                    <Icon />
                  </span>
                  <h3>{t}</h3>
                  <p>{d}</p>
                </article>
              ))}
            </div>
          </div>
        </section>
        <section id="contact">
          <div className="ex-wrap">
            <div className="ex-cta">
              <div>
                <h2>Ready to get started with EXOWORK?</h2>
                <p>
                  Whether you are looking for work, hiring or supporting
                  candidates, tell us how we can help.
                </p>
                <div>
                  <a className="ex-btn grad" href="mailto:hello@exowork.in">
                    Email us
                  </a>
                  <a className="ex-btn light" href="#contact">
                    Request a walkthrough
                  </a>
                </div>
              </div>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  setSent(true);
                }}
              >
                <label>Get in touch</label>
                <input placeholder="Full name" required />
                <input placeholder="Organisation (employer / center)" />
                <input type="tel" placeholder="Mobile number" required />
                <textarea placeholder="What are you looking to do on EXOWORK?" />
                <button className="ex-btn grad">Send enquiry</button>
                <p>
                  {sent
                    ? "Thanks — our team will reach out shortly."
                    : "We reply within one working day."}
                </p>
              </form>
            </div>
          </div>
        </section>
      </main>
      <footer className="ex-footer">
        <div className="ex-wrap">
          <div className="ex-footer-grid">
            <div className="ex-foot-brand">
              <BrandLogo />
              <p>
                A simple platform connecting candidates, training
                partners and employers, from training and job matching to
                successful joining.
              </p>
            </div>
            {[
              [
                "Platform",
                [
                  ["How it works", "journey"],
                  ["Who it's for", "audiences"],
                  ["Automation", "automation"],
                  ["Trust & data", "trust"],
                ],
              ],
              [
                "Roles",
                [
                  ["Employers", "contact"],
                  ["Training partners", "contact"],
                  ["Candidates", "contact"],
                  ["Support teams", "contact"],
                ],
              ],
              [
                "Company",
                [
                  ["Contact us", "contact"],
                  ["hello@exowork.in", "mailto:hello@exowork.in"],
                ],
              ],
            ].map(([h, ls]) => (
              <div className="ex-foot-col" key={h}>
                <b>{h}</b>
                {ls.map(([t, id]) => (
                  <a href={id.startsWith("mailto:") ? id : "#" + id} key={t}>
                    {t}
                  </a>
                ))}
              </div>
            ))}
          </div>
          <div className="ex-footer-bottom">
            <p>© 2026 EXOWORK. Connecting skills to sustainable employment.</p>
          </div>
        </div>
      </footer>
    </div>
  );
}
