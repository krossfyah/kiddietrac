<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\SalesProduct;
use App\Models\SalesQuote;
use App\Models\SalesQuoteItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Platform sales CRM (leads pipeline, activities/follow-ups, quotes/proposals).
 * Gated to sales_rep + platform_admin. Leads are a single shared pipeline (not
 * agency-scoped) — every sales user + superadmin sees them; `owner_id` assigns a rep.
 */
class SalesController extends Controller
{
    public const STAGES = [
        'new'         => 'New',
        'contacted'   => 'Contacted',
        'qualified'   => 'Qualified',
        'proposal'    => 'Proposal Sent',
        'negotiation' => 'Negotiation',
        'won'         => 'Won',
        'lost'        => 'Lost',
    ];

    // ---------------------------------------------------------------- Leads

    public function index(Request $r)
    {
        $q = SalesLead::query()->with('owner:id,first_name,last_name');

        if ($s = trim((string) $r->query('q', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%$s%")->orWhere('company', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%");
            });
        }
        if (($stage = $r->query('stage')) && isset(self::STAGES[$stage])) {
            $q->where('stage', $stage);
        }
        if ($r->query('mine')) {
            $q->where('owner_id', auth()->id());
        }
        if (in_array($r->query('status'), ['open', 'won', 'lost'], true)) {
            $q->where('status', $r->query('status'));
        }

        $leads = $q->orderByDesc('last_activity_at')->orderByDesc('id')->limit(2000)->get();

        return response()->json([
            'leads'    => $leads->map(fn ($l) => $this->leadRow($l))->values(),
            'stages'   => $this->stageSummary(),
            'owners'   => $this->owners(),
            'products' => SalesProduct::orderBy('sort')->orderBy('id')->get(),
            'stats'    => $this->stats(),
        ]);
    }

    public function store(Request $r)
    {
        $data = $this->validateLead($r, true);
        $data['owner_id'] = $data['owner_id'] ?? auth()->id();
        $data['source'] = $data['source'] ?? 'manual';
        $data['stage'] = $data['stage'] ?? 'new';
        $data['status'] = 'open';
        $data['last_activity_at'] = now();

        $lead = SalesLead::create($data);
        $this->logActivity($lead, 'stage', 'Lead created', null);
        if (! empty($data['follow_up_date'])) {
            $this->openFollowup($lead, $data['follow_up_date'], 'Follow up with ' . ($lead->company ?: $lead->name));
        }
        $this->notifyNewLead($lead);

        return response()->json($this->leadDetail($lead->fresh(['owner', 'activities.user', 'quotes.items'])), 201);
    }

    public function show(int $lead)
    {
        $l = SalesLead::with(['owner', 'activities.user', 'quotes.items'])->findOrFail($lead);

        return response()->json($this->leadDetail($l));
    }

    public function update(Request $r, int $lead)
    {
        $l = SalesLead::findOrFail($lead);
        $data = $this->validateLead($r, false);
        /* A blank source on an edit means "not changed", not "erase it": the column is NOT
           NULL, and a lead from the website form ("marketing-site") or chat had a source
           the edit form's dropdown did not list, so saving any other field sent null and
           failed (ticket #116, lead 35). */
        if (array_key_exists('source', $data) && trim((string) $data['source']) === '') {
            unset($data['source']);
        }

        // Stage transition → log it + reflect won/lost on status.
        if (array_key_exists('stage', $data) && $data['stage'] !== $l->stage) {
            $from = self::STAGES[$l->stage] ?? $l->stage;
            $to = self::STAGES[$data['stage']] ?? $data['stage'];
            $this->logActivity($l, 'stage', "Stage moved: {$from} → {$to}", null);
            if ($data['stage'] === 'won') {
                $data['status'] = 'won';
            } elseif ($data['stage'] === 'lost') {
                $data['status'] = 'lost';
            } elseif ($l->status !== 'open') {
                $data['status'] = 'open';
            }
        }

        // Salesforce-style field history — capture what changed before we overwrite.
        $edits = $this->diffLeadChanges($l, $data);

        $l->fill($data);
        $l->last_activity_at = now();
        $l->save();

        if ($edits) {
            $this->logActivity($l, 'edit', $edits, null);
        }

        // A new quick follow-up date opens a follow-up task.
        if (array_key_exists('follow_up_date', $data) && $data['follow_up_date']) {
            $this->openFollowup($l, $data['follow_up_date'], 'Follow up with ' . ($l->company ?: $l->name));
        }

        return response()->json($this->leadDetail($l->fresh(['owner', 'activities.user', 'quotes.items'])));
    }

    public function destroy(Request $request, int $lead)
    {
        $l = SalesLead::findOrFail($lead);

        /* Audited BEFORE the delete, and with the lead's own details in it. Anthony
           deleted several leads and the log could say only that a DELETE happened at
           /leads/{id} — the generic AuditActivity middleware records method, path and
           request body, and a DELETE carries an empty body with the id in the path. So
           the one record of what was removed was the row being removed. */
        $this->auditLeadDeleted($request, $l);

        $l->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * "Deleted sales lead ‟Sunrise Montessori” — Jane Doe · jane@… · stage qualified".
     *
     * A name, not an id: ids are not readable, and whoever reads this later cannot look
     * the lead up because it is gone. The whole row is snapshotted alongside the summary
     * so a deletion can actually be undone by hand if it was a mistake.
     */
    private function auditLeadDeleted(Request $request, SalesLead $l): void
    {
        try {
            $u = $request->user();
            $uid = (int) ($u->id ?? 0);

            $label = trim((string) ($l->company ?: $l->name ?: $l->email ?: ''))
                ?: ('lead #' . $l->id);
            $bits = array_values(array_filter([
                ($l->company && $l->name) ? $l->name : null,
                $l->email ?: null,
                $l->phone ?: null,
                $l->stage ? 'stage ' . $l->stage : null,
                $l->value ? 'value ' . $l->value : null,
            ]));

            \App\Support\Audit::write([
                'user_id'   => $uid ?: null,
                /* Leads are a single shared pipeline rather than an agency's, but the
                   audit viewer filters strictly on agency_id and shows NOTHING for a null
                   one — so this is stamped with the actor's active agency. Better visible
                   in the agency the person was working in than invisible everywhere. */
                'agency_id' => $uid ? \App\Support\AuditScope::resolve($uid, $request) : null,
                'action'      => 'sales.lead_deleted',
                'entity_type' => 'sales_lead',
                'entity_id'   => $l->id,
                'payload'     => json_encode([
                    'summary' => 'Deleted sales lead “' . $label . '”'
                        . ($bits ? ' — ' . implode(' · ', $bits) : ''),
                    'lead' => [
                        'id' => $l->id, 'name' => $l->name, 'company' => $l->company,
                        'email' => $l->email, 'phone' => $l->phone, 'stage' => $l->stage,
                        'status' => $l->status, 'source' => $l->source, 'value' => $l->value,
                        'owner_id' => $l->owner_id, 'city' => $l->city,
                        'created_at' => (string) $l->created_at,
                    ],
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never be the reason a delete fails.
        }
    }

    // ------------------------------------------------------- Activities / follow-ups

    public function addActivity(Request $r, int $lead)
    {
        $l = SalesLead::findOrFail($lead);
        $data = $r->validate([
            'type'     => 'required|in:note,call,email,meeting,followup',
            // Room for a meeting's transcript (the column is TEXT, ~64 KB).
            'body'     => 'nullable|string|max:60000',
            'due_date' => 'nullable|date',
            // Files are coming (a Teams transcript is written into the entry when it lands):
            // hold the team email until POST /sales/activities/{id}/notify, so it carries what
            // was discussed instead of a file name.
            'defer_notify' => 'nullable|boolean',
        ]);

        $this->deferNotify = ! empty($data['defer_notify']);
        $act = $this->logActivity($l, $data['type'], $data['body'] ?? null, $data['due_date'] ?? null);
        $this->deferNotify = false;

        // A followup with a due date is an open task + updates the lead's quick date.
        if ($data['type'] === 'followup' && ! empty($data['due_date'])) {
            $l->follow_up_date = $data['due_date'];
            $l->save();
        }

        /* users has first_name/last_name, not name: 'user:id,name' threw a 500 after the
           note was already saved, so every note looked like it failed (2026-10-09). */
        $act->load('user:id,first_name,last_name');
        $out = $act->toArray();
        $out['user_name'] = $act->user ? trim($act->user->first_name . ' ' . $act->user->last_name) : null;

        return response()->json($out, 201);
    }

    public function updateActivity(Request $r, int $activity)
    {
        $a = SalesActivity::findOrFail($activity);
        $data = $r->validate([
            'body'     => 'nullable|string|max:60000',
            'due_date' => 'nullable|date',
            'done'     => 'nullable|boolean',
            // The kind of entry, among the ones a person logs (2026-10-09: "need to be able
            // to edit the type of action as well"). Stage moves / field changes are not offered.
            'type'     => 'nullable|in:note,call,email,meeting,followup',
        ]);
        /* EDITING WHAT WAS WRITTEN (2026-10-09: "allow me to edit notes that I have added to
           leads to correct anything"). The wording of an entry is its author's — or a
           platform admin's — to correct; anyone on the team may still tick a follow-up done.
           System entries (stage moves, field changes) are a record, not prose: never edited. */
        $editsWords = array_key_exists('body', $data) || array_key_exists('due_date', $data)
            || (! empty($data['type']) && $data['type'] !== $a->type);
        $beforeBody = $a->body; $beforeDue = optional($a->due_date)->toDateString(); $beforeType = $a->type;
        if ($editsWords) {
            if (! self::canEditActivity($a)) {
                return response()->json(['message' => in_array($a->type, ['stage', 'edit'], true)
                    ? 'This entry was recorded by the system and cannot be edited.'
                    : 'Only the person who wrote this entry, or a platform admin, can edit it.'], 403);
            }
            $newType = ! empty($data['type']) ? $data['type'] : $a->type;
            if (array_key_exists('body', $data) && trim((string) $data['body']) === '' && $newType !== 'followup') {
                return response()->json(['message' => 'An entry cannot be empty.', 'errors' => ['body' => ['Write something.']]], 422);
            }
            // A follow-up is a task with a date: becoming one needs a due date.
            $dueAfter = array_key_exists('due_date', $data) ? $data['due_date'] : $beforeDue;
            if ($newType === 'followup' && $beforeType !== 'followup' && ! $dueAfter) {
                return response()->json(['message' => 'A follow-up needs a due date.', 'errors' => ['due_date' => ['Pick a due date.']]], 422);
            }
        }
        if (! empty($data['type']) && $data['type'] !== $a->type) {
            $toFollowup = $data['type'] === 'followup';
            $a->type = $data['type'];
            if ($toFollowup && ! array_key_exists('done', $data)) {
                $a->done = false; $a->done_at = null;          // a new task is open
            } elseif (! $toFollowup && $beforeType === 'followup') {
                $a->done = true; $a->done_at = $a->done_at ?: now();
                if (! array_key_exists('due_date', $data)) { $a->due_date = null; }   // no longer a task
            }
        }
        if (array_key_exists('body', $data)) {
            $a->body = $data['body'];
        }
        if (array_key_exists('due_date', $data)) {
            $a->due_date = $data['due_date'];
        }
        if (array_key_exists('done', $data)) {
            $a->done = (bool) $data['done'];
            $a->done_at = $a->done ? now() : null;
        }
        $changedWords = $editsWords && ((string) $beforeBody !== (string) $a->body
            || (string) $beforeDue !== (string) optional($a->due_date)->toDateString()
            || $beforeType !== $a->type);
        if ($changedWords) {
            $a->edited_at = now();
            $a->edited_by_id = auth()->id();
        }
        $a->save();

        if ($changedWords) {
            try {
                \App\Support\Audit::write([
                    'user_id' => auth()->id(),
                    'agency_id' => auth()->id() ? \App\Support\AuditScope::resolve((int) auth()->id(), $r) : null,
                    'action' => 'sales.activity_edited', 'entity_type' => 'sales_lead', 'entity_id' => $a->lead_id,
                    'payload' => json_encode([
                        'summary' => 'Edited a ' . ($beforeType !== $a->type ? $beforeType . ' (now a ' . $a->type . ')' : $a->type) . ' on lead #' . $a->lead_id,
                        'activity_id' => $a->id,
                        'before' => ['type' => $beforeType, 'body' => $beforeBody, 'due_date' => $beforeDue],
                        'after' => ['type' => $a->type, 'body' => $a->body, 'due_date' => optional($a->due_date)->toDateString()],
                    ]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
            }
        }

        /* The lead's quick "next follow-up" date follows its OPEN follow-ups: the earliest
           one, or none. Recomputed whenever a follow-up is ticked done or an entry becomes /
           stops being one. */
        if (($a->type === 'followup' || $beforeType === 'followup') && ($lead = SalesLead::find($a->lead_id))) {
            $next = SalesActivity::where('lead_id', $lead->id)->where('type', 'followup')->where('done', false)
                ->whereNotNull('due_date')->min('due_date');
            $next = $next ? \Illuminate\Support\Carbon::parse($next)->toDateString() : null;
            if ((string) optional($lead->follow_up_date)->toDateString() !== (string) $next
                && (string) $lead->follow_up_date !== (string) $next) {
                $lead->follow_up_date = $next;
                $lead->save();
            }
        }

        return response()->json($a->fresh('user'));
    }

    /** Set by addActivity while files are still on their way; see notifyNow(). */
    private bool $deferNotify = false;

    /**
     * POST /sales/activities/{activity}/notify — the team email for an entry whose files
     * (a Teams transcript) arrived after it was saved. Sent once.
     */
    public function notifyNow(Request $r, int $activity)
    {
        $a = SalesActivity::findOrFail($activity);
        if (! self::canEditActivity($a)) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }
        if (! \Illuminate\Support\Facades\Cache::add('sales-activity-notified:' . $a->id, 1, now()->addDays(30))) {
            return response()->json(['ok' => true, 'already' => true]);
        }
        if (($lead = SalesLead::find($a->lead_id))) {
            $this->notifyActivity($lead, $a->fresh());
        }

        return response()->json(['ok' => true]);
    }

    /** The author, or a platform admin; never a system entry. */
    public static function canEditActivity(SalesActivity $a): bool
    {
        if (in_array($a->type, ['stage', 'edit'], true)) return false;
        $me = auth()->user();
        if (! $me) return false;
        if ((int) $a->user_id === (int) $me->id) return true;

        return $me->roleAssignments->where('active', true)->pluck('role')->contains('platform_admin');
    }

    // ---------------------------------------------------------------- Quotes

    public function quoteStore(Request $r, int $lead)
    {
        $l = SalesLead::findOrFail($lead);
        $data = $this->validateQuote($r);

        $q = SalesQuote::create([
            'lead_id'        => $l->id,
            'title'          => $data['title'] ?? 'Proposal',
            'status'         => 'draft',
            'billing_period' => $data['billing_period'] ?? 'month',
            'currency'       => $data['currency'] ?? 'CAD',
            'taxes'          => $this->cleanTaxes($data['taxes'] ?? []),
            'terms'          => $data['terms'] ?? null,
            'conditions'     => array_key_exists('conditions', $data) ? $data['conditions'] : (self::proposalDefaultValues()['conditions'] ?? null),
            'prepared_for'   => self::cleanParty($data['prepared_for'] ?? null, self::FOR_KEYS),
            'prepared_by'    => self::cleanParty($data['prepared_by'] ?? null, self::BY_KEYS),
            'valid_until'    => $data['valid_until'] ?? null,
            'discount'       => $data['discount'] ?? 0,
            'notes'          => $data['notes'] ?? null,
            'created_by'     => auth()->id(),
        ]);
        $q->number = 'KT-Q-' . str_pad((string) $q->id, 6, '0', STR_PAD_LEFT);
        $q->save();
        $this->syncItems($q, $data['items'] ?? []);
        $this->logActivity($l, 'note', "Proposal {$q->number} created", null);

        return response()->json($q->fresh('items'), 201);
    }

    public function quoteUpdate(Request $r, int $quote)
    {
        $q = SalesQuote::findOrFail($quote);
        $data = $this->validateQuote($r);

        // Snapshot for the change history (before we overwrite).
        $qLabels = ['title' => 'Title', 'currency' => 'Currency', 'billing_period' => 'Billing period', 'valid_until' => 'Valid until', 'discount' => 'Discount', 'taxes' => 'Taxes', 'notes' => 'Notes', 'terms' => 'Terms', 'conditions' => 'Terms & conditions', 'prepared_for' => 'Prepared for', 'prepared_by' => 'Prepared by', 'status' => 'Status'];
        $qFmt = function ($k, $v) use ($q) {
            if ($v === null || $v === '') { return '—'; }
            if ($k === 'discount') { return self::money($v, $q->currency); }
            if ($k === 'taxes') { return implode(', ', array_map(fn ($t) => ($t['label'] ?? 'Tax') . ' ' . rtrim(rtrim(number_format((float) ($t['rate'] ?? 0), 3), '0'), '.') . '%', (array) $v)) ?: '—'; }
            if ($k === 'conditions') { return $v ? 'edited text (' . mb_strlen((string) $v) . ' characters)' : '—'; }
            if ($k === 'prepared_for' || $k === 'prepared_by') { return is_array($v) ? (implode(', ', array_filter(array_map('strval', $v), 'strlen')) ?: '—') : '—'; }
            if ($k === 'terms') { return mb_strimwidth(preg_replace('/\s+/', ' ', (string) $v), 0, 80, '…'); }
            if ($k === 'billing_period') { return $v === 'year' ? 'Annual' : ($v === 'month' ? 'Monthly' : (string) $v); }
            if ($k === 'valid_until') { return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr((string) $v, 0, 10); }
            return (string) $v;
        };
        $qBefore = [];
        foreach ($qLabels as $k => $lb) { $qBefore[$k] = $qFmt($k, $q->getAttribute($k)); }

        $q->fill(array_intersect_key($data, array_flip(['title', 'billing_period', 'currency', 'valid_until', 'discount', 'notes', 'terms', 'conditions'])));
        if (array_key_exists('taxes', $data)) {
            $q->taxes = $this->cleanTaxes($data['taxes'] ?? []);
        }
        if (array_key_exists('prepared_for', $data)) $q->prepared_for = self::cleanParty($data['prepared_for'], self::FOR_KEYS);
        if (array_key_exists('prepared_by', $data)) $q->prepared_by = self::cleanParty($data['prepared_by'], self::BY_KEYS);
        if (in_array($r->input('status'), ['draft', 'sent', 'accepted', 'declined'], true)) {
            $q->status = $r->input('status');
            if ($q->status === 'sent' && ! $q->sent_at) {
                $q->sent_at = now();
            }
            if ($lead = SalesLead::find($q->lead_id)) {
                if ($q->status === 'sent' && in_array($lead->stage, ['new', 'contacted', 'qualified'], true)) {
                    $lead->stage = 'proposal';
                    $lead->last_activity_at = now();
                    $lead->save();
                    $this->logActivity($lead, 'stage', "Proposal {$q->number} sent — stage → Proposal Sent", null);
                } elseif ($q->status === 'accepted') {
                    $this->logActivity($lead, 'note', "Proposal {$q->number} accepted 🎉", null);
                }
            }
        }
        $q->save();
        if ($r->has('items')) {
            $this->syncItems($q, $data['items'] ?? []);
        } else {
            $this->recomputeTotals($q);   // discount or taxes may have changed
        }

        // Log field-level edits to the lead's change history (Salesforce-style).
        if ($lead = SalesLead::find($q->lead_id)) {
            $qLines = [];
            foreach ($qLabels as $k => $lb) {
                $after = $qFmt($k, $q->getAttribute($k));
                if ($qBefore[$k] !== $after) {
                    $qLines[] = $lb . ': ' . $qBefore[$k] . ' → ' . $after;
                }
            }
            if ($r->has('items')) {
                $qLines[] = 'Line items updated';
            }
            if ($qLines) {
                $this->logActivity($lead, 'edit', "Proposal {$q->number} updated\n" . implode("\n", $qLines), null);
            }
        }

        return response()->json($q->fresh('items'));
    }

    public function quoteShow(int $quote)
    {
        return response()->json(SalesQuote::with('items', 'lead:id,name,company,email')->findOrFail($quote));
    }

    /** The proposal as it prints, for the in-app viewer (2026-10-16). */
    public function quotePreview(int $quote)
    {
        $q = SalesQuote::with('items', 'lead')->findOrFail($quote);
        return response()->json(['html' => $this->quoteHtml($q), 'number' => $q->number]);
    }

    public function quoteDestroy(int $quote)
    {
        $q = SalesQuote::findOrFail($quote);
        $q->delete();   // soft delete
        if ($lead = SalesLead::find($q->lead_id)) {
            $this->logActivity($lead, 'note', "Proposal {$q->number} deleted", null);
        }
        return response()->json(['ok' => true]);
    }

    /* Proposal defaults (2026-10-16, Anthony: "add the terms to the proposal (valid for 90
       days and add other words and ability to edit these words)"). One platform-wide set in
       platform_settings 'sales.proposal_defaults'; each new proposal starts from it and its
       own copy can then be edited. {days} in the terms is replaced with valid_days. */
    /* Standard Terms & Conditions printed on their own page of every proposal (2026-10-16).
       Drafted to match the published Terms of Use (kiddietrac.com/privacy#terms) — the same
       liability cap, data ownership and confidentiality. Edit in Plans & pricing; each
       proposal keeps the copy it was created with. */
    public const DEFAULT_CONDITIONS = <<<'TXT'
## 1. Agreement
When accepted, this proposal, these Terms & Conditions and the KiddieTrac Terms of Use and Privacy Policy (kiddietrac.com/privacy) form the agreement between KiddieTrac and the customer named in this proposal. Where they differ on price, billing or the services included, this proposal governs.

## 2. Services
KiddieTrac provides access to its childcare management platform for the locations, users and features listed in this proposal. Setup, onboarding, data migration and training are included only where they are listed.

## 3. Fees, billing and taxes
- Subscription fees are billed in advance for each billing period (monthly or annual, as shown) and renew automatically for the same period.
- One-time fees are invoiced on acceptance.
- Prices are in the currency shown. Taxes are added as listed and as required by law.
- Invoices are payable by the due date shown on the invoice. If a payment is overdue, access may be suspended after notice until it is paid.

## 4. Term, renewal and cancellation
- The subscription starts on the date the account is activated and continues for each billing period until cancelled.
- Either party may cancel in writing. Cancellation takes effect at the end of the current paid billing period.
- Fees already paid are not refunded, except where the law requires.
- We may change prices for a future billing period by giving at least 30 days' written notice.

## 5. Your data
You own the records you put into KiddieTrac. We host, process and back them up only to provide the service, as described in our Privacy Policy. You can export your data while your subscription is active, and we will help you retrieve it on request when the subscription ends.

## 6. Service availability and support
We work to keep KiddieTrac available and secure, but we do not guarantee uninterrupted service. We may add, change or remove features. Support is available by email at info@kiddietrac.com.

## 7. Your responsibilities
You are responsible for your users' accounts and for using the platform lawfully, including having any consent needed for the information and photographs you upload. KiddieTrac supports childcare operations; it does not replace professional judgement, supervision or your legal obligations.

## 8. Confidentiality
This proposal, its pricing and any demonstration of the platform are confidential. They may not be shared with third parties, and in particular not with any competitor of KiddieTrac.

## 9. Liability
To the fullest extent permitted by law, neither party is liable for indirect or consequential loss, and KiddieTrac's total liability is limited to the fees paid for the service in the twelve months before the claim.

## 10. Acceptance
This proposal can be accepted until the valid-until date shown, by signing below or by confirming acceptance in writing.
TXT;

    public const PROPOSAL_DEFAULTS = [
        'valid_days' => 90,
        'terms'      => "1. This proposal is valid for {days} days from the date issued.
"
            . "2. Prices are in the currency shown. Taxes are added where listed.
"
            . "3. Subscription fees are billed in advance for each billing period and renew automatically until cancelled in writing.
"
            . "4. One-time fees (such as setup and onboarding) are due on acceptance.",
        'conditions' => self::DEFAULT_CONDITIONS,
        'taxes'      => [],
        // "Prepared by" contact: the sales line, never the rep's own login email.
        'sales_title' => 'KiddieTrac Sales',
        'sales_email' => 'sales@kiddietrac.com',
        'sales_phone' => '1-855-400-9996',
    ];

    public const FOR_KEYS = ['company', 'name', 'title', 'address', 'email', 'phone'];
    public const BY_KEYS = ['name', 'title', 'email', 'phone'];

    /** Known keys only, trimmed; all empty = NULL (falls back to the lead / defaults). */
    private static function cleanParty($v, array $keys): ?array
    {
        if (! is_array($v)) return null;
        $out = [];
        foreach ($keys as $k) $out[$k] = trim((string) ($v[$k] ?? ''));
        return implode('', $out) === '' ? null : $out;
    }

    /** Who the proposal is for: what was typed on it, else the lead's details. */
    private static function preparedFor(SalesQuote $q, ?SalesLead $lead): array
    {
        if (is_array($q->prepared_for) && $q->prepared_for) {
            return array_merge(array_fill_keys(self::FOR_KEYS, ''), $q->prepared_for);
        }
        $lead = $lead ?: new SalesLead();
        return [
            'company' => (string) ($lead->company ?? ''), 'name' => (string) ($lead->name ?? ''), 'title' => (string) ($lead->title ?? ''),
            'address' => trim(implode(', ', array_filter([$lead->address ?? null, $lead->city ?? null, $lead->province ?? null, $lead->postal_code ?? null]))),
            'email' => (string) ($lead->email ?? ''), 'phone' => (string) ($lead->phone ?? ''),
        ];
    }

    /** Who prepared it: what was typed on it, else the rep's name with the SALES email and number. */
    private static function preparedBy(SalesQuote $q): array
    {
        if (is_array($q->prepared_by) && $q->prepared_by) {
            return array_merge(array_fill_keys(self::BY_KEYS, ''), $q->prepared_by);
        }
        return self::defaultPreparedBy($q->created_by ? \App\Models\User::find($q->created_by) : null);
    }

    private static function defaultPreparedBy($user): array
    {
        $d = self::proposalDefaultValues();
        return [
            'name'  => $user ? trim($user->first_name . ' ' . $user->last_name) : 'KiddieTrac Sales',
            'title' => (string) ($d['sales_title'] ?? ''), 'email' => (string) ($d['sales_email'] ?? ''), 'phone' => (string) ($d['sales_phone'] ?? ''),
        ];
    }

    private static function proposalDefaultValues(): array
    {
        $raw = DB::table('platform_settings')->where('key', 'sales.proposal_defaults')->value('value');
        $v = $raw ? json_decode($raw, true) : null;
        return array_merge(self::PROPOSAL_DEFAULTS, is_array($v) ? $v : []);
    }

    public function proposalDefaults()
    {
        // prepared_by: what a NEW proposal by this rep starts with.
        return response()->json(self::proposalDefaultValues() + ['prepared_by' => self::defaultPreparedBy(auth()->user())]);
    }

    public function proposalDefaultsSave(Request $r)
    {
        $data = $r->validate([
            'valid_days'   => 'required|integer|min:1|max:730',
            'terms'        => 'nullable|string|max:10000',
            'conditions'   => 'nullable|string|max:60000',
            'taxes'        => 'nullable|array|max:5',
            'taxes.*.label' => 'required|string|max:40',
            'taxes.*.rate'  => 'required|numeric|min:0|max:100',
            'sales_title'  => 'nullable|string|max:150',
            'sales_email'  => 'nullable|email|max:190',
            'sales_phone'  => 'nullable|string|max:60',
        ]);
        $v = ['valid_days' => (int) $data['valid_days'], 'terms' => (string) ($data['terms'] ?? ''), 'conditions' => (string) ($data['conditions'] ?? ''), 'taxes' => $this->cleanTaxes($data['taxes'] ?? [])];
        foreach (['sales_title', 'sales_email', 'sales_phone'] as $k) {
            $v[$k] = $r->has($k) ? trim((string) ($data[$k] ?? '')) : (self::proposalDefaultValues()[$k] ?? '');
        }
        DB::table('platform_settings')->updateOrInsert(['key' => 'sales.proposal_defaults'], ['value' => json_encode($v), 'updated_at' => now(), 'created_at' => now()]);
        return response()->json($v);
    }

    private function cleanTaxes(?array $taxes): array
    {
        $out = [];
        foreach ((array) $taxes as $t) {
            $label = trim((string) ($t['label'] ?? ''));
            if ($label === '') continue;
            $out[] = ['label' => mb_substr($label, 0, 40), 'rate' => round((float) ($t['rate'] ?? 0), 3)];
        }
        return array_slice($out, 0, 5);
    }

    /** Subtotal less discount, plus each tax on that amount. */
    private function recomputeTotals(SalesQuote $q): void
    {
        $taxable = max(0, round((float) $q->subtotal - (float) $q->discount, 2));
        $tax = 0;
        foreach ((array) ($q->taxes ?: []) as $t) {
            $tax += round($taxable * (float) ($t['rate'] ?? 0) / 100, 2);
        }
        $q->tax_total = round($tax, 2);
        $q->total = round($taxable + $tax, 2);
        $q->save();
    }

    // ---------------------------------------------------------------- Products (preset plans)

    public function products()
    {
        return response()->json(SalesProduct::orderBy('sort')->orderBy('id')->get());
    }

    public function productSave(Request $r, ?int $product = null)
    {
        $data = $r->validate([
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'price'       => 'nullable|numeric|min:0',
            'unit'        => 'nullable|string|max:20',
            'active'      => 'nullable|boolean',
            'sort'        => 'nullable|integer',
            // A discount plan (2026-10-14): % off or $ off, on all / recurring / one-time items.
            'kind'        => 'nullable|in:plan,discount',
            'discount_type' => 'nullable|required_if:kind,discount|in:percent,amount',
            'applies_to'  => 'nullable|in:all,recurring,one-time',
        ]);
        if (($data['kind'] ?? 'plan') === 'discount') {
            if (($data['discount_type'] ?? '') === 'percent' && (float) ($data['price'] ?? 0) > 100) {
                return response()->json(['message' => 'A percentage discount can be at most 100%.', 'errors' => ['price' => ['At most 100%.']]], 422);
            }
            $data['applies_to'] = $data['applies_to'] ?? 'all';
            $data['unit'] = 'discount';
        } else {
            $data['kind'] = 'plan';
            $data['discount_type'] = null;
            $data['applies_to'] = null;
        }
        $p = $product ? SalesProduct::findOrFail($product) : new SalesProduct();
        $p->fill($data)->save();

        return response()->json($p, $product ? 200 : 201);
    }

    public function productDelete(int $product)
    {
        SalesProduct::findOrFail($product)->delete();

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- Demo

    public function demoToken(Request $r)
    {
        // Short-lived token for a Test Agency (#6) admin so a rep can walk a
        // prospect through the live product. Demo data only — no real families.
        $demoUser = User::whereHas('roleAssignments', function ($q) {
            $q->whereIn('role', ['agency_admin', 'centre_director'])->where('agency_id', 6)->where('active', true);
        })->orderBy('id')->first();
        if (! $demoUser) {
            return response()->json(['message' => 'No demo account is set up for the Test Agency.'], 404);
        }
        $token = $demoUser->createToken('sales-demo', ['*'], now()->addHours(8))->plainTextToken;


        return response()->json(['token' => $token, 'agency_id' => 6, 'user' => ['id' => $demoUser->id, 'name' => trim($demoUser->first_name . ' ' . $demoUser->last_name)]]);
    }

    // ---------------------------------------------------------------- Sales team chat
    // A single shared channel for the sales team. The whole /sales route group is
    // gated to role:sales_rep,platform_admin, so only sales reps + superadmins reach it.

    public function messagesIndex(Request $r)
    {
        $since = (int) $r->query('since', 0);
        $q = \App\Models\SalesMessage::with('user:id,first_name,last_name')->orderBy('id', 'desc');
        if ($since > 0) {
            $rows = $q->where('id', '>', $since)->get()->sortBy('id')->values();
        } else {
            $rows = $q->limit(80)->get()->sortBy('id')->values();
        }
        $me = auth()->id();
        return response()->json(['messages' => $rows->map(fn ($m) => [
            'id'      => $m->id,
            'body'    => $m->body,
            'user_id' => $m->user_id,
            'mine'    => $m->user_id === $me,
            'author'  => $m->user ? trim($m->user->first_name . ' ' . $m->user->last_name) : 'Someone',
            'at'      => optional($m->created_at)->toDateTimeString(),
        ])->values()]);
    }

    public function messagesStore(Request $r)
    {
        $data = $r->validate(['body' => 'required|string|max:4000']);
        $m = \App\Models\SalesMessage::create(['user_id' => auth()->id(), 'body' => trim($data['body'])]);
        $m->load('user:id,first_name,last_name');
        return response()->json([
            'id' => $m->id, 'body' => $m->body, 'user_id' => $m->user_id, 'mine' => true,
            'author' => trim($m->user->first_name . ' ' . $m->user->last_name),
            'at' => optional($m->created_at)->toDateTimeString(),
        ], 201);
    }

    // ---------------------------------------------------------------- Sales announcements
    // Company / sales-team news only — nothing to do with agencies or centres.
    // Any sales user can post; everyone on the team reads.

    public function announcementsIndex()
    {
        $rows = \App\Models\SalesAnnouncement::with('user:id,first_name,last_name')
            ->orderByDesc('pinned')->orderByDesc('id')->limit(100)->get();
        return response()->json(['announcements' => $rows->map(fn ($a) => [
            'id'     => $a->id,
            'title'  => $a->title,
            'body'   => $a->body,
            'pinned' => (bool) $a->pinned,
            'author' => $a->user ? trim($a->user->first_name . ' ' . $a->user->last_name) : 'Sales team',
            'mine'   => $a->user_id === auth()->id(),
            'at'     => optional($a->created_at)->toDateTimeString(),
        ])->values()]);
    }

    public function announcementsStore(Request $r)
    {
        $data = $r->validate([
            'title'  => 'required|string|max:180',
            'body'   => 'required|string|max:8000',
            'pinned' => 'nullable|boolean',
        ]);
        $a = \App\Models\SalesAnnouncement::create([
            'user_id' => auth()->id(),
            'title'   => trim($data['title']),
            'body'    => trim($data['body']),
            'pinned'  => (bool) ($data['pinned'] ?? false),
        ]);
        return response()->json(['id' => $a->id], 201);
    }

    public function announcementsDestroy(int $announcement)
    {
        $a = \App\Models\SalesAnnouncement::findOrFail($announcement);
        // Author or any platform_admin can remove.
        $roles = auth()->user() ? auth()->user()->roleAssignments->pluck('role')->all() : [];
        if ($a->user_id !== auth()->id() && ! in_array('platform_admin', $roles, true)) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }
        $a->delete();
        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- Quote PDF / send

    public function quotePdf(int $quote)
    {
        $q = SalesQuote::with('items', 'lead')->findOrFail($quote);

        return response($this->quotePdfBytes($q), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . ($q->number ?: 'proposal') . '.pdf"',
        ]);
    }

    /** The proposal PDF: letter size, with "Page n of m" in the footer of every page. */
    private function quotePdfBytes(SalesQuote $q): string
    {
        $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($this->quoteHtml($q, true));
        $pdf->setPaper('letter');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 26.5, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.45, 0.52, 0.6]);

        return $pdf->output();
    }

    public function quoteSend(int $quote)
    {
        $q = SalesQuote::with('items', 'lead')->findOrFail($quote);
        $lead = $q->lead;
        if (! $lead || ! $lead->email) {
            return response()->json(['message' => 'This lead has no email address to send the proposal to.'], 422);
        }
        $bytes = $this->quotePdfBytes($q);

        try {
            $body = $this->proposalEmailHtml($q, $lead);
            Mail::html($body, function ($m) use ($lead, $q, $bytes) {
                \App\Support\MailScope::platform($m);   // sales, not tenant mail
                $m->to($lead->email, $lead->name ?: null)->subject('Your KiddieTrac proposal ' . ($q->number ?: ''));
                // The email says "just reply": send replies to the sales contact, not the no-reply sender.
                $pbm = self::preparedBy($q);
                if (! empty($pbm['email']) && filter_var($pbm['email'], FILTER_VALIDATE_EMAIL)) $m->replyTo($pbm['email'], $pbm['name'] ?: 'KiddieTrac Sales');
                $m->attachData($bytes, ($q->number ?: 'proposal') . '.pdf', ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not send the email: ' . $e->getMessage()], 500);
        }

        if ($q->status === 'draft') {
            $q->status = 'sent';
            $q->sent_at = now();
            $q->save();
        }
        $this->logActivity($lead, 'email', 'Proposal ' . ($q->number ?: '') . ' emailed to ' . $lead->email, null);

        return response()->json(['ok' => true]);
    }

    /**
     * The email that carries a proposal (2026-10-16, Anthony: "do not add the commercial numbers
     * on the email only the pdf attachment ... introducing the kiddietrac solution and thanking
     * those that are interested in working with us to making a difference for child and parent
     * ... and that all proposals are treated as private and confidential"). No prices, totals or
     * dates here — they live only in the attached PDF. Email-client safe: tables, inline styles,
     * hosted https images, no background images.
     */
    private function proposalEmailHtml(SalesQuote $q, SalesLead $lead): string
    {
        $navy = '#1E2A5E'; $teal = '#1F6080'; $muted = '#5B6B7C';
        $first = trim((string) strtok((string) ($lead->name ?: ''), ' ')) ?: 'there';
        $org = $lead->company ?: 'your centre';
        $pb = self::preparedBy($q);
        $rep = $pb['name'] ?: 'The KiddieTrac Team';
        $repLine = implode(' · ', array_filter([$pb['title'] ? e($pb['title']) : null,
            $pb['email'] ? '<a href="mailto:' . e($pb['email']) . '" style="color:' . $teal . ';text-decoration:none">' . e($pb['email']) . '</a>' : null,
            $pb['phone'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $pb['phone'])) . '" style="color:' . $teal . ';text-decoration:none">' . e($pb['phone']) . '</a>' : null])) ?: 'KiddieTrac';

        $tile = fn ($icon, $bg, $title, $text) => '<td class="kt-col" width="33%" valign="top" style="padding:0 6px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F9FB;border-radius:12px"><tr><td style="padding:18px 14px 16px;text-align:center">'
            . '<div style="width:52px;height:52px;line-height:52px;margin:0 auto 10px;border-radius:26px;background:' . $bg . ';font-size:26px;text-align:center">' . $icon . '</div>'
            . '<div style="font-size:14px;font-weight:bold;color:' . $navy . ';margin-bottom:5px">' . $title . '</div>'
            . '<div style="font-size:12.5px;line-height:1.5;color:' . $muted . '">' . $text . '</div>'
            . '</td></tr></table></td>';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<style>@media (prefers-color-scheme: dark){.kt-card{background:#ffffff !important}.kt-band{background:#081C41 !important}.kt-h{color:#1E2A5E !important}}'
            . '[data-ogsc] .kt-band,[data-ogsb] .kt-band{background:#081C41 !important}[data-ogsc] .kt-card,[data-ogsb] .kt-card{background:#ffffff !important}[data-ogsc] .kt-h{color:#1E2A5E !important}'
            . '@media (max-width:600px){.kt-col{display:block !important;width:100% !important;padding:0 0 10px !important}.kt-pad{padding-left:20px !important;padding-right:20px !important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#EEF3F6">'
            . '<div style="display:none;max-height:0;overflow:hidden">Thank you for your interest in KiddieTrac. Your proposal is attached.</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F6"><tr><td align="center" style="padding:24px 10px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="kt-card" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#0D1B2A">'

            // Logo + brand bar. Light-on-navy wordmark on a band that is navy in both schemes
            // (Outlook dark mode darkened the card under the dark-ink logo and it went speckled).
            . '<tr><td bgcolor="#081C41" class="kt-band" style="background:#081C41;padding:22px 24px;text-align:center"><img src="https://app.kiddietrac.com/login-wordmark.png" width="220" alt="KiddieTrac" style="display:block;margin:0 auto;border:0;width:220px;max-width:70%;height:auto"></td></tr>'
            . '<tr><td style="font-size:0;line-height:0"><img src="https://api.kiddietrac.com/email/brand-gradient.png" width="600" height="6" alt="" style="display:block;border:0;width:100%;max-width:600px;height:6px"></td></tr>'

            // Graphic 1: photo.
            . '<tr><td><img src="https://www.kiddietrac.com/images/life/colouring-800.jpg" width="600" alt="An educator and a child colouring a butterfly together" style="display:block;border:0;width:100%;max-width:600px;height:auto"></td></tr>'

            // Thank you + introduction.
            . '<tr><td class="kt-pad" style="padding:28px 40px 6px">'
            . '<div class="kt-h" style="font-size:24px;line-height:1.3;font-weight:bold;color:' . $navy . ';margin:0 0 14px">Thank you for considering KiddieTrac</div>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65">Hi ' . e($first) . ',</p>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65">Thank you for your interest in working with us. Every early-years team we meet shares the same purpose we do: making a real difference for children, and for the parents who trust you with them every day. We are genuinely glad to be talking with ' . e($org) . '.</p>'
            . '<p style="margin:0 0 6px;font-size:15px;line-height:1.65">KiddieTrac is a childcare management platform built around that purpose. It brings the whole day together in one place, so your educators spend less time on paperwork and more time with the children, and families feel close to their child\'s day even when they are apart.</p>'
            . '</td></tr>'

            // Graphic 2: what it brings.
            . '<tr><td class="kt-pad" style="padding:18px 34px 6px">'
            . '<div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;font-weight:bold;color:' . $teal . ';text-align:center;margin:0 0 14px">What KiddieTrac brings to your centre</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . $tile('&#128106;', '#E6F4F1', 'Closer families', 'Daily updates, photos and messages in the parent app, so families never miss a moment.')
            . $tile('&#128203;', '#E8F0FB', 'Less paperwork', 'Attendance, ratios, daily records and billing in one place for your whole team.')
            . $tile('&#128737;&#65039;', '#F1F8E6', 'Safe and ready', 'Secure sign-in and sign-out, and records that are ready whenever you need them.')
            . '</tr></table></td></tr>'

            // The proposal + next steps.
            . '<tr><td class="kt-pad" style="padding:22px 40px 4px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-left:4px solid #2C8AAC;background:#F4F9FB;border-radius:0 10px 10px 0"><tr><td style="padding:16px 18px;font-size:14.5px;line-height:1.6">'
            . '<div style="font-weight:bold;color:' . $navy . ';margin-bottom:4px">&#128206; Your proposal is attached</div>'
            . 'We have put together a proposal for ' . e($org) . ', attached to this email as a PDF together with our terms and conditions.'
            . '</td></tr></table>'
            . '<p style="margin:18px 0 14px;font-size:15px;line-height:1.65">Take your time looking it over. If anything raises a question, or you would like us to walk your team through KiddieTrac, just reply to this email. When you are ready to go ahead, sign the acceptance page and send it back to us.</p>'
            . '<p style="margin:0 0 4px;font-size:15px;line-height:1.65">We look forward to making a difference together.</p>'
            . '<p style="margin:18px 0 26px;font-size:15px;line-height:1.6">Warm regards,<br><strong style="color:' . $navy . '">' . e($rep) . '</strong><br><span style="color:' . $muted . ';font-size:13.5px">' . $repLine . '</span></p>'
            . '</td></tr>'

            // Confidentiality.
            . '<tr><td class="kt-pad" style="padding:0 40px 26px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF8E6;border:1px solid #F3DFA6;border-radius:10px"><tr><td style="padding:14px 16px;font-size:12.5px;line-height:1.55;color:#5C4A12">'
            . '<strong>&#128274; Private and confidential.</strong> This email and the attached proposal are intended only for the person and organisation they are addressed to. All KiddieTrac proposals are treated as private and confidential: please do not share, forward, copy or distribute them outside your organisation without our written permission. If you received this email by mistake, please let us know and delete it.'
            . '</td></tr></table></td></tr>'

            // Footer.
            . '<tr><td style="background:' . $navy . ';padding:18px 32px;text-align:center;font-size:12px;line-height:1.6;color:#C9D3E6">'
            . '<strong style="color:#ffffff">KiddieTrac</strong> · Smart Childcare Management Platform<br>'
            . '<a href="https://www.kiddietrac.com" style="color:#9FE0F0;text-decoration:none">kiddietrac.com</a> · <a href="mailto:info@kiddietrac.com" style="color:#9FE0F0;text-decoration:none">info@kiddietrac.com</a>'
            . '</td></tr>'

            . '</table></td></tr></table></body></html>';
    }

    private function brandBarHtml(): string
    {
        $f = public_path('email/brand-gradient.png');
        if (! is_file($f)) {
            return '<div style="height:5px;background:#2C8AAC;margin:14px 0 18px"></div>';
        }
        return '<div style="margin:14px 0 18px;line-height:0;font-size:0"><img src="data:image/png;base64,' . base64_encode((string) file_get_contents($f)) . '" alt="" style="display:block;width:100%;height:5px"></div>';
    }

    private function preparedByName(SalesQuote $q): ?string
    {
        $u = $q->created_by ? \App\Models\User::find($q->created_by) : null;
        return $u ? trim($u->first_name . ' ' . $u->last_name) : null;
    }

    /** Currencies a proposal can be priced in (code => name). Prices are typed in that currency; nothing is converted. */
    public const CURRENCIES = [
        'CAD' => 'Canadian dollar', 'USD' => 'US dollar', 'GBP' => 'British pound', 'EUR' => 'Euro',
        'AUD' => 'Australian dollar', 'NZD' => 'New Zealand dollar', 'JMD' => 'Jamaican dollar',
        'TTD' => 'Trinidad and Tobago dollar', 'BBD' => 'Barbados dollar', 'XCD' => 'East Caribbean dollar',
        'INR' => 'Indian rupee', 'PHP' => 'Philippine peso', 'ZAR' => 'South African rand', 'MXN' => 'Mexican peso',
    ];

    private static function money($v, ?string $cur): string
    {
        $sym = ['GBP' => '£', 'EUR' => '€', 'INR' => '₹', 'PHP' => '₱', 'ZAR' => 'R'][$cur ?: 'CAD'] ?? '$';
        return ((float) $v < 0 ? '-' : '') . $sym . number_format(abs((float) $v), 2);
    }

    /**
     * The proposal document — PDF, in-app View and email attachment all use this (2026-10-16
     * redesign, Anthony: "add kiddie trac logo and header to the proposal and make the format
     * better and add the terms and conditions"). Branded header with the logo, prepared for /
     * by, a summary strip, the price table and totals, notes and terms, then the Terms &
     * Conditions and an acceptance block on their own page. Tables only — dompdf has no flex.
     */
    private function quoteHtml(SalesQuote $q, bool $forPdf = false): string
    {
        $lead = $q->lead ?: new SalesLead();   // a proposal whose lead was deleted still prints
        $cur = $q->currency ?: 'CAD';
        $money = fn ($v) => self::money($v, $cur);
        $navy = '#1E2A5E'; $teal = '#1F6080'; $muted = '#64748b'; $line = '#E2E8F0';

        // Logo embedded so the PDF (remote loading off) and the sandboxed viewer both show it.
        $logo = '';
        $logoFile = public_path('email/logo-wordmark.png');
        if (is_file($logoFile)) {
            $logo = '<img src="data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile)) . '" style="width:150px;height:auto" alt="KiddieTrac">';
        } else {
            $logo = '<div style="font-size:24px;font-weight:bold;color:' . $navy . '">KiddieTrac</div>';
        }

        $issued = $q->sent_at ?: $q->created_at;
        $issuedTxt = $issued ? $issued->format('F j, Y') : '';
        $validTxt = $q->valid_until ? $q->valid_until->format('F j, Y') : '—';
        $billing = ['month' => 'Monthly', 'year' => 'Annual'][$q->billing_period] ?? 'One-time';
        $perWord = ['month' => 'per month', 'year' => 'per year'][$q->billing_period] ?? '';

        // Prepared for / by: as edited on the proposal, else from the lead and the sales contact.
        $pf = self::preparedFor($q, $lead);
        $pb = self::preparedBy($q);
        $head = $pf['company'] ?: ($pf['name'] ?: 'Prospect');
        $who = $pf['company'] ? trim($pf['name'] . ($pf['name'] && $pf['title'] ? ', ' : '') . $pf['title']) : $pf['title'];
        $for = '<div style="font-size:13px;font-weight:bold;color:' . $navy . '">' . e($head) . '</div>'
            . ($who !== '' ? '<div>' . e($who) . '</div>' : '')
            . ($pf['address'] !== '' ? '<div>' . e($pf['address']) . '</div>' : '')
            . ($pf['email'] !== '' ? '<div>' . e($pf['email']) . '</div>' : '')
            . ($pf['phone'] !== '' ? '<div>' . e($pf['phone']) . '</div>' : '');
        $by = '<div style="font-size:13px;font-weight:bold;color:' . $navy . '">' . e($pb['name'] ?: 'KiddieTrac Sales') . '</div>'
            . ($pb['title'] !== '' ? '<div>' . e($pb['title']) . '</div>' : '')
            . ($pb['email'] !== '' ? '<div>' . e($pb['email']) . '</div>' : '')
            . ($pb['phone'] !== '' ? '<div>' . e($pb['phone']) . '</div>' : '')
            . '<div>kiddietrac.com</div>';

        $label = fn ($t) => '<div style="font-size:8.5px;letter-spacing:1px;text-transform:uppercase;color:' . $muted . ';font-weight:bold;margin-bottom:5px">' . $t . '</div>';

        // Line items.
        $rows = '';
        $i = 0;
        foreach ($q->items as $it) {
            $qty = rtrim(rtrim(number_format((float) $it->qty, 2), '0'), '.');
            $neg = (float) $it->line_total < 0 ? 'color:#047857;' : '';
            $bg = $i++ % 2 ? 'background:#F8FAFC;' : '';
            $desc = e($it->description);
            // "Plan — description" from a preset: the plan name in bold, the description under it.
            if (strpos($it->description, ' — ') !== false) {
                [$name, $rest] = explode(' — ', $it->description, 2);
                $desc = '<span style="font-weight:bold;color:' . $navy . '">' . e($name) . '</span><br><span style="color:' . $muted . ';font-size:10.5px">' . e($rest) . '</span>';
            }
            $rows .= '<tr style="' . $bg . '"><td style="padding:9px 10px;border-bottom:1px solid ' . $line . ';' . $neg . '">' . $desc . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid ' . $line . ';text-align:center">' . $qty . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid ' . $line . ';text-align:right;white-space:nowrap;' . $neg . '">' . $money($it->unit_price) . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid ' . $line . ';text-align:right;white-space:nowrap;' . $neg . '">' . $money($it->line_total) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" style="padding:12px 10px;color:#94a3b8">No line items.</td></tr>';
        }

        // Totals.
        $tr = fn ($l, $v) => '<tr><td style="padding:5px 10px;color:' . $muted . '">' . $l . '</td><td style="padding:5px 10px;text-align:right;white-space:nowrap">' . $v . '</td></tr>';
        $taxes = (array) ($q->taxes ?: []);
        $tot = $tr('Subtotal', $money($q->subtotal));
        if ((float) $q->discount > 0) {
            $tot .= $tr('Discount', $money(-(float) $q->discount));
        }
        $taxable = max(0, round((float) $q->subtotal - (float) $q->discount, 2));
        foreach ($taxes as $t) {
            $rate = rtrim(rtrim(number_format((float) ($t['rate'] ?? 0), 3), '0'), '.');
            $tot .= $tr(e($t['label'] ?? 'Tax') . ' (' . $rate . '%)', $money(round($taxable * (float) ($t['rate'] ?? 0) / 100, 2)));
        }
        $tot .= '<tr><td style="padding:10px;background:' . $navy . ';color:#fff;font-weight:bold">Total ' . e($cur) . ($perWord ? ' <span style="font-weight:normal;font-size:10px">' . $perWord . '</span>' : '') . '</td>'
            . '<td style="padding:10px;background:' . $navy . ';color:#fff;font-weight:bold;text-align:right;font-size:14px;white-space:nowrap">' . $money($q->total) . '</td></tr>';

        // A one-time preset (setup, onboarding) inside a monthly/annual total is said out loud.
        $oneTime = 0;
        $units = \App\Models\SalesProduct::whereIn('id', $q->items->pluck('product_id')->filter()->all())->pluck('unit', 'id');
        foreach ($q->items as $it) {
            if ($it->product_id && ($units[$it->product_id] ?? null) === 'one-time') $oneTime += (float) $it->line_total;
        }
        $oneTimeNote = ($perWord && $oneTime > 0)
            ? '<div style="text-align:right;font-size:9.5px;color:' . $muted . ';margin-top:5px">Includes one-time fees of ' . $money($oneTime) . ' (before tax), charged once on the first invoice.</div>'
            : '';

        $section = fn ($title, $html) => '<div style="margin-top:20px;page-break-inside:avoid">'
            . '<div style="font-size:11px;font-weight:bold;color:' . $navy . ';border-bottom:2px solid ' . $teal . ';padding-bottom:4px;margin-bottom:7px">' . $title . '</div>'
            . '<div style="font-size:10.5px;line-height:1.55;color:#334155">' . $html . '</div></div>';

        $notes = trim((string) $q->notes) !== '' ? $section('Notes', nl2br(e($q->notes))) : '';
        $terms = trim((string) $q->terms) !== '' ? $section('Proposal terms', nl2br(e($q->terms))) : '';

        $conditions = trim((string) ($q->conditions ?? ''));
        $tc = '';
        if ($conditions !== '') {
            $tc = '<div style="page-break-before:always"></div>'
                . '<div style="font-size:18px;font-weight:bold;color:' . $navy . ';margin:0 0 2px">Terms &amp; Conditions</div>'
                . '<div style="font-size:10px;color:' . $muted . ';margin-bottom:12px">Proposal ' . e($q->number ?: '') . ($issuedTxt ? ' · issued ' . e($issuedTxt) : '') . '</div>'
                . '<div style="font-size:9.4px;line-height:1.42;color:#334155">' . self::conditionsHtml($conditions, $navy) . '</div>';
        }

        // Acceptance: signature lines for both sides.
        $sig = fn ($who) => '<td style="width:50%;padding:0 10px 0 0;vertical-align:top">'
            . $label($who)
            . '<div style="border-bottom:1px solid #94a3b8;height:26px"></div><div style="font-size:8.5px;color:' . $muted . ';margin:2px 0 6px">Signature</div>'
            . '<table style="width:100%;border-collapse:collapse"><tr>'
            . '<td style="width:64%;padding:0 8px 0 0"><div style="border-bottom:1px solid #94a3b8;height:18px"></div><div style="font-size:8.5px;color:' . $muted . ';margin-top:2px">Name and title</div></td>'
            . '<td style="width:36%;padding:0"><div style="border-bottom:1px solid #94a3b8;height:18px"></div><div style="font-size:8.5px;color:' . $muted . ';margin-top:2px">Date</div></td>'
            . '</tr></table></td>';
        $accept = '<div style="margin-top:16px;page-break-inside:avoid;border:1px solid ' . $line . ';border-radius:8px;padding:12px 16px">'
            . '<div style="font-size:12px;font-weight:bold;color:' . $navy . ';margin-bottom:4px">Acceptance</div>'
            . '<div style="font-size:10px;color:#334155;margin-bottom:10px">By signing, the customer accepts this proposal' . ($conditions !== '' ? ' and the Terms &amp; Conditions above' : '') . ($q->valid_until ? ', before ' . e($validTxt) : '') . '.</div>'
            . '<table class="r-stack" style="width:100%;border-collapse:collapse"><tr>' . $sig('For ' . e($lead->company ?: ($lead->name ?: 'the customer'))) . $sig('For KiddieTrac') . '</tr></table></div>';

        $css = '@page{margin:34px 40px 50px}'
            . 'body{font-family:"DejaVu Sans",sans-serif;color:#0D1B2A;font-size:11px;margin:0;' . ($forPdf ? '' : 'padding:24px;max-width:780px;margin:0 auto;') . '}'
            . '.foot{position:fixed;bottom:-30px;left:0;right:0;font-size:7.5px;color:#94a3b8}'
            // In-app View only (never the PDF): on a phone the page stacks instead of shrinking.
            . ($forPdf ? '' : '@media (max-width:640px){body{padding:14px !important}'
                . '.r-stack,.r-stack>tbody,.r-stack>tbody>tr,.r-stack>tbody>tr>td{display:block !important;width:auto !important}'
                . '.r-head td{text-align:left !important}.r-head table{margin-left:0 !important}.r-head td:last-child{padding-top:10px}'
                . '.r-cards>tbody>tr>td{margin-bottom:10px}.r-gap{display:none !important}'
                . '.r-sum>tbody>tr>td{border-right:0 !important;border-bottom:1px solid #E2E8F0;text-align:left !important}'
                . '.r-items th,.r-items td{padding:7px 5px !important;font-size:10.5px !important}'
                . '.r-tot{width:100% !important;margin-left:0 !important}}');

        return '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
            . ($forPdf ? '<div class="foot"><strong style="color:#64748b">Private &amp; confidential. Not for distribution.</strong> · KiddieTrac · kiddietrac.com · info@kiddietrac.com</div>' : '')
            // Header: logo left, PROPOSAL + number + dates right.
            . '<table class="r-stack r-head" style="width:100%;border-collapse:collapse"><tr>'
            . '<td style="vertical-align:middle">' . $logo . '</td>'
            . '<td style="vertical-align:middle;text-align:right">'
            . '<div style="font-size:24px;font-weight:bold;letter-spacing:3px;color:' . $navy . '">PROPOSAL</div>'
            . '<div style="font-size:11px;color:' . $teal . ';font-weight:bold;margin-top:2px">' . e($q->number ?: '') . '</div>'
            . '<table style="margin-left:auto;margin-top:6px;font-size:10px;border-collapse:collapse">'
            . ($issuedTxt ? '<tr><td style="color:' . $muted . ';padding:1px 8px 1px 0;text-align:right">Issued</td><td style="text-align:right">' . e($issuedTxt) . '</td></tr>' : '')
            . '<tr><td style="color:' . $muted . ';padding:1px 8px 1px 0;text-align:right">Valid until</td><td style="text-align:right">' . e($validTxt) . '</td></tr>'
            . '</table></td></tr></table>'
            // Brand bar: a gradient image, embedded (dompdf has no CSS gradients and remote images are off).
            . $this->brandBarHtml()
            . ($q->title ? '<div style="font-size:16px;font-weight:bold;color:' . $navy . ';margin-bottom:14px">' . e($q->title) . '</div>' : '')
            // Prepared for / by.
            . '<table class="r-stack r-cards" style="width:100%;border-collapse:collapse;font-size:10.5px;line-height:1.5;color:#334155"><tr>'
            . '<td style="width:50%;vertical-align:top;padding:12px 14px;background:#F4F7FA;border-radius:8px">' . $label('Prepared for') . $for . '</td>'
            . '<td class="r-gap" style="width:4%"></td>'
            . '<td style="width:46%;vertical-align:top;padding:12px 14px;background:#F4F7FA;border-radius:8px">' . $label('Prepared by') . $by . '</td>'
            . '</tr></table>'
            // Summary strip.
            . '<table class="r-stack r-sum" style="width:100%;border-collapse:collapse;margin-top:14px;border:1px solid ' . $line . '"><tr>'
            . '<td style="padding:10px 14px;border-right:1px solid ' . $line . '">' . $label('Billing') . '<div style="font-size:12px;font-weight:bold;color:' . $navy . '">' . $billing . '</div></td>'
            . '<td style="padding:10px 14px;border-right:1px solid ' . $line . '">' . $label('Currency') . '<div style="font-size:12px;font-weight:bold;color:' . $navy . '">' . e($cur) . ' <span style="font-weight:normal;color:' . $muted . ';font-size:10px">' . e(self::CURRENCIES[$cur] ?? '') . '</span></div></td>'
            . '<td style="padding:10px 14px;text-align:right">' . $label('Total' . ($perWord ? ' ' . $perWord : '')) . '<div style="font-size:16px;font-weight:bold;color:#047857">' . $money($q->total) . '</div></td>'
            . '</tr></table>'
            // Items.
            . '<table class="r-items" style="width:100%;border-collapse:collapse;margin-top:18px;font-size:11px">'
            . '<thead><tr style="background:' . $teal . ';color:#fff"><th style="padding:8px 10px;text-align:left">Description</th><th style="padding:8px 10px;text-align:center;width:44px">Qty</th><th style="padding:8px 10px;text-align:right;width:90px">Unit price</th><th style="padding:8px 10px;text-align:right;width:100px">Amount</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<table class="r-tot" style="width:46%;margin-left:54%;border-collapse:collapse;margin-top:10px;font-size:11px">' . $tot . '</table>'
            . $oneTimeNote
            . $notes . $terms
            . $tc
            . $accept
            . ($forPdf ? '' : '<div style="margin-top:18px;font-size:9px;color:#94a3b8"><strong style="color:#64748b">Private &amp; confidential. Not for distribution.</strong> · KiddieTrac · kiddietrac.com · info@kiddietrac.com</div>')
            . '</body></html>';
    }

    /** Terms & Conditions text → HTML: "## " lines are headings, "- " lines bullets, blank lines split paragraphs. */
    private static function conditionsHtml(string $text, string $navy): string
    {
        $out = '';
        $list = false;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $ln) {
            $t = trim($ln);
            if (str_starts_with($t, '- ') || str_starts_with($t, '• ')) {
                if (! $list) { $out .= '<ul style="margin:1px 0 3px 0;padding-left:16px">'; $list = true; }
                $out .= '<li style="margin:0 0 1px">' . e(trim(mb_substr($t, 2))) . '</li>';
                continue;
            }
            if ($list) { $out .= '</ul>'; $list = false; }
            if ($t === '') continue;
            if (str_starts_with($t, '#')) {
                $out .= '<div style="font-weight:bold;color:' . $navy . ';font-size:10.2px;margin:8px 0 2px">' . e(ltrim($t, "# \t")) . '</div>';
            } else {
                $out .= '<p style="margin:0 0 3px">' . e($t) . '</p>';
            }
        }
        if ($list) $out .= '</ul>';
        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private function validateLead(Request $r, bool $creating): array
    {
        $rules = [
            'name'           => ($creating ? 'required' : 'sometimes') . '|string|max:160',
            'company'        => 'nullable|string|max:200',
            'email'          => 'nullable|email|max:190',
            'phone'          => 'nullable|string|max:60',
            'title'          => 'nullable|string|max:120',
            'source'         => 'nullable|string|max:40',
            'website'          => 'nullable|string|max:190',
            'address'          => 'nullable|string|max:200',
            'city'             => 'nullable|string|max:120',
            'province'         => 'nullable|string|max:120',
            'postal_code'      => 'nullable|string|max:30',
            'country'          => 'nullable|string|max:120',
            'current_solution' => 'nullable|string|max:200',
            'owner_name'       => 'nullable|string|max:160',
            'owner_title'      => 'nullable|string|max:120',
            'owner_email'      => 'nullable|email|max:190',
            'owner_phone'      => 'nullable|string|max:60',
            'num_children'     => 'nullable|integer|min:0|max:100000',
            'num_locations'    => 'nullable|integer|min:0|max:10000',
            'stage'          => 'nullable|in:' . implode(',', array_keys(self::STAGES)),
            'owner_id'       => 'nullable|integer',
            'value'          => 'nullable|numeric|min:0',
            'expected_close' => 'nullable|date',
            'follow_up_date' => 'nullable|date',
            'notes'          => 'nullable|string|max:10000',
            'lost_reason'    => 'nullable|string|max:255',
        ];

        return $r->validate($rules);
    }

    private function validateQuote(Request $r): array
    {
        return $r->validate([
            'title'               => 'nullable|string|max:200',
            'billing_period'      => 'nullable|in:month,year',
            'currency'            => 'nullable|in:' . implode(',', array_keys(self::CURRENCIES)),
            'valid_until'         => 'nullable|date',
            'discount'            => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:5000',
            'terms'               => 'nullable|string|max:10000',
            'conditions'          => 'nullable|string|max:60000',
            'prepared_for'        => 'nullable|array',
            'prepared_for.company' => 'nullable|string|max:150',
            'prepared_for.name'   => 'nullable|string|max:150',
            'prepared_for.title'  => 'nullable|string|max:150',
            'prepared_for.address' => 'nullable|string|max:300',
            'prepared_for.email'  => 'nullable|email|max:190',
            'prepared_for.phone'  => 'nullable|string|max:60',
            'prepared_by'         => 'nullable|array',
            'prepared_by.name'    => 'nullable|string|max:150',
            'prepared_by.title'   => 'nullable|string|max:150',
            'prepared_by.email'   => 'nullable|email|max:190',
            'prepared_by.phone'   => 'nullable|string|max:60',
            'taxes'               => 'nullable|array|max:5',
            'taxes.*.label'       => 'nullable|string|max:40',
            'taxes.*.rate'        => 'nullable|numeric|min:0|max:100',
            'items'               => 'nullable|array',
            'items.*.description' => 'required|string|max:255',
            'items.*.qty'         => 'nullable|numeric|min:0',
            'items.*.unit_price'  => 'nullable|numeric',
            'items.*.product_id'  => 'nullable|integer',
        ]);
    }

    private function syncItems(SalesQuote $q, array $items): void
    {
        SalesQuoteItem::where('quote_id', $q->id)->delete();
        $subtotal = 0;
        foreach (array_values($items) as $i => $it) {
            $qty = (float) ($it['qty'] ?? 1);
            $price = (float) ($it['unit_price'] ?? 0);
            $line = round($qty * $price, 2);
            $subtotal += $line;
            SalesQuoteItem::create([
                'quote_id'    => $q->id,
                'product_id'  => $it['product_id'] ?? null,
                'description' => $it['description'],
                'qty'         => $qty,
                'unit_price'  => $price,
                'line_total'  => $line,
                'sort'        => $i,
            ]);
        }
        $q->subtotal = round($subtotal, 2);
        $this->recomputeTotals($q);
    }

    /** Human labels for the lead fields we track in the change history. */
    private function leadFieldLabels(): array
    {
        return [
            'name' => 'Contact', 'title' => 'Job title', 'company' => 'Company',
            'website' => 'Website', 'email' => 'Email', 'phone' => 'Phone',
            'address' => 'Street address', 'city' => 'City', 'province' => 'Province/State',
            'postal_code' => 'Postal/ZIP', 'country' => 'Country', 'current_solution' => 'Using today',
            'owner_name' => 'Owner name', 'owner_title' => 'Owner title', 'owner_email' => 'Owner email',
            'owner_phone' => 'Owner phone', 'num_children' => '# children', 'num_locations' => '# locations',
            'source' => 'Lead source', 'owner_id' => 'Deal owner', 'value' => 'Est. value',
            'expected_close' => 'Expected close', 'follow_up_date' => 'Next follow-up', 'notes' => 'Notes',
        ];
    }

    /** Build a "Field: old → new" list (one per line) for a lead update, or null if nothing changed. */
    private function diffLeadChanges(SalesLead $l, array $data): ?string
    {
        $lines = [];
        foreach ($this->leadFieldLabels() as $key => $label) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $old = $l->getOriginal($key);
            $new = $data[$key];
            if ($this->normValue($key, $old) === $this->normValue($key, $new)) {
                continue;
            }
            $lines[] = $label . ': ' . $this->displayValue($key, $old) . ' → ' . $this->displayValue($key, $new);
        }

        return $lines ? implode("\n", $lines) : null;
    }

    /** Canonical comparable form so "7200.00" == "7200" and dates ignore time. */
    private function normValue(string $key, $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if ($key === 'value') {
            return number_format((float) $v, 2, '.', '');
        }
        if (in_array($key, ['num_children', 'num_locations', 'owner_id'], true)) {
            return (string) (int) $v;
        }
        if (in_array($key, ['expected_close', 'follow_up_date'], true)) {
            return substr((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v), 0, 10);
        }

        return trim((string) $v);
    }

    /** Pretty value for display in the history feed. */
    private function displayValue(string $key, $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($key === 'value') {
            return '$' . number_format((float) $v, 2);
        }
        if ($key === 'owner_id') {
            $u = User::find((int) $v);

            return $u ? (trim($u->first_name . ' ' . $u->last_name) ?: ('User #' . $v)) : ('User #' . $v);
        }
        if (in_array($key, ['expected_close', 'follow_up_date'], true)) {
            return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr((string) $v, 0, 10);
        }

        return (string) $v;
    }

    private function logActivity(SalesLead $lead, string $type, ?string $body, ?string $due): SalesActivity
    {
        $lead->last_activity_at = now();
        $lead->saveQuietly();

        $act = SalesActivity::create([
            'lead_id'  => $lead->id,
            'user_id'  => auth()->id(),
            'type'     => $type,
            'body'     => $body,
            'due_date' => $due,
            'done'     => $type === 'followup' ? false : true,
        ]);

        /* THE TEAM HEARS ABOUT IT (2026-10-09: "when a new activity is generated send email
           out to the superadmin/sales team"). Every entry except field-change records (each
           save of the lead's details would mail the team) and "Lead created" (the new-lead
           email already says so). */
        if (! $this->deferNotify && $type !== 'edit' && ! ($type === 'stage' && $body === 'Lead created')) {
            $this->notifyActivity($lead, $act);
        }

        return $act;
    }

    /**
     * Superadmins (To) and sales reps (Cc) — active roles on live, active accounts —
     * INCLUDING whoever logged it (Anthony, 2026-10-09: "Yes, email me too": while he is
     * the whole sales team the inbox is the record). Website enquiries, contact forms and
     * bookings send their own emails and do not come through here. Never breaks the save.
     */
    private function notifyActivity(SalesLead $lead, SalesActivity $act): void
    {
        try {
            $people = fn (string $role) => User::query()
                ->whereHas('roleAssignments', fn ($q) => $q->where('role', $role)->where('active', true))
                ->whereNull('deleted_at')->where('status', 'active')
                ->whereNotNull('email')->where('email', '!=', '')
                ->pluck('email')->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values()->all();
            $to = $people('platform_admin');
            $cc = array_values(array_diff($people('sales_rep'), $to));
            if (! $to) { $to = $cc; $cc = []; }
            if (! $to) return;

            $actor = auth()->user();
            $who = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'KiddieTrac';
            $label = ['note' => '📝 Note', 'call' => '📞 Call', 'email' => '📧 Email', 'meeting' => '🤝 Meeting',
                'followup' => '⏰ Follow-up', 'stage' => '↗️ Stage change'][$act->type] ?? ucfirst($act->type);
            $leadName = $lead->company ?: ($lead->name ?: ('Lead #' . $lead->id));
            $tz = 'America/Toronto';
            $when = \Illuminate\Support\Carbon::parse($act->created_at)->setTimezone($tz)->format('D j M Y, g:i A T');
            $due = $act->due_date ? \Illuminate\Support\Carbon::parse($act->due_date)->format('D j M Y') : null;

            $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;color:#0D1B2A;max-width:560px">'
                . '<div style="font-size:12px;color:#5a7080;margin:0 0 4px">' . e($when) . ' · ' . e($who) . '</div>'
                . '<h2 style="margin:0 0 4px;font-size:19px">' . e($label) . ' — ' . e($leadName) . '</h2>'
                . ($lead->company && $lead->name ? '<p style="color:#5a7080;margin:0 0 12px">' . e($lead->name) . '</p>' : '')
                . ($act->body ? '<div style="background:#F8FAFC;border-left:3px solid #7C3AED;border-radius:0 8px 8px 0;padding:10px 14px;font-size:14px;line-height:1.5;white-space:normal">'
                    . nl2br(e(mb_strlen($act->body) > 6000 ? mb_substr($act->body, 0, 6000) . "\n… (continues on the lead)" : $act->body)) . '</div>' : '')
                . ($due ? '<p style="margin:10px 0 0;font-weight:700;color:#C2410C">Due ' . e($due) . '</p>' : '')
                . '<p style="margin:18px 0 0"><a href="https://app.kiddietrac.com/dashboard.html#sales-lead?id=' . $lead->id . '&tab=activity'
                . '" style="background:#7C3AED;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700">Open lead →</a></p>'
                . '</div>';
            $subject = $label . ': ' . $leadName . ' — ' . $who;

            Mail::html($html, function ($m) use ($to, $cc, $subject) {
                \App\Support\MailScope::platform($m);   // sales, not tenant mail
                $m->to($to)->subject($subject);
                if ($cc) $m->cc($cc);
            });
        } catch (\Throwable $e) {
            // an email must never block logging the activity
        }
    }

    private function openFollowup(SalesLead $lead, string $due, string $body): void
    {
        $exists = SalesActivity::where('lead_id', $lead->id)->where('type', 'followup')
            ->where('done', false)->whereDate('due_date', $due)->exists();
        if (! $exists) {
            SalesActivity::create([
                'lead_id' => $lead->id, 'user_id' => auth()->id(), 'type' => 'followup',
                'body' => $body, 'due_date' => $due, 'done' => false,
            ]);
        }
    }

    private function leadRow(SalesLead $l): array
    {
        return [
            'id'               => $l->id,
            'name'             => $l->name,
            'company'          => $l->company,
            'email'            => $l->email,
            'phone'            => $l->phone,
            'stage'            => $l->stage,
            'status'           => $l->status,
            'source'           => $l->source,
            'owner_id'         => $l->owner_id,
            'owner_name'       => $l->owner ? trim($l->owner->first_name . ' ' . $l->owner->last_name) : null,
            'value'            => $l->value,
            'expected_close'   => optional($l->expected_close)->toDateString(),
            'follow_up_date'   => optional($l->follow_up_date)->toDateString(),
            'last_activity_at' => optional($l->last_activity_at)->toDateTimeString(),
            'created_at'       => optional($l->created_at)->toDateTimeString(),
        ];
    }

    private function leadDetail(SalesLead $l): array
    {
        return array_merge($this->leadRow($l), [
            'title'            => $l->title,
            'website'          => $l->website,
            'address'          => $l->address,
            'city'             => $l->city,
            'province'         => $l->province,
            'postal_code'      => $l->postal_code,
            'country'          => $l->country,
            'current_solution' => $l->current_solution,
            'owner_name'       => $l->owner_name,
            'owner_title'      => $l->owner_title,
            'owner_email'      => $l->owner_email,
            'owner_phone'      => $l->owner_phone,
            'num_children'     => $l->num_children,
            'num_locations'    => $l->num_locations,
            'notes'       => $l->notes,
            'lost_reason' => $l->lost_reason,
            'activities'  => (function () use ($l) {
                $files = \App\Http\Controllers\Api\SalesActivityFileController::forActivities($l->activities->pluck('id')->all());
                return $l->activities->map(fn ($a) => [
                'files' => $files[$a->id] ?? [],
                'id' => $a->id, 'type' => $a->type, 'body' => $a->body,
                'due_date' => optional($a->due_date)->toDateString(),
                'done' => (bool) $a->done, 'user_name' => $a->user ? trim($a->user->first_name . ' ' . $a->user->last_name) : null,
                'created_at' => optional($a->created_at)->toDateTimeString(),
                'edited_at' => $a->edited_at ? \Illuminate\Support\Carbon::parse($a->edited_at)->toDateTimeString() : null,
                'can_edit' => self::canEditActivity($a),
            ])->values();
            })(),
            'quotes' => $l->quotes->map(fn ($q) => [
                'id' => $q->id, 'number' => $q->number, 'title' => $q->title,
                'status' => $q->status, 'total' => $q->total, 'subtotal' => $q->subtotal,
                'discount' => $q->discount, 'billing_period' => $q->billing_period, 'currency' => $q->currency ?: 'CAD',
                'taxes' => $q->taxes ?: [], 'tax_total' => $q->tax_total, 'terms' => $q->terms, 'conditions' => $q->conditions,
                'prepared_for' => self::preparedFor($q, $l), 'prepared_by' => self::preparedBy($q),
                'valid_until' => optional($q->valid_until)->toDateString(),
                'notes' => $q->notes,
                'items' => $q->items->map(fn ($it) => [
                    'id' => $it->id, 'description' => $it->description, 'qty' => $it->qty,
                    'unit_price' => $it->unit_price, 'line_total' => $it->line_total, 'product_id' => $it->product_id,
                ])->values(),
            ])->values(),
        ]);
    }

    private function stageSummary(): array
    {
        $rows = SalesLead::selectRaw('stage, COUNT(*) c, COALESCE(SUM(value),0) v')->groupBy('stage')->get()->keyBy('stage');
        $out = [];
        foreach (self::STAGES as $key => $label) {
            $out[] = [
                'key' => $key, 'label' => $label,
                'count' => (int) optional($rows->get($key))->c,
                'value' => (float) optional($rows->get($key))->v,
            ];
        }

        return $out;
    }

    private function stats(): array
    {
        return [
            'total'          => SalesLead::count(),
            'open'           => SalesLead::where('status', 'open')->count(),
            'won'            => SalesLead::where('status', 'won')->count(),
            'lost'           => SalesLead::where('status', 'lost')->count(),
            'my_open'        => SalesLead::where('status', 'open')->where('owner_id', auth()->id())->count(),
            'pipeline_value' => (float) SalesLead::where('status', 'open')->sum('value'),
            'won_value'      => (float) SalesLead::where('status', 'won')->sum('value'),
            'win_rate'       => (function () {
                $won  = SalesLead::where('status', 'won')->count();
                $lost = SalesLead::where('status', 'lost')->count();
                $closed = $won + $lost;
                return $closed ? (int) round($won / $closed * 100) : 0;
            })(),
            'avg_won'        => (function () {
                $q = SalesLead::where('status', 'won')->whereNotNull('value')->where('value', '>', 0);
                $c = (clone $q)->count();
                return $c ? round((float) $q->sum('value') / $c, 2) : 0.0;
            })(),
            'followups_due'  => SalesActivity::where('type', 'followup')->where('done', false)
                                    ->whereDate('due_date', '<=', now()->toDateString())->count(),
        ];
    }

    private function owners(): array
    {
        return User::whereHas('roleAssignments', fn ($q) => $q->whereIn('role', ['sales_rep', 'platform_admin']))
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->unique('id')->values()->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)])->all();
    }

    private function superadminEmails(): array
    {
        return User::whereHas('roleAssignments', fn ($q) => $q->where('role', 'platform_admin'))
            ->whereNotNull('email')->pluck('email')->unique()->values()->all();
    }

    private function salesEmails(): array
    {
        return User::whereHas('roleAssignments', fn ($q) => $q->where('role', 'sales_rep'))
            ->whereNotNull('email')->pluck('email')->unique()->values()->all();
    }

    /** New lead → email superadmin (To) + sales reps (Cc). Never breaks lead creation. */
    private function notifyNewLead(SalesLead $lead): void
    {
        try {
            $to = $this->superadminEmails();
            $cc = array_values(array_diff($this->salesEmails(), $to));
            if (empty($to)) {
                $to = $cc;
                $cc = [];
            }
            if (empty($to)) {
                return;
            }

            $rows = '';
            $fields = [
                'Contact'    => $lead->name,
                'Company'    => $lead->company,
                'Email'      => $lead->email,
                'Phone'      => $lead->phone,
                'Source'     => $lead->source,
                'Stage'      => self::STAGES[$lead->stage] ?? $lead->stage,
                'Est. value' => $lead->value ? '$' . number_format((float) $lead->value, 2) : null,
                'Owner'      => $lead->owner ? trim($lead->owner->first_name . ' ' . $lead->owner->last_name) : null,
                'Notes'      => $lead->notes,
            ];
            foreach ($fields as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#5a7080;font-weight:600;vertical-align:top">' . e($k)
                    . '</td><td style="padding:4px 0">' . nl2br(e($v)) . '</td></tr>';
            }
            $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;color:#0D1B2A;max-width:560px">'
                . '<h2 style="margin:0 0 2px">🎯 New sales lead</h2>'
                . '<p style="color:#5a7080;margin:0 0 14px">A new lead was added to the KiddieTrac sales pipeline.</p>'
                . '<table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>'
                . '<p style="margin:18px 0 0"><a href="https://app.kiddietrac.com/dashboard.html#sales-lead?id=' . $lead->id
                . '" style="background:#7C3AED;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700">Open lead →</a></p>'
                . '</div>';

            Mail::html($html, function ($m) use ($to, $cc, $lead) {
                \App\Support\MailScope::platform($m);   // sales, not tenant mail
                $m->to($to)->subject('🎯 New sales lead: ' . ($lead->company ?: $lead->name));
                if (! empty($cc)) {
                    $m->cc($cc);
                }
                if ($lead->email) {
                    $m->replyTo($lead->email, $lead->name);
                }
            });
        } catch (\Throwable $e) {
            // email must never block lead creation
        }
    }
}
