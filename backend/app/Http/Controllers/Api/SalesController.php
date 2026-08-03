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

    public function destroy(int $lead)
    {
        SalesLead::findOrFail($lead)->delete();

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------- Activities / follow-ups

    public function addActivity(Request $r, int $lead)
    {
        $l = SalesLead::findOrFail($lead);
        $data = $r->validate([
            'type'     => 'required|in:note,call,email,meeting,followup',
            'body'     => 'nullable|string|max:5000',
            'due_date' => 'nullable|date',
        ]);

        $act = $this->logActivity($l, $data['type'], $data['body'] ?? null, $data['due_date'] ?? null);

        // A followup with a due date is an open task + updates the lead's quick date.
        if ($data['type'] === 'followup' && ! empty($data['due_date'])) {
            $l->follow_up_date = $data['due_date'];
            $l->save();
        }

        return response()->json($act->load('user:id,name'), 201);
    }

    public function updateActivity(Request $r, int $activity)
    {
        $a = SalesActivity::findOrFail($activity);
        $data = $r->validate([
            'body'     => 'nullable|string|max:5000',
            'due_date' => 'nullable|date',
            'done'     => 'nullable|boolean',
        ]);
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
        $a->save();

        // Clear the lead's quick follow-up date once its open follow-up is done.
        if ($a->type === 'followup' && $a->done && ($lead = SalesLead::find($a->lead_id))) {
            $stillOpen = SalesActivity::where('lead_id', $lead->id)->where('type', 'followup')->where('done', false)->exists();
            if (! $stillOpen) {
                $lead->follow_up_date = null;
                $lead->save();
            }
        }

        return response()->json($a->fresh('user'));
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
        ]);
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
        $rows = '';
        foreach ($q->items as $it) {
            $qty = rtrim(rtrim(number_format((float) $it->qty, 2), '0'), '.');
            $rows .= '<tr><td style="padding:7px 10px;border-bottom:1px solid #e6ebf1">' . e($it->description) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #e6ebf1;text-align:center">' . $qty . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #e6ebf1;text-align:right">$' . $num($it->unit_price) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #e6ebf1;text-align:right">$' . $num($it->line_total) . '</td></tr>';
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

        return SalesActivity::create([
            'lead_id'  => $lead->id,
            'user_id'  => auth()->id(),
            'type'     => $type,
            'body'     => $body,
            'due_date' => $due,
            'done'     => $type === 'followup' ? false : true,
        ]);
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
            'activities'  => $l->activities->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'body' => $a->body,
                'due_date' => optional($a->due_date)->toDateString(),
                'done' => (bool) $a->done, 'user_name' => optional($a->user)->name,
                'created_at' => optional($a->created_at)->toDateTimeString(),
            ])->values(),
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
