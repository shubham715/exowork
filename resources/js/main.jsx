import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App.jsx";
import "./design-tokens.css";
import "./styles.css";
import "./roles.css";
import "./candidate-module.css";
import "./pages/candidate/candidate-registration-enhancements.css";
import "./brand-logo.css";

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
