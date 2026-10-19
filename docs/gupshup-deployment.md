# Gupshup deployment and controlled launch

This integration uses the existing outbox, scheduler, approval gates and encrypted communication settings. It does not enable sending merely by deploying files. Meta remains the default provider. No credentials belong in Git.

## Deploy

Back up the production database, then run from the deployed Laravel application directory after your normal Git pull:

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
php artisan queue:restart
```

Build assets in your existing CI/build process instead if Node is not installed on the server. Keep `APP_URL=https://exowork.in`, `QUEUE_CONNECTION=database`, and a shared persistent `CACHE_STORE=database` or Redis. Run the database queue worker continuously and `php artisan schedule:run` once per minute, as described in whatsapp-management.md. Workers must restart after credential/provider changes. Do not run migrations locally against the production database.

## Save connection, with automation OFF

Under Admin → WhatsApp → API settings select Gupshup:

- App ID: `bcd032f4-5e7b-4929-9945-9f731b7a3af0`
- App name: `ExoworkNotifications`
- Sender: `919785262702`
- Default country code: `91`
- Preferred authentication: Customer Secret. Generate it in Gupshup and enter it privately here. The app caches encrypted Customer App Tokens, refreshes before expiry and uses a shared cache lock to prevent concurrent token generation. The account secret expires within 90 days; rotate it before expiry. Do not rotate an existing account secret without coordinating any other integrations.
- A legacy Gupshup API key can be used for initial testing if no Customer Secret is configured. Gupshup advertises retirement of legacy keys on 31 March 2027. Do not paste secrets into chat or commit them.
- Webhook authentication secret: generate at least 32 random characters in a password manager and save it here. Use the same value in the Gupshup webhook header below.

Save with **WhatsApp messaging enabled** to allow template sync/manual tests, but leave **automatic stage messages OFF**. When paused, no new workflow messages are queued and already queued stage messages are skipped. Test/manual sends remain available with recipient consent. Only locally approved templates can be enabled for workflow use. Switching providers/accounts suppresses old queued sends and prevents resending attempts through the new account.

## Configure webhook only after the receiver is deployed

In Gupshup → ExoworkNotifications → Webhooks → Add Webhook:

- Name: `exowork-production`
- URL: `https://exowork.in/api/webhooks/gupshup`
- Payload: **Gupshup format (v2)**, not Meta v3.
- Includes headers: `X-Exowork-Webhook-Token` = the saved webhook authentication secret.
- Events: Message, Enqueued, Failed, Sent, Delivered, Read.

The receiver requires the matching app name and the secret header. A direct unsigned request should return 403; that is expected. It stores encrypted message text, suppresses repeated deliveries, reconciles Gupshup IDs with WhatsApp delivery IDs, and applies STOP before further sends. Do not use a secret query parameter in the callback URL. Verify the custom header is present during a Gupshup test before enabling events.

## Template and reply testing

`exowork_welcome_v1` was submitted through the Gupshup dashboard with English (US), category Marketing, one name variable and Yes/No quick replies. This code does not submit additional templates to Gupshup. Manage them in Gupshup and sync them into EXOWORK. Sync checks the exact body, language, current approval and supported components; enable the local approval only after Meta approval. The welcome quick replies are deliberately restricted to this exact template; arbitrary buttons/media templates are excluded.

1. Sync templates in EXOWORK and approve the welcome template once its provider status is APPROVED.
2. Confirm wallet funding privately; no payment or recharge is performed by this change.
3. Queue a consenting staff test from the template test dialog. Verify accepted → sent → delivered/read. No candidate stage is changed by test sends.
4. Send the welcome template manually to one consenting, active test candidate through their candidate WhatsApp action. Tapping Yes/No uses a per-message postback ID and updates that candidate's job interest only if unanswered. Verify the saved reply under WhatsApp → Logs → Candidate replies and the interest result in CRM. A response cannot update another candidate. A prior portal decision is preserved.
5. Typed responses (including an unscoped Yes/No), media notifications and unsupported actions are recorded as `needs_review`; staff handle these in the retained WhatsApp Business mobile app. Media files are not downloaded. This is a received-message log, not a full two-way inbox.
6. Reply STOP and confirm consent is withdrawn and pending sends are suppressed. Retried delivery events must not create duplicate messages or decisions. Confirm failed and unknown sends are distinct; never blindly retry an unknown submission.
7. Other stage templates continue using authenticated portal links. Create and approve those exact catalog templates in Gupshup before enabling their workflows. Interview/retention buttons are not automatically interpreted by this release.

## Enable automation gradually

After the controlled test, turn on automatic stage messages and monitor daily limits, wallet balance, provider errors and opt-outs. Turning the Gupshup automation switch on (including after a pause) resets the registration/application start date to that moment, so historical registrations are not welcomed automatically. Existing upcoming interviews and due retention checks are still eligible for their approved stage templates. Candidates registered while paused need a deliberately reviewed manual welcome if required. The onboarding dashboard showed a business portfolio limit of 250 customers per 24 hours; verify its current value in Meta/Gupshup. This code does not override Meta's limit.

Only the welcome template has been submitted so far. Other templates require provider approval and local review. Coexistence eligibility and phone-app availability still depend on Meta. Keep the Business app active as required by the provider. Automated messaging is never guaranteed protection from restrictions: record consent, honour opt-outs and monitor quality.

## Official protocol references

- https://docs.gupshup.io/reference/sending-text-template
- https://docs.gupshup.io/reference/postback-text-support
- https://docs.gupshup.io/reference/get-all-templates-for-an-app
- https://docs.gupshup.io/docs/message-events
- https://docs.gupshup.io/docs/cats
