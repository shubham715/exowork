import React, { useEffect, useState } from "react";
import axios from "axios";
import { Link, useSearchParams } from "react-router-dom";
import { PageHead } from "../../components/UI";

const audiences = { candidate: "Candidate", employer: "Employer", "training-center": "Training center", other: "Other" };

export default function WebsiteEnquiries() {
  const [search, setSearch] = useSearchParams();
  const id = search.get("enquiry");
  const page = search.get("page") || "1";
  const [result, setResult] = useState(null);
  const [error, setError] = useState("");
  useEffect(() => {
    const controller = new AbortController();
    setResult(null);
    setError("");
    axios.get("/admin-api/enquiries", { params: id ? { enquiry: id } : { page }, signal: controller.signal })
      .then(({ data }) => setResult(data))
      .catch(failure => {
        if (failure.code === "ERR_CANCELED") return;
        setError(failure.response?.status === 403 ? "Your account cannot view website enquiries." : "Could not load enquiries. Please refresh and try again.");
      });
    return () => controller.abort();
  }, [id, page]);

  return <>
    <PageHead title={id ? `Website enquiry #${id}` : "Website enquiries"} sub="Callback requests from the EXOWORK homepage.">
      {id && <Link className="btn ghost" to="/admin/enquiries">All enquiries</Link>}
    </PageHead>
    {error ? <p role="alert">{error}</p> : !result ? <p role="status">Loading enquiries…</p> : <>
      {!result.data.length && <p>{id ? "This enquiry could not be found." : "No enquiries received yet."}</p>}
      <div className="website-enquiries">
        {result.data.map(item => <article key={item.id}>
          <header><div><small>#{item.id} · {audiences[item.audience]}</small><h2>{item.name}</h2></div>
            <a className="btn ghost" href={`tel:+91${item.phone}`}>{item.phone}</a>
          </header>
          {item.organization && <p>{item.organization}</p>}
          {item.message && <p className="website-enquiry-message">{item.message}</p>}
          <small>{item.created_at} · Server time</small>
        </article>)}
      </div>
      {result.last_page > 1 && <div className="website-enquiry-pagination">
        <button className="btn ghost" disabled={result.current_page <= 1} onClick={() => setSearch({ page: result.current_page - 1 })}>Previous</button>
        <span>Page {result.current_page} of {result.last_page}</span>
        <button className="btn ghost" disabled={result.current_page >= result.last_page} onClick={() => setSearch({ page: result.current_page + 1 })}>Next</button>
      </div>}
    </>}
  </>;
}
