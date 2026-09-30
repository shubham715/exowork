# EXOWORK database

The Laravel application uses `app/database/migrations` as the schema source. The original Laravel migrations create `users`, sessions, cache, and queue tables. Existing candidate migrations create `candidates` and encrypted `candidate_drafts`. The September 30 migrations add roles and permissions, training organizations and batches, employers and job posts, candidate provenance and consent history, applications, interviews, follow-ups, placements, and audit logs. The hiring table is named `job_posts` because Laravel's queue already uses `jobs`.

## Accounts and ownership

- `users` holds staff, training partner, employer, agent, admin, and superadmin credentials. `user_roles` assigns roles; `role_permissions` sets defaults; `user_permissions` supports explicit per-user grants or denials. A superadmin has all permissions by convention. No administrator account is seeded.
- `candidates` holds candidate profile and credentials for direct registrations. Assisted registrations can have a null password until an account activation flow is built. Candidate login uses a separate Laravel `candidate` guard. A candidate's training partner, center, batch, creator, and assigned agent are foreign keys; original form text is retained for historical display.
- `organization_memberships` connects users to training partners, centers, or employers. The center registration API derives its center from the signed-in user's active membership and rejects a batch from any other center.
- `candidate_consents` is append-only evidence for each consent purpose. `candidate_documents` records protected file paths and metadata; actual files remain on the configured Laravel storage disk.

## Local setup

The current `.env` selects the local MySQL database `exowork`. Run `php artisan migrate --force` and `php artisan db:seed --class=PlatformRolesSeeder --force` after deployment. Supply production database credentials through environment configuration. Do not run `migrate:fresh` on a database with user data.

The public candidate form writes to `/api/candidates`. The login form uses session authentication at `/auth/login`. Center candidate registration reads its signed-in center and actual batches from `/center-api/candidate-context` and writes to `/center-api/candidates`. Create the first staff user, role assignment, active organization, center membership, and training batch through a controlled provisioning flow before using the center form. The other center, employer, and dashboard screens still contain prototype browser data and need their own API work before production use.
