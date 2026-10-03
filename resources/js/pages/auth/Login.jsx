import React, { useState } from "react";
import { ArrowLeft, ArrowRight, UserRound, Building2, GraduationCap, ShieldCheck } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import AuthLayout from "../../features/auth/components/AuthLayout.jsx";
import LoginForm from "../../features/auth/components/LoginForm.jsx";
import { PUBLIC_LOGIN_ROLES } from "../../features/auth/authConfig.js";

const choices = [
  { id: "candidate", icon: UserRound, description: "Find opportunities and build your career." },
  { id: "employer", icon: Building2, description: "Hire skilled people for your team." },
  { id: "center", icon: GraduationCap, description: "Connect your students with opportunities." },
];

export default function Login({ signup = false }) {
  const navigate = useNavigate();
  const [roleId, setRoleId] = useState(null);
  const role = PUBLIC_LOGIN_ROLES.find(item => item.id === roleId);
  return <AuthLayout>
    {!signup && role ? <LoginForm key={role.id} roles={[role]} onChangeRole={() => setRoleId(null)} /> :
      <section className="login-role-picker" aria-labelledby="role-heading">
        <Link className="login-back" to="/"><ArrowLeft /> Back to home</Link>
        <span className="section-kicker">WELCOME TO EXOWORK</span>
        <h2 id="role-heading">Are you a…</h2>
        <p>{signup ? "Choose your account type to get started." : "Choose your account type to log in or sign up."}</p>
        <div className="login-role-cards">
          {choices.map(({ id, icon: Icon, description }) => <button type="button" key={id} onClick={() => signup ? navigate(`/register/${id === "center" ? "training-center" : id}`) : setRoleId(id)}>
            <span className={`login-role-icon ${id}`}><Icon /></span>
            <span><strong>{PUBLIC_LOGIN_ROLES.find(item => item.id === id).label}</strong><small>{description}</small></span>
            <ArrowRight />
          </button>)}
        </div>
        <div className="login-picker-note"><ShieldCheck /> Your next step starts with a secure account.</div>
      </section>}
  </AuthLayout>;
}
