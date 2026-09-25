import React from "react";
import { Link } from "react-router-dom";
import { ShieldCheck } from "lucide-react";
import BrandLogo from "../../../components/BrandLogo.jsx";

export default function AuthLayout({ children, admin = false }) {
  return (
    <div className={`login-page ${admin ? "admin-login-page" : ""}`}>
      <aside>
        <Link to="/" className="public-brand light" aria-label="EXOWORK home">
          <BrandLogo />
        </Link>
        <div>
          <div className="login-kicker">
            <ShieldCheck /> {admin ? "SECURE ADMIN ACCESS" : "OPPORTUNITY THAT LASTS"}
          </div>
          <h1>
            Skills meet opportunity.
            <br />
            People build futures.
          </h1>
          <p>
            EXOWORK brings verified jobs, trusted training partners and human
            support together—helping every candidate move confidently from
            preparation to placement and long-term growth.
          </p>
        </div>
        <small>© {new Date().getFullYear()} EXOWORK · Connecting skills to sustainable employment</small>
      </aside>
      <main>{children}</main>
    </div>
  );
}
