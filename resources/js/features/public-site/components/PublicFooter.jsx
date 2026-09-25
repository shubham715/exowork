import React from "react";
import BrandLogo from "../../../components/BrandLogo.jsx";

const FOOTER_GROUPS = [
  ["Platform", [["How it works", "journey"], ["Who it's for", "audiences"], ["Automation", "automation"], ["Trust & data", "trust"]]],
  ["Roles", [["Employers", "contact"], ["Training partners", "contact"], ["Candidates", "contact"], ["Support teams", "contact"]]],
  ["Company", [["Contact us", "contact"], ["hello@exowork.in", "mailto:hello@exowork.in"]]],
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
                <a href={target.startsWith("mailto:") ? target : `#${target}`} key={label}>
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
