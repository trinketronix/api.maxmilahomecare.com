# Maxmila Homecare API — Client Integration Guide

> **For Claude Code users building a front end against this API:** copy this file into your
> project (for example `docs/maxmila-api.md`) and add `@docs/maxmila-api.md` to that project's
> `CLAUDE.md`. Everything Claude needs — base URLs, auth flow, response envelope, every endpoint
> with its role requirement and payload, the enums, the visit state machine and the gotchas — is
> in this one file. It is maintained in the API repo at `documentation/MAXMILA-API-CLIENT.md`;
> re-copy it when the API changes.

Source of truth is the API repository `trinketronix/api.maxmilahomecare.com` (`routes/*.php`,
`controllers/*.php`, `models/*.php`). Version: API `1.0.0`, guide date 2026-08-21.

---

## 1. Environments

| Environment | Base URL | Notes |
|---|---|---|
| Test | `https://api-test.maxmilahomecare.com` | Deployed automatically from `main`. Use for all development. |
| Production | provided by the Maxmila team | Deployed from GitHub releases. |
| Local (API developers) | `http://localhost:8080` | `docker compose up` in the API repo. |

`GET /` (no auth) returns `{ "status": "success", "info": { name, version, environment, database, apiBaseUrl, appBaseUrl, ... } }` — use it as a health check and to confirm which environment you are talking to.

Make the base URL a build-time/environment variable in your client (`MAXMILA_API_BASE_URL` or similar). Never hard-code it.

## 2. Request conventions

- **Paths have no `/api` prefix** and no version segment: `POST {base}/auth/login`.
- **`Content-Type: application/json` is mandatory on every `POST`/`PUT`/`PATCH`**, including ones without a body. Missing or wrong → `415`. `GET`/`DELETE`/`HEAD` do not need it.
- **Photo uploads** (`/user/.../photo`, `/patient/.../photo`) are always `POST` with `multipart/form-data` and the image as the first file field (any field name). Let the browser/`fetch` set the boundary; do not set `Content-Type` manually.
- **Authentication header:** `Authorization: <token>` — the raw token string exactly as returned by login, **without** `Bearer `. (`X-Auth-Token: <token>` is accepted as a fallback for proxies that strip `Authorization`.)
- IDs are integers. Dates are `YYYY-MM-DD`; date-times are `YYYY-MM-DD HH:MM:SS` in **America/Detroit** local time (no timezone suffix, no UTC conversion on the server).
- CORS: `Access-Control-Allow-Origin: *`, preflight handled. Responses are `Cache-Control: no-store`.
- Bodies are objects; bulk endpoints take a top-level JSON array.

## 3. Response envelope

Every JSON response has the same shape, and the HTTP status code always equals `code`:

```jsonc
// success
{ "status": "success", "code": 200, "data": <payload>, "message": "optional human text" }
// error
{ "status": "error",   "code": 4xx|5xx, "message": "human text or an object with details" }
```

- `data` is usually an object; a few endpoints return a string message or an array (noted below).
- `message` in errors is a string, except the assignment/bulk endpoints which may return an object (`{ message, invalid_patient_ids }`, `{ message, details }`).
- Empty collections on `GET /patients`, `GET /patients/addresses`, `GET /accounts` come back as **`204` with `data: "No records found"`**. Treat `204` as an empty list — browsers discard the body of a 204.
- Mutations often answer `201` (created/updated) or `202` (status change accepted). Do not branch on the exact 2xx code; branch on `status`.
- Unexpected server errors are `500` with a generic message; details are only in the server log.

Common error codes: `400` validation, `401` missing/invalid/expired token or inactive account, `403` role or ownership, `404` not found, `409` conflict (already assigned, address in use, save failed), `415` content type, `422` model validation failed.

## 4. Authentication lifecycle

```
register ──► activation email ──► user clicks {api}/activation/{code} (HTML page) ──► account Active
login ──► { token, user } ──► send token on every call ──► 401 ──► login again
```

1. **`POST /auth/register`** `{ username (email), password (≥8), firstname, lastname }` → `201`, `data` is a confirmation string. Account is created as **Not Verified**; login fails with `403 "Account is not activated."` until the emailed link is visited (or a manager activates it via `PUT /auth/activate/account`).
2. **`POST /auth/login`** `{ username, password }` → `data: { token: string, user: { fullname, photo } | null }`. Errors: `401` bad credentials, `403` not active.
3. **Token**: opaque string, valid **5 days**, **one active session per account** — a new login invalidates the previous token everywhere. Store it securely (Keychain/Keystore/secure storage; `httpOnly` cookie is not available because the API reads the `Authorization` header).
4. **`PUT /auth/renew/token`** (auth, empty JSON body `{}`) → `data: { token, user }`. Call it when the app comes to the foreground and the token is older than a day or two.
5. **On any `401`**: discard the token and send the user to login. Reasons: expired, replaced by another login, account disabled, or password/role changed by a manager.
6. **Decoding the token** is possible (base64 JSON with `id`, `username`, `role`, `expiration` in epoch ms) but **do not rely on its contents for authorization decisions** — the server re-reads role and status from the database on every request. It is fine to use `id` and `role` for UI decisions (which screens to show).
7. There is **no logout endpoint**; drop the token client-side.

### Password change (`PUT /auth/change/password`, authenticated)

| Who | Body |
|---|---|
| Myself | `{ "current_password": "...", "password": "new (≥8 chars)" }` |
| Administrator for another account | `{ "username": "x@y.z" \| "id": 12, "password": "new" }` |

On success (`202`) the affected account's token is revoked → that user must log in again. A self-service "forgot password" (unauthenticated, via emailed one-time link) does not exist yet; it is on the security roadmap.

## 5. Roles and permissions

`role` is an integer; **lower = more privilege**.

| role | name | Can |
|---|---|---|
| `0` | Administrator | everything; only admins can grant the admin role, modify admin accounts, reset other users' passwords, use `/bulk/*`, `/send/email`, `POST /tool` |
| `1` | Manager | manage patients, addresses, assignments, all visits; activate/inactivate/archive/delete non-admin accounts; change roles to manager/caregiver |
| `2` | Caregiver (default) | own profile/photo/addresses; own visits (schedule, update while scheduled, check-in/out, cancel); read assigned patients and patient addresses |

"Manager or higher" below means role `0` or `1`. Nobody can change their own role or account status through the API.

## 6. Enumerations

```ts
// Account / record status
export const Status = { NOT_VERIFIED: -1, INACTIVE: 0, ACTIVE: 1, ARCHIVED: 2, SOFT_DELETED: 3 } as const;
// Visit progress
export const Progress = { CANCELED: -1, SCHEDULED: 0, CHECKED_IN: 1, CHECKED_OUT: 2, APPROVED: 3 } as const;
// Address owner type
export const PersonType = { COMMUNITY: -1, USER: 0, PATIENT: 1 } as const;
// Role
export const Role = { ADMINISTRATOR: 0, MANAGER: 1, CAREGIVER: 2 } as const;
```

- Nothing is hard-deleted: "delete" endpoints set status `3`. `user_patient` assignments use only `0`/`1`.
- Patient status `0` (Inactive) blocks new visits for that patient.
- `extra_minutes` for visits is one of `0, 15, 30, 45`.

## 7. Data models (TypeScript)

```ts
export interface Account {            // GET /accounts, GET /account[/{id}]  (view over auth + user)
  id: number; username: string; role: number; status: number;
  token: string | null; expiration: number | null;
  auth_created_at: string | null; auth_updated_at: string | null;
  firstname: string; lastname: string; middlename: string | null; birthdate: string | null;
  ssn: string | null;                 // plain 9 digits, only present for managers/admins or self
  code: string | null;                // HHAexchange caregiver code
  phone: string | null; phone2: string | null; email: string; email2: string | null;
  languages: string | null; description: string | null; photo: string | null;
  user_created_at: string | null; user_updated_at: string | null;
}

export interface User {               // profile (shares id with the account)
  id: number; firstname: string; lastname: string; middlename: string | null; birthdate: string | null;
  ssn: string | null; code: string | null; phone: string | null; phone2: string | null;
  email: string; email2: string | null; languages: string | null; description: string | null;
  photo: string | null;               // path relative to the API base URL, e.g. "/user/photo/12-jane-doe.jpg"
  created_at: string | null; updated_at: string | null;
}

export interface Patient {
  id: number; patient: string | null; admission: string | null;   // HHAexchange ids
  firstname: string; middlename: string | null; lastname: string;
  gender: 'male' | 'female' | null; birthdate: string | null;
  phone: string; phone2: string | null; phone3: string | null;
  status: number; photo: string | null; created_at: string | null; updated_at: string | null;
}

export interface Address {
  id: number;                         // 0 = the system "Community" address (no fixed location)
  person_id: number; person_type: number;      // PersonType
  type: string;                       // 'House' | 'Apartment' | 'Condominium' | 'Trailer' | 'Other' | free text
  address: string; city: string; county: string; state: string; zipcode: string; country: string;
  latitude: number | null; longitude: number | null;
  created_at: string | null; updated_at: string | null;
}

export interface Assignment {
  user_id: number; patient_id: number; assigned_at: string; assigned_by: number;
  notes: string | null; status: 0 | 1;
}

export interface Visit {
  id: number; user_id: number; patient_id: number; address_id: number;
  visit_date: string;                 // YYYY-MM-DD
  start_time: string | null; end_time: string | null;   // YYYY-MM-DD HH:MM:SS, end = start + duration
  total_hours: number; extra_minutes: 0 | 15 | 30 | 45; note: string | null;
  progress: number;                   // Progress
  scheduled_by: number; checkin_by: number | null; checkout_by: number | null;
  canceled_by: number | null; approved_by: number | null;
  status: number; created_at: string | null; updated_at: string | null;
}

/** What every visit endpoint actually returns */
export interface VisitView extends Visit {
  duration_minutes: number;
  progress_description: 'Canceled' | 'Scheduled' | 'Checked In' | 'Checked Out' | 'Approved' | 'Unknown';
  user: User | {};                    // {} when the related row is missing
  patient: Patient | {};
  address: Address | {};
  is_today: boolean; is_future: boolean; is_past: boolean;
  sort_order?: number;                // present on list endpoints (1 today in-progress … 5 past)
}

export interface Envelope<T> { status: 'success' | 'error'; code: number; data?: T; message?: string | object; }
```

Photo URLs: prefix `photo` with the API base URL (`${base}${user.photo}`). Default photos are `/user/photo/default.jpg` and `/patient/photo/default.jpg`. Images are re-encoded to 360×360.

## 8. Endpoint reference

Legend: 🔓 no token · 👤 any authenticated · 🧑‍⚕️ manager or higher · 🛡️ administrator · "self" = the authenticated user's own record.

### 8.1 Auth

| Method & path | Who | Body | `data` |
|---|---|---|---|
| `POST /auth/register` | 🔓 | `{ username, password, firstname, lastname }` | string message (`201`) |
| `POST /auth/login` | 🔓 | `{ username, password }` | `{ token, user: {fullname, photo} \| null }` |
| `GET /activation/{code}` | 🔓 | — | HTML page (link from the email, not for apps) |
| `PUT /auth/renew/token` | 👤 | `{}` | `{ token, user }` |
| `PUT /auth/change/password` | 👤 | see §4 | `{ message }` (`202`), token revoked |
| `PUT /auth/change/role` | 🧑‍⚕️ | `{ id, role }` | `{ message }` (`202`); admin role requires 🛡️; target's token revoked |
| `PUT /auth/activate/account` | 🧑‍⚕️ | `{ id }` | `{ message }` (`202`) |
| `PUT /auth/inactivate/account` | 🧑‍⚕️ | `{ id }` | `{ message }` (`202`), token revoked |
| `PUT /auth/archive/account` | 🧑‍⚕️ | `{ id }` | `{ message }` (`202`), token revoked |
| `PUT /auth/delete/account` | 🧑‍⚕️ | `{ id }` | `{ message }` (`202`), token revoked (soft delete) |

Managers cannot act on administrator accounts (`403`); acting on your own account is `403`.

### 8.2 Accounts & users

| Method & path | Who | Body | `data` |
|---|---|---|---|
| `GET /accounts` | 🧑‍⚕️ | — | `{ count, users: Account[] }` (`204` when empty) |
| `GET /account` | 👤 | — | `Account` (self) |
| `GET /account/{id}` | self or 🧑‍⚕️ | — | `Account` |
| `PUT /user/{id}` | self or 🧑‍⚕️ | any of `lastname, firstname, middlename, birthdate, code, phone, phone2, email, email2, languages, description, ssn` | `{ message, user: User, updates: {field: {from, to}} }` (`201`) |
| `GET /user/photo` | 👤 | — | `{ photo }` (self) |
| `GET /user/photo/{id}` | self or 🧑‍⚕️ | — | `{ photo }` |
| `POST /user/upload/photo` | 👤 | multipart, first file | `{ message, path, filename, user_id, uploaded_by, processed }` (`201`) |
| `POST /user/{id}/upload/photo` | self or 🧑‍⚕️ | multipart | same |
| `POST /user/update/photo` | 👤 | multipart | same, key `updated_by` |
| `POST /user/{id}/update/photo` | self or 🧑‍⚕️ | multipart | same |

- `ssn` in: 9 digits, dashes allowed; out: 9 digits. Not visible to other caregivers. `photo` cannot be set through `PUT /user/{id}` — use the upload endpoints.
- Upload accepts JPEG, PNG, GIF, WEBP (detected from content, not from the file name) — `400 "Invalid file type…"` otherwise.

### 8.3 Patients

| Method & path | Who | Body | `data` |
|---|---|---|---|
| `POST /patient/new` | 🧑‍⚕️ | `{ firstname, lastname, phone, middlename?, patient?, admission?, gender?, birthdate?, phone2?, phone3?, address?: AddressInput }` | `{ message, patient_id, address_id? }` (`201`) |
| `GET /patients` | 🧑‍⚕️ | — | `{ count, patients: Patient[] }` (`204` empty) |
| `GET /patients/addresses` | 🧑‍⚕️ | — | `{ count, patients: (Patient & {addresses: Address[]})[] }` |
| `GET /patient/{id}` | 🧑‍⚕️ | — | `Patient` |
| `PUT /patient/{id}` | 🧑‍⚕️ | any of `firstname, middlename, lastname, phone, patient, admission, status` | `{ message, patient_id }` (`201`) |
| `PUT /patient/{id}/activate` · `/inactivate` · `/archivate` · `/delete` | 🧑‍⚕️ | `{}` | `{ message }` (`202`) |
| `GET /patient/visits/{id}` | 🧑‍⚕️ | — | see §8.6 |
| `POST /patient/{id}/upload/photo` · `POST /patient/{id}/update/photo` | 🧑‍⚕️ | multipart | `{ message, path, filename, patient_id, uploaded_by\|updated_by, processed }` |

Caregivers do not list patients directly; they use the assignment endpoints (§8.5).

### 8.4 Addresses

`AddressInput = { type, address, city, county, state (2 letters), zipcode (5 digits), country?, latitude?, longitude? }`

| Method & path | Who | Body | `data` |
|---|---|---|---|
| `POST /address` | own user address: 👤 · patient address: 🧑‍⚕️ | `AddressInput & { person_id, person_type }` | `{ message, id }` (`201`) |
| `GET /address/person/{personId}/{personType}` | user: self or 🧑‍⚕️ · patient: 👤 | — | `Address[]` — **for patients the list includes the Community address `id: 0`** |
| `GET /address/{id}` | own / patient: 👤 · other users': 🧑‍⚕️ | — | `Address` |
| `GET /address/nearby?latitude=&longitude=&radius=&person_type=` | 👤 | query string; radius in miles (0–100] | `(Address & {distance_miles})[]` sorted by distance |
| `PUT /address/{id}` | own: 👤 · patient: 🧑‍⚕️ | subset of `AddressInput` | `{ message }` (`201`) |
| `DELETE /address/{id}` | own: 👤 · patient: 🧑‍⚕️ | — | `{ message }` (`201`); `409` if any visit uses it; `id 0` is immutable |

### 8.5 Caregiver ↔ patient assignments

| Method & path | Who | Body | `data` |
|---|---|---|---|
| `POST /assign/patient` | 🧑‍⚕️ | `{ user_id, patient_id, notes? }` | `{ message, assignment }` (`201`); `409` already assigned |
| `POST /assign/patients` | 🧑‍⚕️ | `{ user_id, patient_ids: number[], notes? }` | `{ message, user_id, total_requested, total_success, total_failed, total_skipped, results: {success[], failed[], skipped[]} }` (`201`, `200` all skipped, `422` none) |
| `POST /unassign/patients` | 🧑‍⚕️ | `{ user_id, patient_ids }` | same shape |
| `GET /assigned/patients/{userId}` | self or 🧑‍⚕️ | — | `{ user: {fullname, photo}, count, patients: Patient[] }` |
| `GET /unassigned/patients/{userId}` | self or 🧑‍⚕️ | — | same (active patients not assigned) |
| `GET /assigned/patients/addresses/{userId}` | self or 🧑‍⚕️ | — | `{ user: User, count, patients: (Patient & {addresses: Address[], assignment: Assignment})[] }` |
| `GET /unassigned/patients/addresses/{userId}` | self or 🧑‍⚕️ | — | same with `assignment: null` |
| `GET /assigned/users/{patientId}` | 🧑‍⚕️ | — | `{ patient, count, users: (User & {role, role_name, status, status_name, assignment})[] }` |
| `GET /unassigned/users/{patientId}` | 🧑‍⚕️ | — | same with `assignment: null` |

**Caregiver home screen recipe:** `GET /assigned/patients/addresses/{myId}` gives the patients, their addresses (including Community `id 0`) and the assignment in one call — enough to render the schedule form.

### 8.6 Visits

| Method & path | Who | Body | `data` |
|---|---|---|---|
| `POST /visit/schedule` | self or 🧑‍⚕️ (for others) | `{ user_id, patient_id, address_id, visit_date, total_hours, extra_minutes?, start_time?, note? }` | `{ message, visit: VisitView }` (`201`) |
| `GET /visits` | 🧑‍⚕️ | — | `{ count, visits: VisitView[] }` (all, pre-sorted) |
| `GET /user/visits/{userId}` | self or 🧑‍⚕️ | — | `{ count, visits }` (active only) |
| `GET /patient/visits/{patientId}` | 🧑‍⚕️ | — | `{ patient, count, visits }` |
| `GET /visit/{id}` | owner or 🧑‍⚕️ | — | `VisitView` |
| `PUT /visit/{id}` | owner while Scheduled · 🧑‍⚕️ until Approved/Canceled | any of `visit_date, start_time, total_hours, extra_minutes, note, address_id` | `{ message, visit }` |
| `PUT /visit/{id}/checkin` | owner or 🧑‍⚕️ | `{}` | `{ message, visit }` — only from Scheduled |
| `PUT /visit/{id}/checkout` | owner or 🧑‍⚕️ | `{}` | only from Checked In |
| `PUT /visit/{id}/approve` | 🧑‍⚕️ | `{}` | only from Checked Out |
| `PUT /visit/{id}/cancel` | owner or 🧑‍⚕️ | `{ note }` (required) | from Scheduled or Checked In |
| `PUT /visit/{id}/status/visible` · `/archived` · `/deleted` | 🧑‍⚕️ | `{}` | `{ message, visit }` |

Rules the client should mirror for good UX:
- `address_id` **`0` means Community** (no fixed address); otherwise it must be one of the patient's addresses.
- `total_hours` 0–24 and `extra_minutes ∈ {0,15,30,45}`; the total duration must be > 0. `end_time` is computed server-side from `start_time` + duration.
- Caregivers can only schedule for patients assigned to them; managers can schedule for anyone. The patient must be Active.
- **Duplicate guard:** an identical visit (same user/patient/address/date/duration) posted within 60 s returns the existing one with `201` — safe to retry on network failures.
- State machine: `Scheduled(0) → Checked In(1) → Checked Out(2) → Approved(3)`; `Canceled(-1)` from 0 or 1 with a note. A nightly job checks out visits left in progress from previous days.
- Sorting of list endpoints: today in-progress, today scheduled, future scheduled, canceled, past — then date/time descending.

### 8.7 Administrator-only utilities

| Method & path | Body | Purpose |
|---|---|---|
| `POST /bulk/auths` · `/bulk/users` · `/bulk/patients` · `/bulk/addresses` · `/bulk/user-patient` · `/bulk/visits` | JSON array | seeding / backup recovery; per-row `results.success/failed` |
| `POST /send/email` | `{ to, subject, body }` | send mail from the company account |
| `POST /tool`, `GET /tools`, `GET /tool/{id}` | — | throwaway table for load tests (authenticated; create is admin) |

## 9. Minimal TypeScript client

```ts
export class MaxmilaApi {
  constructor(private baseUrl: string, private getToken: () => string | null, private onUnauthorized: () => void) {}

  private async call<T>(method: string, path: string, body?: unknown, form?: FormData): Promise<T> {
    const headers: Record<string, string> = {};
    const token = this.getToken();
    if (token) headers['Authorization'] = token;                 // raw token, no "Bearer"
    if (!form && method !== 'GET' && method !== 'DELETE') headers['Content-Type'] = 'application/json';
    const res = await fetch(`${this.baseUrl}${path}`, { method, headers, body: form ?? (body !== undefined ? JSON.stringify(body) : undefined) });
    if (res.status === 204) return [] as unknown as T;           // empty collection
    const env = (await res.json()) as Envelope<T>;
    if (res.status === 401) { this.onUnauthorized(); }
    if (env.status !== 'success') throw new ApiError(env.code, env.message);
    return env.data as T;
  }

  login = (username: string, password: string) => this.call<{ token: string; user: { fullname: string; photo: string } | null }>('POST', '/auth/login', { username, password });
  renew = () => this.call<{ token: string }>('PUT', '/auth/renew/token', {});
  myAccount = () => this.call<Account>('GET', '/account');
  myPatients = (userId: number) => this.call<{ count: number; patients: (Patient & { addresses: Address[] })[] }>('GET', `/assigned/patients/addresses/${userId}`);
  myVisits = (userId: number) => this.call<{ count: number; visits: VisitView[] }>('GET', `/user/visits/${userId}`);
  schedule = (v: { user_id: number; patient_id: number; address_id: number; visit_date: string; total_hours: number; extra_minutes?: number; start_time?: string; note?: string }) =>
    this.call<{ visit: VisitView }>('POST', '/visit/schedule', v);
  checkin = (id: number) => this.call<{ visit: VisitView }>('PUT', `/visit/${id}/checkin`, {});
  checkout = (id: number) => this.call<{ visit: VisitView }>('PUT', `/visit/${id}/checkout`, {});
  cancel = (id: number, note: string) => this.call<{ visit: VisitView }>('PUT', `/visit/${id}/cancel`, { note });
  uploadMyPhoto = (file: File) => { const f = new FormData(); f.append('photo', file); return this.call<{ path: string }>('POST', '/user/upload/photo', undefined, f); };
}

export class ApiError extends Error { constructor(public code: number, public detail: string | object) { super(typeof detail === 'string' ? detail : JSON.stringify(detail)); } }
```

Mobile (Swift/Kotlin) clients: same rules — raw token header, JSON content type on every PUT/POST, treat `204` as empty, reconnect on `401`.

## 10. Gotchas checklist

1. `Authorization` is the **raw token** — `Bearer ` makes every call `401`.
2. `PUT` with an empty body still needs `Content-Type: application/json` (send `{}`).
3. `204 No Content` = empty list, not an error; the JSON body is not reliably delivered.
4. HTTP status == `code`; success may be `200`, `201` or `202`.
5. `address_id: 0` is valid (Community). Use `=== undefined`/`=== null` checks, not falsy checks, for `address_id` and `total_hours`.
6. All date-times are Detroit local time without offset — do not convert to/from UTC.
7. One session per account: logging in on a second device logs the first one out.
8. `role` from the token may be stale after a manager changes it — the server revokes the token in that case, so expect a `401` and re-login.
9. Photo `path`s are relative to the API base URL.
10. Patient/account listing endpoints are manager-only; caregivers navigate via `/assigned/...`.
11. `GET /address/person/{id}/1` for a patient always includes the Community row (`id: 0`); render it as "Community / no fixed address".
12. Bulk/email/tool endpoints are admin-only and not for app use.

## 11. Recent API changes clients must know (2026-08)

- `PUT /auth/change/password` now requires a token and `current_password` (or admin). The old unauthenticated `{username, password}` form returns `401`.
- `PUT /visit/{id}` (update visit) now exists.
- `DELETE` requests no longer need a `Content-Type` header.
- `POST /patient/{id}/update/photo` replaces the former `PUT` route (PHP cannot read multipart bodies on PUT).
- `GET /address/nearby` takes query-string parameters.
- `/bulk/*`, `/send/email`, `/tools` require authentication (admin for writes).
- Tokens are revoked when an account is inactivated/archived/deleted or its role/password changes.
