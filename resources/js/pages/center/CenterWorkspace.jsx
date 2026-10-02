import React, { useEffect, useState } from "react";
import axios from "axios";
import { Link, useNavigate } from "react-router-dom";
import {
    Users,
    CalendarDays,
    UserCheck,
    Layers3,
    RefreshCw,
    ArrowRight,
    Save,
} from "lucide-react";
import { PageHead, Panel, Stat, Status, Modal } from "../../components/UI";
import CenterProfile from "./CenterProfile";
const date = (v) =>
    v
        ? new Date(
              v.length === 10 ? `${v}T00:00:00` : v.replace(" ", "T"),
          ).toLocaleDateString("en-IN", { day: "numeric", month: "short" })
        : "Not scheduled";
const label = (v) => String(v || "Not recorded").replaceAll("_", " ");
function useWorkspace() {
    const nav = useNavigate(),
        [data, setData] = useState(null),
        [error, setError] = useState(""),
        [loading, setLoading] = useState(true);
    const load = () => {
        setLoading(true);
        setError("");
        return axios
            .get("/center-api/workspace")
            .then((r) => setData(r.data))
            .catch((e) => {
                if (e.response?.status === 401)
                    nav("/login", { replace: true });
                else
                    setError(
                        e.response?.data?.message ||
                            "Could not load center records. Please try again.",
                    );
            })
            .finally(() => setLoading(false));
    };
    useEffect(() => {
        load();
    }, []);
    return { data, error, loading, load };
}
function State({ state, children }) {
    return state.loading ? (
        <div className="center-workspace-state" role="status">
            Loading center records…
        </div>
    ) : state.error ? (
        <div className="center-workspace-state" role="alert">
            <p>{state.error}</p>
            <button className="btn ghost" onClick={state.load}>
                <RefreshCw />
                Try again
            </button>
        </div>
    ) : (
        children
    );
}
function Empty({ children }) {
    return <p className="center-workspace-empty">{children}</p>;
}
function Metrics({ items }) {
    return (
        <div className="stat-grid">
            {items.map(([name, value, icon, meta]) => (
                <Stat
                    key={name}
                    label={name}
                    value={value}
                    icon={icon}
                    meta={meta}
                />
            ))}
        </div>
    );
}
export function AvailabilityPipeline() {
    const state = useWorkspace(),
        p = state.data?.pipeline;
    return (
        <div className="center-workspace-page">
            <PageHead
                title="30-day placement pipeline"
                sub={
                    p
                        ? `${date(p.from)} – ${date(p.to)} · Live training, interview and joining records`
                        : "Plan training completions and placement activity."
                }
            >
                <Link className="btn ghost" to="/center/batches">
                    View batches <ArrowRight />
                </Link>
            </PageHead>
            <State state={state}>
                {p && (
                    <>
                        <Metrics
                            items={[
                                [
                                    "Candidates completing",
                                    p.metrics.completing,
                                    Users,
                                    "Registered learners in batches ending within 30 days",
                                ],
                                [
                                    "Upcoming interviews",
                                    p.metrics.interviews,
                                    CalendarDays,
                                    "Distinct candidates scheduled within 30 days",
                                ],
                                [
                                    "Upcoming joining",
                                    p.metrics.joining,
                                    UserCheck,
                                    "Distinct candidates with an expected joining date",
                                ],
                                [
                                    "Joining dates missing",
                                    p.metrics.undated,
                                    Layers3,
                                    "Pending candidates without a joining date",
                                ],
                            ]}
                        />
                        <Panel
                            title="Activity over the next 30 days"
                            sub="Candidate counts by week. A candidate may appear in more than one stage or week."
                        >
                            <div className="pipeline-legend">
                                <span>
                                    <i className="completion" />
                                    Training completion
                                </span>
                                <span>
                                    <i className="interview" />
                                    Interview
                                </span>
                                <span>
                                    <i className="joining" />
                                    Joining
                                </span>
                            </div>
                            <div className="pipeline-chart-columns">
                                {p.weeks.map((w) => {
                                    const max = Math.max(
                                        1,
                                        ...p.weeks.flatMap((x) => [
                                            x.candidates,
                                            x.interviews,
                                            x.joining,
                                        ]),
                                    );
                                    return (
                                        <div
                                            key={w.from}
                                            className="pipeline-week"
                                        >
                                            <div className="pipeline-bars">
                                                {[
                                                    [
                                                        "candidates",
                                                        "completion",
                                                    ],
                                                    ["interviews", "interview"],
                                                    ["joining", "joining"],
                                                ].map(([key, tone]) => (
                                                    <div
                                                        key={key}
                                                        className="pipeline-bar-slot"
                                                        aria-label={`${key}: ${w[key]}`}
                                                    >
                                                        <b>{w[key]}</b>
                                                        <i
                                                            className={tone}
                                                            style={{
                                                                height: `${(w[key] / max) * 145}px`,
                                                            }}
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                            <span>
                                                {date(w.from)} – {date(w.to)}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                            {!p.weeks.some(
                                (w) =>
                                    w.candidates || w.interviews || w.joining,
                            ) && (
                                <Empty>
                                    No dated activity in this window. Add batch
                                    end dates, interview schedules or expected
                                    joining dates to populate the chart.
                                </Empty>
                            )}
                        </Panel>
                        <Panel
                            title="Next three batches to complete"
                            sub="Ordered by the nearest expected end date."
                        >
                            <div className="pipeline-roadmap">
                                {p.batches.map((b, i) => (
                                    <article key={b.id}>
                                        <span className="pipeline-step">
                                            {i + 1}
                                        </span>
                                        <div>
                                            <Status
                                                tone={
                                                    b.ends_on <= p.to
                                                        ? "ongoing"
                                                        : "upcoming"
                                                }
                                            >
                                                {b.ends_on <= p.to
                                                    ? "Within 30 days"
                                                    : "Beyond 30 days"}
                                            </Status>
                                            <h3>
                                                {b.code} ·{" "}
                                                {b.job_role ||
                                                    "Course not recorded"}
                                            </h3>
                                            <p>
                                                Expected completion{" "}
                                                {date(b.ends_on)}
                                            </p>
                                            <p>
                                                {b.candidate_count} registered
                                                candidates · {b.available_count}{" "}
                                                marked available
                                            </p>
                                        </div>
                                        <Link
                                            className="btn ghost"
                                            to="/center/batches"
                                        >
                                            View batches <ArrowRight />
                                        </Link>
                                    </article>
                                ))}
                            </div>
                            {!p.batches.length && (
                                <Empty>
                                    No upcoming batches with an end date. Update
                                    training batches to build the roadmap.
                                </Empty>
                            )}
                        </Panel>
                        <p className="pipeline-note">
                            Forecasts use registered candidates, not batch
                            capacity. Training completion does not automatically
                            mean job readiness.{" "}
                            <Link to="/center/placements">
                                Add missing joining dates
                            </Link>
                            .
                        </p>
                    </>
                )}
            </State>
        </div>
    );
}
function Details({ values }) {
    return (
        <dl className="center-record-details">
            {Object.entries(values).map(([k, v]) => (
                <div key={k}>
                    <dt>{k}</dt>
                    <dd>{v ?? "Not recorded"}</dd>
                </div>
            ))}
        </dl>
    );
}
export function TrainingPartnerProfile() {
    const state = useWorkspace(),
        p = state.data?.partner;
    return (
        <div className="center-workspace-page">
            <PageHead
                title="Training partner"
                sub="Organization details linked to your registered center."
            />
            <State state={state}>
                {p ? (
                    <Panel title={p.name} sub="Saved organization record">
                        <Status
                            tone={
                                ["active", "verified"].includes(p.status)
                                    ? "active"
                                    : "pending"
                            }
                        >
                            {label(p.status)}
                        </Status>
                        <Details
                            values={{
                                "Legal name": p.legal_name,
                                PAN: p.pan,
                                "GST / Udyam": p.gst_udyam,
                                "Authorized person": p.authorized_person,
                                Contact: p.authorized_person_phone || p.phone,
                                Email: p.email,
                            }}
                        />
                        <p>
                            Organization identity is maintained through the
                            administration team.
                        </p>
                    </Panel>
                ) : (
                    <Panel title="Independent training center">
                        <p>
                            Your center has no linked training partner. Manage
                            its contact and location details in your profile.
                        </p>
                        <Link className="btn primary" to="/center/profile">
                            Manage center profile <ArrowRight />
                        </Link>
                    </Panel>
                )}
            </State>
        </div>
    );
}
const titles = {
    jobs: [
        "Recommended jobs",
        "Published jobs with available, completed candidates in the same industry.",
    ],
    interviews: [
        "Interview schedule",
        "Scheduled interviews and recorded outcomes. All interview times are IST.",
    ],
    placements: [
        "Placement outcomes",
        "Track selections, expected joining dates and confirmed employment.",
    ],
    performance: [
        "Center performance",
        "Placement counts calculated from your center’s saved records.",
    ],
};
export default function CenterWorkspace({ page }) {
    if (page === "settings")
        return (
            <div className="center-workspace-page">
                <CenterProfile />
            </div>
        );
    return <Records page={page} />;
}
function Records({ page }) {
    const state = useWorkspace(),
        [query, setQuery] = useState(""),
        [status, setStatus] = useState(""),
        [detail, setDetail] = useState(null),
        [joining, setJoining] = useState(""),
        [saving, setSaving] = useState(false),
        [saveError, setSaveError] = useState("");
    const [title, sub] = titles[page],
        rows = Array.isArray(state.data?.[page]) ? state.data[page] : [],
        filtered = rows.filter(
            (r) =>
                (!status || r.status === status) &&
                [
                    r.full_name,
                    r.candidate_code,
                    r.title,
                    r.job_title,
                    r.employer_name,
                    r.location,
                ]
                    .filter(Boolean)
                    .join(" ")
                    .toLowerCase()
                    .includes(query.toLowerCase()),
        );
    const open = (r) => {
            setDetail(r);
            setJoining(r.expected_joining_on || "");
            setSaveError("");
        },
        canEdit =
            detail &&
            !detail.joined_on &&
            ["selected", "offered", "joining_pending"].includes(detail.status);
    const save = async () => {
        setSaving(true);
        setSaveError("");
        try {
            await axios.patch(
                `/center-api/placements/${detail.id}/joining`,
                { expected_joining_on: joining || null },
                {
                    headers: {
                        "X-CSRF-TOKEN": document.querySelector(
                            'meta[name="csrf-token"]',
                        )?.content,
                    },
                },
            );
            setDetail(null);
            await state.load();
        } catch (e) {
            setSaveError(
                e.response?.data?.errors?.expected_joining_on?.[0] ||
                    e.response?.data?.message ||
                    "Could not save joining date.",
            );
        } finally {
            setSaving(false);
        }
    };
    return (
        <div className="center-workspace-page">
            <PageHead title={title} sub={sub}>
                <button className="btn ghost" onClick={state.load}>
                    <RefreshCw />
                    Refresh
                </button>
            </PageHead>
            <State state={state}>
                {state.data &&
                    (page === "performance" ? (
                        <>
                            <Metrics
                                items={[
                                    [
                                        "Registered candidates",
                                        state.data.performance.candidates,
                                        Users,
                                        "Current center records",
                                    ],
                                    [
                                        "Available candidates",
                                        state.data.performance.available,
                                        Users,
                                        "Marked available",
                                    ],
                                    [
                                        "Selected candidates",
                                        state.data.performance.selected,
                                        UserCheck,
                                        "Distinct candidates with selection outcomes",
                                    ],
                                    [
                                        "Joined candidates",
                                        state.data.performance.joined,
                                        UserCheck,
                                        "Actual joining date recorded",
                                    ],
                                ]}
                            />
                            <Panel
                                title="Placement progress"
                                sub="Distinct candidate counts from saved outcomes; stages can overlap."
                            >
                                <div className="center-funnel">
                                    {[
                                        [
                                            "Completed interviews",
                                            state.data.performance.interviewed,
                                        ],
                                        [
                                            "Selected",
                                            state.data.performance.selected,
                                        ],
                                        [
                                            "Joined",
                                            state.data.performance.joined,
                                        ],
                                    ].map(([k, v]) => (
                                        <div key={k}>
                                            <span>{k}</span>
                                            <b>{v}</b>
                                            <i>
                                                <em
                                                    style={{
                                                        width: `${state.data.performance.candidates ? Math.min(100, (v / state.data.performance.candidates) * 100) : 0}%`,
                                                    }}
                                                />
                                            </i>
                                        </div>
                                    ))}
                                </div>
                                <p>
                                    Joined share of registered candidates:{" "}
                                    {state.data.performance.candidates
                                        ? Math.round(
                                              (state.data.performance.joined /
                                                  state.data.performance
                                                      .candidates) *
                                                  100,
                                          )
                                        : 0}
                                    %. Retention will require recorded check-in
                                    outcomes.
                                </p>
                            </Panel>
                        </>
                    ) : (
                        <>
                            <div className="center-workspace-filters">
                                <label>
                                    Search records
                                    <input
                                        value={query}
                                        onChange={(e) =>
                                            setQuery(e.target.value)
                                        }
                                        placeholder={
                                            page === "jobs"
                                                ? "Search role, employer or location"
                                                : "Search candidate, role or employer"
                                        }
                                    />
                                </label>
                                <label>
                                    Status
                                    <select
                                        value={status}
                                        onChange={(e) =>
                                            setStatus(e.target.value)
                                        }
                                    >
                                        <option value="">All statuses</option>
                                        {[
                                            ...new Set(
                                                rows.map((r) => r.status),
                                            ),
                                        ].map((s) => (
                                            <option key={s} value={s}>
                                                {label(s)}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <section className="workspace-card data-table">
                                <div className="table-scroll">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>
                                                    {page === "jobs"
                                                        ? "Requirement"
                                                        : "Candidate"}
                                                </th>
                                                <th>Employer / role</th>
                                                <th>
                                                    {page === "jobs"
                                                        ? "Matches / openings"
                                                        : page === "interviews"
                                                          ? "Schedule"
                                                          : "Joining"}
                                                </th>
                                                <th>Status</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {filtered.map((r) => (
                                                <tr key={r.id}>
                                                    <td>
                                                        <b>
                                                            {r.full_name ||
                                                                r.title}
                                                        </b>
                                                        <small>
                                                            {r.candidate_code ||
                                                                r.industry ||
                                                                "Industry not recorded"}
                                                        </small>
                                                    </td>
                                                    <td>
                                                        {r.employer_name ||
                                                            "Employer not recorded"}
                                                        <small>
                                                            {r.job_title ||
                                                                [
                                                                    r.location,
                                                                    r.district,
                                                                    r.state,
                                                                ]
                                                                    .filter(
                                                                        Boolean,
                                                                    )
                                                                    .join(
                                                                        ", ",
                                                                    ) ||
                                                                "Location not recorded"}
                                                        </small>
                                                    </td>
                                                    <td>
                                                        {page === "jobs"
                                                            ? `${r.candidate_fit_count} candidates / ${r.openings} openings`
                                                            : date(
                                                                  page ===
                                                                      "interviews"
                                                                      ? r.scheduled_at
                                                                      : r.joined_on ||
                                                                            r.expected_joining_on,
                                                              )}
                                                        <small>
                                                            {page ===
                                                            "interviews"
                                                                ? `${label(r.mode)} · ${r.scheduled_at?.slice(11, 16)}`
                                                                : page ===
                                                                    "placements"
                                                                  ? r.joined_on
                                                                      ? "Actual joining"
                                                                      : "Expected joining"
                                                                  : ""}
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <Status>
                                                            {label(r.status)}
                                                        </Status>
                                                    </td>
                                                    <td>
                                                        <button
                                                            className="btn ghost"
                                                            onClick={() =>
                                                                open(r)
                                                            }
                                                            aria-label={`View ${r.full_name || r.title}`}
                                                        >
                                                            View <ArrowRight />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {!filtered.length && (
                                    <Empty>
                                        {rows.length
                                            ? "No records match these filters."
                                            : page === "jobs"
                                              ? "No published jobs currently match your available, completed candidates."
                                              : "No records have been recorded for this center yet."}
                                    </Empty>
                                )}
                            </section>
                        </>
                    ))}
            </State>
            <Modal
                open={!!detail}
                onClose={() => {
                    if (!saving) setDetail(null);
                }}
                title={detail?.full_name || detail?.title || "Record details"}
                sub="Saved details from your center workspace."
                footer={
                    <>
                        <button
                            className="btn ghost"
                            disabled={saving}
                            onClick={() => setDetail(null)}
                        >
                            Close
                        </button>
                        {page === "placements" && canEdit && (
                            <button
                                className="btn primary"
                                disabled={saving}
                                onClick={save}
                            >
                                <Save />
                                {saving ? "Saving…" : "Save joining date"}
                            </button>
                        )}
                    </>
                }
            >
                {detail && (
                    <>
                        <Details
                            values={{
                                Employer: detail.employer_name,
                                Role: detail.job_title || detail.title,
                                Status: label(detail.status),
                                ...(page === "interviews"
                                    ? {
                                          Schedule: date(detail.scheduled_at),
                                          Time: detail.scheduled_at?.slice(
                                              11,
                                              16,
                                          ),
                                          Mode: detail.mode,
                                          Location: detail.location,
                                          Notes: detail.notes,
                                      }
                                    : page === "placements"
                                      ? {
                                            "Offered on": date(
                                                detail.offered_on,
                                            ),
                                            "Joined on": date(detail.joined_on),
                                            "Monthly salary":
                                                detail.monthly_salary
                                                    ? `₹${Number(detail.monthly_salary).toLocaleString("en-IN")}`
                                                    : null,
                                        }
                                      : {
                                            Industry: detail.industry,
                                            Location: [
                                                detail.location,
                                                detail.district,
                                                detail.state,
                                            ]
                                                .filter(Boolean)
                                                .join(", "),
                                            Openings: detail.openings,
                                            "Available candidates in industry":
                                                detail.candidate_fit_count,
                                            Description: detail.description,
                                        }),
                            }}
                        />
                        {page === "placements" && canEdit && (
                            <label className="field-label">
                                Expected joining date
                                <input
                                    type="date"
                                    value={joining}
                                    disabled={saving}
                                    onChange={(e) => setJoining(e.target.value)}
                                />
                                <small>
                                    Use the agreed joining date. Leave blank if
                                    it has not been scheduled.
                                </small>
                            </label>
                        )}
                        {saveError && (
                            <p role="alert" className="center-save-error">
                                {saveError}
                            </p>
                        )}
                    </>
                )}
            </Modal>
        </div>
    );
}
