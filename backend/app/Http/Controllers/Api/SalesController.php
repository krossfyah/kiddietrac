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

    /* ESTIMATED REVENUE FROM PROPOSALS + A WEIGHTED PIPELINE (2026-10-16). Anthony: "based on
       the proposal sent add this to our estimated revenue for that prospect and auto calculates
       based on pipeline". He chose:
         - the lead's estimate is the proposal's ANNUAL value, before tax: recurring lines less
           the proposal discount, x12 for a monthly proposal, plus one-time fees;
         - the proposal that counts is the latest one sent, accepted or signed (not a draft,
           not a declined one); syncLeadValue() runs whenever one is saved, sent, signed,
           countersigned, declined or deleted, and notes the change in the lead's history;
         - typing a value by hand still works (value_quote_id is cleared), until the next
           proposal goes out;
         - the forecast weights each open lead by its stage's chance of closing. These are the
           defaults; the numbers are editable in Proposal defaults (stored as stage_odds). */
    public const STAGE_ODDS = [
        'new' => 10, 'contacted' => 20, 'qualified' => 40, 'proposal' => 60,
        'negotiation' => 80, 'won' => 100, 'lost' => 0,
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
        if (array_key_exists('value', $data) && round((float) $data['value'], 2) !== round((float) $l->value, 2)) {
            $data['value_quote_id'] = null;   // typed by hand: an estimate until the next proposal is sent
            $data['value_currency'] = null;
        }

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
    /** GET /sales/activities/{activity}/email - where the email behind a lead-history entry is now, to open it whole. */
    public function activityEmail(int $activity)
    {
        $a = SalesActivity::findOrFail($activity);
        $where = \App\Support\LeadHistory::emailFor((int) $a->id);
        if (! $where) return response()->json(['message' => 'This entry did not come from an email.'], 404);
        if (! empty($where['missing'])) return response()->json(['message' => 'This email is no longer in the Sales mailbox. It may have been deleted.'], 404);
        return response()->json($where);
    }

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
        // Once the customer has signed, what they signed is fixed (its fingerprint is on record).
        if ($q->client_signed_at) {
            return response()->json(['message' => 'This proposal has been signed by the customer and can no longer be changed. Create a new proposal if something needs to change.'], 409);
        }
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
        $linesSig = fn () => json_encode(SalesQuoteItem::where('quote_id', $q->id)->orderBy('sort')->orderBy('id')->get(['description', 'qty', 'unit_price', 'product_id'])
            ->map(fn ($i) => [(string) $i->description, (float) $i->qty, (float) $i->unit_price, (int) $i->product_id])->all());
        $linesBefore = $linesSig();
        // The lines themselves, so the history can say WHICH line changed and how (2026-11-10).
        $lineRows = fn () => SalesQuoteItem::where('quote_id', $q->id)->orderBy('sort')->orderBy('id')->get(['description', 'qty', 'unit_price', 'product_id', 'discount_type', 'discount_value'])
            ->map(fn ($i) => ['d' => (string) $i->description, 'q' => (float) $i->qty, 'p' => (float) $i->unit_price, 'pid' => (int) $i->product_id,
                'dt' => $i->discount_type, 'dv' => $i->discount_value === null ? null : (float) $i->discount_value])->all();
        $rowsBefore = $lineRows();
        $issuedBefore = ! empty($q->revised_at) ? \Illuminate\Support\Carbon::parse($q->revised_at) : ($q->sent_at ?: $q->created_at);
        $validBefore = $q->valid_until ? $q->valid_until->copy() : null;
        $revisedBefore = ! empty($q->revised_at) ? \Illuminate\Support\Carbon::parse($q->revised_at) : null;
        $wentOutBefore = collect([$q->sent_at, $q->email_sent_at])->filter()->map(fn ($t) => \Illuminate\Support\Carbon::parse($t))->max();

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
        if (in_array($q->status, ['sent', 'accepted'], true)) {
            $this->proposalWentOut($q, $q->status === 'accepted' ? 'accepted' : 'marked sent');
        }
        if ($r->has('items')) {
            $this->syncItems($q, $data['items'] ?? []);
        } else {
            $this->recomputeTotals($q);   // discount or taxes may have changed
        }

        // What changed: the lead's change history (Salesforce-style) and the issued date both go by it.
        $qLines = [];
        foreach ($qLabels as $k => $lb) {
            $after = $qFmt($k, $q->getAttribute($k));
            if ($qBefore[$k] !== $after) {
                $qLines[] = $lb . ': ' . $qBefore[$k] . ' → ' . $after;
            }
        }
        if ($r->has('items') && $linesSig() !== $linesBefore) {
            $qLines = array_merge($qLines, self::lineChanges($rowsBefore, $lineRows(), $q->currency) ?: ['Line items updated']);
        }
        /* A CHANGED PROPOSAL GETS A NEW NUMBER (2026-11-07, Anthony: "when proposals changed or edited increment the
           proposal # or iteration"). KT-Q-000022 becomes KT-Q-000022-R2, then -R3. The number column itself changes,
           so the editor, list, PDF, emails, signing page, reports and lead history all show the version without each
           reader knowing about revisions. It moves once per version the customer has had: the first real change after
           the proposal went out (marked sent or emailed) starts the next revision; more edits before it is sent again
           stay in that revision. A draft that never went out keeps its number. */
        if ($qLines && $wentOutBefore && (! $revisedBefore || $wentOutBefore->gt($revisedBefore))) {
            $rev = max(1, (int) ($q->revision ?? 1)) + 1;
            $num = 'KT-Q-' . str_pad((string) $q->id, 6, '0', STR_PAD_LEFT) . '-R' . $rev;
            DB::table('sales_quotes')->where('id', $q->id)->update(['revision' => $rev, 'number' => $num]);
            $qLines[] = 'Proposal number: ' . $q->number . ' → ' . $num;
            $q->revision = $rev;
            $q->number = $num;
        }
        if ($qLines && ($lead = SalesLead::find($q->lead_id))) {
            $this->logActivity($lead, 'edit', "Proposal {$q->number} updated
" . implode("
", $qLines), null);
        }
        /* THE DATE MOVES WITH THE CONTENT (2026-11-07, Anthony: "update date if proposal changes"). A real change (not
           a save with nothing different, not a status change) makes today the issued date. A "valid until" left as it
           was moves forward by the same number of days it had from the old issued date, so a 90-day proposal is still
           valid for 90 days. */
        if ($qLines) {
            $upd = ['revised_at' => now()];
            if ($validBefore && $issuedBefore && $qBefore['valid_until'] === $qFmt('valid_until', $q->valid_until)) {
                $days = (int) $issuedBefore->copy()->startOfDay()->diffInDays($validBefore->copy()->startOfDay(), false);
                if ($days > 0 && ! $issuedBefore->isToday()) $upd['valid_until'] = now()->startOfDay()->addDays($days)->toDateString();
            }
            DB::table('sales_quotes')->where('id', $q->id)->update($upd);
        }
        $this->syncLeadValueForQuote($q);

        return response()->json($q->fresh('items'));
    }

    /* PROPOSAL HISTORY (2026-11-10, Anthony: "when editing and saving an existing proposal ... add the history on what
       changed and the current status of the edited proposal"). Everything about a proposal is already logged on its
       lead naming its number (edits, emails, opens, follow-ups, signing, value); every revision shares the base number
       KT-Q-000022, so this is that lead's log filtered to it, all revisions, newest first, plus where it stands now. */
    public function quoteHistory(int $quote)
    {
        $q = SalesQuote::findOrFail($quote);
        $base = 'KT-Q-' . str_pad((string) $q->id, 6, '0', STR_PAD_LEFT);
        $rows = SalesActivity::with('user:id,first_name,last_name')->where('lead_id', $q->lead_id)
            ->whereRaw('body REGEXP ?', [$base . '([^0-9]|$)'])->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get();
        $iso = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->toIso8601String() : null;
        $events = $rows->map(fn ($a) => ['at' => $iso($a->created_at), 'type' => $a->type, 'body' => (string) $a->body,
            'by' => $a->user ? trim($a->user->first_name . ' ' . $a->user->last_name) : null])->values()->all();
        $events[] = ['at' => $iso($q->created_at), 'type' => 'created', 'body' => 'Proposal ' . $base . ' created', 'by' => null];

        return response()->json(['history' => $events, 'state' => self::quoteState($q)]);
    }

    /** Where a proposal stands now: for its card and its History. */
    public static function quoteState(SalesQuote $q): array
    {
        $iso = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->toIso8601String() : null;
        return [
            'status' => $q->status, 'number' => $q->number, 'revision' => max(1, (int) ($q->revision ?? 1)),
            'revised_at' => $iso($q->revised_at ?? null), 'sent_at' => $iso($q->sent_at), 'email_sent_at' => $iso($q->email_sent_at ?? null),
            'email_open_count' => (int) ($q->email_open_count ?? 0), 'sign_viewed_at' => $iso($q->sign_viewed_at),
            'client_signed_at' => $iso($q->client_signed_at), 'client_signer_name' => $q->client_signer_name,
            'countersigned_at' => $iso($q->countersigned_at), 'countersigner_name' => $q->countersigner_name,
            'declined_at' => $iso($q->declined_at ?? null), 'decline_reason' => $q->decline_reason ?? null,
            'valid_until' => optional($q->valid_until)->toDateString(),
            'changed_since_sent' => self::changedSinceSent($q),
        ];
    }

    /** Changed after the customer last received it (and not signed or declined): they have the earlier version. */
    public static function changedSinceSent(SalesQuote $q): bool
    {
        if ($q->client_signed_at || $q->status === 'declined' || empty($q->revised_at)) return false;
        $out = collect([$q->sent_at, $q->email_sent_at ?? null])->filter()->map(fn ($t) => \Illuminate\Support\Carbon::parse($t))->max();
        return $out !== null && \Illuminate\Support\Carbon::parse($q->revised_at)->gt($out);
    }

    /** What changed in the lines, one sentence each: added, removed, quantity, price, a discount's rate, text, order. */
    private static function lineChanges(array $a, array $b, ?string $cur): array
    {
        $name = function ($l) {
            $t = trim((string) preg_replace('/\s+/', ' ', (string) strtok((string) $l['d'], "\n")));
            return '“' . mb_strimwidth($t !== '' ? $t : 'line', 0, 60, '…') . '”';
        };
        $rate = function ($t, $v) use ($cur) {
            if (! $t) return '—';
            return $t === 'percent' ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . '% off' : self::money((float) $v, $cur) . ' off';
        };
        $isDisc = fn ($l) => ! empty($l['dt']) || ((float) $l['p'] < 0 && $l['pid']);
        $out = []; $usedB = []; $pairs = [];
        foreach ($a as $i => $l) {
            $hit = null;
            foreach ($b as $j => $m) { if (! isset($usedB[$j]) && $l['pid'] && $m['pid'] === $l['pid']) { $hit = $j; break; } }
            if ($hit === null) foreach ($b as $j => $m) { if (! isset($usedB[$j]) && ! $l['pid'] && ! $m['pid'] && $m['d'] === $l['d']) { $hit = $j; break; } }
            if ($hit === null) { $out[] = 'Removed line: ' . $name($l); continue; }
            $usedB[$hit] = true; $pairs[] = [$i, $hit];
            $m = $b[$hit];
            // A discount's "(10% off)" follows its rate: that is the rate change below, not a text change.
            $bare = fn ($t) => trim((string) preg_replace('/\s*\([^()]*\boff\b[^()]*\)/u', '', (string) $t));
            if ($m['d'] !== $l['d'] && ! ($isDisc($m) && $bare($m['d']) === $bare($l['d']))) $out[] = 'Text of ' . $name($l) . ' changed to ' . $name($m);
            if (abs($m['q'] - $l['q']) > 0.0001 && ! $isDisc($m)) $out[] = 'Quantity on ' . $name($m) . ': ' . rtrim(rtrim(number_format($l['q'], 2, '.', ''), '0'), '.') . ' → ' . rtrim(rtrim(number_format($m['q'], 2, '.', ''), '0'), '.');
            if (abs($m['p'] - $l['p']) > 0.004 && ! $isDisc($m)) $out[] = 'Price on ' . $name($m) . ': ' . self::money($l['p'], $cur) . ' → ' . self::money($m['p'], $cur);
            if ($isDisc($m)) {
                // A line saved before rates were kept has none: compare with its catalogue discount instead.
                $bt = $l['dt']; $bv = $l['dv'];
                if (! $bt && $l['pid'] && ($pr = SalesProduct::find($l['pid'])) && ($pr->kind ?? '') === 'discount') { $bt = $pr->discount_type; $bv = (float) $pr->price; }
                if ($bt && $m['dt'] && ($bt !== $m['dt'] || abs((float) $bv - (float) $m['dv']) > 0.004)) {
                    $out[] = 'Discount rate on ' . $name($m) . ': ' . $rate($bt, $bv) . ' → ' . $rate($m['dt'], $m['dv']);
                }
            }
        }
        foreach ($b as $j => $m) {
            if (isset($usedB[$j])) continue;
            $out[] = 'Added line: ' . $name($m) . ($isDisc($m) ? ' (' . $rate($m['dt'], $m['dv']) . ')' : ' — ' . rtrim(rtrim(number_format($m['q'], 2, '.', ''), '0'), '.') . ' × ' . self::money($m['p'], $cur));
        }
        // Same lines, new order.
        $order = array_column($pairs, 1);
        $sorted = $order; sort($sorted);
        if (count($a) === count($b) && count($pairs) === count($a) && $order !== $sorted) $out[] = 'Line order changed';

        return count($out) > 15 ? array_merge(array_slice($out, 0, 14), [(count($out) - 14) . ' more line changes']) : $out;
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

    /* ---------------------------------------------------------------- Online signing (2026-10-16)
       The prospect signs at proposal.html?t=… (ProposalSignController); KiddieTrac countersigns
       here. Countersigning stores the fully signed PDF, emails it, files it on the lead and
       marks the lead Won. */

    public static function ensureSignToken(SalesQuote $q): string
    {
        if (! $q->sign_token) {
            $q->sign_token = \Illuminate\Support\Str::random(40);
            $q->save();
        }
        return $q->sign_token;
    }

    public static function signUrl(SalesQuote $q): string
    {
        return 'https://app.kiddietrac.com/proposal.html?t=' . self::ensureSignToken($q);
    }

    /** POST /sales/quotes/{id}/sign-link — the private signing link, to copy and share. */
    public function quoteSignLink(int $quote)
    {
        $q = SalesQuote::findOrFail($quote);
        if ($q->client_signed_at || $q->status === 'declined') {
            return response()->json(['message' => 'This proposal is already ' . ($q->client_signed_at ? 'signed' : 'declined') . '.'], 422);
        }
        return response()->json(['url' => self::signUrl($q)]);
    }

    /** POST /sales/quotes/{id}/countersign — KiddieTrac's signature completes the proposal. */
    public function quoteCountersign(Request $r, int $quote)
    {
        $q = SalesQuote::with('items', 'lead')->findOrFail($quote);
        if (! $q->client_signed_at) {
            return response()->json(['message' => 'The customer has not signed this proposal yet.'], 422);
        }
        if ($q->countersigned_at) {
            return response()->json(['message' => 'This proposal has already been countersigned.'], 422);
        }
        $d = $r->validate([
            'name'      => ['required', 'string', 'min:2', 'max:160'],
            'title'     => ['nullable', 'string', 'max:160'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:600000'],
            'agree'     => ['accepted'],
        ]);
        $png = base64_decode(substr($d['signature'], strlen('data:image/png;base64,')), true);
        if (! $png || strlen($png) < 200 || @getimagesizefromstring($png) === false) {
            return response()->json(['message' => 'Please draw your signature.', 'errors' => ['signature' => ['Please draw your signature.']]], 422);
        }

        $q->countersigned_at = now();
        $q->countersigner_id = auth()->id();
        $q->countersigner_name = trim($d['name']);
        $q->countersigner_title = trim((string) ($d['title'] ?? '')) ?: null;
        $q->countersignature = $d['signature'];
        $q->countersign_ip = substr((string) $r->ip(), 0, 45);
        $q->status = 'accepted';
        $q->save();

        // The fully signed copy is the record: stored once, served from then on.
        $bytes = $this->quotePdfBytes($q);
        $path = 'sales-proposals/' . $q->id . '-' . \Illuminate\Support\Str::random(12) . '.pdf';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);
        $q->signed_pdf_path = $path;
        $q->save();

        $lead = $q->lead;
        $mailed = 'not sent (no email on the lead)';
        if ($lead) {
            if ($lead->stage !== 'won') {
                $from = self::STAGES[$lead->stage] ?? $lead->stage;
                $this->logActivity($lead, 'stage', "Stage moved: {$from} → Won (proposal {$q->number} signed by both parties)", null);
                $lead->stage = 'won';
                $lead->status = 'won';
            }
            $lead->last_activity_at = now();
            $lead->save();
            $this->syncLeadValue($lead);
            if ($lead->email) {
                $mailed = $this->mailFullySigned($q, $lead, $bytes);
            }
            $act = $this->logActivity($lead, 'note', "✅ Proposal {$q->number} countersigned by {$q->countersigner_name} — signed by both parties. Signed copy: {$mailed}.", null);
            $this->fileSignedOnLead($q, $lead, $act, $bytes);
        }
        try {
            \App\Support\Audit::write(['user_id' => auth()->id(), 'agency_id' => null, 'action' => 'sales_quote.countersigned', 'entity_type' => 'sales_quote', 'entity_id' => (int) $q->id,
                'payload' => json_encode(['summary' => "Proposal {$q->number} countersigned by {$q->countersigner_name}; signed copy {$mailed}"]),
                'ip_address' => substr((string) $r->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'email' => $mailed]);
    }

    /** The fully signed copy to the customer, with the sales contact copied. Reports what the mail log says. */
    private function mailFullySigned(SalesQuote $q, SalesLead $lead, string $bytes): string
    {
        $pb = self::preparedBy($q);
        $first = trim((string) strtok((string) ($q->client_signer_name ?: $lead->name ?: ''), ' ')) ?: 'there';
        $html = \App\Services\EmailTemplate::wrap(null,
            '<p style="margin:0 0 14px;line-height:1.6">Hi ' . e($first) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6">Thank you for choosing KiddieTrac. Your proposal <strong>' . e($q->number) . '</strong> has now been signed by both parties, and the fully signed copy is attached for your records.</p>'
            . '<p style="margin:0 0 14px;line-height:1.6">' . e($pb['name'] ?: 'Our team') . ' will be in touch shortly about next steps and getting your team set up. We look forward to making a difference together.</p>'
            . '<p style="margin:0;font-size:12.5px;color:#64748B;line-height:1.6">Private and confidential: please do not share or distribute this proposal outside your organisation.</p>',
            ['force_brand' => 'kt', 'eyebrow' => 'SIGNED BY BOTH PARTIES', 'title' => 'Your KiddieTrac proposal', 'subtitle' => $q->number, 'preheader' => 'Your fully signed KiddieTrac proposal is attached.']);
        $before = (int) DB::table('email_logs')->max('id');
        try {
            Mail::html($html, function ($m) use ($q, $lead, $bytes, $pb) {
                \App\Support\MailScope::platform($m);
                $m->to($lead->email, $q->client_signer_name ?: ($lead->name ?: null))->subject('Signed: your KiddieTrac proposal ' . $q->number);
                if (! empty($pb['email']) && filter_var($pb['email'], FILTER_VALIDATE_EMAIL) && strcasecmp($pb['email'], $lead->email) !== 0) {
                    $m->cc($pb['email'], $pb['name'] ?: 'KiddieTrac Sales');
                    $m->replyTo($pb['email'], $pb['name'] ?: 'KiddieTrac Sales');
                }
                $m->attachData($bytes, ($q->number ?: 'proposal') . '-signed.pdf', ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $e) {
            return 'failed: ' . mb_substr($e->getMessage(), 0, 160);
        }
        $row = DB::table('email_logs')->where('id', '>', $before)->where('to_email', 'like', '%' . strtolower($lead->email) . '%')->orderByDesc('id')->first(['status']);
        return 'emailed to ' . $lead->email . ($row && $row->status !== 'sent' ? ' (' . $row->status . ')' : '');
    }

    /** The signed PDF beside the lead's other files, attached to the history entry (like the NDA). */
    private function fileSignedOnLead(SalesQuote $q, SalesLead $lead, ?SalesActivity $act, string $bytes): void
    {
        if (! $act) return;
        try {
            $rel = 'sales-activity/' . (int) $lead->id . '/' . \Illuminate\Support\Str::uuid() . '.pdf';
            \Illuminate\Support\Facades\Storage::disk('public')->put($rel, $bytes);
            DB::table('sales_activity_files')->insert(['activity_id' => $act->id, 'lead_id' => (int) $lead->id, 'kind' => 'document',
                'original_name' => ($q->number ?: 'proposal') . '-signed.pdf', 'file_url' => '/storage/' . $rel, 'mime' => 'application/pdf', 'size' => strlen($bytes),
                'uploaded_by_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('signed proposal filing failed', ['quote' => $q->id, 'error' => $e->getMessage()]);
        }
    }

    /** For ProposalSignController (public signing page). */
    public function proposalHtmlFor(SalesQuote $q, bool $forPdf = false): string { return $this->quoteHtml($q, $forPdf); }
    public function proposalPdfFor(SalesQuote $q): string { return $this->quotePdfBytes($q); }
    public function logOnProposalLead(SalesQuote $q, string $type, string $body): ?SalesActivity
    {
        $lead = SalesLead::find($q->lead_id);
        return $lead ? $this->logActivity($lead, $type, $body, null) : null;
    }
    public static function preparedForOf(SalesQuote $q): array { return self::preparedFor($q, $q->lead); }
    public static function preparedByOf(SalesQuote $q): array { return self::preparedBy($q); }

    public function quoteDestroy(int $quote)
    {
        $q = SalesQuote::findOrFail($quote);
        /* A proposal that set up a customer is that customer's record of what they bought (2026-11-11). */
        abort_if($q->converted_agency_id || DB::table('sales_customers')->where('quote_id', $q->id)->exists(), 422,
            'This proposal set up a customer, so it stays. Change the customer instead.');
        $q->delete();   // soft delete
        if ($lead = SalesLead::find($q->lead_id)) {
            $this->logActivity($lead, 'note', "Proposal {$q->number} deleted", null);
            $this->syncLeadValue($lead);
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

    public const FOR_KEYS = ['company', 'name', 'title', 'address', 'city', 'province', 'postal_code', 'email', 'phone'];
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
    /** "Wasaga Beach, Ontario  L9Z 0A1" from the address parts ('' when none are set). */
    public static function cityLine(array $pf): string
    {
        $cp = implode(', ', array_filter([trim((string) ($pf['city'] ?? '')), trim((string) ($pf['province'] ?? ''))], 'strlen'));
        $pc = trim((string) ($pf['postal_code'] ?? ''));
        return trim($cp . ($cp !== '' && $pc !== '' ? '  ' : '') . $pc);
    }

    private static function preparedFor(SalesQuote $q, ?SalesLead $lead): array
    {
        $lead = $lead ?: new SalesLead();
        // The contact's job title / phone, else the owner's when the owner IS the contact (same
        // name or email, or no separate owner named) - website leads file them there (2026-10-27).
        $same = fn ($a, $b) => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
        $ownerIsContact = ! ($lead->owner_name ?? null) || $same($lead->owner_name, $lead->name)
            || (($lead->owner_email ?? null) && $same($lead->owner_email, $lead->email));
        $title = (string) (($lead->title ?? '') ?: ($ownerIsContact ? ($lead->owner_title ?? '') : ''));
        $phone = (string) (($lead->phone ?? '') ?: ($ownerIsContact ? ($lead->owner_phone ?? '') : ''));
        if (is_array($q->prepared_for) && $q->prepared_for) {
            $pf = array_merge(array_fill_keys(self::FOR_KEYS, ''), $q->prepared_for);
            if (trim((string) $pf['title']) === '' && $title !== '') $pf['title'] = $title;   // blanks only
            if (trim((string) $pf['phone']) === '' && $phone !== '') $pf['phone'] = $phone;
            return $pf;
        }
        return [
            'company' => (string) ($lead->company ?? ''), 'name' => (string) ($lead->name ?? ''), 'title' => $title,
            'address' => (string) ($lead->address ?? ''), 'city' => (string) ($lead->city ?? ''),
            'province' => (string) ($lead->province ?? ''), 'postal_code' => (string) ($lead->postal_code ?? ''),
            'email' => (string) ($lead->email ?? ''), 'phone' => $phone,
        ];
    }

    /** Who prepared it: what was typed on it, else the rep's name with the SALES email and number. */
    private static function preparedBy(SalesQuote $q): array
    {
        if (is_array($q->prepared_by) && $q->prepared_by) {
            $pb = array_merge(array_fill_keys(self::BY_KEYS, ''), $q->prepared_by);
            $pb['name'] = self::staffFirstName((string) $pb['name'], $q->created_by ? (int) $q->created_by : null);
            return $pb;
        }
        return self::defaultPreparedBy($q->created_by ? \App\Models\User::find($q->created_by) : null);
    }

    /* FIRST NAMES ONLY (2026-10-23, Anthony: "with lead proposals sent out never add my last
       name or my staffs with sales last name"). Whatever is on a proposal, PDF, email or sign
       page as "prepared by": a staff member's full name becomes their first name. Proposals
       saved earlier kept the full name typed in, so this is applied when it is shown, not only
       when it is saved. A name that is not one of ours ("KiddieTrac Sales") is left alone. */
    public static function staffFirstName(string $name, ?int $authorId = null): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || ! str_contains($name, ' ')) return $name;
        $first = strtok($name, ' ');
        $u = DB::table('users')->whereRaw("LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))) = ?", [mb_strtolower($name)])->first(['first_name']);
        if ($u && trim((string) $u->first_name) !== '') return trim((string) $u->first_name);
        if ($authorId) {
            $a = DB::table('users')->where('id', $authorId)->first(['first_name', 'last_name']);
            if ($a && mb_strtolower(trim((string) $a->first_name)) === mb_strtolower($first)) return trim((string) $a->first_name);
        }
        return $name;
    }

    private static function defaultPreparedBy($user): array
    {
        $d = self::proposalDefaultValues();
        return [
            'name'  => $user ? (trim((string) $user->first_name) ?: 'KiddieTrac Sales') : 'KiddieTrac Sales',   // first name only (2026-10-23)
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
        return response()->json(['stage_odds' => self::stageOdds()] + self::proposalDefaultValues() + ['prepared_by' => self::defaultPreparedBy(auth()->user())]);
    }

    /** Chance of closing per stage, 0-100, the saved numbers over the defaults. */
    private static ?array $oddsMemo = null;   // read once per request: the lead list asks per row

    public static function stageOdds(): array
    {
        if (self::$oddsMemo !== null) {
            return self::$oddsMemo;
        }
        $saved = self::proposalDefaultValues()['stage_odds'] ?? [];
        $out = [];
        foreach (self::STAGE_ODDS as $k => $d) {
            $v = is_array($saved) && isset($saved[$k]) && is_numeric($saved[$k]) ? (int) round((float) $saved[$k]) : $d;
            $out[$k] = max(0, min(100, $v));
        }
        return self::$oddsMemo = $out;
    }

    /* RECURRING AND ONE-TIME, APART (2026-10-27, Anthony: "the one time plans that are added to
       the proposal shows up in the monthly and not as a one time cost on the proposal"). The
       stored total adds a $500 setup fee into "Total per month". Every view of a proposal now
       shows the subscription and the one-time fees as two totals, each with its own tax. Same
       rule as annualValueOf: one-time = a one-time catalogue item, or a discount that applies to
       one-time items; the proposal's own discount comes off the subscription. */
    public static function splitTotals(SalesQuote $q): array
    {
        $q->loadMissing('items');
        $ids = $q->items->pluck('product_id')->filter()->unique()->values()->all();
        $prods = $ids ? SalesProduct::whereIn('id', $ids)->get()->keyBy('id') : collect();
        $rec = 0.0; $once = 0.0; $onceIds = []; $discAll = 0.0;
        foreach ($q->items as $it) {
            $pr = $it->product_id ? $prods->get($it->product_id) : null;
            $isDisc = $pr && ($pr->kind ?? '') === 'discount';
            // A discount on the WHOLE proposal is shared out below (10% off a $500 setup is
            // $50 off the setup, not $50 more off every month).
            if ($isDisc && ! in_array($pr->applies_to ?? 'all', ['one-time', 'recurring'], true)) { $discAll += (float) $it->line_total; continue; }
            $isOnce = $pr && ($pr->unit === 'one-time' || ($isDisc && ($pr->applies_to ?? '') === 'one-time'));
            if ($isOnce) { $once += (float) $it->line_total; $onceIds[] = (int) $it->id; }
            else { $rec += (float) $it->line_total; }
        }
        if ($discAll != 0.0) {
            $base = max(0.0, $rec) + max(0.0, $once);
            $share = $base > 0 ? max(0.0, $rec) / $base : 1.0;
            $rec += round($discAll * $share, 2);
            $once += round($discAll - round($discAll * $share, 2), 2);
        }
        $recSub = round($rec, 2); $onceSub = round($once, 2);
        $disc = (float) $q->discount;
        $recNet = $rec - $disc; $onceNet = $once;
        if ($recNet < 0) { $onceNet += $recNet; $recNet = 0.0; }
        $recNet = round(max(0, $recNet), 2); $onceNet = round(max(0, $onceNet), 2);
        $rt = []; $ot = []; $rs = 0.0; $os = 0.0;
        foreach ((array) ($q->taxes ?: []) as $t) {
            $rate = (float) ($t['rate'] ?? 0);
            $a = round($recNet * $rate / 100, 2); $b = round($onceNet * $rate / 100, 2);
            $rt[] = ['label' => $t['label'] ?? 'Tax', 'rate' => $rate, 'amount' => $a]; $rs += $a;
            $ot[] = ['label' => $t['label'] ?? 'Tax', 'rate' => $rate, 'amount' => $b]; $os += $b;
        }
        return [
            // Only when the one-time lines come to something: a \/usr/bin/bash complimentary line is not a fee.
            'has_once' => count($onceIds) > 0 && abs($onceSub) >= 0.005 && in_array($q->billing_period, ['month', 'year'], true),
            'once_item_ids' => $onceIds,
            'recurring_subtotal' => $recSub, 'discount' => round($disc, 2), 'recurring_net' => $recNet,
            'recurring_taxes' => $rt, 'recurring_total' => round($recNet + $rs, 2),
            'one_time_subtotal' => $onceSub, 'one_time_net' => $onceNet,
            'one_time_taxes' => $ot, 'one_time_total' => round($onceNet + $os, 2),
        ];
    }

    /** A proposal's annual value before tax: recurring x12 (monthly) or x1, plus one-time fees. */
    public static function annualValueOf(SalesQuote $q): float
    {
        $s = self::splitTotals($q);
        $periods = $q->billing_period === 'month' ? 12 : 1;
        if (in_array($q->billing_period, ['month', 'year'], true)) {
            return round($s['recurring_net'] * $periods + $s['one_time_net'], 2);
        }
        $q->loadMissing('items');
        $ids = $q->items->pluck('product_id')->filter()->unique()->values()->all();
        $prods = $ids ? SalesProduct::whereIn('id', $ids)->get()->keyBy('id') : collect();
        $rec = 0.0;
        $once = 0.0;
        foreach ($q->items as $it) {
            $pr = $it->product_id ? $prods->get($it->product_id) : null;
            $isOnce = $pr && ($pr->unit === 'one-time' || (($pr->kind ?? '') === 'discount' && ($pr->applies_to ?? '') === 'one-time'));
            if ($isOnce) {
                $once += (float) $it->line_total;
            } else {
                $rec += (float) $it->line_total;
            }
        }
        $rec -= (float) $q->discount;          // the proposal's own discount comes off the subscription
        if ($rec < 0) {
            $once += $rec;
            $rec = 0.0;
        }
        $periods = $q->billing_period === 'month' ? 12 : 1;
        return round(max(0.0, $rec) * $periods + max(0.0, $once), 2);
    }

    /** Set a lead's estimate from its current proposal (latest sent / accepted / signed). */
    public function syncLeadValue(?SalesLead $lead): void
    {
        if (! $lead) {
            return;
        }
        $q = SalesQuote::with('items')->where('lead_id', $lead->id)->whereIn('status', ['sent', 'accepted', 'signed'])
            ->orderByRaw('COALESCE(sent_at, updated_at) DESC')->orderByDesc('id')->first();
        if (! $q) {
            // Its proposal was deleted or declined: the figure stays, as an estimate now held by hand.
            if ($lead->value_quote_id) {
                $lead->value_quote_id = null;
                $lead->save();
            }
            return;
        }
        $v = self::annualValueOf($q);
        $cur = $q->currency ?: 'CAD';
        if ((int) $lead->value_quote_id === (int) $q->id && $lead->value !== null
            && round((float) $lead->value, 2) === $v && $lead->value_currency === $cur) {
            return;
        }
        $old = $lead->value;
        $oldCur = $lead->value_currency ?: $cur;
        $lead->value = $v;
        $lead->value_quote_id = $q->id;
        $lead->value_currency = $cur;
        $lead->save();
        $this->logActivity($lead, 'edit', 'Est. annual revenue: ' . ($old === null ? '—' : self::money($old, $oldCur))
            . ' → ' . self::money($v, $cur) . " (from proposal {$q->number})", null);
    }

    /* A PROPOSAL THAT HAS REACHED THE CUSTOMER (2026-10-16, Anthony: "for the web binders lead
       since we sent proposal why is the lead still under contacted - full sweep"). Only
       "marked sent" in the editor moved the lead before. Emailing it, the customer opening
       the signing link, signing it, or marking it accepted left the lead at New / Contacted /
       Qualified, and a link copied and sent by hand left the proposal a "draft". Every one of
       those now comes through here: a draft becomes sent, an early-stage lead moves to
       Proposal Sent (noted in its history), and the estimate is refreshed. Never moves a lead
       backwards, and never past Proposal Sent (countersigning is what makes it Won). */
    public function proposalWentOut(SalesQuote $q, string $how): void
    {
        try {
            if ($q->status === 'draft') {
                $q->status = 'sent';
                $q->sent_at = $q->sent_at ?: now();
                $q->save();
            }
            $lead = SalesLead::find($q->lead_id);
            if ($lead && in_array($lead->stage, ['new', 'contacted', 'qualified'], true)) {
                $from = self::STAGES[$lead->stage] ?? $lead->stage;
                $lead->stage = 'proposal';
                if ($lead->status !== 'open') {
                    $lead->status = 'open';
                }
                $lead->last_activity_at = now();
                $lead->save();
                $this->logActivity($lead, 'stage', "Stage moved: {$from} → Proposal Sent (proposal {$q->number} {$how})", null);
            }
            $this->syncLeadValue($lead);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('sales: proposalWentOut failed', ['quote' => $q->id, 'error' => $e->getMessage()]);
        }
    }

    /** For the signing page (ProposalSignController) and the quote endpoints; never throws. */
    public function syncLeadValueForQuote(SalesQuote $q): void
    {
        try {
            $this->syncLeadValue(SalesLead::find($q->lead_id));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('sales: lead value sync failed', ['quote' => $q->id, 'error' => $e->getMessage()]);
        }
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
            'stage_odds'   => 'nullable|array',
            'stage_odds.*' => 'nullable|numeric|min:0|max:100',
        ]);
        $v = ['valid_days' => (int) $data['valid_days'], 'terms' => (string) ($data['terms'] ?? ''), 'conditions' => (string) ($data['conditions'] ?? ''), 'taxes' => $this->cleanTaxes($data['taxes'] ?? [])];
        foreach (['sales_title', 'sales_email', 'sales_phone'] as $k) {
            $v[$k] = $r->has($k) ? trim((string) ($data[$k] ?? '')) : (self::proposalDefaultValues()[$k] ?? '');
        }
        $odds = self::stageOdds();
        foreach ((array) ($data['stage_odds'] ?? []) as $k => $pct) {
            if (isset(self::STAGE_ODDS[$k]) && is_numeric($pct)) {
                $odds[$k] = max(0, min(100, (int) round((float) $pct)));
            }
        }
        $v['stage_odds'] = $odds;
        self::$oddsMemo = null;
        DB::table('platform_settings')->updateOrInsert(['key' => 'sales.proposal_defaults'], ['value' => json_encode($v), 'updated_at' => now(), 'created_at' => now()]);
        return response()->json(['stage_odds' => self::stageOdds()] + $v);
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
            // Licensing (2026-10-24): plans, add-ons, one-time services and discounts.
            'kind'        => 'nullable|in:plan,addon,service,discount',
            'plan_code'   => 'nullable|in:starter,growth,enterprise',
            'feature_keys' => 'nullable|array',
            'feature_keys.*' => 'string|max:40',
            // Centres the item includes (App\Support\CentreCap, 2026-11-26): the Reseller bundle's 5. Blank = no cap.
            'included_centres' => 'nullable|integer|min:0|max:100000',
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
            $data['kind'] = in_array($data['kind'] ?? 'plan', ['plan', 'addon', 'service'], true) ? ($data['kind'] ?? 'plan') : 'plan';
            if ($data['kind'] === 'service') $data['unit'] = 'one-time';
            $data['discount_type'] = null;
            $data['applies_to'] = null;
        }
        $data['plan_code'] = ($data['kind'] ?? '') === 'plan' ? ($data['plan_code'] ?? null) : null;
        $data['feature_keys'] = ($data['kind'] ?? '') === 'addon' && ! empty($data['feature_keys']) ? json_encode(array_values(array_unique($data['feature_keys']))) : null;
        if (! $r->has('included_centres')) unset($data['included_centres']);
        elseif (! in_array($data['kind'] ?? '', ['plan', 'addon'], true)) $data['included_centres'] = null;
        $p = $product ? SalesProduct::findOrFail($product) : new SalesProduct();
        /* A PART NUMBER FOR EVERY ITEM (2026-10-24): given once, when the item is created (or the
           first time an older item is saved), and never changed, so reports and past proposals
           and invoices keep pointing at the same thing. */
        if (empty($p->part_number)) $data['part_number'] = \App\Support\Licences::nextPart($data['kind'] ?? 'plan');
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
        if ($q->signed_pdf_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($q->signed_pdf_path)) {
            return response(\Illuminate\Support\Facades\Storage::disk('local')->get($q->signed_pdf_path), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . ($q->number ?: 'proposal') . '-signed.pdf"',
            ]);
        }

        return response($this->quotePdfBytes($q), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . ($q->number ?: 'proposal') . '.pdf"',
        ]);
    }

    /** The proposal PDF: letter size, with "Page n of m" in the footer of every page. */
    /** The proposal PDF, for App\Support\ProposalFollowUp. */
    public function pdfBytesOf(SalesQuote $q): string
    {
        return $this->quotePdfBytes($q);
    }

    /** PATCH /sales/quotes/{id}/followup {days, max} — change or stop (days 0) automatic follow-ups. */
    public function quoteFollowup(Request $r, int $quote)
    {
        $q = SalesQuote::findOrFail($quote);
        $d = $r->validate(['days' => 'required|integer|min:0|max:60', 'max' => 'nullable|integer|min:1|max:5']);
        if ($d['days'] > 0 && ($q->client_signed_at || $q->status === 'declined' || $q->status === 'draft')) {
            return response()->json(['message' => $q->status === 'draft' ? 'Email the proposal first; follow-ups start from there.' : 'This proposal is already ' . ($q->client_signed_at ? 'signed' : 'declined') . '.'], 422);
        }
        \App\Support\ProposalFollowUp::schedule($q, (int) $d['days'], (int) ($d['max'] ?? 2), (int) auth()->id());
        $lead = SalesLead::find($q->lead_id);
        if ($lead) {
            $this->logActivity($lead, 'edit', $d['days'] > 0 ? 'Automatic follow-up on proposal ' . ($q->number ?: '') . ': every ' . $d['days'] . ' days, up to ' . ($d['max'] ?? 2) . ' times'
                : 'Automatic follow-up on proposal ' . ($q->number ?: '') . ' stopped', null);
        }
        $q = $q->fresh();
        return response()->json(['ok' => true, 'followup_days' => $q->followup_days, 'followup_max' => $q->followup_max, 'followups_sent' => (int) $q->followups_sent,
            'followup_next_at' => optional($q->followup_next_at ? \Illuminate\Support\Carbon::parse($q->followup_next_at) : null)->toIso8601String()]);
    }

    private function quotePdfBytes(SalesQuote $q): string
    {
        $pdf = new \App\Support\KtDompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($this->quoteHtml($q, true));
        $pdf->setPaper('letter');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 26.5, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.45, 0.52, 0.6]);

        return $pdf->output();
    }

    public function quoteSend(Request $r, int $quote)
    {
        $q = SalesQuote::with('items', 'lead')->findOrFail($quote);
        $lead = $q->lead;
        if (! $lead || ! $lead->email) {
            return response()->json(['message' => 'This lead has no email address to send the proposal to.'], 422);
        }
        // Optional CC / BCC (2026-10-16): arrays or comma-separated strings, every address checked.
        $list = function ($v) {
            $parts = is_array($v) ? $v : preg_split('/[\s,;]+/', (string) $v);
            return array_values(array_unique(array_filter(array_map(fn ($x) => strtolower(trim((string) $x)), (array) $parts), 'strlen')));
        };
        $cc = $list($r->input('cc', []));
        $bcc = $list($r->input('bcc', []));
        $bad = array_values(array_filter(array_merge($cc, $bcc), fn ($x) => ! filter_var($x, FILTER_VALIDATE_EMAIL)));
        if ($bad) {
            return response()->json(['message' => 'Not an email address: ' . implode(', ', $bad)], 422);
        }
        if (count($cc) > 10 || count($bcc) > 10) {
            return response()->json(['message' => 'Up to 10 CC and 10 BCC addresses.'], 422);
        }
        /* A NOTE IN THE EMAIL, AND AUTOMATIC FOLLOW-UP (2026-10-23, Anthony: "allow me to add a
           note before emailing to the body of the email, add feature to follow up with proposal
           based on x timeframe"). App\Support\ProposalFollowUp. */
        $note = mb_substr(trim((string) $r->input('note', '')), 0, 2000);
        $fuDays = max(0, min(60, (int) $r->input('followup_days', 0)));
        $fuMax = max(1, min(5, (int) $r->input('followup_max', 2)));
        $toLc = strtolower(trim($lead->email));
        $cc = array_values(array_diff($cc, [$toLc]));
        $bcc = array_values(array_diff($bcc, [$toLc], $cc));
        if (! $q->client_signed_at && ! in_array($q->status, ['declined'], true)) {
            self::ensureSignToken($q);
        }
        $bytes = $this->quotePdfBytes($q);

        try {
            $body = $this->proposalEmailHtml($q, $lead, $note !== '' ? $note : null);
            Mail::html($body, function ($m) use ($lead, $q, $bytes, $cc, $bcc) {
                \App\Support\MailScope::platform($m);   // sales, not tenant mail
                $m->to($lead->email, $lead->name ?: null)->subject('Your KiddieTrac proposal ' . ($q->number ?: ''));
                if ($cc) $m->cc($cc);
                if ($bcc) $m->bcc($bcc);
                // The email says "just reply": send replies to the sales contact, not the no-reply sender.
                $pbm = self::preparedBy($q);
                if (! empty($pbm['email']) && filter_var($pbm['email'], FILTER_VALIDATE_EMAIL)) $m->replyTo($pbm['email'], $pbm['name'] ?: 'KiddieTrac Sales');
                $m->attachData($bytes, ($q->number ?: 'proposal') . '.pdf', ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $e) {
            \App\Support\ProposalTracking::sent((int) $q->id, false, $e->getMessage());
            return response()->json(['message' => 'Could not send the email: ' . $e->getMessage()], 500);
        }
        \App\Support\ProposalTracking::sent((int) $q->id, true);   // accepted for delivery (2026-10-23)

        if ($q->status === 'draft') {
            $q->status = 'sent';
            $q->sent_at = now();
            $q->save();
        }
        $this->proposalWentOut($q, 'emailed');
        $this->logActivity($lead, 'email', 'Proposal ' . ($q->number ?: '') . ' emailed to ' . $lead->email
            . ($cc ? ' · CC ' . implode(', ', $cc) : '') . ($bcc ? ' · BCC ' . implode(', ', $bcc) : '')
            . ($note !== '' ? "\nNote in the email: " . $note : '')
            . ($fuDays ? "\nAutomatic follow-up every " . $fuDays . ' day' . ($fuDays === 1 ? '' : 's') . ', up to ' . $fuMax . ' time' . ($fuMax === 1 ? '' : 's') . ', until it is signed.' : ''), null);
        // Remembered for "Resend proposal" (2026-10-23): the note, CC and BCC come back prefilled.
        DB::table('sales_quotes')->where('id', $q->id)->update(['email_note' => $note !== '' ? $note : null,
            'email_cc' => $cc ? json_encode(array_values($cc)) : null, 'email_bcc' => $bcc ? json_encode(array_values($bcc)) : null]);
        if ($fuDays) {
            \App\Support\ProposalFollowUp::schedule($q->fresh(), $fuDays, $fuMax, (int) auth()->id());
        }

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
    private function proposalEmailHtml(SalesQuote $q, SalesLead $lead, ?string $note = null): string
    {
        $navy = '#1E2A5E'; $teal = '#1F6080'; $muted = '#5B6B7C';
        $first = trim((string) strtok((string) ($lead->name ?: ''), ' ')) ?: 'there';
        $org = $lead->company ?: 'your centre';
        $pb = self::preparedBy($q);
        $rep = $pb['name'] ?: 'The KiddieTrac Team';
        $signUrl = ($q->sign_token && ! $q->client_signed_at && $q->status !== 'declined') ? self::signUrl($q) : null;
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
            . '<div style="display:none;max-height:0;overflow:hidden">Thank you for your interest in KiddieTrac, the Smart Childcare Management Platform. Your proposal is attached.</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F6"><tr><td align="center" style="padding:24px 10px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="kt-card" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#0D1B2A">'

            // Logo + brand bar. Light-on-navy wordmark on a band that is navy in both schemes
            // (Outlook dark mode darkened the card under the dark-ink logo and it went speckled).
            . '<tr><td bgcolor="#081C41" class="kt-band" style="background:#081C41;padding:22px 24px;text-align:center"><img src="https://api.kiddietrac.com/email/wordmark-tagline-navy.png" width="250" alt="KiddieTrac - Smart Childcare Management Platform" style="display:block;margin:0 auto;border:0;width:250px;max-width:78%;height:auto"></td></tr>'
            . '<tr><td bgcolor="#1FA2A6" style="font-size:0;line-height:0;background:#1FA2A6"><img src="https://api.kiddietrac.com/email/brand-gradient.png" width="600" height="6" alt="" style="display:block;border:0;width:100%;height:6px"></td></tr>'

            // Graphic 1: photo.
            . '<tr><td><img src="https://www.kiddietrac.com/images/life/colouring-800.jpg" width="600" alt="An educator and a child colouring a butterfly together" style="display:block;border:0;width:100%;max-width:600px;height:auto"></td></tr>'

            // Thank you + introduction.
            . '<tr><td class="kt-pad" style="padding:28px 40px 6px">'
            . '<div class="kt-h" style="font-size:24px;line-height:1.3;font-weight:bold;color:' . $navy . ';margin:0 0 14px">Thank you for considering KiddieTrac</div>'
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65">Hi ' . e($first) . ',</p>'
            // The sender's own words, when they added some (2026-10-23).
            . ($note ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;background:#F4F9FB;--kt-edge:#1FA2A6;border-radius:0 10px 10px 0"><tr><td style="padding:14px 18px;font-size:15px;line-height:1.65;color:#0D1B2A">'
                . nl2br(e($note)) . '<div style="margin-top:8px;font-size:13px;color:' . $muted . '">— ' . e($rep) . '</div></td></tr></table>' : '')
            . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65">Thank you for your interest in working with us. Every early-years team we meet shares the same purpose we do: making a real difference for children, and for the parents who trust you with them every day. We are genuinely glad to be talking with ' . e($org) . '.</p>'
            . '<p style="margin:0 0 6px;font-size:15px;line-height:1.65">KiddieTrac, the <strong style="color:' . $teal . '">Smart Childcare Management Platform</strong>, is built around that purpose. It brings the whole day together in one place, so your educators spend less time on paperwork and more time with the children, and families feel close to their child\'s day even when they are apart.</p>'
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
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="--kt-edge:#2C8AAC;background:#F4F9FB;border-radius:0 10px 10px 0"><tr><td style="padding:16px 18px;font-size:14.5px;line-height:1.6">'
            . '<div style="font-weight:bold;color:' . $navy . ';margin-bottom:4px">&#128206; Your proposal is attached</div>'
            . 'We have put together a proposal for ' . e($org) . ', attached to this email as a PDF together with our terms and conditions.'
            . '</td></tr></table>'
            . ($signUrl ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px auto 4px"><tr><td align="center" bgcolor="#1F6080" style="border-radius:10px;background:#1F6080">'
                . '<a href="' . e($signUrl) . '" style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">&#9997;&#65039; Review &amp; sign online</a></td></tr></table>'
                . '<div style="text-align:center;font-size:12px;color:' . $muted . ';margin:0 0 4px">Takes about a minute. Your link is private to you.</div>' : '')
            . '<p style="margin:18px 0 14px;font-size:15px;line-height:1.65">Take your time looking it over. If anything raises a question, or you would like us to walk your team through KiddieTrac, just reply to this email. ' . ($signUrl ? 'When you are ready to go ahead, review and sign it online with the button above. Once KiddieTrac countersigns, you will receive the fully signed copy by email.' : 'When you are ready to go ahead, sign the acceptance page and send it back to us.') . '</p>'
            . '<p style="margin:0 0 4px;font-size:15px;line-height:1.65">We look forward to making a difference together.</p>'
            . '<p style="margin:18px 0 26px;font-size:15px;line-height:1.6">Warm regards,<br><strong style="color:' . $navy . '">' . e($rep) . '</strong><br><span style="color:' . $muted . ';font-size:13.5px">' . $repLine . '</span></p>'
            . '</td></tr>'

            // Now on Google Play (2026-10-25).
            . '<tr><td class="kt-pad" style="padding:0 40px 20px">' . \App\Support\PlayBadge::card('font-family:Arial,Helvetica,sans-serif;', $navy, $muted) . '</td></tr>'

            // Confidentiality.
            . '<tr><td class="kt-pad" style="padding:0 40px 26px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF8E6;border:1px solid #F3DFA6;border-radius:10px"><tr><td style="padding:14px 16px;font-size:12.5px;line-height:1.55;color:#5C4A12">'
            . '<strong>&#128274; Private and confidential.</strong> This email and the attached proposal are intended only for the person and organisation they are addressed to. All KiddieTrac proposals are treated as private and confidential: please do not share, forward, copy or distribute them outside your organisation without our written permission. If you received this email by mistake, please let us know and delete it.'
            . '</td></tr></table></td></tr>'

            // Footer.
            . '<tr><td style="background:' . $navy . ';padding:18px 32px;text-align:center;font-size:12px;line-height:1.6;color:#C9D3E6">'
            . '<strong style="color:#ffffff">' . e(\App\Support\Company::legalName()) . '</strong> · Smart Childcare Management Platform<br>'
            . '<a href="https://www.kiddietrac.com" style="color:#9FE0F0;text-decoration:none">kiddietrac.com</a> · <a href="mailto:info@kiddietrac.com" style="color:#9FE0F0;text-decoration:none">info@kiddietrac.com</a>'
            . '</td></tr>'

            . '</table>' . \App\Support\ProposalTracking::pixel((int) $q->id, 'email') . '</td></tr></table></body></html>';
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
        return $u ? (trim((string) $u->first_name) ?: null) : null;   // first name only (2026-10-23)
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

        /* ISSUED = THE LAST CHANGE TO WHAT THE CUSTOMER READS (2026-11-07, Anthony: "update date if proposal changes").
           revised_at is set by quoteUpdate when the wording, prices, lines or terms change; before any change it is
           the day it went out (or was made). */
        $issued = ! empty($q->revised_at) ? \Illuminate\Support\Carbon::parse($q->revised_at) : ($q->sent_at ?: $q->created_at);
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
            . (($cityLine = self::cityLine($pf)) !== '' ? '<div>' . e($cityLine) . '</div>' : '')
            . ($pf['email'] !== '' ? '<div>' . e($pf['email']) . '</div>' : '')
            . ($pf['phone'] !== '' ? '<div>' . e($pf['phone']) . '</div>' : '');
        $by = '<div style="font-size:13px;font-weight:bold;color:' . $navy . '">' . e($pb['name'] ?: 'KiddieTrac Sales') . '</div>'
            . ($pb['title'] !== '' ? '<div>' . e($pb['title']) . '</div>' : '')
            . ($pb['email'] !== '' ? '<div>' . e($pb['email']) . '</div>' : '')
            . ($pb['phone'] !== '' ? '<div>' . e($pb['phone']) . '</div>' : '')
            . '<div>kiddietrac.com</div>';

        $label = fn ($t) => '<div style="font-size:8.5px;letter-spacing:1px;text-transform:uppercase;color:' . $muted . ';font-weight:bold;margin-bottom:5px">' . $t . '</div>';

        // Line items.
        $split = self::splitTotals($q);
        $perShort = ['month' => '/month', 'year' => '/year'][$q->billing_period] ?? '';
        $rows = '';
        $i = 0;
        foreach ($q->items as $it) {
            $isOnceRow = in_array((int) $it->id, $split['once_item_ids'], true);
            $unitTag = ($split['has_once'] && (float) $it->line_total >= 0)   // a discount line is shared: no tag
                ? '<br><span style="font-size:8.5px;font-weight:bold;letter-spacing:.3px;color:' . ($isOnceRow ? '#B45309' : $muted) . '">' . ($isOnceRow ? 'ONE-TIME' : strtoupper(ltrim($perShort, '/'))) . '</span>'
                : '';
            $qty = rtrim(rtrim(number_format((float) $it->qty, 2), '0'), '.');
            $neg = (float) $it->line_total < 0 ? 'color:#047857;' : '';
            $bg = $i++ % 2 ? 'background:#F8FAFC;' : '';
            $desc = nl2br(e($it->description), false);   // a new line typed in the editor stays a new line
            // "Plan — description" from a preset: the plan name in bold, the description under it.
            if (strpos($it->description, ' — ') !== false) {
                [$name, $rest] = explode(' — ', $it->description, 2);
                $desc = '<span style="font-weight:bold;color:' . $navy . '">' . nl2br(e($name), false) . '</span><br><span style="color:' . $muted . ';font-size:10.5px">' . nl2br(e($rest), false) . '</span>';
            }
            // Part number under the item (2026-10-24, licensing).
            if (! empty($it->part_number)) $desc .= '<br><span style="color:' . $muted . ';font-size:9.5px;letter-spacing:.3px">Part # ' . e($it->part_number) . '</span>';
            $rows .= '<tr style="' . $bg . '"><td style="padding:9px 10px;border-bottom:1px solid ' . $line . ';' . $neg . '">' . $desc . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid ' . $line . ';text-align:center">' . $qty . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid ' . $line . ';text-align:right;white-space:nowrap;' . $neg . '">' . $money($it->unit_price) . $unitTag . '</td>'
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
        if ($split['has_once']) {
            $periodName = $q->billing_period === 'year' ? 'Annual' : 'Monthly';
            $tot = $tr($periodName . ' subtotal', $money($split['recurring_subtotal']));
            if ((float) $q->discount > 0) $tot .= $tr('Discount', $money(-(float) $q->discount));
            foreach ($split['recurring_taxes'] as $t) {
                $tot .= $tr(e($t['label']) . ' (' . rtrim(rtrim(number_format((float) $t['rate'], 3), '0'), '.') . '%)', $money($t['amount']));
            }
            $tot .= '<tr><td style="padding:10px;background:' . $navy . ';color:#fff;font-weight:bold">Total ' . e($cur) . ' <span style="font-weight:normal;font-size:10px">' . $perWord . '</span></td>'
                . '<td style="padding:10px;background:' . $navy . ';color:#fff;font-weight:bold;text-align:right;font-size:14px;white-space:nowrap">' . $money($split['recurring_total']) . '</td></tr>'
                . '<tr><td colspan="2" style="height:10px"></td></tr>'
                . $tr('One-time fees', $money($split['one_time_net']));
            foreach ($split['one_time_taxes'] as $t) {
                $tot .= $tr(e($t['label']) . ' (' . rtrim(rtrim(number_format((float) $t['rate'], 3), '0'), '.') . '%)', $money($t['amount']));
            }
            $tot .= '<tr><td style="padding:10px;background:#B45309;color:#fff;font-weight:bold">One-time total ' . e($cur) . ' <span style="font-weight:normal;font-size:10px">due on acceptance</span></td>'
                . '<td style="padding:10px;background:#B45309;color:#fff;font-weight:bold;text-align:right;font-size:14px;white-space:nowrap">' . $money($split['one_time_total']) . '</td></tr>';
        }

        // A one-time preset (setup, onboarding) inside a monthly/annual total is said out loud.
        $oneTime = 0;
        $units = \App\Models\SalesProduct::whereIn('id', $q->items->pluck('product_id')->filter()->all())->pluck('unit', 'id');
        foreach ($q->items as $it) {
            if ($it->product_id && ($units[$it->product_id] ?? null) === 'one-time') $oneTime += (float) $it->line_total;
        }
        $oneTimeNote = ($perWord && $oneTime > 0 && ! $split['has_once'])
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

        // Acceptance: signature lines for both sides, filled in as each side signs online.
        $tzHq = 'America/Toronto';
        $sigDate = fn ($d) => $d ? $d->copy()->setTimezone($tzHq)->format('M j, Y') : '';
        // Print name, Title and Date each on their own line under the signature (2026-11-10: "print your name").
        $sigLine = fn (string $w, string $val, string $cap, string $pad) => '<td style="width:' . $w . ';padding:0 ' . $pad . ' 0 0"><div style="border-bottom:1px solid #94a3b8;height:16px;padding-bottom:3px;font-size:9.5px;line-height:15px;white-space:nowrap;overflow:hidden">'
            . e($val) . '</div><div style="font-size:8.5px;color:' . $muted . ';margin-top:2px">' . $cap . '</div></td>';
        $sig = fn ($who, ?string $img = null, string $name = '', string $title = '', string $date = '') => '<td style="width:50%;padding:0 10px 0 0;vertical-align:top">'
            . $label($who)
            . ($img ? '<div style="border-bottom:1px solid #94a3b8;height:36px"><img src="' . e($img) . '" alt="Signature" style="height:34px;max-width:210px"></div>'
                    : '<div style="border-bottom:1px solid #94a3b8;height:26px"></div>')
            . '<div style="font-size:8.5px;color:' . $muted . ';margin:2px 0 6px">Signature</div>'
            . '<table style="width:100%;border-collapse:collapse"><tr>'
            . $sigLine('42%', $name, 'Print name', '8px') . $sigLine('30%', $title, 'Title', '8px') . $sigLine('28%', $date, 'Date', '0')
            . '</tr></table></td>';
        $cName = trim((string) $q->client_signer_name . ($q->client_signer_title ? ', ' . $q->client_signer_title : ''));
        $kName = trim((string) $q->countersigner_name . ($q->countersigner_title ? ', ' . $q->countersigner_title : ''));
        $record = '';
        if ($q->client_signed_at) {
            $at = fn ($d) => $d->copy()->setTimezone($tzHq)->format('M j, Y g:i A T');
            $record = '<div style="margin-top:8px;font-size:7.6px;line-height:1.45;color:' . $muted . ';border-top:1px dashed #CBD5E1;padding-top:6px">'
                . '<strong style="color:#475569">Electronic signature record.</strong> '
                . 'Customer: ' . e($cName) . ($q->client_signer_org ? ' for ' . e($q->client_signer_org) : '') . ', signed online ' . e($at($q->client_signed_at))
                . ($q->client_sign_ip ? ' from IP ' . e($q->client_sign_ip) : '') . ' using the private link sent to ' . e((string) ($lead->email ?: 'the customer')) . '. '
                . ($q->countersigned_at ? 'KiddieTrac: ' . e($kName) . ', countersigned ' . e($at($q->countersigned_at)) . '. ' : 'Awaiting KiddieTrac countersignature. ')
                . ($q->document_hash ? 'Document fingerprint (SHA-256) at signing: ' . e($q->document_hash) . '.' : '')
                . '</div>';
        }
        $accept = '<div style="margin-top:16px;page-break-inside:avoid;border:1px solid ' . $line . ';border-radius:8px;padding:12px 16px">'
            . '<div style="font-size:12px;font-weight:bold;color:' . $navy . ';margin-bottom:4px">Acceptance</div>'
            . '<div style="font-size:10px;color:#334155;margin-bottom:10px">By signing, the customer accepts this proposal' . ($conditions !== '' ? ' and the Terms &amp; Conditions above' : '') . ($q->valid_until ? ', before ' . e($validTxt) : '') . '.</div>'
            . '<table class="r-stack" style="width:100%;border-collapse:collapse"><tr>'
            . $sig('For ' . e($q->client_signer_org ?: ($pf['company'] ?: ($pf['name'] ?: 'the customer'))), $q->client_signature, (string) $q->client_signer_name, (string) $q->client_signer_title, $sigDate($q->client_signed_at))
            . $sig('For KiddieTrac', $q->countersignature, (string) $q->countersigner_name, (string) $q->countersigner_title, $sigDate($q->countersigned_at))
            . '</tr></table>' . $record . '</div>';

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
            . ($forPdf ? '<div class="foot"><strong style="color:#64748b">Private &amp; confidential. Not for distribution.</strong> · ' . e(\App\Support\Company::legalName()) . ' · kiddietrac.com · info@kiddietrac.com</div>' : '')
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
            . '<td style="padding:10px 14px;text-align:right">' . $label('Total' . ($perWord ? ' ' . $perWord : '')) . '<div style="font-size:16px;font-weight:bold;color:#047857">' . $money($split['has_once'] ? $split['recurring_total'] : $q->total) . '</div>'
            . ($split['has_once'] ? '<div style="font-size:10.5px;font-weight:bold;color:#B45309;margin-top:2px">+ ' . $money($split['one_time_total']) . ' one-time</div>' : '') . '</td>'
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
            . ($forPdf ? '' : '<div style="margin-top:18px;font-size:9px;color:#94a3b8"><strong style="color:#64748b">Private &amp; confidential. Not for distribution.</strong> · ' . e(\App\Support\Company::legalName()) . ' · kiddietrac.com · info@kiddietrac.com</div>')
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
            'prepared_for.city' => 'nullable|string|max:120',
            'prepared_for.province' => 'nullable|string|max:120',
            'prepared_for.postal_code' => 'nullable|string|max:20',
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
            'items.*.description' => 'required|string|max:20000',   // was 255 (2026-11-07): long part descriptions
            'items.*.qty'         => 'nullable|numeric|min:0',
            'items.*.unit_price'  => 'nullable|numeric',
            'items.*.product_id'  => 'nullable|integer',
            // A discount line's own rate on this proposal (2026-11-10); the catalogue gives the starting rate.
            'items.*.discount_type'  => 'nullable|in:percent,amount',
            'items.*.discount_value' => 'nullable|numeric|min:0|max:1000000',
        ]);
    }

    private function syncItems(SalesQuote $q, array $items): void
    {
        // A percentage discount above 100% would take off more than the lines come to.
        foreach (array_values($items) as $i => $it) {
            if (($it['discount_type'] ?? null) === 'percent' && (float) ($it['discount_value'] ?? 0) > 100) {
                throw \Illuminate\Validation\ValidationException::withMessages(["items.$i.discount_value" => 'A percentage discount can be at most 100%.']);
            }
        }
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
                // The item's part number, kept on the line so the proposal never changes after the fact.
                'part_number' => ! empty($it['product_id']) ? SalesProduct::where('id', $it['product_id'])->value('part_number') : null,
                'description' => $it['description'],
                'qty'         => $qty,
                'unit_price'  => $price,
                'line_total'  => $line,
                'sort'        => $i,
                // Kept only on a discount line: the rate it was set to on this proposal (2026-11-10).
                'discount_type'  => ! empty($it['discount_type']) ? $it['discount_type'] : null,
                'discount_value' => ! empty($it['discount_type']) && isset($it['discount_value']) ? round((float) $it['discount_value'], 2) : null,
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

    /** For SalesConversionController (2026-10-23): an entry on the lead, with the team email. */
    public function logExternal(SalesLead $lead, string $type, string $body): void
    {
        $this->logActivity($lead, $type, $body, null);
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

            /* THE WHOLE LEAD, EVERY TIME (2026-10-23, Anthony: "when a lead stage or activity
               changes - the email should have everything related to the lead broken into readable
               sections to keep a trail of what the lead is about and what has changed"). What
               changed comes first (the message above); then who they are, the business, where it
               stands, the proposals, what is open, and the recent history. */
            $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone($tz)->format('D j M Y') : null;
            $money = fn ($v, $cur) => $v !== null ? self::money($v, $cur ?: 'CAD') . ($cur && $cur !== 'CAD' ? ' ' . $cur : '') : null;
            $owner = $lead->owner_id ? User::find($lead->owner_id) : null;
            $place = implode(', ', array_filter([$lead->address, $lead->city, $lead->province, $lead->postal_code, $lead->country]));
            $sections = [
                ['heading' => 'Contact', 'rows' => ['Name' => $lead->name, 'Title' => $lead->title, 'Email' => $lead->email, 'Phone' => $lead->phone]],
                ['heading' => 'Business', 'rows' => ['Company' => $lead->company, 'Website' => $lead->website, 'Address' => $place ?: null,
                    'Children' => $lead->num_children, 'Locations' => $lead->num_locations, 'Uses today' => $lead->current_solution, 'Came from' => $lead->source]],
                ['heading' => 'Owner / decision maker', 'rows' => ['Name' => $lead->owner_name, 'Title' => $lead->owner_title, 'Email' => $lead->owner_email, 'Phone' => $lead->owner_phone]],
                ['heading' => 'Where it stands', 'rows' => ['Stage' => self::STAGES[$lead->stage] ?? $lead->stage,
                    'Sales rep' => $owner ? trim($owner->first_name . ' ' . $owner->last_name) : null,
                    'Est. annual revenue' => $money($lead->value, $lead->value_currency),
                    'Expected close' => $d($lead->expected_close), 'Next follow-up' => $d($lead->follow_up_date),
                    'Lost because' => $lead->lost_reason, 'Lead since' => $d($lead->created_at)]],
            ];
            $qrows = [];
            foreach (\App\Models\SalesQuote::where('lead_id', $lead->id)->orderByDesc('id')->limit(8)->get() as $q) {
                $state = $q->countersigned_at ? 'Signed by both sides ' . $d($q->countersigned_at)
                    : ($q->client_signed_at ? 'Signed by ' . ($q->client_signer_name ?: 'the client') . ' ' . $d($q->client_signed_at) . ' · waiting for our countersignature'
                    : ($q->declined_at ? 'Declined ' . $d($q->declined_at) . ($q->decline_reason ? ' — ' . $q->decline_reason : '')
                    : ($q->status === 'draft' ? 'Draft' : 'Sent ' . $d($q->sent_at ?: $q->email_sent_at)
                        . ((int) $q->email_open_count ? ' · opened ' . (int) $q->email_open_count . '×' : ' · not opened yet')
                        . ($q->sign_viewed_at ? ' · viewed online' : ''))));
                $qrows[$q->number ?: ('#' . $q->id)] = trim(($q->title ? $q->title . "\n" : '') . $money($q->total, $q->currency)
                    . ($q->billing_period ? ' / ' . $q->billing_period : '') . "\n" . $state);
            }
            if ($qrows) $sections[] = ['heading' => 'Proposals', 'rows' => $qrows];
            $open = [];
            foreach (SalesActivity::where('lead_id', $lead->id)->where('type', 'followup')->where('done', false)->orderBy('due_date')->limit(6)->get() as $f) {
                $open[($d($f->due_date) ?: 'No date') . (isset($open[$d($f->due_date) ?: 'No date']) ? ' (' . $f->id . ')' : '')] = mb_strimwidth((string) $f->body, 0, 200, '…');
            }
            if ($open) $sections[] = ['heading' => 'Open follow-ups', 'rows' => $open];
            if ($lead->notes) $sections[] = ['heading' => 'Notes on the lead', 'rows' => ['Notes' => mb_strimwidth((string) $lead->notes, 0, 1500, '…')]];
            $history = SalesActivity::with('user')->where('lead_id', $lead->id)->where('id', '!=', $act->id)->where('type', '!=', 'edit')
                ->orderByDesc('created_at')->limit(10)->get()->map(function ($h) use ($tz) {
                    $lbl = ['note' => '📝 Note', 'call' => '📞 Call', 'email' => '📧 Email', 'meeting' => '🤝 Meeting', 'followup' => '⏰ Follow-up', 'stage' => '↗️ Stage', 'text' => '💬 Text'][$h->type] ?? ucfirst($h->type);
                    return ['who' => $lbl . ' · ' . ($h->user ? trim($h->user->first_name . ' ' . $h->user->last_name) : 'KiddieTrac'),
                        'at' => \Illuminate\Support\Carbon::parse($h->created_at)->setTimezone($tz)->format('D j M, g:i A'),
                        'text' => mb_strimwidth((string) $h->body, 0, 400, '…')];
                })->all();

            // KiddieTrac-branded (2026-10-16): App\Support\SalesMail, the proposal email's frame.
            [$icon, $word] = array_pad(explode(' ', $label, 2), 2, '');
            $html = \App\Support\SalesMail::render([
                'preheader' => $word . ' on ' . $leadName . ' by ' . $who,
                'eyebrow' => $word ?: $label, 'icon' => $icon,
                'title' => $leadName,
                'subtitle' => implode(' · ', array_filter([$lead->company && $lead->name ? $lead->name : null, self::STAGES[$lead->stage] ?? null])),
                'meta' => $when . ' · ' . $who,
                'message' => $act->body ? (mb_strlen($act->body) > 6000 ? mb_substr($act->body, 0, 6000) . "\n… (continues on the lead)" : $act->body) : null,
                'alert' => $due ? '⏰ Due ' . $due : null,
                'message_label' => 'What changed',
                'sections' => $sections,
                'html_heading' => $history ? 'Recent history' : null,
                'html' => $history ? \App\Support\SalesMail::bubbles($history) : null,
                'button' => ['label' => 'Open lead', 'url' => 'https://app.kiddietrac.com/dashboard.html#sales-lead?id=' . $lead->id . '&tab=activity'],
            ]);
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
            'value_currency'   => $l->value_currency,
            'value_quote_id'   => $l->value_quote_id,
            'odds'             => self::stageOdds()[$l->stage] ?? 0,
            'weighted_value'   => $l->value === null ? null : round((float) $l->value * (self::stageOdds()[$l->stage] ?? 0) / 100, 2),
            'expected_close'   => optional($l->expected_close)->toDateString(),
            'follow_up_date'   => optional($l->follow_up_date)->toDateString(),
            'last_activity_at' => optional($l->last_activity_at)->toDateTimeString(),
            'created_at'       => optional($l->created_at)->toDateTimeString(),
        ];
    }

    private function leadDetail(SalesLead $l): array
    {
        $cust = DB::table('sales_customers')->where('lead_id', $l->id)->first(['id', 'customer_number', 'status', 'quote_id']);
        return array_merge($this->leadRow($l), [
            // Sales -> Customers (2026-11-10).
            'customer'         => $cust ? ['id' => (int) $cust->id, 'number' => $cust->customer_number, 'status' => $cust->status, 'no_services' => ! $cust->quote_id] : null,
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
            'value_quote_number' => $l->value_quote_id ? optional($l->quotes->firstWhere('id', $l->value_quote_id))->number : null,
            'activities'  => (function () use ($l) {
                $files = \App\Http\Controllers\Api\SalesActivityFileController::forActivities($l->activities->pluck('id')->all());
                // Entries that came from an email the Sales mailbox still holds: "View full email" (2026-11-24).
                $mail = DB::table('sales_lead_filed')->whereIn('activity_id', $l->activities->pluck('id')->all() ?: [0])->whereNotNull('message_id')->pluck('activity_id')->flip();
                return $l->activities->map(fn ($a) => [
                'files' => $files[$a->id] ?? [],
                'full_email' => isset($mail[$a->id]),
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
                'status' => $q->status, 'total' => $q->total, 'subtotal' => $q->subtotal, 'split' => self::splitTotals($q),
                'discount' => $q->discount, 'billing_period' => $q->billing_period, 'currency' => $q->currency ?: 'CAD',
                'taxes' => $q->taxes ?: [], 'tax_total' => $q->tax_total, 'terms' => $q->terms, 'conditions' => $q->conditions,
                'prepared_for' => self::preparedFor($q, $l), 'prepared_by' => self::preparedBy($q),
                'sign_url' => ($q->sign_token && ! $q->client_signed_at && $q->status !== 'declined') ? 'https://app.kiddietrac.com/proposal.html?t=' . $q->sign_token : null,
                'sign_viewed_at' => optional($q->sign_viewed_at)->toIso8601String(),
                'client_signed_at' => optional($q->client_signed_at)->toIso8601String(), 'client_signer_name' => $q->client_signer_name,
                'client_signer_title' => $q->client_signer_title, 'client_signer_org' => $q->client_signer_org,
                'countersigned_at' => optional($q->countersigned_at)->toIso8601String(), 'countersigner_name' => $q->countersigner_name,
                'declined_at' => optional($q->declined_at)->toIso8601String(), 'decline_reason' => $q->decline_reason,
                'valid_until' => optional($q->valid_until)->toDateString(),
                'notes' => $q->notes,
                'followup_days' => $q->followup_days, 'followup_max' => $q->followup_max, 'followups_sent' => (int) $q->followups_sent,
                // Received / opened tracking (2026-10-23, App\Support\ProposalTracking).
                'email_sent_at' => $q->email_sent_at ? \Illuminate\Support\Carbon::parse($q->email_sent_at)->toIso8601String() : null,
                'email_send_status' => $q->email_send_status, 'email_send_error' => $q->email_send_error, 'email_open_count' => (int) $q->email_open_count,
                'email_opened_at' => $q->email_opened_at ? \Illuminate\Support\Carbon::parse($q->email_opened_at)->toIso8601String() : null,
                'email_last_opened_at' => $q->email_last_opened_at ? \Illuminate\Support\Carbon::parse($q->email_last_opened_at)->toIso8601String() : null,
                'pdf_downloaded_at' => $q->pdf_downloaded_at ? \Illuminate\Support\Carbon::parse($q->pdf_downloaded_at)->toIso8601String() : null,
                // Last change and whether the customer has that version (2026-11-10, proposal history).
                'revised_at' => ! empty($q->revised_at) ? \Illuminate\Support\Carbon::parse($q->revised_at)->toIso8601String() : null,
                'sent_at' => $q->sent_at ? \Illuminate\Support\Carbon::parse($q->sent_at)->toIso8601String() : null,
                'revision' => max(1, (int) ($q->revision ?? 1)), 'changed_since_sent' => self::changedSinceSent($q),
                'email_note' => $q->email_note, 'email_cc' => json_decode((string) $q->email_cc, true) ?: [], 'email_bcc' => json_decode((string) $q->email_bcc, true) ?: [],
                // Approval + conversion (2026-10-23, SalesConversionController).
                'approval_status' => $q->approval_status, 'reject_reason' => $q->reject_reason,
                'approved_at' => $q->approved_at ? \Illuminate\Support\Carbon::parse($q->approved_at)->toIso8601String() : null,
                'rejected_at' => $q->rejected_at ? \Illuminate\Support\Carbon::parse($q->rejected_at)->toIso8601String() : null,
                'contract_name' => $q->contract_name,
                'contract_uploaded_at' => $q->contract_uploaded_at ? \Illuminate\Support\Carbon::parse($q->contract_uploaded_at)->toIso8601String() : null,
                'converted_agency_id' => $q->converted_agency_id ? (int) $q->converted_agency_id : null,
                'converted_agency_name' => $q->converted_agency_id ? DB::table('agencies')->where('id', $q->converted_agency_id)->value('name') : null,
                'invite_sent_at' => $q->invite_sent_at ? \Illuminate\Support\Carbon::parse($q->invite_sent_at)->toIso8601String() : null,
                'followup_next_at' => $q->followup_next_at ? \Illuminate\Support\Carbon::parse($q->followup_next_at)->toIso8601String() : null,
                'last_followup_at' => $q->last_followup_at ? \Illuminate\Support\Carbon::parse($q->last_followup_at)->toIso8601String() : null,
                'items' => $q->items->map(fn ($it) => [
                    'id' => $it->id, 'description' => $it->description, 'qty' => $it->qty,
                    'unit_price' => $it->unit_price, 'line_total' => $it->line_total, 'product_id' => $it->product_id,
                    'discount_type' => $it->discount_type, 'discount_value' => $it->discount_value,
                ])->values(),
            ])->values(),
        ]);
    }

    private function stageSummary(): array
    {
        $rows = SalesLead::selectRaw('stage, COUNT(*) c, COALESCE(SUM(value),0) v')->groupBy('stage')->get()->keyBy('stage');
        $odds = self::stageOdds();
        $out = [];
        foreach (self::STAGES as $key => $label) {
            $val = (float) optional($rows->get($key))->v;
            $out[] = [
                'key' => $key, 'label' => $label,
                'count' => (int) optional($rows->get($key))->c,
                'value' => $val,
                'odds' => $odds[$key] ?? 0,
                'weighted' => round($val * ($odds[$key] ?? 0) / 100, 2),
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
            // Open leads weighted by their stage's chance of closing.
            'weighted_pipeline' => (function () {
                $odds = self::stageOdds();
                $sum = 0.0;
                foreach (SalesLead::where('status', 'open')->whereNotNull('value')->selectRaw('stage, SUM(value) v')->groupBy('stage')->get() as $row) {
                    $sum += (float) $row->v * ($odds[$row->stage] ?? 0) / 100;
                }
                return round($sum, 2);
            })(),
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
                                    ->whereDate('due_date', '<=', \App\Support\AgencyTime::today())->count(),
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

            $html = \App\Support\SalesMail::render([
                'preheader' => 'New lead in the pipeline: ' . ($lead->company ?: $lead->name),
                'eyebrow' => 'New sales lead', 'icon' => '🎯',
                'title' => $lead->company ?: ($lead->name ?: 'New lead'),
                'subtitle' => implode(' · ', array_filter([$lead->company ? $lead->name : null, $lead->source ? 'Source: ' . $lead->source : null])),
                'intro' => 'A new lead was added to the KiddieTrac sales pipeline. Reply to this email to write to them directly.',
                'sections' => [
                    ['heading' => 'Contact', 'rows' => ['Contact' => $lead->name, 'Company' => $lead->company, 'Email' => $lead->email, 'Phone' => $lead->phone]],
                    ['heading' => 'Pipeline', 'rows' => [
                        'Stage' => self::STAGES[$lead->stage] ?? $lead->stage,
                        'Est. annual revenue' => $lead->value ? self::money($lead->value, $lead->value_currency ?: 'CAD') : null,
                        'Owner' => $lead->owner ? trim($lead->owner->first_name . ' ' . $lead->owner->last_name) : null,
                    ]],
                ],
                'message' => $lead->notes, 'message_label' => 'Notes',
                'button' => ['label' => 'Open lead', 'url' => 'https://app.kiddietrac.com/dashboard.html#sales-lead?id=' . $lead->id],
            ]);

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
