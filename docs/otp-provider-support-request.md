# OTP template creation permission investigation

## Resolved — 29 October 2026

Meta Security Centre now shows EXOWORK TECHNOLOGIES PRIVATE LIMITED Verified, originally verified 29 October 2026. Gupshup shows a 2K messaging limit. Direct Meta submission of `exowork_auth_otp_v2` succeeded and is Active – Quality pending; Gupshup automatically synchronized it as Approved.

- Approved language: `en` (not the failed `en_US` variant)
- Meta template ID: `946598521595389`
- Approved Gupshup template ID: `a570879e-36da-42dc-a5c4-4620c5bb60f7`
- Copy-code authentication, security recommendation, five-minute expiry and delivery validity.
- Production admin updated to the approved language and provider ID; login and registration WhatsApp switches confirmed enabled.
- One real OTP requested through production candidate login to the user-authorized test number. Website advanced to code entry; recipient confirmation and successful verification still required. Do not equate API acceptance with delivery.

The details below record the earlier failure for reference.

Please investigate authentication template creation permission for this account.

- Gupshup customer: 4000386233
- Gupshup app: ExoworkNotifications
- App ID: bcd032f4-5e7b-4929-9945-9f731b7a3af0
- Meta business portfolio: Exowork, 1991874351490062
- Live WABA / messaging account: 2594928817988721
- Sender: +91 97852 62702
- Replacement template: exowork_auth_otp_v2, en_US, AUTHENTICATION, COPY_CODE
- Gupshup template ID: 3829a604-93e1-4a5f-8aa4-776ac5179903
- Submitted: 28 October 2026
- Result: FAILED
- Exact rejection: This WhatsApp Business account does not have permission to create message template

The original exowork_auth_otp_v1 failed with the same reason. Existing utility and marketing templates are active. Meta Business settings shows the live messaging account owned by Exowork, account status Approved, number Connected, quality High, and the business Unverified. Security Centre shows the business is eligible for verification. The signed-in business administrator has full access. Authentication is selectable in Meta's template creation UI, but this does not confirm server-side eligibility.

Please identify the missing permission or account eligibility requirement and confirm whether business verification or provider-side account access must be corrected before this WABA can create authentication templates. Please do not reclassify OTP content as marketing or utility to work around the restriction.

## Direct Meta investigation — 28 October 2026

- Submitting the same v2 Authentication / Copy code template directly in WhatsApp Manager returned the identical permission error. This confirms Meta itself blocks creation, independently of the Gupshup submission path.
- Live Messaging limits page shows current tier 250, with four unique customer conversations in the last seven days.
- The page offers business verification OR 1,000 unique high-quality conversations in a rolling seven-day period to reach the 2,000 tier; upgrades can take up to 24 hours.
- Security Centre shows verification has not been completed. Opened the verification wizard for India / Private company; legal name prefilled as EXOWORK TECHNOLOGIES PRIVATE LIMITED. The next step requires a company registration/tax identifier (CIN, GSTIN, UDYAM, PAN or LLPIN). No legal details were submitted.
- Vonage's current Authentication eligibility documentation explicitly requires verified business and 2,000+ daily messaging tier, and documents this exact Meta error: https://api.support.vonage.com/hc/en-us/articles/30129084631068-Guidelines-and-Eligibility-Criteria-for-Creating-a-WhatsApp-Authentication-Template
