import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App.jsx";
import "./design-tokens.css";
import "./styles.css";
import "./roles.css";
import "./candidate-module.css";
import "./pages/candidate/candidate-registration-enhancements.css";
import "./pages/center/center-onboarding.css";
import "./pages/center/center-dashboard.css";
import "./pages/center/center-profile.css";
import "./brand-logo.css";
import "./workspace-headers.css";
import "./modal-forms.css";
import "./data-tables.css";
import "./scrollbars.css";

createRoot(document.getElementById("root")).render(
  <BrowserRouter
    future={{
      v7_relativeSplatPath: true,
      v7_startTransition: true,
    }}
  >
    <App />
  </BrowserRouter>,
);
import "./pages/center/center-workspace-pages.css";
