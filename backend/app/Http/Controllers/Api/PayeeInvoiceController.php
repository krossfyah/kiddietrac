<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices raised against a PAYEE — an educator working as a contractor, a family, or a
 * supplier — from either recorded hours or a flat amount.
 *
 * Kept apart from `invoices` (parent fees, centre-scoped, driven by fee plans) on purpose.
 * These answer a different question: what do we owe this person, or what have we billed
 * them, outside the fee cycle. Forcing both through one table would mean every existing
 * fee query had to learn to exclude contractor payouts, and one missed WHERE would put a
 * payout into a family's balance.
 *
 * Money is only ever computed here, never assumed: an hours-based invoice records the
 * hours it used and the rate it applied, so the total can be checked against the
 * timesheet afterwards rather than taken on trust.
 */
final class PayeeInvoiceController extends Controller
{
    private const KINDS = ['educator', 'parent', 'contractor'];
    private const STATUSES = ['upcoming', 'issued', 'paid', 'void'];

    private function ensureTable(): void
    {
        if (Schema::hasTable('payee_invoices')) return;
        Schema::create('payee_invoices', function ($t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id')->nullable()->index();
            $t->string('kind', 16);                          // educator | parent | contractor
            $t->unsignedBigInteger('payee_user_id')->nullable()->index();
            $t->unsignedBigInteger('payee_family_id')->nullable()->index();
            $t->string('payee_name', 160);
            $t->string('reference', 40)->nullable();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            // How the figure was arrived at, so it can be audited rather than trusted.
            $t->string('basis', 16)->default('amount');      // amount | hours
            $t->decimal('hours', 8, 2)->nullable();
            $t->decimal('rate', 8, 2)->nullable();
            $t->decimal('amount', 10, 2)->default(0);
            $t->text('details')->nullable();
            $t->string('status', 16)->default('upcoming');
            // A schedule is opt-in: most of these are one-off.
            $t->boolean('recurring')->default(false);
            $t->string('frequency', 16)->nullable();         // weekly | biweekly | monthly
            $t->date('next_run_on')->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['agency_id', 'kind', 'status']);
        });
    }

    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where('role', 'platform_admin')->exists();
        if ($header && ($isPlatform || DB::table('role_assignments')->where('user_id', $uid)
                ->where('active', 1)->where('agency_id', $header)->exists())) {
            return $header;
        }
        return (int) DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])->value('agency_id');
    }

    private function assertBiller(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])->exists();
        abort_unless($ok, 403, 'Directors and admins only');
    }

    /** GET /provider/payee-invoices?kind=&status= */
    public function index(Request $request): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureTable();
        $agencyId = $this->agencyId($request);
        abort_unless($agencyId, 403, 'No agency');

        $q = DB::table('payee_invoices')->where('agency_id', $agencyId);
        if (($k = strtolower((string) $request->query('kind', ''))) && in_array($k, self::KINDS, true)) {
            $q->where('kind', $k);
        }
        if (($s = strtolower((string) $request->query('status', ''))) && $s !== 'all') {
            $q->where('status', $s);
        }

        $rows = $q->orderByDesc('id')->limit(500)->get();

        // Totals per status, so the section header can state where the money is without
        // the client adding up a paginated list and getting it wrong.
        $totals = DB::table('payee_invoices')->where('agency_id', $agencyId)
            ->when($k ?? null, fn ($x) => $x->where('kind', $k))
            ->selectRaw('status, COUNT(*) n, SUM(amount) total')->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['count' => (int) $r->n, 'total' => (float) $r->total]]);

        return response()->json(['invoices' => $rows, 'totals' => $totals]);
    }

    /**
     * GET /provider/payee-invoices/hours?user_id=&from=&to=
     * What the timesheet says, so the generate dialog can offer a figure instead of
     * asking somebody to add up a fortnight of punches by hand.
     */
    public function hours(Request $request): JsonResponse
    {
        $this->assertBiller($request);
        $agencyId = $this->agencyId($request);
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');

        $uid = (int) $request->query('user_id');
        $from = $request->query('from') ?: Carbon::now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: Carbon::now()->toDateString();

        $minutes = 0;
        foreach (DB::table('time_punches')->where('user_id', $uid)->whereIn('centre_id', $centreIds)
            ->whereNotNull('punched_out_at')
            ->whereDate('punched_in_at', '>=', $from)->whereDate('punched_in_at', '<=', $to)
            ->get(['punched_in_at', 'punched_out_at']) as $p) {
            $minutes += Carbon::parse($p->punched_in_at)->diffInMinutes(Carbon::parse($p->punched_out_at));
        }

        return response()->json([
            'user_id' => $uid, 'from' => $from, 'to' => $to,
            'minutes' => $minutes,
            'hours' => round($minutes / 60, 2),
        ]);
    }

    /** POST /provider/payee-invoices */
    public function store(Request $request): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureTable();
        $agencyId = $this->agencyId($request);
        abort_unless($agencyId, 403, 'No agency');

        $data = $request->validate([
            'kind' => ['required', 'in:' . implode(',', self::KINDS)],
            'payee_user_id' => ['nullable', 'integer'],
            'payee_family_id' => ['nullable', 'integer'],
            'payee_name' => ['required', 'string', 'max:160'],
            'basis' => ['required', 'in:amount,hours'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'details' => ['nullable', 'string', 'max:2000'],
            'recurring' => ['nullable', 'boolean'],
            'frequency' => ['nullable', 'in:weekly,biweekly,monthly'],
            'status' => ['nullable', 'in:' . implode(',', self::STATUSES)],
        ]);

        // Hours × rate is computed here, not accepted from the client: a total that
        // disagrees with its own hours and rate is unauditable, and this is money.
        $basis = $data['basis'];
        if ($basis === 'hours') {
            $hours = (float) ($data['hours'] ?? 0);
            $rate = (float) ($data['rate'] ?? 0);
            abort_if($hours <= 0 || $rate <= 0, 422, 'Hours and a rate are both needed for an hours-based invoice.');
            $amount = round($hours * $rate, 2);
        } else {
            $amount = round((float) ($data['amount'] ?? 0), 2);
            abort_if($amount <= 0, 422, 'Enter an amount.');
            $hours = null; $rate = null;
        }

        $recurring = (bool) ($data['recurring'] ?? false);
        abort_if($recurring && empty($data['frequency']), 422, 'A repeating invoice needs a frequency.');

        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $next = null;
        if ($recurring) {
            $base = $data['period_end'] ? Carbon::parse($data['period_end']) : Carbon::now($tz);
            $next = match ($data['frequency']) {
                'weekly' => $base->copy()->addWeek(),
                'biweekly' => $base->copy()->addWeeks(2),
                default => $base->copy()->addMonth(),
            };
        }

        $id = DB::table('payee_invoices')->insertGetId([
            'agency_id' => $agencyId,
            'kind' => $data['kind'],
            'payee_user_id' => $data['payee_user_id'] ?? null,
            'payee_family_id' => $data['payee_family_id'] ?? null,
            'payee_name' => $data['payee_name'],
            'reference' => 'PI-' . Carbon::now($tz)->format('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'basis' => $basis,
            'hours' => $hours,
            'rate' => $rate,
            'amount' => $amount,
            'details' => $data['details'] ?? null,
            'status' => $data['status'] ?? 'upcoming',
            'recurring' => $recurring,
            'frequency' => $recurring ? $data['frequency'] : null,
            'next_run_on' => $next?->toDateString(),
            'created_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'amount' => $amount], 201);
    }

    /**
     * GET /auth/me/payee-invoices — what the SIGNED-IN person owes or is owed.
     *
     * Deliberately not the admin list with a filter bolted on. This is scoped by who you
     * are rather than by which agency you are looking at, so an educator sees their own
     * payouts and a parent their own bills, and neither can widen it.
     */
    public function mine(Request $request): JsonResponse
    {
        $this->ensureTable();
        $uid = (int) $request->user()->id;
        $familyIds = DB::table('guardians')->where('user_id', $uid)->pluck('family_id');

        $rows = DB::table('payee_invoices')
            ->where(function ($q) use ($uid, $familyIds) {
                $q->where('payee_user_id', $uid);
                if ($familyIds->isNotEmpty()) {
                    $q->orWhereIn('payee_family_id', $familyIds);
                }
            })
            // A voided invoice is not something to show somebody as owing.
            ->where('status', '!=', 'void')
            ->orderByDesc('id')->limit(200)->get();

        return response()->json(['invoices' => $rows]);
    }

    /** GET /provider/payee-invoices/{id} — one invoice, for the view dialog. */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureTable();
        $uid = (int) $request->user()->id;
        $row = DB::table('payee_invoices')->where('id', $id)->first();
        abort_unless($row, 404, 'Not found');

        // Either you can bill for this agency, or the invoice is yours.
        // A platform admin's role row carries a NULL agency_id, so matching on
        // agency_id excluded them from every invoice — including the ones they had just
        // created. They are checked by role; everyone else by membership of that agency.
        $isBiller = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(function ($q) use ($row) {
                $q->where('role', 'platform_admin')
                  ->orWhere(function ($w) use ($row) {
                      $w->whereIn('role', ['agency_admin', 'centre_director'])
                        ->where('agency_id', $row->agency_id);
                  });
            })->exists();
        $isMine = (int) $row->payee_user_id === $uid
            || ($row->payee_family_id && DB::table('guardians')->where('user_id', $uid)
                ->where('family_id', $row->payee_family_id)->exists());
        abort_unless($isBiller || $isMine, 404, 'Not found');

        return response()->json(['invoice' => $row]);
    }

    /** POST /provider/payee-invoices/{id}/send — email it to the payee. */
    public function send(Request $request, int $id): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureTable();
        $agencyId = $this->agencyId($request);
        $row = DB::table('payee_invoices')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404, 'Not found');
        abort_if($row->status === 'void', 422, 'That invoice is void - sending it would tell somebody they owe a cancelled bill.');

        // An override is allowed: a contractor may have no account at all.
        $to = trim((string) $request->input('email', ''));
        if ($to === '') {
            if ($row->payee_user_id) {
                $to = (string) DB::table('users')->where('id', $row->payee_user_id)->value('email');
            } elseif ($row->payee_family_id) {
                $to = (string) DB::table('families')->where('id', $row->payee_family_id)->value('primary_email');
            }
        }
        abort_if($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL), 422, 'No email on file for this payee - add one, or type an address to send to.');

        $fmt = fn ($n) => '$' . number_format((float) $n, 2);
        $body = '<div style="font-size:15px;line-height:1.6;color:#334155;">Here are the details of invoice <strong>'
            . e((string) $row->reference) . '</strong>.</div>'
            . '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:11px;padding:14px 16px;margin:14px 0;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;color:#0F172A;">'
            . '<tr><td style="padding:4px 0;color:#64748B;">For</td><td style="padding:4px 0;font-weight:700;">' . e((string) $row->payee_name) . '</td></tr>'
            . (($row->basis === 'hours' && $row->hours)
                ? '<tr><td style="padding:4px 0;color:#64748B;">Hours</td><td style="padding:4px 0;">' . e((string) $row->hours) . ' x ' . e($fmt($row->rate)) . '</td></tr>' : '')
            . ($row->period_start
                ? '<tr><td style="padding:4px 0;color:#64748B;">Period</td><td style="padding:4px 0;">' . e(substr((string) $row->period_start, 0, 10)) . ' to ' . e(substr((string) ($row->period_end ?: $row->period_start), 0, 10)) . '</td></tr>' : '')
            . ($row->details ? '<tr><td style="padding:4px 0;color:#64748B;">Details</td><td style="padding:4px 0;">' . e((string) $row->details) . '</td></tr>' : '')
            . '<tr><td style="padding:10px 0 0;color:#64748B;font-weight:700;">Total</td><td style="padding:10px 0 0;font-size:20px;font-weight:800;">' . e($fmt($row->amount)) . '</td></tr>'
            . '</table></div>';

        $html = \App\Services\EmailTemplate::wrap((int) $agencyId, $body, [
            'eyebrow' => 'INVOICE',
            'title' => 'Invoice ' . $row->reference,
            'preheader' => $row->payee_name . ' - ' . $fmt($row->amount),
        ]);

        try {
            $pdf = $this->documentPdf($row);
            \App\Services\AgencyMailer::forAgency((int) $agencyId)->mailer()->html($html, function ($m) use ($to, $row, $pdf) {
                $m->to($to)->subject('Invoice ' . $row->reference . ' - ' . number_format((float) $row->amount, 2));
                // The identical bytes the preview shows, so "what did we send them" and
                // "what does the screen show" can never be different documents.
                if ($pdf !== null) {
                    $m->attachData($pdf, ($row->reference ?: ('invoice-' . $row->id)) . '.pdf', ['mime' => 'application/pdf']);
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not send: ' . $e->getMessage()], 502);
        }

        // Sending is what makes it issued: an invoice nobody has been told about is not
        // outstanding, it is a draft.
        if ($row->status === 'upcoming') {
            DB::table('payee_invoices')->where('id', $id)->update(['status' => 'issued', 'updated_at' => now()]);
        }

        return response()->json(['ok' => true, 'sent_to' => $to]);
    }

    /**
     * The invoice document itself — one renderer, so the PDF a director previews and the
     * PDF the payee receives cannot drift apart.
     */
    private function documentHtml(object $row): string
    {
        // brand_address is a key inside the settings JSON, not a column — selecting it
        // directly threw, which is why every PDF came back null while dompdf was fine.
        $agency = DB::table('agencies')->where('id', $row->agency_id)->first(['name', 'settings']);
        $agencyAddress = '';
        if ($agency && $agency->settings) {
            $decoded = json_decode((string) $agency->settings, true) ?: [];
            $agencyAddress = (string) ($decoded['brand_address'] ?? '');
        }
        $fmt = fn ($n) => '$' . number_format((float) $n, 2);
        $line = function (string $l, ?string $v): string {
            return ($v === null || $v === '') ? '' :
                '<tr><td style="padding:5px 16px 5px 0;color:#64748B;">' . e($l) . '</td>'
                . '<td style="padding:5px 0;color:#0F172A;font-weight:600;">' . e($v) . '</td></tr>';
        };

        return '<!doctype html><html><head><meta charset="UTF-8"><style>'
            . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;color:#0F172A;font-size:12px;margin:34px;}'
            . 'h1{font-size:20px;margin:0 0 2px;} .muted{color:#64748B;}'
            . 'table{border-collapse:collapse;} .box{border:1px solid #E2E8F0;border-radius:8px;padding:14px 16px;margin:18px 0;}'
            . '.total{border-top:2px solid #0F172A;margin-top:14px;padding-top:10px;font-size:18px;font-weight:800;}'
            . '</style></head><body>'
            . '<h1>' . e((string) ($agency->name ?? 'Invoice')) . '</h1>'
            . '<div class="muted">' . e($agencyAddress) . '</div>'
            . '<div style="margin:22px 0 4px;font-size:15px;font-weight:800;">Invoice ' . e((string) $row->reference) . '</div>'
            . '<div class="muted">Issued ' . e(substr((string) $row->created_at, 0, 10)) . ' · ' . e((string) $row->status) . '</div>'
            . '<div class="box"><table>'
            . $line('Billed to', (string) $row->payee_name)
            . $line('Record', $row->payee_family_id ? ('F-' . $row->payee_family_id) : ($row->payee_user_id ? ('U-' . $row->payee_user_id) : null))
            . (($row->basis === 'hours' && $row->hours)
                ? $line('Hours', $row->hours . ' at ' . $fmt($row->rate) . ' per hour') : '')
            . ($row->period_start
                ? $line('Period', substr((string) $row->period_start, 0, 10) . ' to ' . substr((string) ($row->period_end ?: $row->period_start), 0, 10)) : '')
            . $line('Details', (string) ($row->details ?? ''))
            . '</table>'
            . (function () use ($row, $fmt) {
                if (! Schema::hasTable('payee_invoice_lines')) { return ''; }
                $ls = DB::table('payee_invoice_lines')->where('payee_invoice_id', $row->id)->orderBy('id')->get();
                if ($ls->isEmpty()) { return ''; }
                $rows = '';
                foreach ($ls as $l) {
                    $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#64748B;text-transform:capitalize;">' . e((string) $l->category) . '</td>'
                        . '<td style="padding:4px 12px 4px 0;">' . e((string) $l->description) . '</td>'
                        . '<td style="padding:4px 0;text-align:right;font-weight:600;">' . e($fmt($l->amount)) . '</td></tr>';
                }
                return '<div style="margin-top:14px;"><div style="font-weight:700;margin-bottom:4px;">Additional items</div>'
                    . '<table style="width:100%;">' . $rows . '</table></div>';
            })()
            . '<div class="total">Total ' . e($fmt($row->amount)) . '</div></div>'
            . '<div class="muted" style="font-size:11px;">Generated by KiddieTrac.</div>'
            . '</body></html>';
    }

    /** Render it, or fall back to the HTML if the PDF engine is unavailable. */
    private function documentPdf(object $row): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) {
            return null;
        }
        try {
            $d = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $d->loadHtml($this->documentHtml($row));
            $d->setPaper('letter');
            $d->render();
            return $d->output();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Payee invoice PDF failed', ['id' => $row->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function ensureLinesTable(): void
    {
        if (Schema::hasTable('payee_invoice_lines')) return;
        Schema::create('payee_invoice_lines', function ($t) {
            $t->id();
            $t->unsignedBigInteger('payee_invoice_id')->index();
            $t->string('category', 32)->default('misc');   // misc | expense | mileage | supplies | adjustment
            $t->string('description', 300);
            $t->decimal('amount', 10, 2)->default(0);
            $t->timestamps();
        });
    }

    /** An invoice stops being editable the moment it represents something settled. */
    private function assertEditable(object $row): void
    {
        abort_if(
            in_array($row->status, ['paid', 'void'], true),
            422,
            'That invoice is ' . $row->status . ' — it is a record now. Void it and raise a new one instead.'
        );
    }

    /**
     * Once an invoice has lines, its total IS the sum of them. Keeping a separate stored
     * figure would let a header and its own breakdown disagree, and both would look
     * authoritative.
     */
    private function retotal(int $invoiceId): float
    {
        $sum = (float) DB::table('payee_invoice_lines')->where('payee_invoice_id', $invoiceId)->sum('amount');
        $base = DB::table('payee_invoices')->where('id', $invoiceId)->first(['basis', 'hours', 'rate']);
        // The original charge stays the first component: lines are additions to it, not a
        // replacement for the hours somebody worked.
        $core = ($base && $base->basis === 'hours' && $base->hours)
            ? round((float) $base->hours * (float) $base->rate, 2)
            : (float) DB::table('payee_invoices')->where('id', $invoiceId)->value('base_amount');
        $total = round($core + $sum, 2);
        DB::table('payee_invoices')->where('id', $invoiceId)->update(['amount' => $total, 'updated_at' => now()]);
        return $total;
    }

    /** GET /provider/payee-invoices/{id}/lines */
    public function lines(Request $request, int $id): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureLinesTable();
        $row = DB::table('payee_invoices')->where('id', $id)->where('agency_id', $this->agencyId($request))->first();
        abort_unless($row, 404, 'Not found');

        return response()->json([
            'lines' => DB::table('payee_invoice_lines')->where('payee_invoice_id', $id)->orderBy('id')->get(),
            'editable' => ! in_array($row->status, ['paid', 'void'], true),
            'amount' => (float) $row->amount,
        ]);
    }

    /** POST /provider/payee-invoices/{id}/lines */
    public function addLine(Request $request, int $id): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureLinesTable();
        $row = DB::table('payee_invoices')->where('id', $id)->where('agency_id', $this->agencyId($request))->first();
        abort_unless($row, 404, 'Not found');
        $this->assertEditable($row);

        $data = $request->validate([
            'category' => ['required', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:300'],
            // Negative is allowed on purpose: a discount or a correction is a line too.
            'amount' => ['required', 'numeric', 'min:-100000', 'max:100000'],
        ]);
        abort_if((float) $data['amount'] === 0.0, 422, 'A line of zero changes nothing — leave it off.');

        $this->rememberBase($id, $row);
        $lineId = DB::table('payee_invoice_lines')->insertGetId([
            'payee_invoice_id' => $id,
            'category' => strtolower($data['category']),
            'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $lineId, 'amount' => $this->retotal($id)], 201);
    }

    /** DELETE /provider/payee-invoices/{id}/lines/{line} */
    public function removeLine(Request $request, int $id, int $line): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureLinesTable();
        $row = DB::table('payee_invoices')->where('id', $id)->where('agency_id', $this->agencyId($request))->first();
        abort_unless($row, 404, 'Not found');
        $this->assertEditable($row);

        DB::table('payee_invoice_lines')->where('id', $line)->where('payee_invoice_id', $id)->delete();
        return response()->json(['ok' => true, 'amount' => $this->retotal($id)]);
    }

    /** PATCH /provider/payee-invoices/{id} — edit the invoice itself while unpaid. */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureTable();
        $row = DB::table('payee_invoices')->where('id', $id)->where('agency_id', $this->agencyId($request))->first();
        abort_unless($row, 404, 'Not found');
        $this->assertEditable($row);

        $data = $request->validate([
            'payee_name' => ['nullable', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:2000'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ]);

        $update = [];
        foreach (['payee_name', 'details', 'period_start', 'period_end'] as $f) {
            if (array_key_exists($f, $data)) { $update[$f] = $data[$f]; }
        }
        if ($row->basis === 'hours') {
            if (isset($data['hours'])) { $update['hours'] = $data['hours']; }
            if (isset($data['rate'])) { $update['rate'] = $data['rate']; }
        } elseif (isset($data['amount'])) {
            // The base changes; any lines still add on top of it.
            $update['base_amount'] = round((float) $data['amount'], 2);
        }
        $update['updated_at'] = now();
        DB::table('payee_invoices')->where('id', $id)->update($update);

        $this->ensureLinesTable();
        return response()->json(['ok' => true, 'amount' => $this->retotal($id)]);
    }

    /**
     * The figure the invoice started from, kept separately once lines exist so the two can
     * be added without the original being overwritten by its own total.
     */
    private function rememberBase(int $id, object $row): void
    {
        if (! Schema::hasColumn('payee_invoices', 'base_amount')) {
            Schema::table('payee_invoices', function ($t) { $t->decimal('base_amount', 10, 2)->nullable()->after('amount'); });
        }
        if ($row->base_amount ?? null) { return; }
        DB::table('payee_invoices')->where('id', $id)->update(['base_amount' => $row->amount]);
    }

    /** GET /provider/payee-invoices/{id}/pdf — the exact document the payee receives. */
    public function pdf(Request $request, int $id)
    {
        $this->ensureTable();
        $uid = (int) $request->user()->id;
        $row = DB::table('payee_invoices')->where('id', $id)->first();
        abort_unless($row, 404, 'Not found');

        // A platform admin's role row carries a NULL agency_id, so matching on
        // agency_id excluded them from every invoice — including the ones they had just
        // created. They are checked by role; everyone else by membership of that agency.
        $isBiller = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(function ($q) use ($row) {
                $q->where('role', 'platform_admin')
                  ->orWhere(function ($w) use ($row) {
                      $w->whereIn('role', ['agency_admin', 'centre_director'])
                        ->where('agency_id', $row->agency_id);
                  });
            })->exists();
        $isMine = (int) $row->payee_user_id === $uid
            || ($row->payee_family_id && DB::table('guardians')->where('user_id', $uid)
                ->where('family_id', $row->payee_family_id)->exists());
        abort_unless($isBiller || $isMine, 404, 'Not found');

        $pdf = $this->documentPdf($row);
        if ($pdf === null) {
            // Better a readable document than an error page: the engine being missing is
            // our problem, not a reason to withhold the invoice.
            return response($this->documentHtml($row), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . ($row->reference ?: ('invoice-' . $row->id)) . '.pdf"',
        ]);
    }

    /** POST /provider/payee-invoices/{id}/status */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $this->assertBiller($request);
        $this->ensureTable();
        $data = $request->validate(['status' => ['required', 'in:' . implode(',', self::STATUSES)]]);
        $agencyId = $this->agencyId($request);

        $row = DB::table('payee_invoices')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404, 'Not found');

        DB::table('payee_invoices')->where('id', $id)->update([
            'status' => $data['status'],
            // Voiding stops a schedule: a cancelled invoice that keeps regenerating is
            // not cancelled.
            'next_run_on' => $data['status'] === 'void' ? null : $row->next_run_on,
            'recurring' => $data['status'] === 'void' ? false : $row->recurring,
            'paid_at' => $data['status'] === 'paid' ? now() : null,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
