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

Build assets in your existing CI/build process instead if Node is not installed on the server. Keep `APP_URL=https://exowork.in`, `QUEUE_CONNECTION=database`, and a shared persistent `CACHE_STORE=database` or Redis. VPS hosts can run the queue worker continuously and `php artisan schedule:run` once per minute. For cron-only hosting use the alternative below. Do not run migrations locally against the production database.

## Cron-only hosting (no permanent worker)

The web server continues serving Laravel through `public/index.php`. Cron invokes PHP CLI separately; `npm run build` is unchanged. No public cron URL or `artisan serve` process is needed.

Set these values in the server `.env`:

```dotenv
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_QUEUE_RETRY_AFTER=180
```

After pulling the updated code run `php artisan migrate --force` and `php artisan config:clear` once from the project directory. Use the PHP CLI executable supplied by your host (PHP 8.2 or newer). If there is no terminal, run these as temporary cron entries and remove them after the log confirms success; do not leave migration/config-clear commands running every minute. Clearing the cache every minute also destroys the cached Gupshup tokens.

Add one cron entry with all five time fields set to `*` (every minute). Replace both example paths with the actual hosting paths:

```cron
* * * * * cd /home/YOURUSER/exowork/app && /usr/local/bin/php artisan whatsapp:cron >> /home/YOURUSER/exowork/app/storage/logs/whatsapp-cron.log 2>&1
```

In a cPanel Command box enter only the portion beginning with `cd`; choose “Once Per Minute” separately. The working directory must contain `artisan`, `.env`, and `vendor`, not be the `public` directory. The cron user must be able to write `storage` and `bootstrap/cache`.

`whatsapp:cron` checks the stage workflow and recovers the outbox, then runs the database queue for at most 50 jobs or about 45 seconds, finishing any current job. It exits immediately when empty. A persistent ten-minute lock prevents overlapping cron workers; after a host forcibly kills a run, a later run may wait until that lock expires. The per-job timeout is 60 seconds; PHP CLI and hosting limits must allow a batch to finish (potentially about 105 seconds including the final job, plus stage checks). Larger backlogs drain across subsequent runs. This processes the configured default database queue, including manually queued tests even while stage automation is paused.

Use either this cron entry OR the scheduler/permanent-worker setup, not both for WhatsApp. Expected new messages are sent on a subsequent cron run, usually within a minute plus queue/provider time; this is not an immediate-delivery guarantee. Check the cron log for start/finish lines and EXOWORK logs for delivery status. Keep automation OFF until manual delivery and reply tests pass. Gupshup token errors still need to be resolved separately.

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

## Formatted templates and testing (21 October 2026)

The catalog uses `exowork_registration_v3` and twelve stage templates ending in `_v2`. Bodies include WhatsApp bold markers and line breaks. Copy the exact body, category, language and sample variables from EXOWORK. Templates remain unusable until Meta approval, an exact-content sync and local approval. Earlier unformatted drafts remain in Gupshup but are not selected by this release. The old welcome job-interest question is retired. The migration resets local reviews and skips queued retired workflow templates without replaying them.

The new registration confirmation has two variables: candidate name and `https://exowork.in/candidate/profile`. It confirms registration without asking job interest in WhatsApp. Candidates review profile data and open **Placement preferences → Review job interest & communication** to explicitly save their preference in their account. Profile availability alone does not silently opt a candidate into job outreach.

### First controlled test

1. Deploy this release using the commands above, including the new migration and queue restart. Keep automatic stage messaging OFF. Enable WhatsApp messaging only for sync and manual tests.
2. Open Admin → WhatsApp → Templates, click **Sync Meta status**, and locally approve only templates whose exact provider content is APPROVED. Check the returned category; Meta may reclassify a Utility submission.
3. Open **Test** on the registration template. Enter a consenting staff number (not the business sender itself), the staff name and `https://exowork.in/candidate/profile`. Send once. Confirm the phone shows bold text and paragraph breaks and the log reaches accepted → sent → delivered/read. Acceptance alone is not delivery.
4. Reply with an ordinary text message. Confirm it appears in **WhatsApp → Logs → Candidate replies**. Staff reply using the retained WhatsApp Business app. Arbitrary Yes/No replies do not change candidate or interview records.
5. In staging, enable automation and register a new test candidate using that consenting number, then activate the account. Verify one registration message. Open the profile link, sign in, review data, and explicitly confirm job interest via Placement preferences. Running the scheduler again must not repeat the welcome. On production, keep automation OFF during individual template tests: enabling it also makes existing upcoming interviews and due employment check-ins eligible, not just the test candidate.

### Stage-by-stage test

Use staging first for date simulation and disposable records. On production use only your controlled test candidate and actual future dates; never change the server clock or alter real candidate outcomes to manufacture a test.

| Stage | Action | Expected message/response |
| --- | --- | --- |
| Training | Interested candidate, ongoing batch ending more than 15 days away | Training reconnect once; no job recommendations yet |
| Ready for jobs | Candidate explicitly interested, available, eligible and within 15 days of completion (or completed/not enrolled) | Career intro only if a real career video is configured; eligible job alert with employer hidden |
| Job interest | Candidate registers interest in a live eligible job | Interview availability link; candidate chooses within 3 days / 7 days / later in account; coordinator follow-up is created |
| Interview | Staff schedule an interview with location and future date | Confirmed details, company visible; no preparation-video configuration required |
| Reschedule | Staff change the interview date/time | New schedule notification; old queued schedule/reminder is suppressed |
| Reminder | Interview is tomorrow in Asia/Kolkata | Hindi reminder once; candidate confirms attendance, needs help or cannot attend in account |
| Selection | Staff record selected/rejected | Application decision update; candidate views next steps |
| Joining | Staff record joining pending, then actual joining with date | Joining update for each stage |
| Retention | Day 7, 15 or 30 after recorded actual joining | One due check-in; portal response is saved and assistance creates follow-up; left employment stops later checks |
| Center | Linked center has recorded WhatsApp consent and valid SPOC phone | Center placement update for interview/selection/joining |
| STOP | Test number replies STOP | Consent withdrawn and later queued/manual stage sends suppressed; do this last |

Scheduler must run each minute and queue workers must run continuously. Delivery/read receipts and candidate responses are separate records. Portal links require candidate authentication. Interview/retention decisions are submitted in the secure portal; free-text WhatsApp responses are logged for staff review and media is not downloaded.

## Enable automation gradually

After the controlled test, turn on automatic stage messages and monitor daily limits, wallet balance, provider errors and opt-outs. Turning the Gupshup automation switch on (including after a pause) resets the registration/application start date to that moment, so historical registrations are not welcomed automatically. Existing upcoming interviews and due retention checks are still eligible for their approved stage templates. Candidates registered while paused need a deliberately reviewed manual welcome if required. The onboarding dashboard showed a business portfolio limit of 250 customers per 24 hours; verify its current value in Meta/Gupshup. This code does not override Meta's limit.

All templates require provider approval and local review before sending. General career introductions and new-job alerts remain Marketing; registration and event-specific updates are submitted as Utility, with final category determined by Meta. Coexistence eligibility and phone-app availability still depend on Meta. Keep the Business app active as required by the provider. Automated messaging is never guaranteed protection from restrictions: record consent, honour opt-outs and monitor quality.

## Official protocol references

- https://docs.gupshup.io/reference/sending-text-template
- https://docs.gupshup.io/reference/postback-text-support
- https://docs.gupshup.io/reference/get-all-templates-for-an-app
- https://docs.gupshup.io/docs/message-events
- https://docs.gupshup.io/docs/cats

## Explicit app API key authentication

In WhatsApp API settings choose **App API key**, enter the key generated under the Gupshup app Settings → API Keys, and save. This explicitly ignores any retained Customer Secret. Test connection reads the app template endpoint with the apikey header; it does not mint a CAT or send a message. Sync templates after a successful test, then test one approved template with a consenting test recipient. Existing automatic mode remains unchanged. App API keys are a transitional option: Gupshup's published CAT guide sets retirement at 31 March 2027.

Deploy backend and public/build together. If Laravel configuration/routes are cached, clear those caches after pulling. No database migration is required for the authentication selection.

