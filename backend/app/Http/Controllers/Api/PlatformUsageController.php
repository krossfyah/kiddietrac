<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\MessagingOverage;
use App\Support\PlatformSettings;
use App\Support\UsageSpend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Reseller → Usage & spend (2026-10-20): the rates, the per-agency thresholds and the
 * spend behind the superadmin weekly summary. Platform admins only (route group).
 */
class PlatformUsageController extends Controller
{
    /** GET /platform/usage-spend?days=7 */
    public function show(Request $request): JsonResponse
    {
        $days = max(1, min(31, (int) $request->query('days', 7)));
        $end = Carbon::now('America/Toronto')->addDay()->startOfDay();
        $start = $end->copy()->subDays($days);
        $rows = UsageSpend::period($start->copy()->utc()->format('Y-m-d H:i:s'), $end->copy()->utc()->subSecond()->format('Y-m-d H:i:s'));

        // Every live agency appears, with or without use, so a threshold can be set ahead.
        $seen = [];
        foreach ($rows as $r) if ($r['agency_id']) $seen[$r['agency_id']] = true;
        $t = UsageSpend::thresholds();
        foreach (DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) as $a) {
            if (isset($seen[$a->id])) continue;
            $th = [];
            foreach (array_keys(UsageSpend::CATEGORIES) as $c) $th[$c] = UsageSpend::thresholdFor($t, (int) $a->id, $c);
            $rows[] = ['agency_id' => (int) $a->id, 'name' => $a->name, 'texts' => 0, 'segments' => 0, 'calls' => 0, 'minutes' => 0,
                'ai_requests' => 0, 'ai_input' => 0, 'ai_output' => 0, 'cost' => ['sms' => 0, 'voice' => 0, 'ai' => 0], 'total' => 0,
                'threshold' => $th, 'over' => []];
        }
        foreach ($rows as &$r) {
            $r['custom'] = $r['agency_id'] ? ($t['agencies'][$r['agency_id']] ?? (object) []) : (object) [];
        }
        unset($r);

        return response()->json([
            'days' => $days,
            'from' => $start->toDateString(),
            'to' => $end->copy()->subDay()->toDateString(),
            'rates' => UsageSpend::rates(),
            'defaults' => $t['default'],
            'categories' => UsageSpend::CATEGORIES,
            'agencies' => $rows,
        ]);
    }

    /** PUT /platform/usage-settings {rates, defaults, agencies: {id: {sms, voice, ai}}} */
    public function save(Request $request): JsonResponse
    {
        $num = fn ($v) => is_numeric($v) && $v >= 0 && $v < 1000000 ? round((float) $v, 4) : null;

        $rates = [];
        foreach (array_keys(UsageSpend::DEFAULT_RATES) as $k) {
            $v = $num($request->input("rates.$k"));
            if ($v !== null) $rates[$k] = $v;
        }
        $defaults = [];
        foreach (array_keys(UsageSpend::CATEGORIES) as $c) {
            $v = $num($request->input("defaults.$c"));
            if ($v !== null) $defaults[$c] = $v;
        }
        $live = DB::table('agencies')->pluck('id')->map(fn ($v) => (int) $v)->flip();
        $agencies = [];
        foreach ((array) $request->input('agencies', []) as $id => $row) {
            if (! isset($live[(int) $id]) || ! is_array($row)) continue;
            foreach (array_keys(UsageSpend::CATEGORIES) as $c) {
                // Blank = use the default; a number (0 = no alert) = this agency's own.
                if (! array_key_exists($c, $row) || $row[$c] === '' || $row[$c] === null) continue;
                $v = $num($row[$c]);
                if ($v !== null) $agencies[(int) $id][$c] = $v;
            }
        }

        PlatformSettings::set('usage.rates', json_encode($rates + UsageSpend::rates()));
        PlatformSettings::set('usage.thresholds', json_encode(['default' => $defaults + UsageSpend::thresholds()['default'], 'agencies' => $agencies]));
        return $this->show($request);
    }

    /* ── SMS Incremental: the monthly text & call allowance (2026-10-28) - see App\Support\MessagingOverage. ── */

    /** GET /platform/messaging-overage?month=YYYY-MM */
    public function overage(Request $request): JsonResponse
    {
        return response()->json(MessagingOverage::rows(MessagingOverage::month($request->query('month'))));
    }

    /** PUT /platform/messaging-overage/settings {default: {texts, calls}, agencies: {id: {texts?, calls?}}} */
    public function overageSettings(Request $request): JsonResponse
    {
        MessagingOverage::saveSettings((array) $request->input('default', []), (array) $request->input('agencies', []));
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'platform.overage_settings', 'entity_type' => 'platform_setting',
            'payload' => json_encode(['summary' => 'Changed the text & call allowances (SMS Incremental)']), 'created_at' => now()]);

        return $this->overage($request);
    }

    /** POST /platform/messaging-overage/charge {agency_id, month} - approve: the blocks go on the next invoice. */
    public function overageCharge(Request $request): JsonResponse
    {
        $d = $request->validate(['agency_id' => ['required', 'integer'], 'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        try {
            $r = MessagingOverage::charge((int) $d['agency_id'], $d['month'], (int) $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => (int) $d['agency_id'], 'action' => 'platform.overage_charged',
            'entity_type' => 'platform_invoice_extra', 'entity_id' => $r['extra_id'],
            'payload' => json_encode(['summary' => 'Added SMS Incremental for ' . $d['month'] . ': ' . ($r['text_blocks'] + $r['call_blocks'])
                . ' block(s), $' . number_format($r['amount_cents'] / 100, 2) . ' to the next invoice', 'texts' => $r['texts'], 'calls' => $r['calls']]),
            'created_at' => now()]);

        return $this->overage($request->merge(['month' => $d['month']]));
    }

    /** POST /platform/messaging-overage/waive {agency_id, month} */
    public function overageWaive(Request $request): JsonResponse
    {
        $d = $request->validate(['agency_id' => ['required', 'integer'], 'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        try {
            MessagingOverage::dismiss((int) $d['agency_id'], $d['month'], (int) $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => (int) $d['agency_id'], 'action' => 'platform.overage_waived',
            'entity_type' => 'agency', 'entity_id' => (int) $d['agency_id'],
            'payload' => json_encode(['summary' => 'Waived SMS Incremental for ' . $d['month']]), 'created_at' => now()]);

        return $this->overage($request->merge(['month' => $d['month']]));
    }

    /** DELETE /platform/messaging-overage/{id} - undo a decision (a charge only before it is invoiced). */
    public function overageUndo(Request $request, int $id): JsonResponse
    {
        try {
            $c = MessagingOverage::undo($id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => (int) $c->agency_id, 'action' => 'platform.overage_undone',
            'entity_type' => 'agency', 'entity_id' => (int) $c->agency_id,
            'payload' => json_encode(['summary' => 'Took back the SMS Incremental ' . ($c->status === 'added' ? 'charge' : 'waiver') . ' for ' . $c->month]), 'created_at' => now()]);

        return $this->overage($request->merge(['month' => $c->month]));
    }

    /** POST /platform/usage-summary/test — this week's summary to the signed-in admin only. */
    public function test(Request $request): JsonResponse
    {
        $email = (string) $request->user()->email;
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return response()->json(['message' => 'Your account has no email address.'], 422);
        Artisan::call('platform:usage-summary', ['--test' => $email]);
        $out = Artisan::output();
        $ok = str_contains($out, 'sent to ' . $email);
        return response()->json(['ok' => $ok, 'email' => $email, 'message' => $ok ? 'Handed to the mail system for ' . $email . '. Check that inbox.' :'Could not send: ' . trim(mb_substr($out, -300))], $ok ? 200 : 502);
    }
}
