import React, { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { X } from "lucide-react";
import BrandLogo from "../../../components/BrandLogo.jsx";

const NAVIGATION = [
  ["pipeline", "Candidate journey"],
  ["journey", "How it works"],
  ["audiences", "Who it's for"],
  ["automation", "Automation"],
  ["trust", "Trust & data"],
  ["contact", "Contact"],
];

export default function PublicHeader() {
  const [menuOpen, setMenuOpen] = useState(false);
  const location = useLocation();

  useEffect(() => setMenuOpen(false), [location.pathname, location.hash]);

  useEffect(() => {
    document.body.classList.toggle("menu-open", menuOpen);
    return () => document.body.classList.remove("menu-open");
  }, [menuOpen]);

  return (
    <header className="ex-nav">
      <div className="ex-wrap ex-nav-inner">
        <a className="ex-brand" href="#top" aria-label="EXOWORK home">
          <BrandLogo />
        </a>
        <button
          type="button"
          className={`ex-menu-backdrop ${menuOpen ? "open" : ""}`}
          onClick={() => setMenuOpen(false)}
          aria-label="Close navigation menu"
          tabIndex={menuOpen ? 0 : -1}
        />
        <nav className={`ex-links ${menuOpen ? "open" : ""}`} aria-label="Main navigation">
          <div className="ex-drawer-head">
            <BrandLogo />
            <button type="button" onClick={() => setMenuOpen(false)} aria-label="Close menu">
              <X />
            </button>
          </div>
          <p className="ex-drawer-label">Explore EXOWORK</p>
          {NAVIGATION.map(([id, label]) => (
            <a href={`#${id}`} onClick={() => setMenuOpen(false)} key={id}>
              {label}
            </a>
          ))}
          <div className="ex-drawer-actions">
            <Link className="ex-btn ghost" to="/login">Sign in</Link>
            <Link className="ex-btn grad" to="/register">Get started</Link>
          </div>
        </nav>
        <div className="ex-nav-actions">
          <Link className="ex-btn ghost small" to="/login">Sign in</Link>
          <Link className="ex-btn grad small" to="/register">Get started</Link>
        </div>
        <button
          type="button"
          className={`ex-menu ${menuOpen ? "active" : ""}`}
          onClick={() => setMenuOpen((open) => !open)}
          aria-label={menuOpen ? "Close menu" : "Open menu"}
          aria-expanded={menuOpen}
        >
          <i />
          <i />
          <i />
        </button>
      </div>
    </header>
  );
}
