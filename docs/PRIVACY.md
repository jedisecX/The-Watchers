# Privacy by design

## Data we collect, and why

| Data | Purpose | Visible to |
|---|---|---|
| Handle, display name, bio, avatar | Public profile | Everyone except people you blocked* |
| Email | Sign-in, verification, recovery, security notices | Only you (not staff in the console) |
| Password | Authentication | Nobody (argon2id hash only) |
| TOTP seed, recovery codes, passkey public keys | MFA | Nobody (encrypted / hashed / public-key only) |
| Posts, likes, reposts, follows | The service | Per your privacy settings |
| Direct messages | The service | Conversation members only |
| Session metadata: truncated IP (/24 or /48), user agent (200 chars), timestamps | Account security (device list, new-network alerts) | Only you |
| Security logs (full IP, request ID) | Abuse prevention and incident response | Operators |

\* A blocked user sees your profile as "doesn't exist".

**Not collected:**
- phone numbers, contact lists, precise location, or device fingerprints;
- third-party analytics, advertising identifiers, or tracking pixels;
- link previews: we never fetch the URLs you post, so neither we nor third parties learn who viewed them.

Uploaded media is **re-encoded from pixels**, which removes EXIF/GPS, camera serials and editing history
before anything is stored.

## Your controls

| Setting | Effect |
|---|---|
| Private account | Followers need approval; your posts, likes and follower lists are visible to approved followers only |
| Post visibility | Per post: public or followers-only (replies inherit the stricter visibility of the parent) |
| Who can message me | Everyone, people I follow, or no one. Once you reply in a conversation, the other person may continue |
| Discoverable | Whether you appear in people search |
| Show likes | Whether your liked posts are listed on your profile |
| Block | Removes follows both ways; they can't see or interact with you or your content; hidden from them as "not found" |
| Mute | Silently hides someone from your timelines and notifications |
| Sessions | See and sign out individual devices, or all others |
| Export | Download your data as JSON (profile, posts, likes, reposts, graph, messages you sent, your security log) |
| Delete account | Immediate deactivation; permanent purge after 30 days |

## Retention

| Data | Retention |
|---|---|
| Deleted posts | Body erased immediately (a tombstone row remains for thread integrity) |
| Deleted accounts | Hidden immediately; **hard-deleted after 30 days** (`app.cli purge`), cascading to all owned rows and media files |
| Unsent ("deleted") DMs | Content erased immediately for both parties; tombstone kept |
| Verification/reset/MFA/WS tokens | Expire in minutes or hours; rows purged 1 day after expiry |
| Email outbox | Bodies (which may contain links) erased on send; rows deleted after 30 days |
| Uploads | Originals deleted right after processing; failed uploads purged after 1 day; uploads never attached to a post, message or avatar purged after 7 days (with files) |
| Sessions | Revoked or expired rows kept for 90 days for your device history, then purged |
| Security logs (with full IP) | 90 days (log pipeline configuration) |
| Audit log | Append-only and retained by default (accountability); contains identifiers and reasons, never content or secrets. Archival is a deliberate owner-level procedure (export → `verify-audit` → store in WORM → drop), because the app cannot delete it |
| Report evidence | Kept with the report; resolved reports are purged after 1 year |
| Backups | Per `BACKUP-RECOVERY.md`; purged accounts disappear from backups when those backups expire |
