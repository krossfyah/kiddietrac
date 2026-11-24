<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * THE PROSPECT INTAKE FORM (2026-10-23, Anthony: "attach a digital intake form that allows
 * us to understand their requirements (brand this as kiddietrac) and allow prospects to
 * fill in digitally"). app.kiddietrac.com/intake.html?b={booking}&s={sig} — signed per
 * booking, prefilled with what the booking already knows. Answers land on the booking and
 * the sales lead, and sales@ is told. Booking 0 is the sample link in test emails: it
 * shows and validates, and saves and tells nobody.
 */
class ProspectIntakeController extends Controller
{
    public const LISTS = [
        'program_type' => ['Licensed childcare centre(s)', 'Home child care agency', 'Both centres and home child care', 'Before & after school program', 'Other'],
        'age_groups' => ['Infant', 'Toddler', 'Preschool', 'Kindergarten', 'School-age'],
        'pain_points' => ['Attendance & sign-in sheets', 'Billing, payments & chasing balances', 'Parent communication', 'Staff scheduling & ratios', 'Compliance & licensing records',
            'Subsidy / CWELCC claims', 'Waitlist & enrolment', 'Reporting'],
        'interests' => ['One-Tap check-in', 'Parent app & daily reports', 'Billing & autopay', 'Subsidy tracking', 'Staff scheduling & payroll', 'Safe Arrival',
            'Waitlist & online enrolment', 'Your own brand (white label)', 'Moving data from another system'],
        'timeline' => ['As soon as possible', 'Within 1–3 months', 'Within 3–6 months', 'Just exploring for now'],
    ];
    private const TEXT = ['name' => 120, 'role' => 120, 'email' => 160, 'phone' => 40, 'org_name' => 160, 'region' => 80, 'website' => 200, 'locations' => 20,
        'children' => 20, 'staff' => 20, 'current_tools' => 600, 'must_haves' => 2000, 'decision_makers' => 400, 'notes' => 2000, 'best_time' => 120];

    public static function sig(int $bookingId): string
    {
        return substr(hash_hmac('sha256', 'intake|' . $bookingId, (string) config('app.key')), 0, 24);
    }

    public static function url(object $b): string
    {
        return 'https://app.kiddietrac.com/intake.html?b=' . (int) $b->id . '&s=' . self::sig((int) $b->id);
    }

    private function booking(Request $request, int $id): ?object
    {
        if (! hash_equals(self::sig($id), (string) $request->query('s', $request->input('s', '')))) abort(404, 'This form link is not valid.');
        if ($id === 0) return null;   // the sample in test emails
        $b = DB::table('marketing_bookings')->where('id', $id)->first();
        abort_unless($b, 404, 'This form link is not valid.');
        return $b;
    }

    /** GET /marketing-site/intake/{id}?s= — what the page needs to prefill. */
    public function show(Request $request, int $id): JsonResponse
    {
        $b = $this->booking($request, $id);
        $saved = $b ? DB::table('prospect_intakes')->where('booking_id', $id)->first() : null;
        return response()->json([
            'test' => $b === null, 'lists' => self::LISTS,
            'booking' => $b ? ['name' => $b->name, 'email' => $b->email, 'phone' => $b->phone, 'agency' => $b->agency, 'children' => $b->children,
                'type' => BookingMail::typeName($b), 'when_et' => in_array($b->status, ['booked', 'requested'], true) ? BookingMail::whenEt($b->starts_at) : null]
                : ['name' => 'Sample Prospect', 'email' => 'sample@example.com', 'phone' => null, 'agency' => 'Sample Childcare', 'children' => null, 'type' => 'Product demo', 'when_et' => null],
            'answers' => $saved ? (json_decode($saved->answers, true) ?: null) : null,
            'submitted_at' => $saved->submitted_at ?? null,
        ]);
    }

    /** POST /marketing-site/intake/{id}?s= — save the answers (again, if they come back). */
    public function submit(Request $request, int $id): JsonResponse
    {
        if (trim((string) $request->input('website_url_hp', '')) !== '') return response()->json(['ok' => true]);   // honeypot
        $b = $this->booking($request, $id);
        $a = [];
        foreach (self::TEXT as $k => $max) {
            $v = trim((string) $request->input($k, ''));
            if ($v !== '') $a[$k] = mb_substr($v, 0, $max);
        }
        foreach (self::LISTS as $k => $allowed) {
            $v = $request->input($k);
            $v = is_array($v) ? $v : ($v !== null && $v !== '' ? [$v] : []);
            $v = array_values(array_intersect($allowed, array_map('strval', $v)));
            if ($v) $a[$k] = in_array($k, ['program_type', 'timeline'], true) ? $v[0] : $v;
        }
        $other = trim((string) $request->input('interests_other', ''));
        if ($other !== '') $a['interests_other'] = mb_substr($other, 0, 300);
        if (empty($a['name']) || empty($a['email']) || ! filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Please give your name and a valid email address.'], 422);
        }
        if (! $b) return response()->json(['ok' => true, 'test' => true]);

        $first = ! DB::table('prospect_intakes')->where('booking_id', $id)->exists();
        DB::table('prospect_intakes')->updateOrInsert(['booking_id' => $id], [
            'sales_lead_id' => $b->sales_lead_id, 'email' => $a['email'], 'answers' => json_encode($a),
            'submitted_at' => now(), 'ip' => substr((string) $request->ip(), 0, 45), 'updated_at' => now(),
        ] + ($first ? ['created_at' => now()] : []));
        \App\Support\Audit::write(['user_id' => null, 'agency_id' => null, 'action' => 'prospect.intake_submitted', 'entity_type' => 'marketing_booking', 'entity_id' => $id,
            'payload' => json_encode(['summary' => ($first ? 'Intake form submitted' : 'Intake form updated') . ' by ' . $a['name'] . (($a['org_name'] ?? '') !== '' ? ' (' . $a['org_name'] . ')' : '')]),
            'ip_address' => $request->ip(), 'created_at' => now()]);
        try {
            if ($b->sales_lead_id) \App\Models\SalesActivity::create(['lead_id' => $b->sales_lead_id, 'type' => 'note', 'done' => true, 'due_date' => now()->toDateString(),
                'body' => ($first ? 'Intake form received' : 'Intake form updated') . ":\n" . self::plain($a)]);
        } catch (\Throwable $e) {
        }
        self::fillLead($b, $a);
        self::tellSales($b, $a, $first);
        return response()->json(['ok' => true]);
    }

    /* THE LEAD LEARNS IT TOO (2026-10-23, Anthony: "that info should be added to updates to the
       lead that this is attached to"). The answers are a note on the lead (above) and fill the
       lead's own fields where they are still empty - never overwriting what Sales typed. */
    private static function fillLead(object $b, array $a): void
    {
        if (! $b->sales_lead_id) return;
        try {
            $lead = DB::table('sales_leads')->where('id', $b->sales_lead_id)->first();
            if (! $lead) return;
            $num = fn ($v) => preg_match('/\d+/', (string) $v, $m) ? (int) $m[0] : null;
            $want = [
                'company' => $a['org_name'] ?? null, 'phone' => $a['phone'] ?? null, 'website' => $a['website'] ?? null,
                'province' => $a['region'] ?? null, 'owner_title' => $a['role'] ?? null, 'title' => $a['role'] ?? null,
                'num_children' => isset($a['children']) ? $num($a['children']) : null, 'num_locations' => isset($a['locations']) ? $num($a['locations']) : null,
                'current_solution' => $a['current_tools'] ?? null,
            ];
            $upd = [];
            foreach ($want as $col => $val) {
                if ($val === null || $val === '' || ! property_exists($lead, $col)) continue;
                if ($lead->$col === null || $lead->$col === '' || $lead->$col === 0) $upd[$col] = is_string($val) ? mb_substr($val, 0, 190) : $val;
            }
            $upd['last_activity_at'] = now(); $upd['updated_at'] = now();
            DB::table('sales_leads')->where('id', $lead->id)->update($upd);
        } catch (\Throwable $e) {
            Log::warning('intake lead fill failed', ['booking' => $b->id, 'error' => $e->getMessage()]);
        }
    }

    public static function labels(): array
    {
        return ['name' => 'Name', 'role' => 'Role', 'email' => 'Email', 'phone' => 'Phone', 'best_time' => 'Best time to reach', 'org_name' => 'Organisation',
            'program_type' => 'Program type', 'locations' => 'Locations', 'region' => 'Province / state', 'website' => 'Website', 'children' => 'Children enrolled',
            'staff' => 'Staff', 'age_groups' => 'Age groups', 'current_tools' => 'Tools used today', 'pain_points' => 'Biggest time drains', 'interests' => 'Interested in',
            'interests_other' => 'Also interested in', 'must_haves' => 'Must-haves', 'timeline' => 'Timeline', 'decision_makers' => 'Who decides', 'notes' => 'Anything else'];
    }

    private static function plain(array $a): string
    {
        $o = [];
        foreach (self::labels() as $k => $l) if (isset($a[$k])) $o[] = $l . ': ' . (is_array($a[$k]) ? implode(', ', $a[$k]) : $a[$k]);
        return implode("\n", $o);
    }

    private static function tellSales(object $b, array $a, bool $first): void
    {
        $rows = [];
        foreach (self::labels() as $k => $l) if (isset($a[$k])) $rows[$l] = is_array($a[$k]) ? implode(', ', $a[$k]) : $a[$k];
        try {
            $html = \App\Support\SalesMail::render([
                'preheader' => ($a['org_name'] ?? $b->name) . ' told us about their program',
                'eyebrow' => $first ? 'Intake form received' : 'Intake form updated', 'icon' => '📋',
                'title' => ($a['org_name'] ?? $b->agency ?? $b->name),
                'subtitle' => BookingMail::typeName($b) . ' · ' . BookingMail::whenEt($b->starts_at),
                'intro' => 'Use this to shape the meeting and the proposal. It is also on the booking in Sales → Bookings and on the lead.',
                'sections' => [['heading' => 'Their answers', 'rows' => $rows]],
                'button' => ['label' => 'Open the booking', 'url' => 'https://app.kiddietrac.com/dashboard.html#sales-bookings?id=' . (int) $b->id],
            ]);
            Mail::html($html, function ($m) use ($a, $b) {
                \App\Support\MailScope::platform($m);
                $m->to(BookingMail::SALES)->subject('Intake form: ' . ($a['org_name'] ?? $b->name) . ' — ' . BookingMail::typeName($b))->replyTo($a['email'], $a['name']);
            });
            $ids = DB::table('role_assignments')->whereIn('role', ['platform_admin', 'sales_rep'])->where('active', true)->distinct()->limit(25)->pluck('user_id');
            foreach ($ids as $uid) {
                \App\Support\Notify::write(['user_id' => (int) $uid, 'type' => 'booking_request', 'title' => '📋 Intake form received',
                    'body' => ($a['org_name'] ?? $b->name) . ' told us about their program before the meeting.', 'data' => json_encode(['link' => '#sales-bookings?id=' . (int) $b->id]), 'created_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('intake sales notice failed', ['booking' => $b->id, 'error' => $e->getMessage()]);
        }
    }
}
