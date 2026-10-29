# WhatsApp login and registration

Open Admin > Settings > Login & registration. WhatsApp login and registration verification default to enabled independently. Turning registration verification off restores the existing account forms. Turning login off shows mobile and password. Admin sign-in remains password based.

Under Communication, save an active Meta Cloud API or Gupshup connection. Under WhatsApp templates, the OTP account verification entry uses the same Meta sync, review, approval and test controls as every other template. Sync the provider status, then approve the exact AUTHENTICATION template before enabling real OTP traffic. Existing authentication name/language settings identify the canonical OTP template; the synced provider ID is used for sending. The template must accept one OTP body parameter. Meta templates must use a copy-code URL button at index 0. This configuration is required before real codes can be delivered; no simulated codes are exposed to users.

Codes expire after five minutes, allow five guesses, and can be resent after 60 seconds. Challenges are bound to the browser session, role and purpose and are consumed once. Registration proofs expire after two hours and match the submitted mobile and role. Registration endpoints enforce verification regardless of UI state. Password login remains available when WhatsApp login is enabled.

The server needs its configured persistent cache and session stores. Provider delivery acceptance is checked before advancing to code entry. Automated tests mock delivery; live provider delivery requires the approved template and credentials.


## Delivery and diagnosis

Gupshup receives the same code twice, once for the body and once for its copy-code button. OTP sends run synchronously so queue delays cannot consume their five-minute lifetime. They still create records in the shared WhatsApp message log, using `otp_login` or `otp_registration`. Code values and retry payloads are never stored in those records; OTP attempts cannot be resent from the logs. Users request a fresh code instead.

Provider acceptance requires a message ID. Acceptance is distinct from delivery: the browser polls its session-bound challenge status and reports failed delivery or missing confirmation. Signed receipts update the shared log. Timeouts are recorded as unknown and are not retried automatically.

A failed template must not be locally approved or represented as ready. Provider rejection reasons are shown beside the shared Meta status. The observed failure for `exowork_auth_otp_v1` was: `This WhatsApp Business account does not have permission to create message template`. This is an account-side creation failure; fixing the request parameter count alone cannot approve that template. Check the actual live WABA and its owning business portfolio with Gupshup/Meta before retrying submission.

Deploy the authentication-template migration before serving the updated admin API. It adds the canonical OTP entry and provider failure reason column without approving any template or enabling any provider credentials.
