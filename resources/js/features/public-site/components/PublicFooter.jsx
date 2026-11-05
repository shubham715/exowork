import React from "react";
import BrandLogo from "../../../components/BrandLogo.jsx";

const FOOTER_GROUPS = [
  ["Platform", [["How it works", "/how-it-works"], ["Who it's for", "audiences"], ["Automation", "automation"], ["Trust & data", "trust"]]],
  ["Roles", [["Employers", "/how-it-works?role=employer"], ["Training partners", "/how-it-works?role=training-center"], ["Candidates", "/how-it-works?role=candidate"], ["Support teams", "contact"]]],
  ["Company", [["Contact us", "contact"], ["contact@exowork.in", "mailto:contact@exowork.in"]]],
];

export default function PublicFooter() {
  return (
    <footer className="ex-footer">
      <div className="ex-wrap">
        <div className="ex-footer-grid">
          <div className="ex-foot-brand">
            <BrandLogo />
            <p>
              A simple platform connecting candidates, training partners and
              employers, from training and job matching to successful joining.
            </p>
          </div>
          {FOOTER_GROUPS.map(([heading, links]) => (
            <div className="ex-foot-col" key={heading}>
              <b>{heading}</b>
              {links.map(([label, target]) => (
                <a href={target.startsWith("mailto:") || target.startsWith("/") ? target : `/#${target}`} key={label}>
                  {label}
                </a>
              ))}
            </div>
          ))}
        </div>
        <div className="ex-footer-bottom">
          <p>© {new Date().getFullYear()} EXOWORK. Connecting skills to sustainable employment.</p>
        </div>
      </div>
    </footer>
  );
}
