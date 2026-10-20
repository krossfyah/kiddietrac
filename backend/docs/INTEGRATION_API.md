# KiddieTrac Agency Integration API (v1)

A generic, idempotent, **agency-scoped** ingest API that lets any external agency
platform (the first consumer is **iLearn**) feed its **centres, families, and
children** into KiddieTrac under **its own agency** — and read back the families,
children and people created in KiddieTrac (see *Reading back from KiddieTrac*). Built for reuse: any agency
gets an `agency_admin` API token and pushes the same JSON shapes.

Base URL: `https://api.kiddietrac.com/api/v1/integration`

## Auth & scoping
- **Header:** `Authorization: Bearer <agency_admin Sanctum token>`
- All writes are scoped to the **token's agency**. An agency can never read/write
  another agency's data. (`platform_admin` tokens may target any agency via the
  `X-Active-Agency-Id` header.)
- **`X-Integration-Source: <slug>`** (e.g. `ilearn`) — namespaces each external
  system's records so two sources can't collide on the same `external_id`.
- `Content-Type: application/json`, `Accept: application/json`.

## Idempotency
Every entity is matched on **`external_id`** (the *caller's own* primary key) within
`(agency, source)`. Re-posting the same `external_id` **updates** the existing
record instead of duplicating it. Responses include `"created": true|false` and the
KiddieTrac `id` — store that on your side if you like, but you never need it: your
own `external_id` is the durable key.

## Endpoints

### `GET /ping`
Verify the token and see which agency it writes to.
```json
{ "ok": true, "agency_id": 2, "agency_name": "iLearn Home Childcare", "source": "ilearn" }
```

### `POST /centres`  → upsert a centre (a provider/home)
Required: `external_id`, `name`. Optional: `license_number`, `license_capacity`,
`address_line1/2`, `city`, `province`, `postal_code`, `country` (default Canada),
`phone`, `email`, `status` (default `active`).

### `POST /families`  → upsert a family
Required: `external_id`, `centre_external_id` (the centre's `external_id` — upsert
the centre first), `family_name`. Optional: `primary_phone`, `primary_email`,
`address_line1/2`, `city`, `province`, `postal_code`, `preferred_lang`, `notes`.

### `POST /children`  → upsert a child
Required: `external_id`, `family_external_id`, `first_name`. Optional: `last_name`,
`preferred_name`, `date_of_birth`, `gender`, `dietary_notes`, `medical_notes`,
`preferred_lang`, `enrollment_status`, `enrolled_at`. Re-sending a previously
withdrawn child with `enrollment_status: "enrolled"` re-enrols them and clears
`withdrawn_at`.

### `POST /children/deactivate`  → withdraw / graduate a child
Idempotent counterpart to `/children`, keyed on `external_id` within (agency,
source). Required: `external_id`. Optional: `enrollment_status` (`withdrawn`
default, or `graduated`), `withdrawn_at` (date; defaults to now). Sets the status
and stamps `withdrawn_at`. **The record is kept, not deleted** — attendance,
billing and roster history survive. If the child was never pushed here, returns
`200 { "found": false }` (a safe no-op).

### `POST /guardians`  → upsert a parent's parent-portal login
Creates/links a `User` + `Guardian` (role `guardian`) for a family, so the parent
can sign in to the parent-portal. Required: `family_external_id`, `email`,
`first_name`. Optional: `last_name`, `phone`, `relationship`, `is_primary`,
`can_pickup`. New users start `invited` with a random password and activate via
the normal forgot-password flow (no email is sent here, so a bulk backfill never
spams parents).

### `POST /sync`  → batch upsert, in dependency order
Body: `{ "centres": [...], "families": [...], "guardians": [...], "children": [...], "withdrawals": [...] }`.
Each item uses the same shape as its single endpoint; processed
centres→families→guardians→children→withdrawals so references resolve and an
enrol+withdraw in the same batch never undoes itself. `withdrawals[]` items take
the `/children/deactivate` shape. Returns per-item results (a bad row never blocks
the batch).

## Reading back from KiddieTrac (pull endpoints)

KiddieTrac is the source of record for anything created in it. A consumer that keeps
its own copy polls these endpoints; nothing is pushed to the consumer, so there is no
second credential and no inbound API to secure on its side. Same token, same headers,
same agency scoping as everything above. All are `GET` and read-only.

### `GET /pull/new?since=<ISO-8601>`  → families and children created here
- `families[]` — families created in KiddieTrac (`kt_id`, name, contact and address,
  `centre_external_id`), each with its `guardians[]` and `children[]`.
- `children[]` — children created here inside a family *you* created (a new sibling),
  carrying `family_external_id` so you attach them to the parent you already hold.
- `deactivations{children[],families[],centres[]}` — departures (`withdrawn`,
  `deleted`, `de_enrolled`, `archived`), stated explicitly rather than implied by a
  record no longer appearing. Rows carry `external_id` when you created the record.

### `GET /pull/users`  → every person in the agency (staff and parents)
The whole current list on every call (it is small, and matching on your side should be
idempotent, so a full read can never drift). Service accounts, including the caller's
own integration login, are never included.

```json
{
  "ok": true,
  "users": [
    {
      "kt_user_id": 812,
      "first_name": "Jordan", "last_name": "Lee",
      "email": "jordan@example.com", "phone": "+14165550100",
      "status": "active",
      "roles": ["educator"],
      "staff": {
        "roles": ["educator"],
        "centres": [{ "kt_id": 41, "name": "Maple Home Daycare",
                      "external_id": "provider-123", "external_source": "ilearn" }]
      },
      "guardian_of": [],
      "created_at": "2026-10-19 14:02:11", "updated_at": "2026-10-19 14:02:11"
    },
    {
      "kt_user_id": 913,
      "first_name": "Sam", "last_name": "Rivera", "email": "sam@example.com",
      "roles": ["guardian"], "staff": null,
      "guardian_of": [{ "family_kt_id": 77, "family_name": "Rivera",
                        "family_external_id": "parent-456", "family_external_source": "ilearn",
                        "centre_external_id": "provider-123",
                        "is_primary": false, "relationship": "guardian" }]
    }
  ],
  "deactivations": [
    { "kt_user_id": 640, "name": "Alex Moore", "email": "alex@example.com",
      "reason": "roles_ended", "at": "2026-10-18 09:30:00" }
  ]
}
```

- `staff` is `null` for someone who is only a parent; `guardian_of` is empty for
  someone who is only staff. A person can be both.
- `roles` are `educator`, `home_visitor`, `centre_director`, `agency_admin`, `guardian`.
- `guardian_of[].family_external_id` is set when **you** created the family — attach
  the parent to that record. When it is null the family was created in KiddieTrac and
  arrives through `/pull/new` as `ktfam-<family_kt_id>` on your side.
- `deactivations[]` lists **staff** whose access here has ended (`deleted` or
  `roles_ended`). Parents leave with their family, which `/pull/new` states.
- A staff member's `centres[].external_id` names your own provider when you created
  that centre; it is null for a centre made in KiddieTrac.

### `GET /contacts/pull?since=`, `GET /waitlist/pull?since=`
Name/date-of-birth changes to children you created, and waitlist leads created here.

### Suggested polling
Every 5 minutes for `/pull/new` and `/pull/users`. Match on `kt_user_id` /
`kt_id` once you have stored them; before that, match people on email
(case-insensitive) so someone you already hold is never created twice.

## Example
```bash
curl -X POST https://api.kiddietrac.com/api/v1/integration/centres \
  -H "Authorization: Bearer $TOKEN" -H "X-Integration-Source: ilearn" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"external_id":"prov-42","name":"Sunshine Home Daycare","city":"Mono","province":"ON"}'
```

## Errors
- `401` bad/missing token · `403` token isn't an agency_admin for the agency ·
  `422` validation error or unknown `centre_external_id` / `family_external_id`
  (upsert the parent first).

## Notes / roadmap
- Guardian invite emails are **not** sent on create (logins land in `invited`,
  activated via forgot-password). An opt-in invite/activation email is a follow-up.
- `/children/deactivate` withdraws but never hard-deletes. A child that genuinely
  needs removing (e.g. created in error) is still a manual KiddieTrac action.
