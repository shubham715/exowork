import React, { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
import { Link, useNavigate, useLocation } from "react-router-dom";
import {
    BriefcaseBusiness, Eye, Send, CalendarDays, Copy, Pencil, CircleCheck, X,
    Plus,
    ShieldCheck,
    MapPin, GraduationCap, FileText, Wallet, Gift, ClipboardList, UsersRound, Banknote, SlidersHorizontal, ArrowRight, ChevronLeft, Layers3, Building2, Clock3, Laptop, Check,
} from "lucide-react";
import { PageHead, Panel, Status, Modal } from "../../components/UI.jsx";
import {
    profileEmpty,
    profileSteps,
    EmployerFields,
    useEmployerMasters,
    csrf,
} from "./EmployerRegistration.jsx";
import "./employer.css";
import { HiringOverview, HiringTable } from "./HiringWorkbench.jsx";

const jobEmpty = {
    title: "",
    department: "",
    job_type: "Full-Time",
    workplace_type: "On-site",
    state: "",
    district: "",
    location: "",
    openings: 1,
    industry: "",
    description: "",
    responsibilities: "",
    skills: "",
    education: "",
    experience: "Fresher",
    salary_type: "Fixed",
    salary_min: "",
    salary_max: "",
    benefits: "",
    screening_questions: "",
    application_deadline: "",
    status: "draft",
};
const jobSteps = [
    [
        "Basic details",
        [
            "title",
            "department",
            "industry",
            "job_type",
            "workplace_type",
            "state",
            "district",
            "location",
            "openings",
        ],
    ],
    [
        "Role & skills",
        [
            "description",
            "responsibilities",
            "skills",
            "education",
            "experience",
        ],
    ],
    [
        "Compensation & benefits",
        ["salary_type", "salary_min", "salary_max", "benefits"],
    ],
    ["Hiring preferences", ["screening_questions", "application_deadline"]],
];
const jobSections = [
    [["Role and hiring demand", "Tell us who you need and how many people you are hiring.", ["title","department","industry","openings","job_type"]],["Workplace and location", "Set where your team will work.", ["workplace_type","state","district","location"]]],
    [["Candidate eligibility", "Define the skills, education and experience needed for this role.", ["education","experience","skills"]],["Role description", "Explain the work clearly so candidates can decide whether it suits them.", ["description","responsibilities"]]],
    [["Salary", "Choose how the role is paid and enter the offered range in INR.", ["salary_type","salary_min","salary_max"]],["Benefits", "Select the benefits you actually offer.", ["benefits"]]],
    [["Screening and application window", "Add questions for candidates and the last date to apply.", ["screening_questions","application_deadline"]]],
];
const roleTemplates = [
    {title:'Machine operator',department:'Production',description:'Operate production equipment following documented safety and quality standards.',responsibilities:'Set up equipment; monitor production; report faults; maintain a clean and safe work area.',skills:'Machine operation, safety procedures, quality checks',education:'ITI / relevant technical qualification',experience:'Fresher or experienced'},
    {title:'Warehouse associate',department:'Operations',description:'Support receiving, storage, picking and dispatch of warehouse goods.',responsibilities:'Check incoming goods; pick and pack orders; maintain stock records; follow warehouse safety procedures.',skills:'Inventory handling, basic numeracy, teamwork',education:'10th pass or equivalent',experience:'Fresher or experienced'},
    {title:'Customer support executive',department:'Customer service',description:'Help customers resolve queries through the company’s approved support channels.',responsibilities:'Respond to queries; record customer interactions; escalate unresolved issues; follow service standards.',skills:'Communication, basic computer skills, problem solving',education:'12th pass or equivalent',experience:'Fresher or experienced'},
];
const stepIcons = [BriefcaseBusiness, UsersRound, Banknote, SlidersHorizontal];
const sectionIcons = {"Role and hiring demand": BriefcaseBusiness, "Workplace and location": MapPin, "Candidate eligibility": GraduationCap, "Role description": FileText, "Salary": Wallet, "Benefits": Gift, "Screening and application window": ClipboardList};
const jobLabels = {
    title: "Job title / designation",
    department: "Department",
    job_type: "Job type",
    workplace_type: "Workplace type",
    state: "State",
    district: "City / district",
    location: "Office address / location",
    openings: "Number of openings",
    industry: "Industry / sector",
    description: "Job description",
    responsibilities: "Key responsibilities",
    skills: "Mandatory skills",
    education: "Educational qualification",
    experience: "Experience / fresher eligibility",
    salary_type: "Salary type",
    salary_min: "Minimum salary (INR)",
    salary_max: "Maximum salary (INR)",
    benefits: "Benefits",
    screening_questions: "Screening questions",
    application_deadline: "Application deadline",
};
const benefitChoices = [
    "Health Insurance",
    "Flexible Hours",
    "Work From Home Allowance",
    "PF",
    "ESIC",
];
const fail = (e) =>
    e.response?.data?.message ||
    "Could not load employer data. Please try again.";
export function useEmployerWorkspace() {
    const navigate = useNavigate(),
        { pathname } = useLocation(),
        requestVersion = useRef(0),
        [data, setData] = useState(null),
        [error, setError] = useState(""),
        [loading, setLoading] = useState(true);
    const load = useCallback((background = false) => {
        const version = ++requestVersion.current;
        if (!background) setLoading(true);
        setError("");
        axios
            .get("/employer-api/workspace")
            .then(({ data }) => {
                if (version === requestVersion.current) setData(data);
            })
            .catch((e) => {
                if (version !== requestVersion.current) return;
                if ([401, 403].includes(e.response?.status))
                    navigate("/login", { replace: true });
                else setError(fail(e));
            })
            .finally(() => {
                if (version === requestVersion.current) setLoading(false);
            });
    }, [navigate]);
    useEffect(() => {
        load(true);
        const refresh = () => load(true);
        const onVisible = () => {
            if (document.visibilityState === "visible") refresh();
        };
        window.addEventListener("focus", refresh);
        window.addEventListener("employer-profile-updated", refresh);
        document.addEventListener("visibilitychange", onVisible);
        return () => {
            ++requestVersion.current;
            window.removeEventListener("focus", refresh);
            window.removeEventListener("employer-profile-updated", refresh);
            document.removeEventListener("visibilitychange", onVisible);
        };
    }, [load, pathname]);
    return { data, setData, error, setError, loading, load };
}
export default function EmployerWorkspace({ page = "dashboard" }) {
    const state = useEmployerWorkspace();
    if (state.loading) return <p role="status">Loading employer workspace…</p>;
    if (!state.data)
        return (
            <div role="alert">
                {state.error}
                <button className="btn ghost" onClick={state.load}>
                    Retry
                </button>
            </div>
        );
    const { data } = state;
    if (["company", "onboarding", "settings"].includes(page))
        return <CompanyProfile key={page} {...state} page={page} />;
    if (page === "jobs/new") return <JobEditor {...state} />;
    return (
        <div className="employer-live">
            <PageHead
                kicker="EMPLOYER WORKSPACE"
                title={
                    page === "dashboard"
                        ? "Jobs and hiring"
                        : {
                              jobs: "Job requirements",
                              talent: "Candidate pool",
                              interviews: "Interviews",
                              joining: "Selections & joining",
                              history: "Hiring history",
                          }[page] || "Employer workspace"
                }
                sub={`${data.employer.name} · ${data.employer.code}`}
            >
                <details className="employer-post-menu"><summary className="btn primary"><Plus />Post a job <span aria-hidden="true">⌄</span></summary><div><Link to="/employer/jobs/new"><b>Start a new requirement</b><span>Enter your role and hiring needs</span></Link><Link to="/employer/jobs/new" state={{openTemplates:true}}><b>Use a role template</b><span>Start with role details or reuse a saved job</span></Link></div></details>
            </PageHead>
            {state.error && (
                <p className="employer-error" role="alert">
                    {state.error}
                </p>
            )}
            <Verification employer={data.employer} />
            {page === "dashboard" && <div className="employer-hiring-strip"><Link to="/employer/jobs">{data.summary.active_jobs} active jobs</Link><Link to="/employer/jobs?status=draft">{data.summary.draft_jobs} drafts</Link><Link to="/employer/talent">{data.summary.candidates} released candidates</Link><Link to="/employer/interviews">{data.summary.interviews} scheduled interviews</Link><Link to="/employer/joining">{data.summary.joined} joined</Link></div>}
            {["dashboard", "jobs"].includes(page) ? (
                <JobList {...state} />
            ) : (
                <HiringTable key={page} page={page} data={data} />
            )}
            {page === "dashboard" && <HiringOverview data={data} compact />}
        </div>
    );
}
function Verification({ employer }) {
    return (
        <div className="employer-verification">
            <ShieldCheck />
            <div>
                <b>
                    {employer.status === "verified"
                        ? "Company verified"
                        : employer.status === "rejected"
                          ? "Verification needs changes"
                          : "Pending company verification"}
                </b>
                <p>
                    {employer.status === "verified"
                        ? "Your company can publish job requirements."
                        : "Save drafts while EXOWORK reviews your company documents. Publishing requires approval."}
                </p>
                {employer.review_remarks && (
                    <p>Review remarks: {employer.review_remarks}</p>
                )}
            </div>
            <Status tone={employer.status}>{employer.status}</Status>
        </div>
    );
}
function JobList({ data, setData, setError }) {
    const location = useLocation();
    const navigate = useNavigate();
    const [busy, setBusy] = useState(false),
        [query, setQuery] = useState(""),
        [filter, setFilter] = useState(new URLSearchParams(location.search).get("status") || "all");
    useEffect(() => setFilter(new URLSearchParams(location.search).get("status") || "all"), [location.search]);
    const rows = data.jobs.filter(
        (j) =>
            (filter === "all" || j.status === filter) &&
            `${j.title} ${j.district || ""} ${j.department || ""}`.toLowerCase().includes(query.toLowerCase()),
    );
    const changeStatus = async (j, status) => {
        setBusy(true);
        setError("");
        try {
            setData(
                (
                    await axios.put(
                        `/employer-api/jobs/${j.id}`,
                        { ...j, status },
                        { headers: csrf() },
                    )
                ).data,
            );
        } catch (e) {
            setError(fail(e));
        } finally {
            setBusy(false);
        }
    };
    return (
        <Panel
            title={`Job requirements (${data.jobs.length})`}
            sub="Saved and published hiring demand"
            className="employer-jobs-panel"
        >
            <div className="employer-list-filters">
                <input
                    aria-label="Search jobs"
                    placeholder="Search title, location or department"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <select
                    aria-label="Job status"
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                >
                    {["all", "draft", "active", "filled", "closed"].map((x) => (
                        <option key={x} value={x}>{x === "all" ? "All statuses" : x.charAt(0).toUpperCase()+x.slice(1)}</option>
                    ))}
                </select>
            </div>
            {!rows.length ? (
                <div className="employer-empty">
                    <BriefcaseBusiness />
                    <h3>
                        No job requirements{" "}
                        {data.jobs.length ? "match your filters" : "yet"}
                    </h3>
                    <p>
                        Create a requirement and save it as a draft to get
                        started.
                    </p>
                    {data.jobs.length ? <button className="btn ghost" onClick={()=>{setQuery("");setFilter("all");}}>Clear filters</button> : <Link className="btn primary" to="/employer/jobs/new">Post your first job</Link>}
                </div>
            ) : (
                <div className="employer-job-list">
                    {rows.map(j => {
                        const interviews=data.interviews.filter(r=>r.job_post_id===j.id), outcomes=data.placements.filter(r=>r.job_post_id===j.id);
                        const active = ['active','published'].includes(j.status);
                        const remaining = j.days_remaining;
                        const deadline = j.application_deadline ? new Date(j.application_deadline+'T00:00:00').toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'}) : null;
                        return <article className="employer-demand-card" key={j.id}>
                            <header>
                                <span className="employer-demand-icon"><BriefcaseBusiness size={21}/></span>
                                <div className="employer-demand-heading"><div className="employer-demand-title"><button onClick={()=>navigate("/employer/jobs/new",{state:{job:j}})}>{j.title}</button><Status tone={j.status}>{j.status}</Status></div><p><span><MapPin size={13}/>{[j.district,j.state].filter(Boolean).join(', ') || 'Add location'}</span><span><Clock3 size={13}/>{j.job_type || 'Job type pending'}</span><span><UsersRound size={13}/>{j.openings} {Number(j.openings)===1?'opening':'openings'}</span></p></div>
                                <button className="btn ghost" disabled={busy} onClick={()=>navigate("/employer/jobs/new",{state:{job:j}})}><Pencil size={14}/>{j.status==='draft'?'Continue draft':'View / edit'}</button>
                            </header>
                            <div className="employer-job-insights">
                                <div className="job-insight views" title={j.views_count==null?'Candidate views are not tracked yet.': 'Candidate views'}><span className="job-insight-icon"><Eye size={18}/></span><div><b>{j.views_count ?? '—'}</b><span>Candidate views</span><small>{j.views_count==null?'Not tracked yet':'Opportunity reach'}</small></div></div>
                                <div className="job-insight applications"><span className="job-insight-icon"><Send size={18}/></span><div><b>{j.applications_count ?? 0}</b><span>Applications</span><small>Total candidate applications</small></div></div>
                                <div className={'job-insight deadline'+(active&&remaining!==null&&remaining<=3?' urgent':'')}><span className="job-insight-icon"><CalendarDays size={18}/></span><div><b>{active ? remaining==null?'—':remaining===0?'Ended':`${remaining} ${remaining===1?'day':'days'}` : j.status==='draft'?'Draft':'Ended'}</b><span>{active?'Application window':'Job status'}</span><small>{deadline?`Deadline: ${deadline}`:'No deadline set'}</small></div></div>
                            </div>
                            <div className="employer-job-pipeline"><Link to={`/employer/talent?job=${j.id}`}><UsersRound size={14}/><b>{new Set(interviews.map(r=>r.candidate_id)).size}</b> released</Link><Link to={`/employer/interviews?job=${j.id}`}><CalendarDays size={14}/><b>{interviews.filter(r=>['scheduled','confirmed'].includes(r.status)).length}</b> interviews</Link><Link to={`/employer/joining?job=${j.id}`}><CircleCheck size={14}/><b>{outcomes.filter(r=>['selected','joining_pending'].includes(r.status)).length}</b> selected</Link><Link to={`/employer/joining?job=${j.id}`}><Check size={14}/><b>{outcomes.filter(r=>r.joined_on).length}/{j.openings}</b> joined</Link></div>
                            <footer><span className="employer-job-note">{j.status==='draft'?'Finish your draft to start receiving applications':active?(remaining===0?'Application deadline has passed':'Live · Accepting applications'):'Hiring requirement '+j.status}</span><div><Link className="btn ghost" to="/employer/jobs/new" state={{template:j}}><Copy size={14}/>Reuse</Link>{active&&<><button className="btn ghost" disabled={busy} onClick={()=>changeStatus(j,'filled')}><CircleCheck size={14}/>Mark filled</button><button className="btn ghost" disabled={busy} onClick={()=>changeStatus(j,'closed')}><X size={14}/>Close job</button></>}</div></footer>
                        </article>;
                    })}
                </div>
            )}
        </Panel>
    );
}
function CompanyProfile({ data, setData, error, setError, page }) {
    const [form, setForm] = useState({
            ...profileEmpty,
            ...data.employer,
            document: null,
        }),
        [errors, setErrors] = useState({}),
        [busy, setBusy] = useState(false),
        [saved, setSaved] = useState(false);
    const { master, districts, masterError } = useEmployerMasters(form.state);
    const set = (key, value) => {
        setSaved(false);
        setForm((f) => ({
            ...f,
            [key]: value,
            ...(key === "state" ? { district: "" } : {}),
        }));
        setErrors((e) => ({ ...e, [key]: undefined }));
    };
    const save = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError("");
        setErrors({});
        const body = new FormData();
        Object.keys(profileEmpty).forEach((k) => body.append(k, form[k] ?? ""));
        if (form.document) body.append("document", form.document);
        try {
            const result = await axios.post("/employer-api/profile", body, {
                headers: csrf(),
            });
            setData((d) => ({ ...d, employer: result.data.employer }));
            setSaved(true);
            setForm((f) => ({ ...f, ...result.data.employer, document: null }));
            window.dispatchEvent(new Event("employer-profile-updated"));
        } catch (e) {
            setErrors(e.response?.data?.errors || {});
            setError(fail(e));
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="employer-live">
            <PageHead
                kicker="EMPLOYER WORKSPACE"
                title={
                    page === "onboarding"
                        ? "Company verification"
                        : page === "settings"
                          ? "Primary contact & account"
                          : "Company profile"
                }
                sub="Saved company details. Profile changes require a new review and return published jobs to drafts."
            />
            <Verification employer={data.employer} />
            {page === "settings" && (
                <Panel title="Account access">
                    <p>Work email: {data.employer.email}</p>
                    <p>Mobile: {data.employer.phone}</p>
                    <p>
                        Contact EXOWORK support for changes to your account
                        credentials.
                    </p>
                </Panel>
            )}
            <form className="employer-profile-form" onSubmit={save}>
                {(error || masterError) && (
                    <p role="alert" className="employer-error">
                        {error || masterError}
                    </p>
                )}
                {saved && (
                    <p role="status" className="employer-success">
                        Company details saved.
                    </p>
                )}
                {profileSteps.map(([title, keys]) => (
                    <Panel key={title} title={title}>
                        <EmployerFields
                            keys={keys}
                            data={form}
                            set={set}
                            errors={errors}
                            master={master}
                            districts={districts}
                            existingDocument={data.employer.document_name}
                        />
                        {title === "Verification" &&
                            data.employer.document_url && (
                                <a
                                    className="text-link"
                                    href={data.employer.document_url}
                                >
                                    Download current document
                                </a>
                            )}
                    </Panel>
                ))}
                <button className="btn primary" disabled={busy}>
                    {busy ? "Saving…" : "Save company details"}
                </button>
            </form>
        </div>
    );
}
function JobEditor({ data, setData, setError, job, onClose }) {
    const route = useLocation();
    job = job || route.state?.job;
    const template = !job ? route.state?.template : null;
    const nav = useNavigate(),
        [form, setForm] = useState({
            ...jobEmpty,
            industry: data.employer.industry,
            state: data.employer.state,
            district: data.employer.district,
            ...(template ? Object.fromEntries(Object.keys(jobEmpty).filter(k => !["status", "application_deadline"].includes(k)).map(k => [k, template[k] ?? jobEmpty[k]])) : {}),
            ...job,
        }),
        [step, setStep] = useState(0),
        [preview, setPreview] = useState(false),
        [templatesOpen, setTemplatesOpen] = useState(!!route.state?.openTemplates),
        [templateQuery, setTemplateQuery] = useState(""),
        [errors, setErrors] = useState({}),
        [message, setMessage] = useState(""),
        [busy, setBusy] = useState(false);
    const { master, districts, masterError } = useEmployerMasters(form.state);
    const set = (key, value) => {
        setForm((f) => ({
            ...f,
            [key]: value,
            ...(key === "state" ? { district: "" } : {}),
        }));
        setErrors((e) => ({ ...e, [key]: undefined }));
    };
    const save = async (status) => {
        setBusy(true);
        setMessage("");
        setErrors({});
        const payload = Object.fromEntries(
            Object.entries({ ...form, status }).map(([k, v]) => [
                k,
                v === "" ? null : v,
            ]),
        );
        try {
            const response = job
                ? await axios.put(`/employer-api/jobs/${job.id}`, payload, {
                      headers: csrf(),
                  })
                : await axios.post("/employer-api/jobs", payload, {
                      headers: csrf(),
                  });
            setData(response.data);
            if (onClose) onClose();
            else nav("/employer/jobs");
        } catch (e) {
            const fields = e.response?.data?.errors || {};
            setErrors(fields);
            setMessage(fail(e));
            const key = Object.keys(fields)[0];
            const index = jobSteps.findIndex((x) => x[1].includes(key));
            if (index >= 0) setStep(index);
            setPreview(false);
        } finally {
            setBusy(false);
        }
    };
    const validateStep = (index) => {
        const next = {};
        jobSteps[index][1].filter(k => !["benefits", "screening_questions", "salary_min", "salary_max"].includes(k)).forEach(k => {
            if (!String(form[k] ?? "").trim()) next[k] = [`${jobLabels[k]} is required before publishing.`];
        });
        if (index === 0 && (!Number.isInteger(Number(form.openings)) || Number(form.openings) < 1 || Number(form.openings) > 100000)) next.openings = ["Enter between 1 and 100,000 openings."];
        if (index === 2 && form.salary_min !== "" && form.salary_max !== "" && Number(form.salary_max) < Number(form.salary_min)) next.salary_max = ["Maximum salary must be at least the minimum salary."];
        const today = new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Kolkata',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
        if (index === 3 && form.application_deadline && form.application_deadline < today) next.application_deadline = ["Choose today or a future deadline."];
        if (Object.keys(next).length) {
            setErrors(next); setMessage("Complete the highlighted fields to continue. You can still save a draft."); setStep(index); setPreview(false);
            requestAnimationFrame(() => document.getElementById(`job-${Object.keys(next)[0]}`)?.focus());
            return false;
        }
        setErrors({}); setMessage(""); return true;
    };
    const advance = () => { if (validateStep(step)) setStep(s => s + 1); };
    const review = () => { for(let i=0;i<jobSteps.length;i++) if(!validateStep(i)) return; setPreview(true); };
    const field = (key) => {
        const options =
            key === "job_type"
                ? [
                      "Full-Time",
                      "Part-Time",
                      "Contract",
                      "Internship",
                      "Freelance",
                  ]
                : key === "workplace_type"
                  ? ["On-site", "Remote", "Hybrid"]
                  : key === "salary_type"
                    ? ["Fixed", "Hourly", "Incentive-based", "Negotiable"]
                    : key === "state"
                      ? master.states.map((x) => x.name)
                      : key === "district"
                        ? districts.map((x) => x.name)
                        : key === "industry"
                          ? master.industries.map((x) => x.name)
                          : null;
        return (
            <div
                className={
                    "field-label " +
                    ([
                        "job_type",
                        "workplace_type",
                        "salary_type",
                        "description",
                        "responsibilities",
                        "skills",
                        "screening_questions",
                        "benefits",
                    ].includes(key)
                        ? "wide"
                        : "")
                }
                key={key}
            >
                <label htmlFor={key === "benefits" ? undefined : `job-${key}`}>{jobLabels[key]}{!["benefits", "screening_questions", "salary_min", "salary_max"].includes(key) && <em className="employer-required"> *</em>}</label>
                {key === "benefits" ? (
                    <div className="employer-benefits">
                        {benefitChoices.map((b) => (
                            <label key={b}>
                                <input
                                    type="checkbox"
                                    checked={(form.benefits || "")
                                        .split(", ")
                                        .includes(b)}
                                    onChange={(e) => {
                                        const values = (form.benefits || "")
                                            .split(", ")
                                            .filter(Boolean);
                                        set(
                                            "benefits",
                                            (e.target.checked
                                                ? [...values, b]
                                                : values.filter((x) => x !== b)
                                            ).join(", "),
                                        );
                                    }}
                                />
                                {b}
                            </label>
                        ))}
                    </div>
                ) : options && ["job_type", "workplace_type", "salary_type"].includes(key) ? (
                    <div className="employer-choice-group" role="group" aria-label={jobLabels[key]} id={`job-${key}`} tabIndex={-1}>{options.map(option=><button type="button" key={option} disabled={busy} aria-pressed={form[key]===option} className={form[key]===option?"selected":""} onClick={()=>set(key,option)}>{form[key]===option && <Check aria-hidden="true"/>}{option}</button>)}</div>
                ) : options ? (
                    <select
                        id={`job-${key}`}
                        disabled={busy || (key === "district" && !form.state)}
                        aria-invalid={!!errors[key]}
                        value={form[key] || ""}
                        onChange={(e) => set(key, e.target.value)}
                    >
                        <option value="">Select {jobLabels[key].toLowerCase()}</option>
                        {options.map((x) => (
                            <option key={x} value={x}>{x === "all" ? "All statuses" : x.charAt(0).toUpperCase()+x.slice(1)}</option>
                        ))}
                        {form[key] && !options.includes(form[key]) && (
                            <option>{form[key]}</option>
                        )}
                    </select>
                ) : [
                      "description",
                      "responsibilities",
                      "skills",
                      "screening_questions",
                  ].includes(key) ? (
                    <textarea
                        id={`job-${key}`}
                        disabled={busy}
                        rows={4}
                        placeholder={`Enter ${jobLabels[key].toLowerCase()}`}
                        aria-invalid={!!errors[key]}
                        value={form[key] || ""}
                        onChange={(e) => set(key, e.target.value)}
                    />
                ) : (
                    <input
                        id={`job-${key}`}
                        disabled={busy}
                        type={
                            key === "application_deadline"
                                ? "date"
                                : [
                                        "openings",
                                        "salary_min",
                                        "salary_max",
                                    ].includes(key)
                                  ? "number"
                                  : "text"
                        }
                        placeholder={`Enter ${jobLabels[key].toLowerCase()}`}
                        aria-invalid={!!errors[key]}
                        min={key === "openings" ? 1 : 0}
                        value={form[key] ?? ""}
                        onChange={(e) => set(key, e.target.value)}
                    />
                )}
                {key === "education" && <div className="employer-suggestions">{["10th pass","12th pass","ITI","Diploma","Graduate"].map(option=><button type="button" key={option} disabled={busy} onClick={()=>set(key,option)}>{option}</button>)}</div>}
                {key === "experience" && <div className="employer-suggestions">{["Fresher","Experienced","Fresher or experienced"].map(option=><button type="button" key={option} disabled={busy} onClick={()=>set(key,option)}>{option}</button>)}</div>}
                {errors[key] && (
                    <small className="field-error">{errors[key][0]}</small>
                )}
            </div>
        );
    };
    return (
        <div className="employer-live">
            <PageHead
                kicker="EMPLOYER WORKSPACE"
                title={job ? "Edit job requirement" : "Create job requirement"}
                sub="Complete four steps, save a draft or preview before publishing."
            >
                <button className="btn primary" disabled={busy} onClick={()=>setTemplatesOpen(true)}><Layers3 aria-hidden="true"/>Use a template</button>
            </PageHead>
            <div className="employer-editor-context employer-editor-status"><span className="employer-company-icon"><Building2 aria-hidden="true"/></span><b>{data.employer.legal_name || data.employer.name}</b><Status tone={data.employer.status}>{data.employer.status === "verified" ? "Verified" : data.employer.status === "rejected" ? "Review required" : "Pending verification"}</Status>{data.employer.status !== "verified" && <p>Save drafts. Approval needed to publish.</p>}<Link className="text-link" to="/employer/onboarding">View verification</Link></div>
            {data.employer.status === "rejected" && data.employer.review_remarks && <p className="employer-error">Review remarks: {data.employer.review_remarks}</p>}
            <Modal open={templatesOpen} onClose={()=>setTemplatesOpen(false)} title="Choose a job starting point" sub="Use a role starter or one of your saved requirements. Review every detail before publishing."><label className="field-label">Search templates<input placeholder="Search by job title" value={templateQuery} onChange={e=>setTemplateQuery(e.target.value)}/></label><div className="employer-template-grid">{[...roleTemplates,...data.jobs.map(j=>({...j,templateSource:'Your saved requirement'}))].filter(t=>t.title.toLowerCase().includes(templateQuery.toLowerCase())).map((t,i)=><button key={`${t.id || t.title}-${i}`} onClick={()=>{setForm({...jobEmpty,industry:data.employer.industry,state:data.employer.state,district:data.employer.district,...Object.fromEntries(Object.keys(jobEmpty).filter(k=>!["status","application_deadline"].includes(k)&&t[k]!=null).map(k=>[k,t[k]]))});setTemplatesOpen(false);setErrors({});setMessage("");setStep(0);setPreview(false);}}><small>{t.templateSource || 'Role starter'}</small><b>{t.title}</b><span>{t.department || 'Review and adapt to your role'} →</span></button>)}</div>{![...roleTemplates,...data.jobs].some(t=>t.title.toLowerCase().includes(templateQuery.toLowerCase()))&&<p>No templates match. Try another role title.</p>}</Modal>
            <section className="panel employer-job-editor employer-guided-editor">
                <div className="employer-step-tabs">
                    {jobSteps.map(([title], i) => { const Icon = stepIcons[i]; return (
                        <button
                            type="button"
                            disabled={busy}
                            aria-current={i === step && !preview ? "step" : undefined}
                            key={title}
                            className={i === step && !preview ? "active" : ""}
                            onClick={() => {
                                setStep(i);
                                setPreview(false);
                            }}
                        >
                            <span className="employer-step-icon"><Icon aria-hidden="true" /></span><span className="employer-step-copy"><small>Step {i + 1}</small><b>{title}</b></span>
                        </button>
                    ); })}
                </div>
                {(message || masterError) && (
                    <p role="alert" className="employer-error">
                        {message || masterError}
                    </p>
                )}
                {errors.status && (
                    <p role="alert" className="employer-error">
                        {errors.status[0]}
                    </p>
                )}
                {preview ? (
                    <div className="employer-job-preview"><h2>{form.title || "Untitled job"}</h2><p>{data.employer.legal_name || data.employer.name} · Review your requirement before publishing.</p>{data.employer.status!=="verified"&&<p className="employer-error">Company approval is required to publish. Save a draft while verification is pending.</p>}{jobSteps.map(([title,keys],i)=><section className="employer-preview-section" key={title}><header><h3>{title}</h3><button className="btn ghost" onClick={()=>{setStep(i);setPreview(false);}}>Edit section</button></header><dl>{keys.map(key=><div key={key}><dt>{jobLabels[key]}</dt><dd>{form[key] || 'Not provided'}</dd></div>)}</dl></section>)}</div>
                ) : (
                    <>
                        <div className="employer-step-intro"><p>{["Define the role, workplace and number of people you need.", "Set clear eligibility and responsibilities for relevant candidates.", "Add salary and benefits so candidates understand the offer.", "Add screening questions and a deadline, then review your job."][step]}</p><span>{step+1} of 4 steps</span></div>
                        <div className="employer-section-stack">{jobSections[step].map(([heading,copy,keys],index)=>{const Icon=sectionIcons[heading];return <section className="employer-editor-section" key={heading} aria-labelledby={`job-section-${step}-${index}`}><header><span className="employer-section-icon"><Icon aria-hidden="true" /></span><div><h4 id={`job-section-${step}-${index}`}>{heading}</h4><p>{copy}</p></div><span className="employer-section-count">{keys.length} details</span></header><div className="employer-fields">{keys.map(field)}</div></section>;})}</div>
                    </>
                )}
                <footer className="employer-form-footer">
                    <button
                        className="btn ghost"
                        disabled={busy}
                        onClick={() => save("draft")}
                    >
                        <FileText aria-hidden="true" />{busy ? "Saving…" : "Save draft"}
                    </button>
                    <div>
                        {onClose && (
                            <button
                                className="btn ghost"
                                onClick={onClose}
                                disabled={busy}
                            >
                                Cancel
                            </button>
                        )}
                        {step > 0 && !preview && (
                            <button
                                className="btn ghost"
                                disabled={busy}
                                onClick={() => setStep((s) => s - 1)}
                            >
                                <ChevronLeft aria-hidden="true" />Back
                            </button>
                        )}
                        {step < 3 && !preview ? (
                            <button
                                className="btn primary"
                                disabled={busy}
                                onClick={advance}
                            >
                                Continue<ArrowRight aria-hidden="true" />
                            </button>
                        ) : !preview ? (
                            <button
                                className="btn primary"
                                disabled={busy}
                                onClick={review}
                            >
                                Preview job<ArrowRight aria-hidden="true" />
                            </button>
                        ) : (
                            <>
                                <button
                                    className="btn ghost"
                                    disabled={busy}
                                    onClick={() => setPreview(false)}
                                >
                                    Edit
                                </button>
                                <button
                                    className="btn primary"
                                    disabled={
                                        busy ||
                                        data.employer.status !== "verified"
                                    }
                                    onClick={() => save("active")}
                                >
                                    {busy ? "Saving…" : "Publish job"}
                                </button>
                            </>
                        )}
                    </div>
                </footer>
            </section>
        </div>
    );
}
