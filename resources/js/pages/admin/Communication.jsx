import React, { useEffect, useRef, useState } from "react";
import axios from "axios";
import {
    HelpCircle,
    Mail,
    MessageCircle,
    Plus,
    Save,
    Send,
    RefreshCw,
    Trash2,
    Search,
    Settings2,
    ShieldCheck,
    KeyRound,
    Webhook,
    ExternalLink,
    Copy,
    CheckCircle2,
} from "lucide-react";
import { Modal, Status } from "../../components/UI";
import "./communication.css";

const base = "/admin-api/communication";
const headers = () => ({
    "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content,
});
const roles = [
    ["candidate", "Candidates"],
    ["employer", "Employers"],
    ["training_partner", "Training partners / centers"],
    ["agent", "Placement agents"],
    ["admin", "Administrators"],
    ["superadmin", "Super administrators"],
];
const emptyTemplate = { name: "", subject: "", body: "", is_active: true };
const emptyWhatsapp = {
    name: "",
    language: "en_US",
    category: "UTILITY",
    body: "",
    examples: [],
};
const helpContent = {
    smtp: {
        title: "Set up SMTP email",
        body: (
            <>
                <p>
                    SMTP sends email through your mail provider. Obtain these
                    details from your hosting control panel or provider’s SMTP /
                    transactional email settings.
                </p>
                <ol>
                    <li>
                        Create a sender mailbox or verify your sending domain
                        with your provider.
                    </li>
                    <li>
                        Copy the SMTP server hostname, port and username. Use
                        the SMTP password or app password supplied by the
                        provider.
                    </li>
                    <li>
                        Choose STARTTLS (typically port 587) or SSL (typically
                        port 465), following your provider’s instructions.
                    </li>
                    <li>
                        Enter a verified From address and the display name
                        recipients should see.
                    </li>
                    <li>
                        Enable SMTP, save, and send a test email to yourself.
                        Check spam and your provider’s logs if it does not
                        arrive.
                    </li>
                </ol>
                <p>
                    Your domain’s SPF, DKIM and DMARC records should follow the
                    provider’s setup guide. Passwords are encrypted in storage;
                    leave a password blank to keep the saved value. Disable SMTP
                    to stop admin email sending.
                </p>
            </>
        ),
    },
    whatsapp: {
        title: "WhatsApp Business API setup",
        body: (
            <>
                <p>
                    Meta’s WhatsApp Cloud API lets EXOWORK send approved
                    messages from your business number and receive delivery
                    updates. You need a Meta business portfolio, a WhatsApp
                    Business Account (WABA), a registered business phone number
                    and an access token.
                </p>
                <ol>
                    <li>
                        Open{" "}
                        <a
                            href="https://developers.facebook.com/apps/"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Meta for Developers
                        </a>
                        , create a business app with the WhatsApp use case, and
                        connect your business portfolio.
                    </li>
                    <li>
                        Open WhatsApp → API Setup / Getting Started. Copy the
                        Phone Number ID and WhatsApp Business Account ID. These
                        are numeric IDs, not the phone number itself.
                    </li>
                    <li>
                        Use the temporary token for initial testing. For
                        production, open{" "}
                        <a
                            href="https://business.facebook.com/settings/"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Business Settings
                        </a>{" "}
                        → Users → System Users. Assign the app and WhatsApp
                        account, then generate a token with{" "}
                        <code>whatsapp_business_messaging</code> and{" "}
                        <code>whatsapp_business_management</code>. Choose the
                        available expiry appropriate for your account and
                        replace tokens before they expire.
                    </li>
                    <li>
                        Copy the App Secret from the app’s Settings → Basic.
                        Create your own random webhook Verify Token and save it
                        here.
                    </li>
                    <li>
                        Publish EXOWORK on a public HTTPS URL. In Meta’s
                        WhatsApp webhook configuration, paste the callback URL
                        shown below and the same Verify Token. Subscribe to the
                        messages field and subscribe the app to your WABA.
                        Localhost cannot receive Meta webhooks.
                    </li>
                    <li>
                        Register / verify the production number, complete the
                        business and billing requirements shown by Meta, and
                        choose a supported Graph API version.
                    </li>
                    <li>
                        Enable WhatsApp and save. Open WhatsApp templates and
                        refresh to check the connection. Approved supported
                        templates then appear in candidate messaging.
                    </li>
                </ol>
                <p>
                    Only candidates with recorded WhatsApp consent receive
                    messages. Replies such as STOP withdraw consent through the
                    webhook. Meta controls approval, account limits and charges.
                </p>
                <a
                    href="https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api"
                    target="_blank"
                    rel="noreferrer"
                >
                    Meta’s official Cloud API reference and setup instructions
                </a>
            </>
        ),
    },
    email: {
        title: "Email templates and sending",
        body: (
            <>
                <p>
                    Edit the system templates to control registration
                    invitations and employer password reset emails. Their helper
                    lists the required link placeholders. Disabling or deleting
                    a system template restores its built-in message. Create
                    reusable plain text messages, then choose them in Email
                    management. Use <code>{"{{name}}"}</code>,{" "}
                    <code>{"{{email}}"}</code> and <code>{"{{app_name}}"}</code>{" "}
                    for personalized subject lines and messages. The preview
                    uses sample details.
                </p>
                <ol>
                    <li>
                        Create a template with a name, subject and message.
                        Inactive templates remain editable but cannot be
                        selected in the composer.
                    </li>
                    <li>
                        Select a user type, search the recipient list and tick
                        individual users. You can combine recipients from
                        different types, up to 100 per send.
                    </li>
                    <li>
                        Choose a template or write a message, then review
                        recipients and preview before sending.
                    </li>
                    <li>
                        Keep this page open while sending. Each recipient
                        receives an individual message; addresses are not
                        exposed to other recipients.
                    </li>
                    <li>
                        Check Send history. “Accepted” means the SMTP server
                        accepted it, not confirmed inbox delivery. If
                        interrupted, resume pending recipients from history.
                        Sending / unknown records need provider-log verification
                        before a new send.
                    </li>
                </ol>
                <p>
                    Candidate lists include active profiles with email consent.
                    The system checks eligibility again before sending.
                    Configure SMTP before sending; the test button sends a real
                    email to the address you enter.
                </p>
            </>
        ),
    },
    templates: {
        title: "WhatsApp template helper",
        body: (
            <>
                <p>
                    WhatsApp business initiated messages require Meta approved
                    templates. This panel creates text body templates and lists
                    all templates directly from your WABA.
                </p>
                <ol>
                    <li>
                        Choose a unique lowercase name using letters, numbers
                        and underscores, such as <code>interview_reminder</code>
                        .
                    </li>
                    <li>
                        Use a Meta language code such as <code>en_US</code> or{" "}
                        <code>hi</code>. Select Utility for a specific service
                        update or Marketing for promotional content. Meta
                        determines the final category.
                    </li>
                    <li>
                        Write your message. Dynamic values must use consecutive
                        placeholders <code>{"{{1}}"}</code>,{" "}
                        <code>{"{{2}}"}</code>, etc. Add one realistic sample
                        value per placeholder.
                    </li>
                    <li>
                        Submit to Meta for review. Refresh to see Pending,
                        Approved or Rejected status. Review rejection details or
                        advanced templates in{" "}
                        <a
                            href="https://business.facebook.com/wa/manage/message-templates/"
                            target="_blank"
                            rel="noreferrer"
                        >
                            WhatsApp Manager
                        </a>
                        .
                    </li>
                    <li>
                        Approved templates with positional body parameters and
                        optional static text header/footer appear in candidate
                        Send WhatsApp dialogs. Media, buttons and named
                        parameters currently require another integration.
                    </li>
                </ol>
                <p>
                    Use Edit text for body-only templates in this panel. For
                    advanced templates, use WhatsApp Manager and refresh here
                    after review. Meta limits edits and may send changes for
                    another review. Deleting by name removes every language
                    variant with that name from Meta. Only approved templates
                    may be sent; saving here cannot bypass Meta’s review.
                </p>
            </>
        ),
    },
};
const preview = (value) =>
    value
        .replaceAll("{{name}}", "Asha Sharma")
        .replaceAll("{{email}}", "asha@example.com")
        .replaceAll("{{app_name}}", "EXOWORK")
        .replaceAll("{{center_name}}", "Jaipur Training Center")
        .replaceAll("{{partner_name}}", "Skills Partner")
        .replaceAll(
            "{{registration_url}}",
            "https://example.com/register/candidate",
        )
        .replaceAll(
            "{{reset_url}}",
            "https://example.com/reset-password/sample",
        )
        .replaceAll("{{expiry_minutes}}", "60");

export default function Communication({ initialTab = "Email management", onlyTab = false }) {
    const [tab, setTab] = useState(initialTab),
        [config, setConfig] = useState(null),
        [error, setError] = useState(""),
        [notice, setNotice] = useState(""),
        [busy, setBusy] = useState(false),
        [help, setHelp] = useState(null);
    const [template, setTemplate] = useState(null),
        [waDraft, setWaDraft] = useState(emptyWhatsapp),
        [waTemplates, setWaTemplates] = useState(null),
        [testEmail, setTestEmail] = useState("");
    const [role, setRole] = useState("candidate"),
        [search, setSearch] = useState(""),
        [page, setPage] = useState(1),
        [recipients, setRecipients] = useState(null),
        [selected, setSelected] = useState({}),
        [recipientError, setRecipientError] = useState("");
    const [subject, setSubject] = useState(""),
        [body, setBody] = useState(""),
        [history, setHistory] = useState(null),
        [historyPage, setHistoryPage] = useState(1),
        [review, setReview] = useState(false),
        [progress, setProgress] = useState(""),
        [deleteTarget, setDeleteTarget] = useState(null);
    const requestKey = useRef(null),
        running = useRef(false);
    const settingsFields = useRef({});
    const [fieldErrors, setFieldErrors] = useState({});
    const [settingsToast, setSettingsToast] = useState(null);
    useEffect(() => {
        if (tab !== "WhatsApp API" || (!error && !notice)) return;
        setSettingsToast({ error: !!error, message: error || notice });
    }, [error, notice, tab]);
    useEffect(() => {
        if (!settingsToast) return;
        const timer = setTimeout(() => setSettingsToast(null), settingsToast.error ? 10000 : 5000);
        return () => clearTimeout(timer);
    }, [settingsToast]);
    const load = async () => {
        const { data } = await axios.get(base);
        setConfig(data);
    };
    useEffect(() => {
        load().catch((e) =>
            setError(
                e.response?.data?.message ||
                    "Could not load communication settings.",
            ),
        );
    }, []);
    useEffect(() => {
        let active = true;
        const timer = setTimeout(() => {
            setRecipients(null);
            setRecipientError("");
            axios
                .get(`${base}/recipients`, {
                    params: { type: role, search, page },
                })
                .then(({ data }) => {
                    if (active) setRecipients(data);
                })
                .catch((e) => {
                    if (active)
                        setRecipientError(
                            e.response?.data?.message ||
                                "Could not load recipients.",
                        );
                });
        }, 250);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [role, search, page]);
    useEffect(() => {
        if (tab !== "Send history") return;
        let active = true;
        axios
            .get(`${base}/emails`, { params: { page: historyPage } })
            .then(({ data }) => {
                if (active) setHistory(data);
            })
            .catch((e) => {
                if (active)
                    setError(
                        e.response?.data?.message || "Could not load history.",
                    );
            });
        return () => {
            active = false;
        };
    }, [tab, historyPage]);
    useEffect(() => {
        if (!busy) return;
        const guard = (e) => {
            e.preventDefault();
            e.returnValue = "";
        };
        window.addEventListener("beforeunload", guard);
        return () => window.removeEventListener("beforeunload", guard);
    }, [busy]);
    const action = async (task) => {
        if (running.current) return;
        running.current = true;
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await task();
        } catch (e) {
            setError(
                Object.values(e.response?.data?.errors || {})
                    .flat()
                    .join(" ") ||
                    e.response?.data?.message ||
                    "The request could not be completed.",
            );
        } finally {
            running.current = false;
            setBusy(false);
        }
    };
    const saveSettings = (channel) =>
        action(async () => {
            setFieldErrors({});
            setSettingsToast(null);
            try {
                await axios.put(`${base}/settings/${channel}`, config[channel], {
                    headers: headers(),
                });
            } catch (e) {
                const errors = e.response?.data?.errors || {};
                setFieldErrors(errors);
                const key = Object.keys(settingsFields.current).find(key => errors[key]);
                if (key) {
                    settingsFields.current[key].focus({ preventScroll: true });
                    settingsFields.current[key].scrollIntoView({ behavior: "smooth", block: "center" });
                }
                throw e;
            }
            await load();
            setNotice(channel === "whatsapp" ? "WhatsApp connection saved successfully." : "Settings saved.");
        });
    const change = (channel, key, value) =>
        setConfig((c) => ({
            ...c,
            [channel]: { ...c[channel], [key]: value },
        }));
    const field = (channel, key, label, type = "text", hint = "") => (
        <label className="field-label">
            {label}
            <input
                ref={element => { settingsFields.current[key] = element; }}
                aria-invalid={!!fieldErrors[key]}
                aria-describedby={fieldErrors[key] ? `settings-error-${key}` : undefined}
                type={type}
                value={config[channel][key] ?? ""}
                autoComplete={type === "password" ? "new-password" : "off"}
                onChange={(e) =>
                    change(
                        channel,
                        key,
                        type === "number"
                            ? Number(e.target.value)
                            : e.target.value,
                    )
                }
                placeholder={
                    type === "password" && config[channel][key + "_configured"]
                        ? "Saved — leave blank to keep"
                        : ""
                }
            />
            {fieldErrors[key] && <small id={`settings-error-${key}`} className="comm-field-error">{[].concat(fieldErrors[key]).join(" ")}</small>}
            {hint && <small>{hint}</small>}
        </label>
    );
    const refreshWa = async () => {
        const { data } = await axios.get(`${base}/whatsapp-templates`);
        setWaTemplates(data.templates);
    };
    const saveTemplate = () =>
        action(async () => {
            await axios({
                method: template.id ? "put" : "post",
                url: `${base}/email-templates${template.id ? "/" + template.id : ""}`,
                data: template,
                headers: headers(),
            });
            setTemplate(null);
            await load();
            setNotice("Email template saved.");
        });
    const process = async (id) => {
        let result;
        do {
            const { data } = await axios.post(
                `${base}/emails/${id}/process`,
                {},
                { headers: headers() },
            );
            result = data;
            setProgress(
                `${data.deliveries.filter((x) => x.status !== "pending").length} of ${data.deliveries.length} processed`,
            );
        } while (result.remaining > 0);
        setNotice(
            `Finished: ${result.deliveries.filter((x) => x.status === "accepted").length} accepted by SMTP, ${result.deliveries.filter((x) => x.status === "unknown" || x.status === "sending").length} unconfirmed, ${result.deliveries.filter((x) => x.status === "skipped").length} skipped. See Send history.`,
        );
        const { data } = await axios.get(`${base}/emails`);
        setHistory(data);
    };
    const send = () =>
        action(async () => {
            if (!requestKey.current) requestKey.current = crypto.randomUUID();
            const { data } = await axios.post(
                `${base}/emails`,
                {
                    request_key: requestKey.current,
                    subject,
                    body,
                    recipients: Object.values(selected).map((r) => ({
                        id: r.id,
                        type: r.type,
                    })),
                },
                { headers: headers() },
            );
            setReview(false);
            await process(data.id);
            requestKey.current = null;
            setSelected({});
            setTab("Send history");
        });
    const toggle = (recipient) => {
        const key = `${role}:${recipient.id}`;
        setSelected((old) => {
            const next = { ...old };
            if (next[key]) delete next[key];
            else if (Object.keys(next).length < 100)
                next[key] = { ...recipient, type: role };
            return next;
        });
        requestKey.current = null;
    };
    const Help = ({ kind }) => (
        <button
            type="button"
            className="btn ghost"
            onClick={() => setHelp(kind)}
        >
            <HelpCircle size={16} />
            Setup help
        </button>
    );
    if (!config)
        return (
            <div className="data-card comm-card">
                {error ? (
                    <p role="alert">{error}</p>
                ) : (
                    <p role="status">Loading communication settings…</p>
                )}
            </div>
        );
    return (
        <div className="comm-workspace">
            {!onlyTab && <div className="comm-title">
                <div>
                    <h2>{onlyTab ? "WhatsApp API settings" : "Communication center"}</h2>
                    <p>
                        Email, templates and business messaging under your
                        control.
                    </p>
                </div>
                <Help
                    kind={
                        tab === "SMTP settings"
                            ? "smtp"
                            : tab === "WhatsApp API"
                              ? "whatsapp"
                              : tab === "WhatsApp templates"
                                ? "templates"
                                : "email"
                    }
                />
            </div>
            }
            <div className="comm-tabs" role="tablist" hidden={onlyTab}>
                {[
                    "Email management",
                    "Mail templates",
                    "SMTP settings",
                    "WhatsApp API",
                    "WhatsApp templates",
                    "Send history",
                ].map((name) => (
                    <button
                        role="tab"
                        aria-selected={tab === name}
                        key={name}
                        disabled={busy}
                        className={tab === name ? "active" : ""}
                        onClick={() => {
                            setTab(name);
                            setError("");
                            setNotice("");
                        }}
                    >
                        {name}
                    </button>
                ))}
            </div>
            {tab === "WhatsApp API" && settingsToast && <div className={`comm-settings-toast ${settingsToast.error ? "error" : "success"}`} role={settingsToast.error ? "alert" : "status"}><span>{settingsToast.error ? <HelpCircle size={21}/> : <CheckCircle2 size={21}/>}</span><div><strong>{settingsToast.error ? "Unable to complete action" : "Success"}</strong><p>{settingsToast.message}</p></div><button type="button" aria-label="Dismiss notification" onClick={()=>setSettingsToast(null)}>×</button></div>}
            {error && tab !== "WhatsApp API" && (
                <p className="comm-alert error" role="alert">
                    {error}
                </p>
            )}
            {notice && tab !== "WhatsApp API" && (
                <p className="comm-alert success" role="status">
                    {notice}
                </p>
            )}
            {busy && progress && (
                <p role="status">
                    {progress} · Keep this page open until sending finishes.
                </p>
            )}
            {tab === "SMTP settings" && (
                <section className="data-card comm-card">
                    <h3>
                        <Mail size={20} />
                        SMTP connection
                    </h3>
                    <p>
                        Use your mail provider’s SMTP credentials. Save before
                        testing.
                    </p>
                    <label className="comm-check">
                        <input
                            type="checkbox"
                            checked={config.smtp.enabled}
                            onChange={(e) =>
                                change("smtp", "enabled", e.target.checked)
                            }
                        />
                        Enable SMTP email sending
                    </label>
                    <div className="form-grid">
                        {field(
                            "smtp",
                            "host",
                            "SMTP host",
                            "text",
                            "Example: smtp.your-provider.com",
                        )}
                        {field("smtp", "port", "Port", "number")}
                        <label className="field-label">
                            Encryption
                            <select
                                value={config.smtp.encryption}
                                onChange={(e) =>
                                    change("smtp", "encryption", e.target.value)
                                }
                            >
                                <option value="tls">STARTTLS</option>
                                <option value="ssl">SSL / TLS</option>
                                <option value="none">
                                    None (provider must support this)
                                </option>
                            </select>
                        </label>
                        {field("smtp", "username", "SMTP username")}
                        {field(
                            "smtp",
                            "password",
                            "SMTP password",
                            "password",
                            "Leave blank to retain the saved password.",
                        )}
                        {field(
                            "smtp",
                            "from_address",
                            "From email address",
                            "email",
                        )}
                        {field("smtp", "from_name", "Sender display name")}
                    </div>
                    <button
                        className="btn primary"
                        disabled={busy}
                        onClick={() => saveSettings("smtp")}
                    >
                        <Save size={16} />
                        Save SMTP settings
                    </button>
                    <div className="comm-test">
                        <label className="field-label">
                            Send a test email
                            <input
                                type="email"
                                value={testEmail}
                                onChange={(e) => setTestEmail(e.target.value)}
                                placeholder="you@example.com"
                            />
                        </label>
                        <button
                            className="btn ghost"
                            disabled={
                                busy || !testEmail || !config.smtp.enabled
                            }
                            onClick={() =>
                                action(async () => {
                                    const { data } = await axios.post(
                                        `${base}/smtp/test`,
                                        { email: testEmail },
                                        { headers: headers() },
                                    );
                                    setNotice(data.message);
                                })
                            }
                        >
                            <Send size={16} />
                            Send test
                        </button>
                    </div>
                </section>
            )}
            {tab === "WhatsApp API" && (
                <div className="wa-api-layout">
                    <section className="wa-api-main">
                        <div className="wa-api-panel-head">
                            <span className="wa-api-heading-icon"><MessageCircle size={22}/></span>
                            <div><h3>Business account connection</h3><p>Your sender, credentials and webhook in one place.</p></div>
                            <Help kind="whatsapp"/>
                        </div>
                        <label className="wa-enable-row">
                            <span><b>WhatsApp messaging</b><small>Allow approved templates to be sent from your business number.</small></span>
                            <span className="wa-switch"><input type="checkbox" checked={Boolean(config.whatsapp.enabled)} onChange={e=>change("whatsapp","enabled",e.target.checked)}/><i/></span>
                        </label>
                        <div className="wa-settings-section">
                            <div className="wa-settings-section-head"><span className="wa-settings-number">01</span><div><h4>Account identity</h4><p>Find these IDs in Meta Developers → WhatsApp → API Setup.</p></div></div>
                            <div className="wa-settings-fields">
                                {field("whatsapp","phone_number_id","Phone Number ID")}
                                {field("whatsapp","business_account_id","Business Account ID (WABA)")}
                            </div>
                        </div>
                        <div className="wa-settings-section">
                            <div className="wa-settings-section-head"><span className="wa-settings-number purple"><KeyRound size={17}/></span><div><h4>Access & security</h4><p>Credentials are encrypted. Leave a saved secret blank to keep it.</p></div><span className="wa-security-label"><ShieldCheck size={13}/>Protected</span></div>
                            <div className="wa-settings-fields">
                                {field("whatsapp","access_token","Access token","password","Use a system user token for production.")}
                                {field("whatsapp","app_secret","Meta app secret","password","Meta app settings → Basic.")}
                                {field("whatsapp","verify_token","Webhook verify token","password","Use the same value in Meta webhook settings.")}
                            </div>
                        </div>
                        <div className="wa-settings-section">
                            <div className="wa-settings-section-head"><span className="wa-settings-number blue"><Webhook size={17}/></span><div><h4>Delivery & webhooks</h4><p>Receive message delivery and read updates securely.</p></div></div>
                            <div className="wa-settings-fields">
                                {field("whatsapp","api_version","Graph API version","text","For example, v26.0.")}
                                {field("whatsapp","default_country_code","Default country code","text","91 for India. Do not include +.")}
                            </div>
                            <div className="wa-webhook-box"><label htmlFor="wa-callback">Webhook callback URL</label><div><input id="wa-callback" readOnly value={config.webhook_url}/><button className="wa-copy-link" aria-label="Copy webhook callback URL" onClick={async()=>{try{await navigator.clipboard.writeText(config.webhook_url);setNotice("Webhook URL copied.");}catch{setError("Select and copy the webhook URL manually.");}}}><Copy size={16}/></button></div><small>A publicly reachable HTTPS URL is required for Meta delivery updates.</small></div>
                        </div>
                        <div className="wa-settings-footer"><span><ShieldCheck size={15}/>Secrets stay encrypted</span><button className="btn wa-button-save" disabled={busy} onClick={()=>saveSettings("whatsapp")}><Save size={16}/>{busy?"Saving…":"Save connection"}</button></div>
                    </section>
                    <aside className="wa-api-aside">
                        <div className="wa-connection-card"><div className="wa-brand-symbol"><MessageCircle size={30}/></div><span>MESSAGING CHANNEL</span><h3>WhatsApp Business</h3><p>One connected number.<br/>Every step of the employment journey.</p><div className={"wa-connection-state "+(config.whatsapp.enabled?"enabled":"")}><i/>{config.whatsapp.enabled?"Messaging enabled":"Messaging disabled"}</div></div>
                        <div className="wa-setup-checklist"><h4>Ready to connect?</h4><p>A few essentials before your first message.</p><div><span><CheckCircle2 size={17}/></span><p><b>Business account</b><small>Add your WABA and sender IDs.</small></p></div><div><span className="purple"><KeyRound size={17}/></span><p><b>Production access</b><small>Use a token with messaging and management permissions.</small></p></div><div><span className="blue"><Webhook size={17}/></span><p><b>Delivery updates</b><small>Verify your public HTTPS webhook in Meta.</small></p></div><a className="wa-meta-link" href="https://developers.facebook.com/apps/" target="_blank" rel="noreferrer">Open Meta Developers<ExternalLink size={15}/></a></div>
                        <div className="wa-api-note"><ShieldCheck size={18}/><p>Only approved templates are sent. Recipient consent is checked before every message.</p></div>
                    </aside>
                </div>
            )}
            {tab === "Mail templates" && (
                <section className="data-card comm-card">
                    <div className="comm-section-head">
                        <div>
                            <h3>Reusable email templates</h3>
                            <p>
                                Personalize with {"{{name}}"}, {"{{email}}"} and{" "}
                                {"{{app_name}}"}.
                            </p>
                        </div>
                        <button
                            className="btn primary"
                            disabled={busy}
                            onClick={() => setTemplate({ ...emptyTemplate })}
                        >
                            <Plus size={16} />
                            Create template
                        </button>
                    </div>
                    {!config.templates.length ? (
                        <p className="comm-empty">
                            No templates yet. Create your first reusable email.
                        </p>
                    ) : (
                        <div className="comm-template-grid">
                            {config.templates.map((t) => (
                                <article key={t.id}>
                                    <div>
                                        <h4>{t.name}</h4>
                                        <Status
                                            tone={
                                                t.is_active
                                                    ? "active"
                                                    : "inactive"
                                            }
                                        >
                                            {t.is_active
                                                ? "Active"
                                                : "Inactive"}
                                        </Status>
                                    </div>
                                    {t.system_key && (
                                        <small>
                                            System email · used automatically
                                        </small>
                                    )}
                                    <b>{t.subject}</b>
                                    <p>
                                        {t.body.slice(0, 160)}
                                        {t.body.length > 160 ? "…" : ""}
                                    </p>
                                    <button
                                        className="btn ghost"
                                        onClick={() =>
                                            setTemplate({
                                                ...t,
                                                is_active: Boolean(t.is_active),
                                            })
                                        }
                                    >
                                        Edit & preview
                                    </button>
                                    <button
                                        className="btn ghost"
                                        onClick={() =>
                                            setDeleteTarget({
                                                kind: "email",
                                                id: t.id,
                                                name: t.name,
                                            })
                                        }
                                    >
                                        <Trash2 size={15} />
                                        Delete
                                    </button>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            )}
            {tab === "Email management" && (
                <div className="comm-compose-grid">
                    <section className="data-card comm-card">
                        <h3>
                            Choose recipients{" "}
                            <span className="comm-count">
                                {Object.keys(selected).length} / 100
                            </span>
                        </h3>
                        <label className="field-label">
                            User type
                            <select
                                disabled={busy}
                                value={role}
                                onChange={(e) => {
                                    setRole(e.target.value);
                                    setPage(1);
                                }}
                            >
                                {roles.map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="field-label">
                            Search name or email
                            <input
                                disabled={busy}
                                value={search}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setPage(1);
                                }}
                                placeholder="Search recipients…"
                            />
                        </label>
                        <p className="comm-hint">
                            Candidates require an active profile and email
                            consent.
                        </p>
                        {recipientError && <p role="alert">{recipientError}</p>}
                        {!recipients && !recipientError ? (
                            <p role="status">Loading recipients…</p>
                        ) : (
                            recipients && (
                                <>
                                    <label className="comm-check">
                                        <input
                                            type="checkbox"
                                            disabled={
                                                busy || !recipients.data.length
                                            }
                                            checked={
                                                recipients.data.length > 0 &&
                                                recipients.data.every(
                                                    (r) =>
                                                        selected[
                                                            `${role}:${r.id}`
                                                        ],
                                                )
                                            }
                                            onChange={(e) => {
                                                const checked =
                                                    e.target.checked;
                                                setSelected((old) => {
                                                    const next = { ...old };
                                                    for (const r of recipients.data) {
                                                        const key = `${role}:${r.id}`;
                                                        if (!checked)
                                                            delete next[key];
                                                        else if (
                                                            Object.keys(next)
                                                                .length < 100
                                                        )
                                                            next[key] = {
                                                                ...r,
                                                                type: role,
                                                            };
                                                    }
                                                    return next;
                                                });
                                                requestKey.current = null;
                                            }}
                                        />
                                        Select this page
                                    </label>
                                    <div className="comm-recipient-list">
                                        {recipients.data.map((r) => (
                                            <label key={r.id}>
                                                <input
                                                    type="checkbox"
                                                    disabled={
                                                        busy ||
                                                        (!selected[
                                                            `${role}:${r.id}`
                                                        ] &&
                                                            Object.keys(
                                                                selected,
                                                            ).length >= 100)
                                                    }
                                                    checked={Boolean(
                                                        selected[
                                                            `${role}:${r.id}`
                                                        ],
                                                    )}
                                                    onChange={() => toggle(r)}
                                                />
                                                <span>
                                                    <b>{r.name}</b>
                                                    <small>{r.email}</small>
                                                </span>
                                            </label>
                                        ))}
                                        {!recipients.data.length && (
                                            <p>
                                                No eligible recipients match
                                                this search.
                                            </p>
                                        )}
                                    </div>
                                    <div className="comm-pagination">
                                        <button
                                            className="btn ghost"
                                            disabled={busy || page <= 1}
                                            onClick={() =>
                                                setPage((p) => p - 1)
                                            }
                                        >
                                            Previous
                                        </button>
                                        <span>
                                            {page} / {recipients.last_page} ·{" "}
                                            {recipients.total} users
                                        </span>
                                        <button
                                            className="btn ghost"
                                            disabled={
                                                busy ||
                                                page >= recipients.last_page
                                            }
                                            onClick={() =>
                                                setPage((p) => p + 1)
                                            }
                                        >
                                            Next
                                        </button>
                                    </div>
                                </>
                            )
                        )}
                        {Object.keys(selected).length > 0 && (
                            <details>
                                <summary>
                                    Selected recipients across user types
                                </summary>
                                {Object.entries(selected).map(([key, r]) => (
                                    <div className="comm-selected" key={key}>
                                        <span>
                                            {r.name} · {r.email}
                                        </span>
                                        <button
                                            disabled={busy}
                                            aria-label={`Remove ${r.name}`}
                                            onClick={() => {
                                                setSelected((s) => {
                                                    const n = { ...s };
                                                    delete n[key];
                                                    return n;
                                                });
                                                requestKey.current = null;
                                            }}
                                        >
                                            Remove
                                        </button>
                                    </div>
                                ))}
                            </details>
                        )}
                    </section>
                    <section className="data-card comm-card">
                        <h3>Compose email</h3>
                        <label className="field-label">
                            Start from a template
                            <select
                                disabled={busy}
                                defaultValue=""
                                onChange={(e) => {
                                    const t = config.templates.find(
                                        (t) => String(t.id) === e.target.value,
                                    );
                                    if (t) {
                                        setSubject(t.subject);
                                        setBody(t.body);
                                        requestKey.current = null;
                                    }
                                }}
                            >
                                <option value="">Write your own email</option>
                                {config.templates
                                    .filter((t) => t.is_active && !t.system_key)
                                    .map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="field-label">
                            Subject
                            <input
                                disabled={busy}
                                maxLength={255}
                                value={subject}
                                onChange={(e) => {
                                    setSubject(e.target.value);
                                    requestKey.current = null;
                                }}
                            />
                        </label>
                        <label className="field-label">
                            Message
                            <textarea
                                disabled={busy}
                                rows={12}
                                maxLength={30000}
                                value={body}
                                onChange={(e) => {
                                    setBody(e.target.value);
                                    requestKey.current = null;
                                }}
                                placeholder="Hello {{name}}, …"
                            />
                        </label>
                        <p className="comm-hint">
                            Plain text email. Use {"{{name}}"}, {"{{email}}"} or{" "}
                            {"{{app_name}}"} for personalization.
                        </p>
                        <button
                            className="btn primary"
                            disabled={
                                busy ||
                                !subject.trim() ||
                                !body.trim() ||
                                !Object.keys(selected).length
                            }
                            onClick={() => setReview(true)}
                        >
                            <Send size={16} />
                            Review & send
                        </button>
                    </section>
                </div>
            )}
            {tab === "WhatsApp templates" && (
                <section className="data-card comm-card">
                    <div className="comm-section-head">
                        <div>
                            <h3>WhatsApp templates</h3>
                            <p>
                                Review statuses and approved messages directly
                                from Meta.
                            </p>
                        </div>
                        <button
                            className="btn ghost"
                            disabled={busy}
                            onClick={() => action(refreshWa)}
                        >
                            <RefreshCw size={16} />
                            Refresh from Meta
                        </button>
                    </div>
                    {waTemplates === null ? (
                        <p className="comm-empty">
                            Save and enable the API connection, then refresh to
                            load templates.
                        </p>
                    ) : (
                        <div className="comm-template-grid">
                            {waTemplates.map((t) => (
                                <article
                                    key={t.id || `${t.name}:${t.language}`}
                                >
                                    <div>
                                        <h4>{t.name}</h4>
                                        <Status
                                            tone={
                                                t.status === "APPROVED"
                                                    ? "active"
                                                    : t.status === "REJECTED"
                                                      ? "rejected"
                                                      : "pending"
                                            }
                                        >
                                            {t.status}
                                        </Status>
                                    </div>
                                    <small>
                                        {t.language} · {t.category}
                                    </small>
                                    <p className="comm-preserve">
                                        {
                                            t.components?.find(
                                                (c) => c.type === "BODY",
                                            )?.text
                                        }
                                    </p>
                                    {t.id &&
                                        t.components?.length === 1 &&
                                        t.components[0].type === "BODY" &&
                                        t.parameter_format !== "NAMED" && (
                                            <button
                                                className="btn ghost"
                                                disabled={busy}
                                                onClick={() => {
                                                    const text =
                                                        t.components[0].text;
                                                    const count = new Set(
                                                        [
                                                            ...text.matchAll(
                                                                /\{\{(\d+)\}\}/g,
                                                            ),
                                                        ].map((m) => m[1]),
                                                    ).size;
                                                    setWaDraft({
                                                        id: String(t.id),
                                                        name: t.name,
                                                        language: t.language,
                                                        category: t.category,
                                                        body: text,
                                                        examples: Array.from(
                                                            { length: count },
                                                            (_, i) =>
                                                                t.components[0]
                                                                    .example
                                                                    ?.body_text?.[0]?.[
                                                                    i
                                                                ] || "",
                                                        ),
                                                    });
                                                }}
                                            >
                                                Edit text
                                            </button>
                                        )}
                                    <button
                                        className="btn ghost"
                                        disabled={busy}
                                        onClick={() =>
                                            setDeleteTarget({
                                                kind: "whatsapp",
                                                name: t.name,
                                            })
                                        }
                                    >
                                        <Trash2 size={15} />
                                        Delete from Meta
                                    </button>
                                </article>
                            ))}
                            {!waTemplates.length && (
                                <p>No templates in this WhatsApp account.</p>
                            )}
                        </div>
                    )}
                    <hr />
                    <h3>
                        {waDraft.id
                            ? "Edit text template"
                            : "Submit a new text template"}
                    </h3>
                    {waDraft.id && (
                        <button
                            className="btn ghost"
                            onClick={() => setWaDraft({ ...emptyWhatsapp })}
                        >
                            Cancel editing
                        </button>
                    )}
                    <div className="form-grid">
                        <label className="field-label">
                            Template name
                            <input
                                readOnly={Boolean(waDraft.id)}
                                value={waDraft.name}
                                onChange={(e) =>
                                    setWaDraft({
                                        ...waDraft,
                                        name: e.target.value,
                                    })
                                }
                                placeholder="interview_reminder"
                            />
                        </label>
                        <label className="field-label">
                            Language code
                            <input
                                readOnly={Boolean(waDraft.id)}
                                value={waDraft.language}
                                onChange={(e) =>
                                    setWaDraft({
                                        ...waDraft,
                                        language: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="field-label">
                            Category
                            <select
                                value={waDraft.category}
                                onChange={(e) =>
                                    setWaDraft({
                                        ...waDraft,
                                        category: e.target.value,
                                    })
                                }
                            >
                                <option value="UTILITY">Utility</option>
                                <option value="MARKETING">Marketing</option>
                            </select>
                        </label>
                    </div>
                    <label className="field-label">
                        Template body
                        <textarea
                            rows={5}
                            maxLength={1024}
                            value={waDraft.body}
                            onChange={(e) => {
                                const text = e.target.value;
                                const count = new Set(
                                    [...text.matchAll(/\{\{(\d+)\}\}/g)].map(
                                        (m) => m[1],
                                    ),
                                ).size;
                                setWaDraft({
                                    ...waDraft,
                                    body: text,
                                    examples: Array.from(
                                        { length: count },
                                        (_, i) => waDraft.examples[i] || "",
                                    ),
                                });
                            }}
                            placeholder="Hello {{1}}, your interview is scheduled for {{2}}."
                        />
                    </label>
                    <div className="form-grid">
                        {waDraft.examples.map((v, i) => (
                            <label className="field-label" key={i}>
                                Sample for {`{{${i + 1}}}`}
                                <input
                                    value={v}
                                    onChange={(e) =>
                                        setWaDraft({
                                            ...waDraft,
                                            examples: waDraft.examples.map(
                                                (x, j) =>
                                                    i === j
                                                        ? e.target.value
                                                        : x,
                                            ),
                                        })
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    <button
                        className="btn primary"
                        disabled={busy || !waDraft.name || !waDraft.body}
                        onClick={() =>
                            action(async () => {
                                await axios.post(
                                    `${base}/whatsapp-templates`,
                                    waDraft,
                                    { headers: headers() },
                                );
                                setWaDraft({ ...emptyWhatsapp });
                                setNotice(
                                    "Submitted to Meta for review. Refresh to check approval.",
                                );
                                await refreshWa();
                            })
                        }
                    >
                        <Send size={16} />
                        {waDraft.id
                            ? "Save & submit changes"
                            : "Submit to Meta"}
                    </button>
                    <a
                        className="btn ghost"
                        href="https://business.facebook.com/wa/manage/message-templates/"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Edit in WhatsApp Manager
                    </a>
                </section>
            )}
            {tab === "Send history" && (
                <section className="data-card comm-card">
                    <h3>Email send history</h3>
                    <p>
                        Accepted means SMTP accepted the message. Check provider
                        logs for delivery or unconfirmed sends.
                    </p>
                    {!history ? (
                        <p>Loading history…</p>
                    ) : (
                        <>
                            {!history.data.length && (
                                <p className="comm-empty">
                                    No emails have been sent yet.
                                </p>
                            )}
                            {history.data.map((c) => (
                                <details className="comm-history" key={c.id}>
                                    <summary>
                                        <b>{c.subject}</b>
                                        <span>
                                            {new Date(
                                                c.created_at,
                                            ).toLocaleString("en-IN")}{" "}
                                            ·{" "}
                                            {
                                                c.deliveries.filter(
                                                    (d) =>
                                                        d.status === "accepted",
                                                ).length
                                            }
                                            /{c.deliveries.length} accepted
                                        </span>
                                    </summary>
                                    {c.deliveries.some(
                                        (d) => d.status === "pending",
                                    ) && (
                                        <button
                                            className="btn primary"
                                            disabled={busy}
                                            onClick={() =>
                                                action(() => process(c.id))
                                            }
                                        >
                                            Resume pending recipients
                                        </button>
                                    )}
                                    <div className="comm-preserve comm-history-body">
                                        {c.body}
                                    </div>
                                    {c.deliveries.map((d) => (
                                        <div
                                            className="comm-delivery"
                                            key={d.id}
                                        >
                                            <span>
                                                {d.name}
                                                <small>{d.email}</small>
                                                {d.error && (
                                                    <small>{d.error}</small>
                                                )}
                                            </span>
                                            <Status
                                                tone={
                                                    d.status === "accepted"
                                                        ? "active"
                                                        : d.status === "pending"
                                                          ? "pending"
                                                          : "rejected"
                                                }
                                            >
                                                {d.status}
                                            </Status>
                                        </div>
                                    ))}
                                </details>
                            ))}
                            <div className="comm-pagination">
                                <button
                                    className="btn ghost"
                                    disabled={busy || historyPage <= 1}
                                    onClick={() => setHistoryPage((p) => p - 1)}
                                >
                                    Previous
                                </button>
                                <span>
                                    {history.current_page} / {history.last_page}
                                </span>
                                <button
                                    className="btn ghost"
                                    disabled={
                                        busy || historyPage >= history.last_page
                                    }
                                    onClick={() => setHistoryPage((p) => p + 1)}
                                >
                                    Next
                                </button>
                            </div>
                        </>
                    )}
                </section>
            )}
            <Modal
                open={Boolean(help)}
                onClose={() => setHelp(null)}
                title={helpContent[help]?.title}
                className="comm-modal"
            >
                {helpContent[help]?.body}
            </Modal>
            <Modal
                open={Boolean(template)}
                onClose={() => {
                    if (!busy) setTemplate(null);
                }}
                title={
                    template?.id
                        ? "Edit email template"
                        : "Create email template"
                }
                className="comm-modal"
                footer={
                    <button
                        className="btn primary"
                        disabled={busy}
                        onClick={saveTemplate}
                    >
                        <Save size={16} />
                        Save template
                    </button>
                }
            >
                {template && (
                    <>
                        <label className="field-label">
                            Template name
                            <input
                                value={template.name}
                                onChange={(e) =>
                                    setTemplate({
                                        ...template,
                                        name: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="field-label">
                            Subject
                            <input
                                value={template.subject}
                                onChange={(e) =>
                                    setTemplate({
                                        ...template,
                                        subject: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="field-label">
                            Message
                            <textarea
                                rows={8}
                                value={template.body}
                                onChange={(e) =>
                                    setTemplate({
                                        ...template,
                                        body: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="comm-check">
                            <input
                                type="checkbox"
                                checked={template.is_active}
                                onChange={(e) =>
                                    setTemplate({
                                        ...template,
                                        is_active: e.target.checked,
                                    })
                                }
                            />
                            {template.system_key
                                ? "Active — use this template for system emails"
                                : "Active — available in email composer"}
                        </label>
                        <p>
                            Placeholders:{" "}
                            {template.system_key === "candidate_invitation"
                                ? "{{center_name}}, {{partner_name}}, {{registration_url}}, {{app_name}}"
                                : template.system_key === "password_reset"
                                  ? "{{name}}, {{email}}, {{reset_url}}, {{expiry_minutes}}, {{app_name}}"
                                  : "{{name}}, {{email}}, {{app_name}}"}
                            .{" "}
                            {template.system_key &&
                                "Disabling or deleting a system template restores the built-in email."}
                        </p>
                        <div className="comm-preview">
                            <small>Sample preview</small>
                            <h4>{preview(template.subject)}</h4>
                            <p>{preview(template.body)}</p>
                        </div>
                        {error && (
                            <p role="alert" className="comm-alert error">
                                {error}
                            </p>
                        )}
                    </>
                )}
            </Modal>
            <Modal
                open={review}
                onClose={() => {
                    if (!busy) setReview(false);
                }}
                title="Review email before sending"
                sub={`${Object.keys(selected).length} selected users; duplicate email addresses receive one message.`}
                className="comm-modal"
                footer={
                    <>
                        <button
                            className="btn ghost"
                            disabled={busy}
                            onClick={() => setReview(false)}
                        >
                            Back to editing
                        </button>
                        <button
                            className="btn primary"
                            disabled={busy}
                            onClick={send}
                        >
                            <Send size={16} />
                            {busy ? "Sending…" : "Send email"}
                        </button>
                    </>
                }
            >
                <div className="comm-preview">
                    <small>Sample personalization</small>
                    <h4>{preview(subject)}</h4>
                    <p>{preview(body)}</p>
                </div>
                <details>
                    <summary>View all recipients</summary>
                    {Object.values(selected).map((r) => (
                        <p key={`${r.type}:${r.id}`}>
                            {r.name} · {r.email}
                        </p>
                    ))}
                </details>
                <p>
                    Individual plain text messages will be sent using the saved
                    SMTP connection. Keep this page open until processing
                    finishes.
                </p>
                {error && (
                    <p role="alert" className="comm-alert error">
                        {error}
                    </p>
                )}
            </Modal>
            <Modal
                open={Boolean(deleteTarget)}
                onClose={() => {
                    if (!busy) setDeleteTarget(null);
                }}
                title="Delete template"
                footer={
                    <>
                        <button
                            className="btn ghost"
                            disabled={busy}
                            onClick={() => setDeleteTarget(null)}
                        >
                            Cancel
                        </button>
                        <button
                            className="btn primary"
                            disabled={busy}
                            onClick={() =>
                                action(async () => {
                                    if (deleteTarget.kind === "email") {
                                        await axios.delete(
                                            `${base}/email-templates/${deleteTarget.id}`,
                                            { headers: headers() },
                                        );
                                        await load();
                                    } else {
                                        await axios.delete(
                                            `${base}/whatsapp-templates`,
                                            {
                                                headers: headers(),
                                                data: {
                                                    name: deleteTarget.name,
                                                },
                                            },
                                        );
                                        await refreshWa();
                                    }
                                    setDeleteTarget(null);
                                    setNotice("Template deleted.");
                                })
                            }
                        >
                            Delete template
                        </button>
                    </>
                }
            >
                <p>
                    Delete <b>{deleteTarget?.name}</b>?{" "}
                    {deleteTarget?.kind === "whatsapp"
                        ? "This deletes every language variant with this name from your Meta account."
                        : "Existing email history remains available."}
                </p>
                {error && (
                    <p role="alert" className="comm-alert error">
                        {error}
                    </p>
                )}
            </Modal>
        </div>
    );
}
