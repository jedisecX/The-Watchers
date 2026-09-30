# Administrator & moderator guide

## Access requirements

To use the moderation console at `/admin`, which calls `/admin/api/v1/*`, you need:

1. A staff role: `moderator` or `admin`.
2. **Two-factor authentication enabled** (TOTP) or a registered **passkey**. Staff cannot disable MFA.
3. A **recent step-up**: a second-factor verification within the last 15 minutes. The console prompts you automatically.
4. An interactive browser session. API tokens never work on the admin API.

Staff sessions expire after **30 minutes of inactivity** (users: 7 days).

## Roles and permissions

| Capability | Moderator | Admin |
|---|---|---|
| View and resolve reports | ✓ | ✓ |
| Remove or restore posts | ✓ (not posts by staff of equal or higher rank) | ✓ |
| Warn, suspend or unsuspend users | ✓ (regular users only) | ✓ (users and moderators) |
| Revoke a user's sessions | — | ✓ |
| Change roles | — | ✓ (never your own; never the last admin; target must have MFA) |
| Blocked link domains | — | ✓ |
| Audit log and chain verification | — | ✓ |

## Every action is accountable

* A **reason** is mandatory and is stored in the tamper-evident audit log, together with your account, IP prefix and request ID.
* Destructive actions (suspend, role change) require **typing the target's handle** to confirm.
* The audit log cannot be edited or deleted through the application or by the app's database role. Tampering at the database level is detected by the hash chain (`Audit log → Hash chain intact`).

## Moderation workflow

1. **Reports → open.** Each report shows the reason, the reporter's note and trust tier, and an **evidence snapshot**. The snapshot is taken when the report is filed, so it survives even if the author deletes the content. It also shows how many open reports the target has.
   - Weigh reports from `new`-tier reporters accordingly (report-bombing).
   - Direct-message reports contain only the single reported message, never the conversation.
2. **Decide:**
   - Content violates the rules: **Remove post**, then **Resolve**.
   - Account-level abuse: **Warn** (adds a strike, lowering their rate limits), or **Suspend** (optionally for N days). Suspension signs the user out everywhere immediately, including open realtime connections.
   - No violation: **Dismiss**.
3. **Appeals:** **Restore** a removed post, or **Unsuspend**. Both need a reason.

## Blocked domains (admins)

Adding `example.com` also blocks every subdomain. New posts linking to it are rejected; existing posts are
unaffected, so remove them via reports if needed. Use this for phishing, malware and spam campaigns.

## Things staff can never do (by design)

* Read private messages. There is no admin endpoint for DMs; only individually reported messages appear as evidence.
* See users' email addresses in the console.
* Sign in as another user ("impersonation").
* Bypass the audit log.

## Bootstrapping the first admin

Run `python -m app.cli create-admin --handle H --email E` on the server. It prints a one-time password. Sign in, change
the password, and enrol MFA; until then the admin API refuses the account.
