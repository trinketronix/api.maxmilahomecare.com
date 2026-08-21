# Security Migration Plan (Batch E)

Status: **proposal for review** — nothing in this document is implemented yet.
Owner: Maxmila / Trinketronix. Target: after batches A–D are merged and verified on test.

These items need a schema change, a data migration, a maintenance window, or coordination with
the mobile/web clients, which is why they are separated from the hot-fixes already merged.

| # | Item | Data migration | Client impact | Window |
|---|---|---|---|---|
| E1 | Password hashing → Argon2id | transparent re-hash on login | none | none |
| E2 | Activation by random token, not the password hash | new columns; re-send for pending accounts | none | none |
| E3 | SSN encryption at rest (libsodium) + last-4 display | one-off re-encryption of `user.ssn` | managers see `***-**-1234` unless they request full | ~5 min |
| E4 | Opaque, hashed session tokens | none (forces re-login) | none (token is already opaque to clients) | all users re-login once |
| E5 | Forgot-password flow | uses E2 columns | new screens | none |
| E6 | Login throttling | new table | none | none |
| E7 | CORS allow-list | none | web origins must be registered | none |
| E8 | Purge `tests/maxmila/bulk-backup.http` from git history + credential rotation | — | — | coordinate with all clones |

## E1 — Password hashing (Argon2id)

Current: `sha512(username . password . username)` (`models/Auth.php`). Fast, unsalted beyond the
username, GPU-crackable; the hash is also the activation code (E2).

Plan
1. `Auth::setPassword()` → `password_hash($p, PASSWORD_ARGON2ID)`; `Auth::isCorrect()` →
   `password_verify()`, **with legacy fallback**: if the stored value is 128 hex chars, compare
   with the old scheme and, on success, re-hash with Argon2id and save (transparent upgrade).
2. `auth.password` is `CHAR(128)`; Argon2id strings are ~97 chars, so no DDL is required, but
   change it to `VARCHAR(255)` for headroom: `ALTER TABLE auth MODIFY password VARCHAR(255) NOT NULL`.
3. After 90 days, report accounts still on the legacy format (`LENGTH(password) = 128 AND password REGEXP '^[0-9a-f]+$'`)
   and force a reset for them (E5).
4. Rollback: none needed; both formats verify during the transition.

## E2 — Activation tokens

Current: the activation link is `strrev(passwordHash)`; the hash leaks via email, logs and browser
history, and never expires.

Plan
1. DDL: `ALTER TABLE auth ADD activation_token CHAR(64) NULL, ADD activation_expires DATETIME NULL, ADD INDEX idx_activation (activation_token)`.
2. On register: `token = bin2hex(random_bytes(32))`; store `sha256(token)` and `NOW() + 72h`;
   email `{API_BASE_URL}/activation/{token}`.
3. `GET /activation/{token}`: look up `sha256(token)` where not expired and status = Not Verified;
   activate and null the columns. Expired → page offering "resend activation".
4. `POST /auth/activation/resend { username }` (public, throttled by E6): regenerates for
   Not Verified accounts only; always answers 202 to avoid enumeration.
5. Migration: for accounts currently Not Verified, generate tokens and resend (script in
   `database/migrations/` + a one-off CLI in `cronjobs/`).
6. Remove the `password = :code:` lookup.

## E3 — SSN at rest

Current: `base64("S@1t3dPr3f1x" . ssn . "P3pp3rSuf1x")` with constants in source — any DB dump or
backup exposes every SSN in clear. Decoded SSNs are returned to every manager on `GET /accounts`.

Plan
1. Key: 32 random bytes, base64 in env `SSN_ENCRYPTION_KEY` (set via Apache `SetEnv`, same as DB
   credentials; never in git). Keep a second `SSN_ENCRYPTION_KEY_PREVIOUS` slot for rotation.
2. Crypto: `sodium_crypto_secretbox` (XSalsa20-Poly1305) with a random 24-byte nonce per value;
   store `base64(nonce . ciphertext)` prefixed with a version tag, e.g. `v1:`. `user.ssn` is
   already `TEXT`, no DDL.
3. New `Api\Services\SsnCipher` with `encrypt()`, `decrypt()`, `lastFour()`; delete
   `Api\Encoding\Base64::*SaltedPeppered`.
4. API surface: `ssn` becomes `***-**-1234` in `GET /accounts`, `GET /account/{id}`,
   `PUT /user/{id}` responses; full value only on `GET /account/{id}?reveal_ssn=1` for admins and
   for the user themselves, and every reveal is written to the server log with the actor id.
   Strip `ssn` from `User::toArray()` output used by the assignment endpoints.
5. Migration (maintenance window, ~minutes): CLI script decodes each legacy value
   (`Base64::decodingSaltedPeppered`) and writes the `v1:` ciphertext, in a transaction per 500
   rows, idempotent (skips rows already prefixed `v1:`). Take a DB backup first; the backup must
   be stored encrypted and deleted after verification because it contains clear SSNs.
6. Bulk import (`/bulk/users`) encrypts with the new cipher.
7. Rollback: the CLI accepts `--decrypt-to-legacy` during the transition window only.

## E4 — Session tokens

Current: `base64(json{id, username, role, expiration})`, stored in clear in `auth.token`; security
rests on the string comparison. Middleware already ignores the payload's role (Batch A).

Plan
1. Token = `bin2hex(random_bytes(32))` (64 chars). Store `sha256(token)` in `auth.token`
   (`TEXT`, no DDL; optionally `CHAR(64)` + index later). Keep `auth.expiration` as is.
2. Middleware: look up by hash (`WHERE token = sha256(header)`), then check expiration and status.
   This removes the need for the client to send an `id` anywhere.
3. Login/renew responses unchanged (`{ token, user }`), plus add `user.id` and `user.role` to the
   payload so clients no longer need to decode the token for UI decisions (update the client guide).
4. Rollout: deploy; every existing session becomes invalid → all users log in once. Announce.

## E5 — Forgot password

1. `POST /auth/password/forgot { username }` (public, throttled): generates a one-time token
   (reuse E2 columns or add `reset_token`, `reset_expires`, 30 min), emails
   `{APP_BASE_URL}/reset-password?token=…`; always 202.
2. `POST /auth/password/reset { token, password }` (public): verifies hash + expiry, sets the new
   password (E1), clears tokens, revokes the session (`auth.token = NULL`).
3. Clients add the two screens.

## E6 — Login throttling

1. Table `login_attempt (id, username, ip, attempted_at, success)` with indexes on
   `(username, attempted_at)` and `(ip, attempted_at)`.
2. Rule: more than 5 failures for a username or 20 for an IP in 15 minutes → `429` with
   `Retry-After`. Successful login clears the username counter. Apply to `/auth/login`,
   `/auth/password/*`, `/auth/activation/resend`.
3. Uniform error text (`Invalid credentials.`) on register/forgot to prevent enumeration.
4. Purge rows older than 24 h from the existing cron.

## E7 — CORS

Replace `Access-Control-Allow-Origin: *` + `Allow-Credentials: true` with an allow-list from env
(`CORS_ALLOWED_ORIGINS="https://app.maxmilahomecare.com,https://app-test.maxmilahomecare.com"`);
reflect the request `Origin` when listed, add `Vary: Origin`, drop `Allow-Credentials` (the API
uses header tokens, not cookies). Native mobile apps send no `Origin` and are unaffected.

## E8 — Purge PII from git history (decision needed)

`tests/maxmila/bulk-backup.http` was committed on 2025-04-15 and removed from the tree in Batch A,
but every clone and GitHub's history still contain ~110 real names, SSNs, birthdates, addresses,
phones, emails and plaintext passwords.

1. Treat as a data exposure: decide with the business whether notification obligations apply
   (Michigan Identity Theft Protection Act for SSNs; HIPAA if patient data was included — the
   file held caregiver data, verify whether any patient rows were present).
2. Rotate: every password in that file (force reset via E5 or admin reset), the SMTP/DB/FTP
   credentials in `secrets.txt` (never committed, but they are on every developer machine).
3. Rewrite history: `git filter-repo --invert-paths --path tests/maxmila/bulk-backup.http`,
   force-push all branches/tags, ask GitHub support to clear cached views, have every collaborator
   re-clone. This is destructive and must be scheduled explicitly.
4. Add a pre-commit hook / CI grep for SSN-shaped strings (`\b\d{3}-\d{2}-\d{4}\b`) in `tests/`.

## Suggested order and effort

1. E8 decision + credential rotation (business decision, 1 day)
2. E1 + E4 together (one deploy, one forced re-login) — 1 day dev, 1 day test
3. E2 + E5 (activation + forgot password, share DDL) — 2 days dev incl. client screens
4. E6 throttling — 0.5 day
5. E3 SSN — 1 day dev + migration window
6. E7 CORS — 0.5 day, needs the list of web origins
