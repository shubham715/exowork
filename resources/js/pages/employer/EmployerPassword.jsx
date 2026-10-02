import React, { useState } from "react";
import axios from "axios";
import { Link, useParams, useSearchParams } from "react-router-dom";
import AuthLayout from "../../features/auth/components/AuthLayout.jsx";
import { csrf } from "./EmployerRegistration.jsx";
export default function EmployerPassword() {
    const { token } = useParams(),
        [query] = useSearchParams(),
        [email, setEmail] = useState(query.get("email") || ""),
        [password, setPassword] = useState(""),
        [confirmation, setConfirmation] = useState(""),
        [message, setMessage] = useState(""),
        [errors, setErrors] = useState({}),
        [busy, setBusy] = useState(false),
        [done, setDone] = useState(false);
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setMessage("");
        setErrors({});
        try {
            const response = await axios.post(
                token
                    ? "/auth/employer/password/reset"
                    : "/auth/employer/password/email",
                token
                    ? {
                          email,
                          token,
                          password,
                          password_confirmation: confirmation,
                      }
                    : { email },
                { headers: csrf() },
            );
            setMessage(response.data.message);
            setDone(true);
        } catch (e) {
            setErrors(e.response?.data?.errors || {});
            setMessage(
                e.response?.data?.message ||
                    "Unable to complete password recovery. Try again.",
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <AuthLayout>
            <form className="login-form" onSubmit={submit}>
                <Link className="login-back" to="/login">
                    Back to login
                </Link>
                <span className="section-kicker">EMPLOYER ACCOUNT</span>
                <h2>{token ? "Reset your password" : "Forgot password?"}</h2>
                <p>
                    {token
                        ? "Choose a password with at least 8 characters."
                        : "Enter your registered work email to receive a reset link."}
                </p>
                {message && (
                    <p
                        role="status"
                        className={done ? "employer-success" : "employer-error"}
                    >
                        {message}
                    </p>
                )}
                {!done && (
                    <>
                        <label>
                            Work email
                            <input
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                required
                                autoComplete="email"
                            />
                            {errors.email && (
                                <small className="field-error">
                                    {errors.email[0]}
                                </small>
                            )}
                        </label>
                        {token && (
                            <>
                                <label>
                                    New password
                                    <input
                                        type="password"
                                        minLength={8}
                                        value={password}
                                        onChange={(e) =>
                                            setPassword(e.target.value)
                                        }
                                        autoComplete="new-password"
                                        required
                                    />
                                    {errors.password && (
                                        <small className="field-error">
                                            {errors.password[0]}
                                        </small>
                                    )}
                                </label>
                                <label>
                                    Confirm password
                                    <input
                                        type="password"
                                        value={confirmation}
                                        onChange={(e) =>
                                            setConfirmation(e.target.value)
                                        }
                                        autoComplete="new-password"
                                        required
                                    />
                                </label>
                            </>
                        )}
                        <button className="btn primary block" disabled={busy}>
                            {busy
                                ? "Please wait…"
                                : token
                                  ? "Update password"
                                  : "Send reset link"}
                        </button>
                    </>
                )}
            </form>
        </AuthLayout>
    );
}
