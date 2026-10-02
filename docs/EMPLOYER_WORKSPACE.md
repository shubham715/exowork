# Employer workspace

Requirements basis: EXOWORK Website PPT.pdf pages 6-14 and EXOWORK_D3LOGICS_Development_Proposal(1).pdf pages 2, 6 and 8.

## Registration and access

`/login` selects an account type before displaying credentials. Employer signup opens `/register/employer` as one short form: full name, company name, work email and password. Mobile is optional. Consent remains required. Company industry, address, GSTIN, proof and extra contact details are completed later through the saved Company profile. Account name/mobile populate the hiring contact when available.

Registration stores the short name, email/mobile, hashed password, company legal name, industry, website, description, GSTIN, state/district/address/PIN, contact name/designation/direct phone/department, private document, and consent timestamps/version. GSTIN, proof and primary contact details are optional at signup and can be added later in Company profile. PDF/JPG/PNG proof is limited to 5 MB and supports browsing or drag and drop. Industry uses the active database master options. A hidden honeypot and server-side registration throttling provide spam controls without a visible math challenge.

An employer role and active organization membership are required for login and every workspace request. Identity, jobs, interviews and placements are scoped to that membership. Verification documents are downloaded only through authenticated, authorized endpoints.

Employer password recovery uses Laravel's expiring, single-use reset tokens. The local configuration currently uses `MAIL_MAILER=log`: reset emails are written to Laravel logs. Configure the deployment's mail transport for delivery to real inboxes. The application's employer terms/privacy notice is versioned `employer-v1`; replace the notice text with approved client policy copy when supplied.

## Verification and hiring

New accounts are pending. The admin Employers page lists saved organizations, permits private proof download, and accepts approval or rejection with remarks. Review requires `employers.manage`; list/download requires `employers.view`. Reviews and profile/job changes are audited.

Employers can create and edit drafts before verification. Publishing requires complete four-step job details and a verified employer, enforced on the server. Jobs support draft, active, filled and closed states. Changes to the company profile or a rejected review return active jobs to drafts and require fresh approval before publishing again.

The dashboard computes counts from owned database records. Candidate identities are shown only for applications with a staff-scheduled interview; arbitrary candidates or unconfirmed interests are not exposed. Selection/joining data is read-only for employers because staff confirmation is the MVP process in the proposal.

Job descriptions are manually editable. Client-funded AI description drafting is not connected by this change; it needs the agreed provider and credentials. This change does not configure WhatsApp or email provider accounts.

## Verification

`php artisan test` covers the existing candidate/center flows. `EmployerWorkspaceTest` additionally covers persistent registration and email/mobile login, consent/honeypot/file validation, private document access, organization isolation, manual review, publishing restrictions, profile reverification, pausing active jobs and password reset token reuse. `npm run build` compiles the UI.


## Operational employer panel (2 October 2026)

The employer dashboard prioritizes owned active jobs, drafts, released candidates, interview schedules and staff-confirmed joining follow-ups. Cards link to the relevant working lists. Job rows show real interview, released candidate and joined counts grouped by job ID rather than title.

Job posting retains the specified four stages, with field validation, draft saving and a review before publishing. Previous owned jobs can prefill a new requirement; the publishing status and deadline are reset and no record is copied until saved. View/edit opens the dedicated editor. Company approval still gates publishing on the server.

Candidate, interview and outcome lists provide name/job search, job ID and status filters, 20-row client pagination, selection across pages and CSV export of selected visible-authorized records. Filtering clears selection. Candidate pool rows represent candidate-job applications, using the latest scheduled interview per application. CSV cells escape quotes and neutralize formula prefixes. These tools do not expose unreleased candidates or let employers confirm selections directly. Pagination currently bounds rendered rows; the existing workspace endpoint still loads the organization's records in one response, so very large employer datasets need server pagination before scale deployment.

Typography, controls, page headers and operational tables follow the shared UI guide with scoped employer overrides. Frontend regression tests cover pending verification, large pool row limits, job identity filtering, latest application interviews and filtered empty states.

The revised default view leads with job cards and per-job hiring progress. Post a job offers a blank requirement or searchable starter templates, including saved company requirements. The four steps use grouped role/location/eligibility/salary/settings sections, option buttons and editable eligibility suggestions. The final preview groups fields by step with an Edit section action. Starter content is manually editable and is not AI-generated.

Guided job posting now separates field groups into bordered cards on the page background, with icon headers, a compact icon-based step navigator and a sticky draft/continue footer. Repeated step headings and oversized gaps are removed; verified company context is shown inline instead of an extra verification banner. Pending publishing requirements remain visible.
