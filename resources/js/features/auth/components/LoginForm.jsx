import PhoneVerification from './PhoneVerification.jsx';
import React, { useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, ArrowRight, Eye, EyeOff, LockKeyhole, UserRound, Building2, GraduationCap } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { validateLogin } from "../validation.js";
import axios from "axios";

const INITIAL_VALUES = { identifier: "", password: "", remember: true };

export default function LoginForm({ roles, admin = false, onChangeRole }) {
  const navigate = useNavigate();
  const [authSettings,setAuthSettings]=useState(null),[passwordMode,setPasswordMode]=useState(false),[settingsError,setSettingsError]=useState('');
  const loadSettings=()=>{setSettingsError('');axios.get('/auth/settings').then(({data})=>setAuthSettings(data)).catch(()=>setSettingsError('Unable to load login options. Please retry.'))};
  useEffect(()=>{if(!admin)loadSettings()},[admin]);
  const identifierRef = useRef(null);
  const [roleId, setRoleId] = useState(roles[0].id);
  const [values, setValues] = useState(INITIAL_VALUES);
  const [errors, setErrors] = useState({});
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const role = useMemo(() => roles.find((item) => item.id === roleId) ?? roles[0], [roleId, roles]);

  useEffect(() => {
    identifierRef.current?.focus();
  }, [roleId]);

  const updateValue = (field, value) => {
    setValues((current) => ({ ...current, [field]: value }));
    setErrors((current) => ({ ...current, [field]: undefined }));
  };

  const submit = async (event) => {
    event.preventDefault();
    const nextErrors = validateLogin(values);

    if (Object.keys(nextErrors).length) {
      setErrors(nextErrors);
      return;
    }

    setSubmitting(true);

    try {
      const response = await axios.post('/auth/login', { ...values, role: role.id }, {
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
      });
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      if (csrfMeta && response.data.csrf_token) csrfMeta.content = response.data.csrf_token;
      navigate(response.data.destination);
    } catch (error) {
      setErrors({ identifier: error.response?.data?.message || 'Sign in failed. Please try again.' });
    } finally {
      setSubmitting(false);
    }
  };

  if(!admin&&!authSettings)return <div className="login-form"><div className={settingsError?'auth-notice error':'auth-notice loading'} role={settingsError?'alert':'status'}>{settingsError||'Loading secure login…'}</div>{settingsError&&<button type="button" className="btn ghost block" onClick={loadSettings}>Retry</button>}</div>;
  if(!admin&&authSettings.login_enabled&&!passwordMode)return <div className="login-form"><div className="selected-login-role"><span>{role.label}</span><button type="button" onClick={onChangeRole}>Change account type</button></div><PhoneVerification role={role.id} purpose="login" onVerified={data=>{const meta=document.querySelector('meta[name="csrf-token"]');if(meta&&data.csrf_token)meta.content=data.csrf_token;navigate(data.destination)}}/><div className="login-divider"><span>OR</span></div><button type="button" className="btn ghost block" onClick={()=>setPasswordMode(true)}>Log in with password</button><div className="otp-alternative"><button type="button" className="btn ghost" onClick={onChangeRole}>Change account type</button><Link className="btn dark" to={role.id === "employer" ? "/register/employer" : role.id === "candidate" ? "/register/candidate" : "/register/training-center"}>Create an account</Link></div></div>;
  return (
    <form className="login-form" onSubmit={submit} noValidate>
      <Link className="login-back" to="/">
        <ArrowLeft /> Back to home
      </Link>
      <span className="section-kicker">{admin ? "ADMIN PORTAL" : "WELCOME BACK"}</span>
      <h2 className={admin ? "admin-h2" : undefined}>{admin ? "Admin sign in" : `${role.label} login`}</h2>
      <p>
        {admin
          ? "Use your authorized EXOWORK operations account."
          : "Enter your registered details to continue."}
      </p>

      {!admin && <div className="selected-login-role">
        <span>{role.id === "candidate" ? <UserRound /> : role.id === "employer" ? <Building2 /> : <GraduationCap />}{role.label}</span>
        <button type="button" onClick={onChangeRole}>Change account type</button>
      </div>}

      {admin && (
        <div className="admin-access-note">
          <LockKeyhole />
          <span>This page is restricted to authorized EXOWORK administrators.</span>
        </div>
      )}

      <label htmlFor="login-identifier">
        {admin?role.identifierLabel:"Mobile number"}
        <input
          ref={identifierRef}
          id="login-identifier"
          name="identifier"
          type="text"
          autoComplete={admin || role.id !== "candidate" ? "username" : "tel"}
          inputMode={admin ? "text" : "tel"}
          value={values.identifier}
          placeholder={admin?role.identifierPlaceholder:"Registered 10-digit mobile number"}
          aria-invalid={Boolean(errors.identifier)}
          aria-describedby={errors.identifier ? "identifier-error" : undefined}
          onChange={(event) => updateValue("identifier", event.target.value)}
        />
        {errors.identifier && <small role="alert" id="identifier-error" className="auth-notice error">{errors.identifier}</small>}
      </label>

      <label htmlFor="login-password">
        Password
        <div className={`password ${errors.password ? "invalid" : ""}`}>
          <input
            id="login-password"
            name="password"
            type={showPassword ? "text" : "password"}
            autoComplete="current-password"
            value={values.password}
            placeholder="Enter your password"
            aria-invalid={Boolean(errors.password)}
            aria-describedby={errors.password ? "password-error" : undefined}
            onChange={(event) => updateValue("password", event.target.value)}
          />
          <button
            type="button"
            onClick={() => setShowPassword((visible) => !visible)}
            aria-label={showPassword ? "Hide password" : "Show password"}
          >
            {showPassword ? <EyeOff /> : <Eye />}
          </button>
        </div>
        {errors.password && <small id="password-error" className="field-error">{errors.password}</small>}
      </label>

      <div className="login-help">
        <label>
          <input
            type="checkbox"
            checked={values.remember}
            onChange={(event) => updateValue("remember", event.target.checked)}
          />
          Remember me
        </label>
        {role.id === "employer" ? <Link className="text-link" to="/forgot-password/employer">Forgot password?</Link> : !admin && authSettings?.login_enabled ? <button type="button" onClick={()=>setPasswordMode(false)}>Recover with WhatsApp</button> : null}
      </div>

      <button className="btn primary block" type="submit" disabled={submitting}>
        {submitting ? "Signing in…" : `Sign in to ${role.label}`}
        {!submitting && <ArrowRight />}
      </button>
      {!admin && authSettings?.login_enabled && <button type="button" className="otp-resend" onClick={()=>setPasswordMode(false)}>Log in with WhatsApp instead</button>}
      {!admin && <>
        <div className="login-divider"><span>OR</span></div>
        <div className="login-signup">
          <p>New to EXOWORK?</p>
          <Link className="btn dark block" to={role.id === "employer" ? "/register/employer" : role.id === "candidate" ? "/register/candidate" : "/register/training-center"}>Sign up as {role.id === "employer" ? "an employer" : role.id === "candidate" ? "a candidate" : "a training center"} <ArrowRight /></Link>
        </div>
      </>}
    </form>
  );
}
