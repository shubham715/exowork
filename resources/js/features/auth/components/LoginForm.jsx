import React, { useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, ArrowRight, Eye, EyeOff, LockKeyhole } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { validateLogin } from "../validation.js";

const INITIAL_VALUES = { identifier: "", password: "", remember: true };

export default function LoginForm({ roles, admin = false }) {
  const navigate = useNavigate();
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

  const submit = (event) => {
    event.preventDefault();
    const nextErrors = validateLogin(values);

    if (Object.keys(nextErrors).length) {
      setErrors(nextErrors);
      return;
    }

    setSubmitting(true);

    // Static milestone: this boundary will call Laravel Sanctum in the auth phase.
    window.setTimeout(() => navigate(role.destination), 350);
  };

  return (
    <form className="login-form" onSubmit={submit} noValidate>
      <Link className="login-back" to="/">
        <ArrowLeft /> Back to home
      </Link>
      <span className="section-kicker">{admin ? "ADMIN PORTAL" : "WELCOME BACK"}</span>
      <h2>{admin ? "Admin sign in" : "Sign in to your workspace"}</h2>
      <p>
        {admin
          ? "Use your authorized EXOWORK operations account."
          : "Choose your account type and enter your registered details."}
      </p>

      {!admin && (
        <fieldset className="role-grid">
          <legend className="sr-only">Choose account type</legend>
          {roles.map((item) => (
            <button
              type="button"
              onClick={() => setRoleId(item.id)}
              className={roleId === item.id ? "active" : ""}
              aria-pressed={roleId === item.id}
              key={item.id}
            >
              {item.label}
            </button>
          ))}
        </fieldset>
      )}

      {admin && (
        <div className="admin-access-note">
          <LockKeyhole />
          <span>This page is restricted to authorized EXOWORK administrators.</span>
        </div>
      )}

      <label htmlFor="login-identifier">
        {role.identifierLabel}
        <input
          ref={identifierRef}
          id="login-identifier"
          name="identifier"
          type="text"
          autoComplete={admin || role.id !== "candidate" ? "username" : "tel"}
          inputMode={role.id === "candidate" ? "email" : "text"}
          value={values.identifier}
          placeholder={role.identifierPlaceholder}
          aria-invalid={Boolean(errors.identifier)}
          aria-describedby={errors.identifier ? "identifier-error" : undefined}
          onChange={(event) => updateValue("identifier", event.target.value)}
        />
        {errors.identifier && <small id="identifier-error" className="field-error">{errors.identifier}</small>}
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
        <button type="button">Forgot password?</button>
      </div>

      <button className="btn primary block" type="submit" disabled={submitting}>
        {submitting ? "Signing in…" : `Sign in to ${role.label}`}
        {!submitting && <ArrowRight />}
      </button>
      <small className="demo-note">
        Authentication UI is ready. Secure Laravel login will be connected in the backend milestone.
      </small>
    </form>
  );
}
