import React, { useEffect, useState, useRef, useId } from "react";

import axios from "axios";

import {
    ArrowRight,
    ArrowLeft,
    ShieldCheck,
    Users,
    BriefcaseBusiness,
    Eye,
    EyeOff,
    Upload,
    FileText,
    X,
} from "lucide-react";

import { Link, useNavigate } from "react-router-dom";

import BrandLogo from "../../components/BrandLogo.jsx";

import { Modal } from "../../components/UI.jsx";

import "./employer.css";
import "./employer-registration.css";

export const profileEmpty = {
    name: "",

    legal_name: "",

    industry: "",

    website: "",

    description: "",

    gstin: "",

    state: "",

    district: "",

    address: "",

    pincode: "",

    contact_name: "",

    contact_designation: "",

    contact_phone: "",

    contact_department: "",
};

export const profileSteps = [
    ["Company", ["name", "legal_name", "industry", "website", "description"]],

    [
        "Verification",

        ["gstin", "state", "district", "address", "pincode", "document"],
    ],

    [
        "Primary contact",

        [
            "contact_name",

            "contact_designation",

            "contact_phone",

            "contact_department",
        ],
    ],
];

export const optionalEmployerFields = new Set([
    "website",
    "gstin",
    "document",
    "account_name",
    "contact_name",
    "contact_designation",
    "contact_phone",
    "contact_department",
]);

const placeholders = {
    name: "Enter your employer short name",
    account_name: "Enter your full name",
    legal_name: "Enter your company legal name",
    website: "https://your-company.com",
    description: "Describe your company and the work you do",
    gstin: "Enter your 15-character GSTIN",
    address: "Building, street, area, and landmark",
    pincode: "Enter the 6-digit PIN code",
    contact_name: "Enter the contact person's full name",
    contact_designation: "Enter designation or role",
    contact_phone: "Enter direct phone number or extension",
    contact_department: "Enter department",
    email: "Enter your work email",
    phone: "10-digit mobile number",
    password: "Create a password with at least 8 characters",
    password_confirmation: "Re-enter your password",
};

const labels = {
    account_name: "Your name",

    name: "Employer short name",

    legal_name: "Company legal name",

    industry: "Industry / sector",

    website: "Company website",

    description: "Company description",

    gstin: "GSTIN",

    state: "State",

    district: "City / district",

    address: "Full address",

    pincode: "PIN code",

    document: "Document proof",

    contact_name: "Contact full name",

    contact_designation: "Designation / role",

    contact_phone: "Direct phone / extension",

    contact_department: "Department",

    email: "Work email",

    phone: "Mobile number",

    password: "Create password",

    password_confirmation: "Confirm password",
};

export const csrf = () => ({
    "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content,
});

export function useEmployerMasters(state) {
    const [master, setMaster] = useState({ states: [], industries: [] }),
        [districts, setDistricts] = useState([]),
        [error, setError] = useState("");

    useEffect(() => {
        axios

            .get("/api/master-data")

            .then(({ data }) => setMaster(data))

            .catch(() =>
                setError("Choices could not be loaded. Please refresh."),
            );
    }, []);

    useEffect(() => {
        const selected = master.states.find((x) => x.name === state);

        setDistricts([]);

        if (!selected) return;

        let active = true;

        axios

            .get(`/api/master-data/states/${selected.slug}/districts`)

            .then(({ data }) => {
                if (active) setDistricts(data.districts);
            })

            .catch(() => {
                if (active)
                    setError("Districts could not be loaded. Please refresh.");
            });

        return () => {
            active = false;
        };
    }, [master, state]);

    return { master, districts, masterError: error };
}

function DocumentUpload({ file, onChange, existingDocument, error }) {
    const input = useRef(null),
        id = useId(),
        [dragging, setDragging] = useState(false),
        [localError, setLocalError] = useState("");

    const choose = (file) => {
        if (!file) return;
        if (
            !/\.(pdf|jpe?g|png)$/i.test(file.name) ||
            file.size > 5 * 1024 * 1024
        ) {
            setLocalError("Choose a PDF, JPG or PNG file up to 5 MB.");
            return;
        }
        setLocalError("");
        onChange(file);
    };

    return (
        <div className="field-label wide employer-document">
            <span id={id}>
                Document proof{" "}
                <span className="employer-optional">(optional)</span>
            </span>

            <input
                ref={input}
                className="employer-file-input"
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                aria-labelledby={id}
                onChange={(e) => {
                    choose(e.target.files?.[0]);
                    e.target.value = "";
                }}
            />

            {file ? (
                <div className="employer-file-card">
                    <FileText />
                    <div>
                        <b>{file.name}</b>
                        <small>
                            {file.name.split(".").pop().toUpperCase()} '{" "}
                            {(file.size / 1024).toFixed(1)} KB
                        </small>
                    </div>
                    <button type="button" onClick={() => input.current.click()}>
                        Change file
                    </button>
                    <button
                        type="button"
                        aria-label="Remove document"
                        onClick={() => {
                            onChange(null);
                            setLocalError("");
                        }}
                    >
                        <X />
                    </button>
                </div>
            ) : (
                <button
                    type="button"
                    className={
                        "employer-drop-zone" + (dragging ? " dragging" : "")
                    }
                    onClick={() => input.current.click()}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setDragging(false);
                        choose(e.dataTransfer.files?.[0]);
                    }}
                >
                    <Upload />
                    <span>
                        <b>Click to upload</b> or drag and drop
                    </span>
                    <small>PDF, JPG or PNG, up to 5 MB</small>
                </button>
            )}

            <small>
                You can add your verification document later in Company profile.
                {existingDocument
                    ? ` Current document: ${existingDocument}`
                    : ""}
            </small>

            {(localError || error) && (
                <small role="alert" className="field-error">
                    {localError || error[0]}
                </small>
            )}
        </div>
    );
}

export function EmployerFields({
    keys,
    data,
    set,
    errors,
    master,
    districts,
    existingDocument,
}) {
    return (
        <div className="employer-fields">
            {keys.map((key) => {
                if (key === "document")
                    return (
                        <DocumentUpload
                            key={key}
                            file={data.document}
                            onChange={(file) => set("document", file)}
                            existingDocument={existingDocument}
                            error={errors[key]}
                        />
                    );

                const options =
                    key === "industry"
                        ? master.industries
                        : key === "state"
                          ? master.states
                          : key === "district"
                            ? districts
                            : null;

                const optional = optionalEmployerFields.has(key),
                    error = errors[key],
                    errorId = `employer-${key}-error`;

                const props = {
                    name: key,
                    value: data[key] || "",
                    required: !optional,
                    "aria-invalid": !!error,
                    "aria-describedby": error ? errorId : undefined,
                    onChange: (e) =>
                        set(
                            key,
                            key === "gstin"
                                ? e.target.value.toUpperCase()
                                : e.target.value,
                        ),
                };

                return (
                    <label
                        className={
                            "field-label " +
                            (["description", "address"].includes(key)
                                ? "wide "
                                : "") +
                            (error ? "has-error" : "")
                        }
                        key={key}
                    >
                        <span>
                            {labels[key] || key}
                            {optional ? (
                                <span className="employer-optional">
                                    {" "}
                                    (optional)
                                </span>
                            ) : (
                                <em className="employer-required"> *</em>
                            )}
                        </span>

                        {options ? (
                            <select
                                {...props}
                                disabled={key === "district" && !data.state}
                            >
                                <option value="">
                                    {key === "district" && !data.state
                                        ? "Select a state first"
                                        : `Select ${labels[key].toLowerCase()}`}
                                </option>
                                {options.map((x) => (
                                    <option key={x.id} value={x.name}>
                                        {x.name}
                                    </option>
                                ))}
                                {data[key] &&
                                    !options.some(
                                        (x) => x.name === data[key],
                                    ) && <option>{data[key]}</option>}
                            </select>
                        ) : ["description", "address"].includes(key) ? (
                            <textarea
                                {...props}
                                rows={3}
                                placeholder={placeholders[key]}
                            />
                        ) : (
                            <input
                                {...props}
                                placeholder={placeholders[key]}
                                type={
                                    key.includes("password")
                                        ? "password"
                                        : key === "email"
                                          ? "email"
                                          : key === "website"
                                            ? "url"
                                            : [
                                                    "phone",
                                                    "contact_phone",
                                                ].includes(key)
                                              ? "tel"
                                              : "text"
                                }
                                autoComplete={
                                    key.includes("password")
                                        ? "new-password"
                                        : key === "email"
                                          ? "email"
                                          : key === "account_name"
                                            ? "name"
                                            : undefined
                                }
                                maxLength={
                                    key === "pincode"
                                        ? 6
                                        : key === "gstin"
                                          ? 15
                                          : key === "phone"
                                            ? 10
                                            : undefined
                                }
                            />
                        )}

                        {key === "gstin" && (
                            <small>
                                Skip for now if unavailable. Add it later for
                                company verification.
                            </small>
                        )}

                        {error && (
                            <small id={errorId} className="field-error">
                                {error[0]}
                            </small>
                        )}
                    </label>
                );
            })}
        </div>
    );
}

export default function EmployerRegistration() {
    const navigate = useNavigate();
    const [data, setData] = useState({
        account_name: "",
        legal_name: "",
        email: "",
        phone: "",
        password: "",
        terms_accepted: false,
        privacy_accepted: false,
        website_check: "",
    });
    const [errors, setErrors] = useState({}),
        [message, setMessage] = useState(""),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(null),
        [showPassword, setShowPassword] = useState(false);
    const set = (key, value) => {
        setData((d) => ({ ...d, [key]: value }));
        setErrors((e) => ({ ...e, [key]: undefined }));
    };
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setMessage("");
        const payload = {
            ...data,
            name: data.legal_name.slice(0, 100),
            password_confirmation: data.password,
            contact_name: data.account_name,
            contact_phone: data.phone,
        };
        try {
            let response;
            try {
                response = await axios.post(
                    "/auth/register/employer",
                    payload,
                    { headers: csrf() },
                );
            } catch (error) {
                if (error.response?.status !== 419) throw error;
                const fresh = await axios.get("/auth/csrf-token");
                document.querySelector('meta[name="csrf-token"]').content =
                    fresh.data.csrf_token;
                response = await axios.post(
                    "/auth/register/employer",
                    payload,
                    { headers: csrf() },
                );
            }
            document.querySelector('meta[name="csrf-token"]').content =
                response.data.csrf_token;
            navigate(response.data.destination);
        } catch (error) {
            setErrors(error.response?.data?.errors || {});
            setMessage(
                error.response?.data?.message ||
                    "Unable to create your account. Please try again.",
            );
        } finally {
            setBusy(false);
        }
    };
    const field = (key, label, type = "text", hint, required = true) => (
        <label
            className={"field-label " + (errors[key] ? "has-error" : "")}
            key={key}
        >
            <span>
                {label}
                {required ? (
                    <em className="employer-required"> *</em>
                ) : (
                    <span className="employer-optional"> (optional)</span>
                )}
            </span>
            <input
                name={key}
                type={type}
                placeholder={hint}
                value={data[key]}
                onChange={(e) => set(key, e.target.value)}
                required={required}
                autoComplete={
                    key === "account_name"
                        ? "name"
                        : key === "email"
                          ? "email"
                          : key === "password"
                            ? "new-password"
                            : key === "legal_name"
                              ? "organization"
                              : "tel"
                }
                minLength={key === "password" ? 8 : undefined}
                maxLength={
                    key === "phone"
                        ? 10
                        : key === "legal_name"
                          ? 255
                          : undefined
                }
                aria-invalid={!!errors[key]}
                aria-describedby={
                    errors[key] ? `signup-${key}-error` : undefined
                }
            />
            {errors[key] && (
                <small id={`signup-${key}-error`} className="field-error">
                    {errors[key][0]}
                </small>
            )}
        </label>
    );
    return (
        <div className="candidate-register employer-registration employer-quick-signup">
            <header className="reg-top">
                <Link to="/">
                    <ArrowLeft />
                    Back to home
                </Link>
                <BrandLogo />
                <Link to="/login">Log in</Link>
            </header>
            <main>
                <aside className="reg-aside">
                    <span className="reg-kicker">
                        YOUR NEXT TEAM STARTS HERE
                    </span>
                    <h1>
                        Start simply.
                        <br />
                        Hire with confidence.
                    </h1>
                    <p>
                        One account for your company’s hiring journey, with
                        EXOWORK support at every step.
                    </p>
                    <div className="employer-signup-benefits">
                        <div>
                            <ShieldCheck />
                            <span>
                                <b>A trusted hiring network</b>
                                <small>
                                    Company verification before jobs go live.
                                </small>
                            </span>
                        </div>
                        <div>
                            <Users />
                            <span>
                                <b>Candidates that fit your roles</b>
                                <small>
                                    Review candidates released by the placement
                                    team.
                                </small>
                            </span>
                        </div>
                        <div>
                            <BriefcaseBusiness />
                            <span>
                                <b>Follow every hiring milestone</b>
                                <small>
                                    Keep job requirements, interviews and
                                    joining in one place.
                                </small>
                            </span>
                        </div>
                    </div>
                    <div className="privacy-note">
                        <ShieldCheck />
                        <p>
                            Company profile, industry and documents can be
                            completed after you create your account.
                        </p>
                    </div>
                </aside>
                <section className="reg-form">
                    <div className="reg-form-head">
                        <span>EMPLOYER ACCOUNT</span>
                        <h2>Let’s get your hiring started</h2>
                        <p>
                            Create your account now. Complete your company
                            details when you’re ready.
                        </p>
                    </div>
                    <form
                        onSubmit={submit}
                        className="employer-registration-form"
                    >
                        {message && (
                            <p role="alert" className="employer-error">
                                {message}
                            </p>
                        )}
                        <div className="employer-fields">
                            {field(
                                "account_name",
                                "Your full name",
                                "text",
                                "Enter your full name",
                            )}
                            {field(
                                "legal_name",
                                "Company name",
                                "text",
                                "Enter your company name",
                            )}
                            {field(
                                "email",
                                "Work email",
                                "email",
                                "Enter your work email address",
                            )}
                            <div className="employer-signup-password">
                                {field(
                                    "password",
                                    "Create password",
                                    showPassword ? "text" : "password",
                                    "At least 8 characters",
                                )}
                                <button
                                    type="button"
                                    onClick={() => setShowPassword((v) => !v)}
                                    aria-label={
                                        showPassword
                                            ? "Hide password"
                                            : "Show password"
                                    }
                                >
                                    {showPassword ? <EyeOff /> : <Eye />}
                                </button>
                            </div>
                        </div>
                        <details className="employer-signup-optional" open={errors.phone ? true : undefined}>
                            <summary>
                                Add a contact mobile <span>(optional)</span>
                            </summary>
                            <p>
                                Use it to log in or reach your hiring contact.
                                You can add it later.
                            </p>
                            <div className="employer-fields">
                                {field(
                                    "phone",
                                    "Mobile number",
                                    "tel",
                                    "10-digit mobile number",
                                    false,
                                )}
                            </div>
                        </details>
                        <label className="employer-consent">
                            <input
                                type="checkbox"
                                checked={
                                    data.terms_accepted && data.privacy_accepted
                                }
                                onChange={(e) => {
                                    set("terms_accepted", e.target.checked);
                                    set("privacy_accepted", e.target.checked);
                                }}
                                required
                            />
                            <span>
                                I agree to the{" "}
                                <button
                                    type="button"
                                    onClick={() =>
                                        setNotice("Terms & Conditions")
                                    }
                                >
                                    Terms & Conditions
                                </button>{" "}
                                and{" "}
                                <button
                                    type="button"
                                    onClick={() => setNotice("Privacy Policy")}
                                >
                                    Privacy Policy
                                </button>
                                .
                            </span>
                        </label>
                        {(errors.terms_accepted || errors.privacy_accepted) && (
                            <p className="field-error">
                                Please accept the terms and privacy policy.
                            </p>
                        )}
                        <div hidden>
                            <input
                                tabIndex={-1}
                                autoComplete="off"
                                value={data.website_check}
                                onChange={(e) =>
                                    set("website_check", e.target.value)
                                }
                            />
                        </div>
                        <button
                            className="btn primary block employer-create-account"
                            disabled={busy}
                        >
                            {busy
                                ? "Creating your account…"
                                : "Create account & start hiring"}
                            {!busy && <ArrowRight />}
                        </button>
                        <p className="employer-signup-login">
                            Already have an account?{" "}
                            <Link to="/login">Log in</Link>
                        </p>
                    </form>
                </section>
            </main>
            <Modal
                open={!!notice}
                onClose={() => setNotice(null)}
                title={notice}
            >
                <p className="employer-notice">
                    {notice === "Terms & Conditions"
                        ? "Provide accurate company and hiring information. EXOWORK reviews company details before allowing jobs to go live. Use candidate information only for legitimate recruitment through authorized workflows. Registration does not guarantee approval or placement."
                        : "EXOWORK stores your account and company information to manage access, verify employers and support recruitment. Documents are restricted to your organization and authorized EXOWORK staff. Contact support to request corrections, access or deletion."}
                </p>
            </Modal>
        </div>
    );
}
