<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * What each agency's texts, phone calls and AI cost over a period, and which went over
 * the superadmin's threshold (2026-10-20). Spend = usage x the rates set in Reseller →
 * Usage & spend, so it reads the same for every carrier and for AI.
 *
 *   Texts: every text sent or received (status not failed/skipped), counted in carrier
 *          segments: 160 characters a segment (153 when split), or 70 / 67 once any
 *          character falls outside the GSM alphabet (an emoji, an accented letter...).
 *   Calls: completed calls, billed per started minute.
 *   AI:    the input and output tokens Anthropic reported (ai_usage, App\Support\UsageMeter).
 *
 * Thresholds are per week (the summary is weekly): one default per category, and any
 * agency may have its own.
 */
class UsageSpend
{
    public const CATEGORIES = ['sms' => 'Texts', 'voice' => 'Phone calls', 'ai' => 'AI'];

    public const DEFAULT_RATES = [
        'sms_segment' => 0.0075,      // $ per text segment (carrier + fees)
        'voice_minute' => 0.012,      // $ per call minute
        'ai_input_mtok' => 3.00,      // $ per million input tokens
        'ai_output_mtok' => 15.00,    // $ per million output tokens
    ];

    public const DEFAULT_THRESHOLDS = ['sms' => 25.0, 'voice' => 25.0, 'ai' => 25.0];

    public static function rates(): array
    {
        $saved = json_decode((string) PlatformSettings::get('usage.rates', ''), true) ?: [];
        $out = self::DEFAULT_RATES;
        foreach ($out as $k => $v) {
            if (isset($saved[$k]) && is_numeric($saved[$k]) && $saved[$k] >= 0) $out[$k] = (float) $saved[$k];
        }
        return $out;
    }

    /** ['default' => [cat => $], 'agencies' => [agencyId => [cat => $|null]]] */
    public static function thresholds(): array
    {
        $saved = json_decode((string) PlatformSettings::get('usage.thresholds', ''), true) ?: [];
        $def = self::DEFAULT_THRESHOLDS;
        foreach ($def as $k => $v) {
            if (isset($saved['default'][$k]) && is_numeric($saved['default'][$k]) && $saved['default'][$k] >= 0) $def[$k] = (float) $saved['default'][$k];
        }
        $ag = [];
        foreach ((array) ($saved['agencies'] ?? []) as $id => $row) {
            foreach (array_keys(self::CATEGORIES) as $k) {
                if (isset($row[$k]) && is_numeric($row[$k]) && $row[$k] >= 0) $ag[(int) $id][$k] = (float) $row[$k];
            }
        }
        return ['default' => $def, 'agencies' => $ag];
    }

    public static function thresholdFor(array $t, ?int $agencyId, string $cat): float
    {
        return $agencyId !== null && isset($t['agencies'][$agencyId][$cat]) ? $t['agencies'][$agencyId][$cat] : $t['default'][$cat];
    }

    /** Carrier segments for one text. */
    public static function segments(string $body): int
    {
        if ($body === '') return 1;
        $gsm = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $ext = '^{}\\[~]|€';
        $chars = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isGsm = true; $len = 0;
        foreach ($chars as $c) {
            if (mb_strpos($ext, $c) !== false) { $len += 2; continue; }
            if (mb_strpos($gsm, $c) === false) { $isGsm = false; break; }
            $len++;
        }
        if ($isGsm) return $len <= 160 ? 1 : (int) ceil($len / 153);
        $units = (int) (strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')) / 2);
        return $units <= 70 ? 1 : (int) ceil($units / 67);
    }

    /**
     * Spend per agency between two UTC datetimes ('Y-m-d H:i:s').
     * @return array<int,array> rows sorted by total spend, highest first
     */
    public static function period(string $fromUtc, string $toUtc): array
    {
        $r = self::rates();
        $t = self::thresholds();
        $rows = [];
        $row = function (?int $id) use (&$rows) {
            $k = $id ?? 0;
            if (! isset($rows[$k])) {
                $rows[$k] = ['agency_id' => $id, 'texts' => 0, 'segments' => 0, 'calls' => 0, 'minutes' => 0,
                    'ai_requests' => 0, 'ai_input' => 0, 'ai_output' => 0, 'cost' => ['sms' => 0.0, 'voice' => 0.0, 'ai' => 0.0]];
            }
            return $k;
        };

        DB::table('sms_messages')->whereBetween('created_at', [$fromUtc, $toUtc])
            ->whereNotIn('status', ['failed', 'skipped'])
            ->select('id', 'agency_id', 'body')->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$rows, $row) {
                foreach ($chunk as $m) {
                    $k = $row($m->agency_id ? (int) $m->agency_id : null);
                    $rows[$k]['texts']++;
                    $rows[$k]['segments'] += self::segments((string) $m->body);
                }
            });

        foreach (DB::table('voice_calls')->whereBetween('created_at', [$fromUtc, $toUtc])->where('duration_secs', '>', 0)
            ->select('agency_id', DB::raw('COUNT(*) n'), DB::raw('SUM(CEIL(duration_secs / 60)) mins'))->groupBy('agency_id')->get() as $c) {
            $k = $row($c->agency_id ? (int) $c->agency_id : null);
            $rows[$k]['calls'] += (int) $c->n;
            $rows[$k]['minutes'] += (int) $c->mins;
        }

        foreach (DB::table('ai_usage')->whereBetween('created_at', [$fromUtc, $toUtc])
            ->select('agency_id', DB::raw('COUNT(*) n'), DB::raw('SUM(input_tokens) i'), DB::raw('SUM(output_tokens) o'))->groupBy('agency_id')->get() as $a) {
            $k = $row($a->agency_id ? (int) $a->agency_id : null);
            $rows[$k]['ai_requests'] += (int) $a->n;
            $rows[$k]['ai_input'] += (int) $a->i;
            $rows[$k]['ai_output'] += (int) $a->o;
        }

        $names = DB::table('agencies')->pluck('name', 'id');
        foreach ($rows as $k => &$x) {
            $x['name'] = $x['agency_id'] ? (string) ($names[$x['agency_id']] ?? ('Agency #' . $x['agency_id'])) : 'No agency (platform)';
            $x['cost']['sms'] = round($x['segments'] * $r['sms_segment'], 2);
            $x['cost']['voice'] = round($x['minutes'] * $r['voice_minute'], 2);
            $x['cost']['ai'] = round($x['ai_input'] / 1e6 * $r['ai_input_mtok'] + $x['ai_output'] / 1e6 * $r['ai_output_mtok'], 2);
            $x['total'] = round(array_sum($x['cost']), 2);
            $x['threshold'] = [];
            $x['over'] = [];
            foreach (array_keys(self::CATEGORIES) as $cat) {
                $x['threshold'][$cat] = self::thresholdFor($t, $x['agency_id'], $cat);
                // A threshold of 0 means "no alert" for that category.
                if ($x['threshold'][$cat] > 0 && $x['cost'][$cat] > $x['threshold'][$cat]) $x['over'][] = $cat;
            }
        }
        unset($x);
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => (count($b['over']) <=> count($a['over'])) ?: ($b['total'] <=> $a['total']));
        return $rows;
    }
}
