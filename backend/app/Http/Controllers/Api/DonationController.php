<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Donations & fundraising (2026-09-29).
 *
 * Anthony asked for it after comparing KiddieTrac with TUIO: "donations and fundraising,
 * with receipts". Agencies run campaigns with a public giving page; gifts are recorded as
 * pledged (from the page) or received (entered by the office: cash, cheque, e-transfer);
 * receipts are issued from received gifts.
 *
 * RECEIPTS. An OFFICIAL receipt for income tax purposes is only possible when the agency
 * is a registered charity: it needs the CRA registration number, the donor's full name
 * and address, a unique serial, the date received and issued, the place issued, the
 * eligible amount, an authorised signature and the CRA's name and web address. When any
 * of that is missing the gift gets an ACKNOWLEDGEMENT instead -- a thank-you that says
 * plainly it is not a tax receipt. A receipt is a snapshot and is never edited; a mistake
 * is fixed by voiding it and issuing a replacement that names the serial it replaces.
 *
 * ONLINE CARD GIVING is not here yet: no agency has a card provider connected
 * (agency_payment_providers). The public page takes pledges and shows the agency's own
 * instructions (e-transfer address, cheques payable to...).
 */
class DonationController extends Controller
{
    use ResolvesCentreContext;

    private const METHODS = ['cash', 'cheque', 'etransfer', 'card', 'other'];
    private const METHOD_LABEL = ['cash' => 'Cash', 'cheque' => 'Cheque', 'etransfer' => 'Interac e-Transfer', 'card' => 'Card', 'other' => 'Other'];

    /* ═════════════ settings ═════════════ */

    public static function settings(int $agencyId): array
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first();
        $s = $a ? (json_decode((string) $a->settings, true) ?: []) : [];
        $d = is_array($s['donations'] ?? null) ? $s['donations'] : [];
        $addr = trim(implode(', ', array_filter([$a->address_line1 ?? null, $a->address_line2 ?? null, $a->city ?? null,
            trim(($a->province ?? '') . ' ' . ($a->postal_code ?? ''))])));

        return [
            'enabled' => (bool) ($d['enabled'] ?? false),
            'charity_number' => (string) ($d['charity_number'] ?? ''),
            'legal_name' => (string) ($d['legal_name'] ?? ($a->legal_name ?? $a->name ?? '')),
            'receipt_address' => (string) ($d['receipt_address'] ?? $addr),
            'receipt_location' => (string) ($d['receipt_location'] ?? ($a->city ?? '')),
            'signatory_name' => (string) ($d['signatory_name'] ?? ''),
            'signatory_title' => (string) ($d['signatory_title'] ?? ''),
            'signature' => (string) ($d['signature'] ?? ''),
            'giving_instructions' => (string) ($d['giving_instructions'] ?? ''),
            'notify_email' => (string) ($d['notify_email'] ?? ''),
            'currency' => strtoupper((string) ($a->currency ?? 'CAD')) ?: 'CAD',
            'agency_name' => (string) ($a->name ?? ''),
        ];
    }

    public function showSettings(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        return response()->json(['settings' => self::settings($agencyId), 'official_ready' => $this->officialReady(self::settings($agencyId))]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'charity_number' => ['nullable', 'string', 'max:20', 'regex:/^\s*$|^\s*\d{9}\s*RR\s*\d{4}\s*$/i'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'receipt_address' => ['nullable', 'string', 'max:250'],
            'receipt_location' => ['nullable', 'string', 'max:80'],
            'signatory_name' => ['nullable', 'string', 'max:120'],
            'signatory_title' => ['nullable', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:200000'],
            'giving_instructions' => ['nullable', 'string', 'max:1500'],
            'notify_email' => ['nullable', 'email', 'max:160'],
        ], ['charity_number.regex' => 'A CRA charity registration number looks like 123456789 RR 0001.']);
        if (! empty($data['signature']) && ! str_starts_with($data['signature'], 'data:image/')) {
            return response()->json(['message' => 'The signature must be an image.'], 422);
        }
        $data['charity_number'] = strtoupper(preg_replace('/\s+/', '', (string) ($data['charity_number'] ?? '')));
        if ($data['charity_number'] !== '') {
            $data['charity_number'] = substr($data['charity_number'], 0, 9) . ' RR ' . substr($data['charity_number'], 11);
        }
        DB::transaction(function () use ($agencyId, $data) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $s = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $s['donations'] = array_merge(is_array($s['donations'] ?? null) ? $s['donations'] : [], $data);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE)]);
        });
        $this->audit($request, $agencyId, 'donations.settings', 'agency', $agencyId, 'Updated donation settings');

        return $this->showSettings($request);
    }

    private function officialReady(array $s): array
    {
        $missing = [];
        if ($s['charity_number'] === '') $missing[] = 'charity registration number';
        if (trim($s['legal_name']) === '') $missing[] = 'legal name';
        if (trim($s['receipt_address']) === '') $missing[] = 'charity address';
        if (trim($s['receipt_location']) === '') $missing[] = 'place of issue';
        if (trim($s['signatory_name']) === '') $missing[] = 'authorised signatory';

        return ['ready' => ! $missing, 'missing' => $missing];
    }

    /* ═════════════ campaigns ═════════════ */

    public function campaigns(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $rows = DB::table('donation_campaigns')->where('agency_id', $agencyId)->orderByDesc('id')->get();
        $totals = DB::table('donations')->where('agency_id', $agencyId)->where('status', '!=', 'cancelled')
            ->selectRaw("campaign_id, SUM(CASE WHEN status='received' THEN amount ELSE 0 END) as received, SUM(CASE WHEN status='pledged' THEN amount ELSE 0 END) as pledged, COUNT(*) as gifts")
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');
        foreach ($rows as $r) {
            $t = $totals[$r->id] ?? null;
            $r->received = (float) ($t->received ?? 0);
            $r->pledged = (float) ($t->pledged ?? 0);
            $r->gifts = (int) ($t->gifts ?? 0);
            $r->public_url = 'https://app.kiddietrac.com/give.html?c=' . $r->slug;
        }
        $general = $totals[''] ?? $totals[null] ?? null;

        return response()->json(['campaigns' => $rows, 'unassigned' => ['received' => (float) ($general->received ?? 0), 'gifts' => (int) ($general->gifts ?? 0)],
            'settings' => self::settings($agencyId)]);
    }

    public function saveCampaign(Request $request, ?int $id = null): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:4000'],
            'goal_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['required', 'in:draft,active,closed'],
            'show_progress' => ['nullable', 'boolean'],
            'suggested_amounts' => ['nullable', 'string', 'max:120', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'thank_you_message' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['suggested_amounts'] = isset($data['suggested_amounts']) ? preg_replace('/\s+/', '', $data['suggested_amounts']) : null;
        $data['show_progress'] = (bool) ($data['show_progress'] ?? true);
        if ($id) {
            $row = DB::table('donation_campaigns')->where('id', $id)->where('agency_id', $agencyId)->first();
            abort_unless($row, 404);
            DB::table('donation_campaigns')->where('id', $id)->update($data + ['updated_at' => now()]);
        } else {
            $slug = Str::slug($data['title']) ?: 'give';
            $slug = substr($slug, 0, 60) . '-' . Str::lower(Str::random(6));
            $id = DB::table('donation_campaigns')->insertGetId($data + ['agency_id' => $agencyId, 'slug' => $slug,
                'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'donations.campaign_saved', 'donation_campaign', $id, 'Saved campaign "' . $data['title'] . '"');

        return response()->json(['id' => $id]);
    }

    /* ═════════════ donations ═════════════ */

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $q = DB::table('donations as d')->leftJoin('donation_campaigns as c', 'c.id', '=', 'd.campaign_id')
            ->where('d.agency_id', $agencyId);
        if ($request->filled('campaign_id')) $q->where('d.campaign_id', (int) $request->query('campaign_id'));
        if ($request->filled('status')) $q->where('d.status', (string) $request->query('status'));
        if ($request->filled('year')) $q->whereRaw('YEAR(COALESCE(d.received_on, d.created_at)) = ?', [(int) $request->query('year')]);
        $rows = $q->orderByDesc('d.id')->limit(1000)->get(['d.*', 'c.title as campaign']);
        $receipts = DB::table('donation_receipts')->where('agency_id', $agencyId)->whereIn('donation_id', $rows->pluck('id'))
            ->orderBy('id')->get(['id', 'donation_id', 'serial', 'kind', 'status', 'issued_on', 'emailed_at', 'email_status'])->groupBy('donation_id');
        foreach ($rows as $r) {
            $r->receipts = array_values(($receipts[$r->id] ?? collect())->all());
            $r->receipt_kind = $this->receiptKind($r, self::settings($agencyId));
        }

        return response()->json(['donations' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $this->validateDonation($request);
        $this->assertCampaign($agencyId, $data['campaign_id'] ?? null);
        $id = DB::table('donations')->insertGetId($data + ['agency_id' => $agencyId, 'source' => 'admin',
            'currency' => self::settings($agencyId)['currency'], 'recorded_by_id' => (int) $request->user()->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'donations.recorded', 'donation', $id,
            'Recorded a ' . number_format((float) $data['amount'], 2) . ' ' . $data['status'] . ' gift from ' . trim($data['donor_first_name'] . ' ' . ($data['donor_last_name'] ?? '')));

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $row = DB::table('donations')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404);
        $live = DB::table('donation_receipts')->where('donation_id', $id)->where('status', 'issued')->exists();
        $data = $this->validateDonation($request);
        if ($live) {
            // An issued receipt is a snapshot; the gift behind it must not drift from it.
            foreach (['amount', 'advantage_amount', 'received_on', 'donor_first_name', 'donor_last_name', 'address_line1', 'city', 'province', 'postal_code'] as $k) {
                if (array_key_exists($k, $data) && (string) $data[$k] !== (string) ($row->$k ?? '')) {
                    return response()->json(['message' => 'This gift has an issued receipt. Void the receipt first, then correct the gift and issue a replacement.'], 422);
                }
            }
        }
        $this->assertCampaign($agencyId, $data['campaign_id'] ?? null);
        DB::table('donations')->where('id', $id)->update($data + ['updated_at' => now()]);
        $this->audit($request, $agencyId, 'donations.updated', 'donation', $id, 'Updated gift #' . $id . ' (' . $data['status'] . ')');

        return response()->json(['ok' => true]);
    }

    private function validateDonation(Request $request): array
    {
        $data = $request->validate([
            'campaign_id' => ['nullable', 'integer'],
            'family_id' => ['nullable', 'integer'],
            'donor_first_name' => ['required', 'string', 'max:80'],
            'donor_last_name' => ['nullable', 'string', 'max:80'],
            'donor_email' => ['nullable', 'email', 'max:160'],
            'donor_phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'advantage_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'method' => ['required', 'in:' . implode(',', self::METHODS)],
            'status' => ['required', 'in:pledged,received,cancelled'],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'],
            'anonymous' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['status'] === 'received' && empty($data['received_on'])) $data['received_on'] = now()->toDateString();
        $data['advantage_amount'] = (float) ($data['advantage_amount'] ?? 0);
        $data['anonymous'] = (bool) ($data['anonymous'] ?? false);
        if (! empty($data['family_id'])) {
            abort_unless(DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('f.id', $data['family_id'])->where('c.agency_id', $this->agency($request))->exists(), 422, 'That family is not in this agency.');
        }
        return $data;
    }

    private function assertCampaign(int $agencyId, $campaignId): void
    {
        if ($campaignId) abort_unless(DB::table('donation_campaigns')->where('id', $campaignId)->where('agency_id', $agencyId)->exists(), 422, 'Unknown campaign.');
    }

    /* ═════════════ receipts ═════════════ */

    /** 'official' when every CRA requirement is met, else 'acknowledgement' (with the reasons). */
    private function receiptKind(object $d, array $s): array
    {
        $why = [];
        if ($d->status !== 'received') $why[] = 'the gift has not been received yet';
        if (! $this->officialReady($s)['ready']) $why[] = 'the agency has not completed its charity details (' . implode(', ', $this->officialReady($s)['missing']) . ')';
        if (trim((string) $d->donor_last_name) === '') $why[] = "the donor's full name is missing";
        if (trim((string) $d->address_line1) === '' || trim((string) $d->city) === '' || trim((string) $d->postal_code) === '') $why[] = "the donor's address is incomplete";
        if ((float) $d->amount - (float) $d->advantage_amount <= 0) $why[] = 'there is no eligible amount';

        return ['kind' => $why ? 'acknowledgement' : 'official', 'why' => $why];
    }

    public function issueReceipt(Request $request, int $donationId, ?int $replacesId = null): JsonResponse
    {
        $agencyId = $this->agency($request);
        $d = DB::table('donations')->where('id', $donationId)->where('agency_id', $agencyId)->first();
        abort_unless($d, 404);
        abort_unless($d->status === 'received', 422, 'Record the gift as received before issuing a receipt.');
        if (! $replacesId && DB::table('donation_receipts')->where('donation_id', $donationId)->where('status', 'issued')->exists()) {
            return response()->json(['message' => 'This gift already has a receipt. Void it to issue a replacement.'], 422);
        }
        $s = self::settings($agencyId);
        $kind = $this->receiptKind($d, $s)['kind'];
        $name = trim($d->donor_first_name . ' ' . ($d->donor_last_name ?? ''));
        $addr = trim(implode(', ', array_filter([$d->address_line1, $d->city, trim(($d->province ?? '') . ' ' . ($d->postal_code ?? '')), $d->country])));

        $id = DB::transaction(function () use ($agencyId, $d, $s, $kind, $name, $addr, $request, $replacesId) {
            $year = now()->year;
            // Sequential per agency and year; the lock keeps two issuers from sharing a serial.
            $last = DB::table('donation_receipts')->where('agency_id', $agencyId)->where('serial', 'like', $year . '-%')
                ->lockForUpdate()->orderByDesc('id')->value('serial');
            $n = $last ? ((int) substr($last, strpos($last, '-') + 1)) + 1 : 1;
            $serial = $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $rid = DB::table('donation_receipts')->insertGetId([
                'agency_id' => $agencyId, 'donation_id' => $d->id, 'serial' => $serial, 'kind' => $kind,
                'amount' => $d->amount, 'advantage_amount' => $d->advantage_amount,
                'eligible_amount' => round((float) $d->amount - (float) $d->advantage_amount, 2),
                'currency' => $d->currency, 'received_on' => $d->received_on, 'issued_on' => now()->toDateString(),
                'issued_at_location' => $s['receipt_location'] ?: null, 'donor_name' => $name, 'donor_address' => $addr ?: null,
                'donor_email' => $d->donor_email,
                'charity' => json_encode(['name' => $s['agency_name'], 'legal_name' => $s['legal_name'], 'address' => $s['receipt_address'],
                    'number' => $s['charity_number'], 'signatory_name' => $s['signatory_name'], 'signatory_title' => $s['signatory_title'],
                    'signature' => $s['signature']], JSON_UNESCAPED_UNICODE),
                'status' => 'issued', 'replaces_receipt_id' => $replacesId, 'issued_by_id' => (int) $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($replacesId) DB::table('donation_receipts')->where('id', $replacesId)->update(['replaced_by_receipt_id' => $rid]);
            return $rid;
        });
        $r = DB::table('donation_receipts')->where('id', $id)->first();
        $this->audit($request, $agencyId, 'donations.receipt_issued', 'donation_receipt', $id,
            ($kind === 'official' ? 'Official receipt ' : 'Acknowledgement ') . $r->serial . ' for gift #' . $d->id . ($replacesId ? ' (replaces receipt #' . $replacesId . ')' : ''));

        return response()->json(['receipt' => $r], 201);
    }

    public function voidReceipt(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $r = DB::table('donation_receipts')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($r, 404);
        abort_unless($r->status === 'issued', 422, 'This receipt is already void.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'replace' => ['nullable', 'boolean']]);
        DB::table('donation_receipts')->where('id', $id)->update(['status' => 'void', 'void_reason' => $data['reason'], 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'donations.receipt_voided', 'donation_receipt', $id, 'Voided receipt ' . $r->serial . ': ' . $data['reason']);
        if (! empty($data['replace'])) {
            return $this->issueReceipt($request, (int) $r->donation_id, (int) $r->id);
        }

        return response()->json(['ok' => true]);
    }

    public function receiptPdf(Request $request, int $id): Response
    {
        $agencyId = $this->agency($request);
        $r = DB::table('donation_receipts')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($r, 404);

        return new Response($this->pdf($r), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->fileName($r) . '"']);
    }

    public function emailReceipt(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $r = DB::table('donation_receipts')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($r, 404);
        abort_unless($r->status === 'issued', 422, 'A void receipt cannot be emailed.');
        abort_unless($r->donor_email, 422, 'This donor has no email address.');
        if (! \App\Support\Suppression::agencyNotificationsEnabled($agencyId)) {
            return response()->json(['message' => 'Email is switched off for this agency, so nothing was sent. Download the PDF instead.'], 422);
        }
        $c = json_decode((string) $r->charity, true) ?: [];
        $first = strtok($r->donor_name, ' ');
        $what = $r->kind === 'official' ? 'your official donation receipt for income tax purposes' : 'an acknowledgement of your gift';
        $body = '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Dear ' . e($first) . ',</p>'
            . '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Thank you so much for your generous gift of <strong>'
            . e($this->money($r->amount, $r->currency)) . '</strong> to ' . e($c['name'] ?? '') . '. It makes a real difference to the children and families we care for.</p>'
            . '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Attached is ' . $what . ' (' . e($r->serial) . '). Please keep it with your records.</p>'
            . '<p style="margin:0;font-size:15px;color:#334155;line-height:1.6">With gratitude,<br>' . e($c['name'] ?? '') . '</p>';
        $html = \App\Services\EmailTemplate::wrap($agencyId, $body, ['title' => 'Thank you for your gift', 'preheader' => 'Your receipt ' . $r->serial]);
        $pdf = $this->pdf($r);
        $file = $this->fileName($r);
        try {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($r, $pdf, $file, $c) {
                $m->to($r->donor_email, $r->donor_name)->subject('Your donation receipt from ' . ($c['name'] ?? 'us') . ' (' . $r->serial . ')')
                  ->attachData($pdf, $file, ['mime' => 'application/pdf']);
            });
            DB::table('donation_receipts')->where('id', $id)->update(['emailed_at' => now(), 'email_status' => 'sent', 'updated_at' => now()]);
            $this->audit($request, $agencyId, 'donations.receipt_emailed', 'donation_receipt', $id, 'Emailed receipt ' . $r->serial . ' to ' . $r->donor_email);
            return response()->json(['sent' => true]);
        } catch (\Throwable $e) {
            Log::warning('donation receipt email failed', ['receipt' => $id, 'error' => $e->getMessage()]);
            DB::table('donation_receipts')->where('id', $id)->update(['email_status' => 'failed', 'updated_at' => now()]);
            return response()->json(['message' => 'The email could not be sent: ' . $e->getMessage()], 502);
        }
    }

    private function fileName(object $r): string
    {
        return ($r->kind === 'official' ? 'Donation-Receipt-' : 'Donation-Acknowledgement-') . preg_replace('/[^A-Za-z0-9-]/', '', $r->serial) . '.pdf';
    }

    private function money($v, string $cur): string
    {
        return '$' . number_format((float) $v, 2) . ($cur !== 'CAD' ? ' ' . $cur : '');
    }

    private function pdf(object $r): string
    {
        $agency = DB::table('agencies')->where('id', $r->agency_id)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);
        $replaces = $r->replaces_receipt_id ? DB::table('donation_receipts')->where('id', $r->replaces_receipt_id)->value('serial') : null;
        $html = view('pdf.donation_receipt', ['r' => $r, 'c' => json_decode((string) $r->charity, true) ?: [], 'agency' => $agency,
            'replaces' => $replaces, 'money' => fn ($v) => $this->money($v, $r->currency)])->render();
        $dompdf = new Dompdf(['isRemoteEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /* ═════════════ export ═════════════ */

    public function export(Request $request): Response
    {
        $agencyId = $this->agency($request);
        $year = (int) ($request->query('year') ?: now()->year);
        $rows = DB::table('donations as d')->leftJoin('donation_campaigns as c', 'c.id', '=', 'd.campaign_id')
            ->where('d.agency_id', $agencyId)->whereRaw('YEAR(COALESCE(d.received_on, d.created_at)) = ?', [$year])
            ->orderBy('d.received_on')->get(['d.*', 'c.title as campaign']);
        $rec = DB::table('donation_receipts')->where('agency_id', $agencyId)->where('status', 'issued')->get()->keyBy('donation_id');
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Gift #', 'Status', 'Received on', 'Donor', 'Email', 'Address', 'Amount', 'Advantage', 'Eligible', 'Currency', 'Method', 'Reference', 'Campaign', 'Anonymous', 'Receipt', 'Receipt type', 'Source']);
        foreach ($rows as $d) {
            $r = $rec[$d->id] ?? null;
            $cell = fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v;   // no spreadsheet formulas from donor input
            fputcsv($out, [$d->id, $d->status, $d->received_on, $cell(trim($d->donor_first_name . ' ' . $d->donor_last_name)), $cell($d->donor_email),
                $cell(trim(implode(', ', array_filter([$d->address_line1, $d->city, $d->province, $d->postal_code])))),
                number_format((float) $d->amount, 2, '.', ''), number_format((float) $d->advantage_amount, 2, '.', ''),
                number_format((float) $d->amount - (float) $d->advantage_amount, 2, '.', ''), $d->currency, self::METHOD_LABEL[$d->method] ?? $d->method,
                $cell($d->reference), $cell($d->campaign), $d->anonymous ? 'yes' : 'no', $r->serial ?? '', $r->kind ?? '', $d->source]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        $this->audit($request, $agencyId, 'donations.exported', 'agency', $agencyId, 'Exported ' . $year . ' donations (' . count($rows) . ' gifts)');

        return new Response("\xEF\xBB\xBF" . $csv, 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="donations-' . $year . '.csv"']);
    }

    /* ═════════════ public giving page ═════════════ */

    /** GET /public/give/{slug} */
    public function publicCampaign(string $slug): JsonResponse
    {
        $c = DB::table('donation_campaigns')->where('slug', $slug)->first();
        if (! $c || $c->status === 'draft') return response()->json(['message' => 'This campaign is not available.'], 404);
        $s = self::settings((int) $c->agency_id);
        if (! $s['enabled']) return response()->json(['message' => 'This campaign is not available.'], 404);
        $agency = DB::table('agencies')->where('id', $c->agency_id)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);
        $raised = (float) DB::table('donations')->where('campaign_id', $c->id)->where('status', 'received')->sum('amount');
        $donors = (int) DB::table('donations')->where('campaign_id', $c->id)->whereIn('status', ['received', 'pledged'])->count();
        $open = $c->status === 'active' && (! $c->ends_on || $c->ends_on >= now()->toDateString()) && (! $c->starts_on || $c->starts_on <= now()->toDateString());

        return response()->json([
            'title' => $c->title, 'description' => $c->description, 'goal' => $c->goal_amount ? (float) $c->goal_amount : null,
            'raised' => $c->show_progress ? $raised : null, 'donors' => $c->show_progress ? $donors : null,
            'ends_on' => $c->ends_on, 'open' => $open, 'currency' => $s['currency'],
            'suggested' => array_values(array_filter(array_map('intval', explode(',', (string) ($c->suggested_amounts ?: '25,50,100,250'))))),
            'instructions' => $s['giving_instructions'], 'official_receipts' => $this->officialReady($s)['ready'],
            'agency' => ['name' => $agency->name ?? '', 'logo' => $agency->brand_logo_url ?: ($agency->logo_url ?? null), 'colour' => $agency->brand_primary_color ?? null],
        ]);
    }

    /** POST /public/give/{slug} -- a pledge from the public page. */
    public function publicPledge(Request $request, string $slug): JsonResponse
    {
        if (trim((string) $request->input('website', '')) !== '') return response()->json(['ok' => true]);   // honeypot
        $c = DB::table('donation_campaigns')->where('slug', $slug)->first();
        abort_unless($c && $c->status === 'active' && self::settings((int) $c->agency_id)['enabled'], 404, 'This campaign is not taking gifts.');
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:160'], 'phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:160'], 'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'], 'postal_code' => ['nullable', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'], 'method' => ['required', 'in:etransfer,cheque,cash,other'],
            'anonymous' => ['nullable', 'boolean'], 'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $agencyId = (int) $c->agency_id;
        $s = self::settings($agencyId);
        $id = DB::table('donations')->insertGetId([
            'agency_id' => $agencyId, 'campaign_id' => $c->id, 'donor_first_name' => trim($data['first_name']), 'donor_last_name' => trim($data['last_name']),
            'donor_email' => strtolower(trim($data['email'])), 'donor_phone' => $data['phone'] ?? null,
            'address_line1' => $data['address_line1'] ?? null, 'city' => $data['city'] ?? null, 'province' => $data['province'] ?? null,
            'postal_code' => $data['postal_code'] ?? null, 'amount' => round((float) $data['amount'], 2), 'currency' => $s['currency'],
            'method' => $data['method'], 'status' => 'pledged', 'anonymous' => (bool) ($data['anonymous'] ?? false),
            'message' => $data['message'] ?? null, 'source' => 'public', 'ip' => substr((string) $request->ip(), 0, 45),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $name = trim($data['first_name'] . ' ' . $data['last_name']);
        $amount = $this->money($data['amount'], $s['currency']);

        // The donor: thanks, and how to complete the gift.
        $mailed = false;
        if (\App\Support\Suppression::agencyNotificationsEnabled($agencyId)) {
            try {
                $body = '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Dear ' . e($data['first_name']) . ',</p>'
                    . '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Thank you for pledging <strong>' . e($amount) . '</strong> to <strong>' . e($c->title) . '</strong>. '
                    . e($c->thank_you_message ?: 'Your support means a great deal to the children and families we care for.') . '</p>'
                    . ($s['giving_instructions'] ? \App\Services\EmailTemplate::calloutBox('<strong>How to complete your gift</strong><br>' . nl2br(e($s['giving_instructions'])), 'info') : '')
                    . '<p style="margin:14px 0 0;font-size:14px;color:#64748B;line-height:1.6">' . ($this->officialReady($s)['ready']
                        ? 'Once your gift is received we will send your receipt for income tax purposes.'
                        : 'Once your gift is received we will send you an acknowledgement.') . '</p>';
                $html = \App\Services\EmailTemplate::wrap($agencyId, $body, ['title' => 'Thank you for your pledge', 'preheader' => $amount . ' to ' . $c->title]);
                \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($data, $name, $c) {
                    $m->to(strtolower(trim($data['email'])), $name)->subject('Thank you for supporting ' . $c->title);
                });
                $mailed = true;
            } catch (\Throwable $e) {
                Log::warning('donation pledge thank-you failed', ['donation' => $id, 'error' => $e->getMessage()]);
            }
        }
        // The agency: in-app to its admins, and email only to the address it configured.
        foreach (DB::table('role_assignments')->where('agency_id', $agencyId)->where('role', 'agency_admin')->where('active', 1)->pluck('user_id')->unique() as $uid) {
            try {
                \App\Support\Notify::write(['user_id' => $uid, 'type' => 'donation_pledge', 'title' => 'New pledge: ' . $amount,
                    'body' => $name . ' pledged ' . $amount . ' to ' . $c->title . ' (' . (self::METHOD_LABEL[$data['method']] ?? $data['method']) . ').',
                    'data' => json_encode(['link' => '#donations']), 'created_at' => now()]);
            } catch (\Throwable $e) {
            }
        }
        if ($s['notify_email']) {
            try {
                \App\Services\AgencyMailer::forAgency($agencyId)->html(
                    '<p style="font-family:sans-serif">' . e($name) . ' (' . e($data['email']) . ') pledged <strong>' . e($amount) . '</strong> to <strong>' . e($c->title)
                    . '</strong> by ' . e(self::METHOD_LABEL[$data['method']] ?? $data['method']) . '.</p><p style="font-family:sans-serif">Mark it received in KiddieTrac → Donations when it arrives, then issue the receipt.</p>',
                    function ($m) use ($s, $name, $amount, $data) {
                        $m->to($s['notify_email'])->subject('New pledge: ' . $amount . ' from ' . $name)->replyTo(strtolower(trim($data['email'])), $name);
                    });
            } catch (\Throwable $e) {
                Log::warning('donation pledge notify failed', ['donation' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true, 'thanked_by_email' => $mailed, 'instructions' => $s['giving_instructions']], 201);
    }

    /* ═════════════ helpers ═════════════ */

    private function agency(Request $request): int
    {
        $id = (int) $this->resolveAgencyId($request);
        abort_unless($id > 0, 400, 'Select an agency first.');
        return $id;
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => $type, 'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
