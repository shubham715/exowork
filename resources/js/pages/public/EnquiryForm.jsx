import React, { useState } from "react";
import axios from "axios";
import { ArrowRight, CheckCircle2 } from "lucide-react";

export default function EnquiryForm() {
  const [sending, setSending] = useState(false);
  const [reference, setReference] = useState(null);
  const [error, setError] = useState("");

  async function submit(event) {
    event.preventDefault();
    if (sending) return;
    const form = event.currentTarget;
    const fields = Object.fromEntries(new FormData(form));
    setSending(true);
    setError("");
    try {
      const { data } = await axios.post("/public-api/enquiries", fields, {
        headers: { Accept: "application/json", "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content },
      });
      setReference(data.reference);
      form.reset();
    } catch (failure) {
      const response = failure.response;
      const validation = Object.values(response?.data?.errors || {})[0]?.[0];
      setError(validation || (response?.status === 429
        ? "Too many enquiries. Please wait a minute and try again."
        : response?.status === 419
          ? "Your session has expired. Refresh the page and try again."
          : "We couldn't send your enquiry. Please try again."));
    } finally {
      setSending(false);
    }
  }

  if (reference) {
    return <div className="ex-enquiry-success" role="status">
      <CheckCircle2 aria-hidden="true" />
      <h3>Enquiry received</h3>
      <p>Our team can contact you on the mobile number you shared. Your reference is #{reference}.</p>
      <button type="button" className="ex-btn ghost" onClick={() => setReference(null)}>Send another enquiry</button>
    </div>;
  }

  return <form className="ex-enquiry" onSubmit={submit} aria-labelledby="enquiry-title" aria-busy={sending}>
    <h3 id="enquiry-title">Get in touch</h3>
    <label htmlFor="enquiry-name">Full name</label>
    <input id="enquiry-name" name="name" autoComplete="name" maxLength={120} required />
    <label htmlFor="enquiry-role">I am here to</label>
    <select id="enquiry-role" name="audience" defaultValue="" required>
      <option value="" disabled>Select a reason</option>
      <option value="candidate">Find work</option>
      <option value="employer">Hire candidates</option>
      <option value="training-center">Support trainees</option>
      <option value="other">Ask something else</option>
    </select>
    <label htmlFor="enquiry-organization">Organisation <small>(optional)</small></label>
    <input id="enquiry-organization" name="organization" autoComplete="organization" maxLength={160} />
    <label htmlFor="enquiry-phone">Mobile number</label>
    <input id="enquiry-phone" name="phone" type="tel" autoComplete="tel-national" inputMode="numeric"
      pattern="[0-9]{10}" maxLength={10} placeholder="10-digit mobile number" required />
    <label htmlFor="enquiry-message">How can we help? <small>(optional)</small></label>
    <textarea id="enquiry-message" name="message" maxLength={1500} rows={3} />
    <button className="ex-btn grad" type="submit" disabled={sending}>{sending ? "Sending…" : "Send enquiry"} <ArrowRight size={16} aria-hidden="true" /></button>
    {error && <p className="ex-enquiry-error" role="alert">{error}</p>}
    <p>We’ll use the number you share to follow up on your enquiry.</p>
  </form>;
}
