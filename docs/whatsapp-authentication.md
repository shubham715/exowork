# WhatsApp login and registration

Open Admin > Settings > Login & registration. WhatsApp login and registration verification default to enabled independently. Turning registration verification off restores the existing account forms. Turning login off shows mobile and password. Admin sign-in remains password based.

Under Communication, save an active Meta Cloud API or Gupshup connection. In Login & registration, enter an approved AUTHENTICATION template name, language code, and Gupshup template ID when using Gupshup. The template must accept one OTP body parameter. Meta templates must use a copy-code URL button at index 0. This configuration is required before real codes can be delivered; no simulated codes are exposed to users.

Codes expire after five minutes, allow five guesses, and can be resent after 60 seconds. Challenges are bound to the browser session, role and purpose and are consumed once. Registration proofs expire after two hours and match the submitted mobile and role. Registration endpoints enforce verification regardless of UI state. Password login remains available when WhatsApp login is enabled.

The server needs its configured persistent cache and session stores. Provider delivery acceptance is checked before advancing to code entry. Automated tests mock delivery; live provider delivery requires the approved template and credentials.
