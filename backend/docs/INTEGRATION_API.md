# KiddieTrac Agency Integration API (v1)

A generic, idempotent, **agency-scoped** ingest API that lets any external agency
platform (the first consumer is **iLearn**) feed its **centres, families, and
children** into KiddieTrac under **its own agency**. Built for reuse: any agency
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
