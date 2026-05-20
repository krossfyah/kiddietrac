---
title: AI tools (recap / churn risk / doc extraction)
category: AI
order: 50
---
# AI tools

Three AI-powered tools, all using Claude under the hood.

## Churn-risk score

**Sidebar → Churn risk** (director / agency admin).

Heuristic risk score 0-100 per family. **No AI call** — pure SQL aggregation over observation activity, sign-in attendance, overdue invoices, and balance owed. Updates each time you load the page.

Buckets:
- **High** (≥60) — needs intervention this week
- **Medium** (30-59) — keep an eye
- **Low** (<30) — engaged

Signals are spelled out in the table — "Observations down >50%", "2 overdue invoices", etc. Use this to know *why* a family is flagged.

## Weekly recap (parent-facing)

A friendly 1-paragraph recap of the child's week. Generated on demand, free for parents.

**Parent:** Sidebar → child name → **Portfolio** → **Generate AI recap**.

Uses Claude with the child's observations + activities tracked this week. Comes back in 5-15 seconds. Don't show this in real-time — render a "generating…" spinner.

## Document extraction

**Sidebar → AI doc extract** (admin / director).

Upload a photo or scan of a document — immunization record, certification, VSS check, ID, enrollment form — and we extract structured fields into JSON.

1. Paste the document URL (the doc must be publicly reachable — use the **MediaController** upload first if you have it on disk, then copy the returned URL).
2. Pick the document type.
3. Click **Extract fields**.
4. The JSON appears below. Copy fields into the relevant child / staff record manually for now — auto-link comes in v22p52.

Costs apply per Claude vision call. Budget ~$0.01-0.03 per document depending on size.
