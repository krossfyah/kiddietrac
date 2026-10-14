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
            'billing_period' => $data['billing_period'] ?? null,
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
        $qLabels = ['title' => 'Title', 'billing_period' => 'Billing period', 'valid_until' => 'Valid until', 'discount' => 'Discount', 'notes' => 'Notes', 'status' => 'Status'];
        $qFmt = function ($k, $v) {
            if ($v === null || $v === '') { return '—'; }
            if ($k === 'discount') { return '$' . number_format((float) $v, 2); }
            if ($k === 'valid_until') { return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr((string) $v, 0, 10); }
            return (string) $v;
        };
        $qBefore = [];
        foreach ($qLabels as $k => $lb) { $qBefore[$k] = $qFmt($k, $q->getAttribute($k)); }

        $q->fill(array_intersect_key($data, array_flip(['title', 'billing_period', 'valid_until', 'discount', 'notes'])));
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
        $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($this->quoteHtml($q));
        $pdf->setPaper('letter');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . ($q->number ?: 'proposal') . '.pdf"',
        ]);
    }

    public function quoteSend(int $quote)
    {
        $q = SalesQuote::with('items', 'lead')->findOrFail($quote);
        $lead = $q->lead;
        if (! $lead || ! $lead->email) {
            return response()->json(['message' => 'This lead has no email address to send the proposal to.'], 422);
        }
        $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($this->quoteHtml($q));
        $pdf->setPaper('letter');
        $pdf->render();
        $bytes = $pdf->output();

        try {
            $body = '<div style="font-family:system-ui,Arial,sans-serif;color:#0D1B2A">'
                . '<p>Hi ' . e($lead->name ?: 'there') . ',</p>'
                . '<p>Thank you for your interest in KiddieTrac. Please find our proposal attached as a PDF.</p>'
                . '<p>Happy to walk you through it — just reply to this email.</p>'
                . '<p>Warm regards,<br>The KiddieTrac Team</p></div>';
            Mail::html($body, function ($m) use ($lead, $q, $bytes) {
                \App\Support\MailScope::platform($m);   // sales, not tenant mail
                $m->to($lead->email, $lead->name ?: null)->subject('Your KiddieTrac proposal ' . ($q->number ?: ''));
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

    private function quoteHtml(SalesQuote $q): string
    {
        $lead = $q->lead;
        $num = fn ($v) => number_format((float) $v, 2);
        $money = fn ($v) => ((float) $v < 0 ? '-$' : '$') . number_format(abs((float) $v), 2);
        $rows = '';
        foreach ($q->items as $it) {
            $qty = rtrim(rtrim(number_format((float) $it->qty, 2), '0'), '.');
            // A discount plan's line is negative: shown in green with a minus (2026-10-14).
            $neg = (float) $it->line_total < 0 ? 'color:#047857;' : '';
            $rows .= '<tr><td style="padding:7px 10px;border-bottom:1px solid #e6ebf1;' . $neg . '">' . e($it->description) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #e6ebf1;text-align:center">' . $qty . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #e6ebf1;text-align:right;' . $neg . '">' . $money($it->unit_price) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #e6ebf1;text-align:right;' . $neg . '">' . $money($it->line_total) . '</td></tr>';
        }
        $disc = (float) $q->discount > 0
            ? '<tr><td colspan="3" style="padding:6px 10px;text-align:right;color:#64748b">Discount</td><td style="padding:6px 10px;text-align:right">-$' . $num($q->discount) . '</td></tr>'
            : '';
        $valid = $q->valid_until ? '<p style="color:#64748b;font-size:12px;margin:2px 0 0">Valid until ' . e($q->valid_until->format('M j, Y')) . '</p>' : '';
        $notes = $q->notes ? '<div style="margin-top:16px;font-size:12px;color:#334155"><strong>Notes</strong><br>' . nl2br(e($q->notes)) . '</div>' : '';
        $forWhom = e($lead->company ?: ($lead->name ?? 'Prospect')) . ($lead->name && $lead->company ? ' (' . e($lead->name) . ')' : '');
        $period = $q->billing_period ? ' <span style="font-weight:400;font-size:11px;color:#64748b">/' . e($q->billing_period) . '</span>' : '';

        return '<html><body style="font-family:DejaVu Sans,sans-serif;color:#0D1B2A;padding:14px">'
            . '<div style="background:#5B2A86;color:#fff;padding:20px 24px;border-radius:10px">'
            . '<div style="font-size:24px;font-weight:800">KiddieTrac</div>'
            . '<div style="opacity:.9;font-size:12px">Smart Childcare Management Platform</div></div>'
            . '<h2 style="margin:18px 0 0">Proposal ' . e($q->number ?: '') . '</h2>' . $valid
            . '<table style="margin:12px 0 6px;font-size:13px"><tr><td style="color:#64748b;padding-right:14px">Prepared for</td><td><strong>' . $forWhom . '</strong></td></tr>'
            . ($lead->email ? '<tr><td style="color:#64748b">Email</td><td>' . e($lead->email) . '</td></tr>' : '') . '</table>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:13px">'
            . '<thead><tr style="background:#f4f6f9;text-align:left"><th style="padding:8px 10px">Item</th><th style="padding:8px 10px;text-align:center">Qty</th><th style="padding:8px 10px;text-align:right">Unit</th><th style="padding:8px 10px;text-align:right">Amount</th></tr></thead>'
            . '<tbody>' . ($rows ?: '<tr><td colspan="4" style="padding:10px;color:#94a3b8">No line items.</td></tr>') . '</tbody>'
            . '<tfoot>' . $disc . '<tr><td colspan="3" style="padding:9px 10px;text-align:right;font-weight:800">Total</td><td style="padding:9px 10px;text-align:right;font-weight:800;color:#047857">$' . $num($q->total) . $period . '</td></tr></tfoot>'
            . '</table>' . $notes
            . '<p style="margin-top:26px;color:#94a3b8;font-size:11px">KiddieTrac · Smart Childcare Management Platform · kiddietrac.com</p>'
            . '</body></html>';
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
            'billing_period'      => 'nullable|string|max:20',
            'valid_until'         => 'nullable|date',
            'discount'            => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:5000',
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
        $q->total = round($subtotal - (float) $q->discount, 2);
        $q->save();
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
                'discount' => $q->discount, 'billing_period' => $q->billing_period,
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
