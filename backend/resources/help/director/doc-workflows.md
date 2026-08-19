---
roles: agency_admin, platform_admin
title: Multi-step document workflows
category: Administration
order: 65
---
# Document workflows

Multi-signer document signoff with full audit trail. Parent signs → admin reviews → director countersigns, in sequence.

## Creating a workflow (admin)

1. Sidebar → **Doc workflows**.
2. Tap **+ New workflow**.
3. Enter title, document type, full document text.
4. Specify the ordered list of signers (max 6 steps). Each step has:
   - **Role** (guardian / educator / centre_director / agency_admin)
   - **Specific user ID** (optional — leave blank to allow any user with that role)
   - **Signer label** (display text like "Parent signature" or "Director countersign")
5. Save.

## Workflow progression

- Step 1's signer is auto-notified.
- They sign with a signature pad (canvas drawing) + their name.
- The system records: signature image, signer user, timestamp, IP address, SHA-256 hash of (document_text + user_id + timestamp).
- Step 2's signer auto-notified once step 1 completes.
- And so on until the last step.

## Final state

When the last step is signed:
- Workflow status flips to `complete`.
- The originator gets a notification.
- The full document + every signature + every audit step is locked.

## Each step records

- Step order
- Required signer role
- Actual signer user
- Signed-at timestamp
- Signature image (base64 PNG)
- Signature hash (SHA-256)
- Signer IP address
- Signer label
- Optional notes

## When to use

- Enrolment contracts (parent + director)
- Medical authorization (parent + director + nurse if applicable)
- Field-trip permission (parent + lead educator)
- Policy acknowledgement (parent only, single-step)
- Termination paperwork (parent + admin)

## Audit trail

Every signed document is immutable after completion. The hash chain allows you to verify in court that the document was not altered after signing.
