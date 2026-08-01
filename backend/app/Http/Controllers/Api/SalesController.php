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

        $l->fill($data);
        $l->last_activity_at = now();
        $l->save();

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
            'title'       => $l->title,
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
