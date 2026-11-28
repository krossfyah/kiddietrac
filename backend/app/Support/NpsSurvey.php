<?php

namespace App\Support;

use App\Services\AgencyMailer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * THE NPS SURVEY THAT GOES OUT (2026-11-28). The NPS screen showed results, but nothing ever asked anyone.
 *
 * Agency setting (agencies.settings.nps): enabled (default OFF for every agency), every_months (3/6/12), who (families
 * and/or staff), last_sent_at. A survey round = nps_surveys row + one nps_invites row per person. Each invite gets a
 * signed link (sha256 of a 40-char token, 30 days) to the public page nps.html: "How likely are you to recommend us?"
 * 0-10 + an optional comment, no sign-in. The same person can also answer from the portal prompt (GET /engagement/nps
 * /pending, POST /engagement/nps with invite_id). One answer per invite; answering again changes it.
 *
 * Who: families = guardians of live families at the agency's open centres (no pick-up-only logins, no suspended or
 * deactivated accounts, never families 17 and 80), staff = Live::staffIds. Platform people (AgencyOutsiders) never.
 * A test agency (TestAgency::blocks) sends nothing at all. Email goes through AgencyMailer (X-KT-Agency-Id, so the
 * normal mail gate applies: not-onboarded accounts, do-not-contact, agency/centre switches). The bell row carries the
 * agency, so it pushes under the usual rules.
 */
final class NpsSurvey
{
    public const PAGE = 'https://app.kiddietrac.com/nps.html?t=';
    /** Open families on the closed Bruni Meeser home, kept open on purpose (Anthony, 2026-11-12/13): never contacted. */
    public const NEVER_FAMILIES = [17, 80];
    public const DEFAULTS = ['enabled' => false, 'every_months' => 6, 'families' => true, 'staff' => false, 'last_sent_at' => null];
    public const LINK_DAYS = 30;

    public static function settings(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $n = is_array($s['nps'] ?? null) ? $s['nps'] : [];
        $out = array_merge(self::DEFAULTS, array_intersect_key($n, self::DEFAULTS));
        $out['enabled'] = (bool) $out['enabled'];
        $out['families'] = (bool) $out['families'];
        $out['staff'] = (bool) $out['staff'];
        $out['every_months'] = in_array((int) $out['every_months'], [3, 6, 12], true) ? (int) $out['every_months'] : 6;
        $out['next_due'] = $out['enabled'] ? self::nextDue($out) : null;

        return $out;
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = json_decode((string) $row, true) ?: [];
            $cur = is_array($s['nps'] ?? null) ? $s['nps'] : [];
            foreach (['enabled', 'families', 'staff'] as $k) if (array_key_exists($k, $in)) $cur[$k] = (bool) $in[$k];
            if (array_key_exists('every_months', $in)) $cur['every_months'] = (int) $in['every_months'];
            if (array_key_exists('last_sent_at', $in)) $cur['last_sent_at'] = $in['last_sent_at'];
            $s['nps'] = $cur;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return self::settings($agencyId);
    }

    private static function nextDue(array $s): string
    {
        $last = $s['last_sent_at'] ?? null;
        return $last ? Carbon::parse($last)->addMonths((int) $s['every_months'])->toDateString() : AgencyTime::today();
    }

    /**
     * The people a round would ask: [user_id => [user_id, email, first_name, family_id, centre_id, audience]].
     * A person who is both a parent and staff is asked once (as a parent when families are included).
     */
    public static function audience(int $agencyId, bool $families, bool $staff): array
    {
        $out = [];
        $open = Live::centreIds($agencyId);
        if ($families && $open) {
            $q = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->join('families as families', 'families.id', '=', 'g.family_id')
                ->whereIn('families.centre_id', $open)
                ->whereNotIn('families.id', self::NEVER_FAMILIES)
                ->whereNull('u.deleted_at')->whereNotIn('u.status', ['suspended', 'deactivated']);
            Live::families($q, 'families');
            AgencyOutsiders::exclude($q, 'u.id');
            foreach ($q->orderBy('families.id')->get(['u.id', 'u.email', 'u.first_name', 'families.id as family_id', 'families.centre_id']) as $r) {
                if (isset($out[(int) $r->id]) || PickupLogin::isPickupOnly((int) $r->id)) continue;
                $out[(int) $r->id] = ['user_id' => (int) $r->id, 'email' => (string) $r->email, 'first_name' => (string) $r->first_name,
                    'family_id' => (int) $r->family_id, 'centre_id' => (int) $r->centre_id, 'audience' => 'family'];
            }
        }
        if ($staff) {
            $ids = array_values(array_diff(Live::staffIds($agencyId), array_keys($out)));
            if ($ids) {
                $users = DB::table('users')->whereIn('id', $ids)->whereNotIn('status', ['suspended', 'deactivated'])->get(['id', 'email', 'first_name'])->keyBy('id');
                $centres = DB::table('role_assignments')->whereIn('user_id', $ids)->where('active', 1)->whereIn('centre_id', $open ?: [0])
                    ->orderBy('id')->get(['user_id', 'centre_id'])->groupBy('user_id');
                foreach ($users as $u) {
                    if (AgencyOutsiders::isOutsider((int) $u->id)) continue;
                    $out[(int) $u->id] = ['user_id' => (int) $u->id, 'email' => (string) $u->email, 'first_name' => (string) $u->first_name,
                        'family_id' => null, 'centre_id' => optional(($centres[$u->id] ?? collect())->first())->centre_id, 'audience' => 'staff'];
                }
            }
        }

        return $out;
    }

    /** What "Send now" would do: counts, and whether this agency can send at all. */
    public static function preview(int $agencyId, bool $families, bool $staff): array
    {
        $a = self::audience($agencyId, $families, $staff);
        $fam = array_filter($a, fn ($r) => $r['audience'] === 'family');

        return [
            'people' => count($a),
            'families' => count(array_unique(array_column($fam, 'family_id'))),
            'parents' => count($fam),
            'staff' => count($a) - count($fam),
            'test_agency' => TestAgency::isAgency($agencyId),
        ];
    }

    /** Start a round: the survey row and its invites (no sending; deliver() does that from the five-minute job). */
    public static function start(int $agencyId, bool $families, bool $staff, string $source, ?int $by): array
    {
        $people = self::audience($agencyId, $families, $staff);
        $id = DB::transaction(function () use ($agencyId, $families, $staff, $source, $by, $people) {
            $id = DB::table('nps_surveys')->insertGetId([
                'agency_id' => $agencyId, 'families' => $families, 'staff' => $staff, 'source' => $source, 'started_by' => $by,
                'invited' => count($people), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk(array_values($people), 500) as $chunk) {
                DB::table('nps_invites')->insert(array_map(fn ($p) => [
                    'survey_id' => $id, 'agency_id' => $agencyId, 'centre_id' => $p['centre_id'], 'user_id' => $p['user_id'],
                    'family_id' => $p['family_id'], 'audience' => $p['audience'], 'status' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            return $id;
        });
        self::saveSettings($agencyId, ['last_sent_at' => now()->toDateTimeString()]);

        return ['survey_id' => $id, 'invited' => count($people)];
    }

    /** Send the pending invites of open rounds: email + bell, each with its own link. Returns counts. */
    public static function deliver(int $limit = 400): array
    {
        $done = ['sent' => 0, 'blocked' => 0, 'failed' => 0];
        $rows = DB::table('nps_invites as i')->join('users as u', 'u.id', '=', 'i.user_id')
            ->where('i.status', 'pending')->orderBy('i.id')->limit($limit)
            ->get(['i.*', 'u.email', 'u.first_name']);
        foreach ($rows as $inv) {
            $agencyId = (int) $inv->agency_id;
            if (TestAgency::blocks($agencyId) || TestAgency::skips($agencyId, 'nps survey')) {
                DB::table('nps_invites')->where('id', $inv->id)->update(['status' => 'blocked', 'updated_at' => now()]);
                $done['blocked']++;
                continue;
            }
            try {
                $token = Str::random(40);
                DB::table('nps_invites')->where('id', $inv->id)->update([
                    'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::LINK_DAYS),
                    'status' => 'sent', 'sent_at' => now(), 'updated_at' => now(),
                ]);
                $url = self::PAGE . $token;
                $team = FamilyFacingName::agency($agencyId);
                Notify::write([
                    'user_id' => (int) $inv->user_id, 'agency_id' => $agencyId, 'type' => 'nps',
                    'title' => 'How are we doing? A one-question survey',
                    'body' => 'How likely are you to recommend ' . $team . '? Tap to answer, it takes a few seconds.',
                    'data' => json_encode(['link' => $url, 'agency_id' => $agencyId]),
                ]);
                $email = trim((string) $inv->email);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    NpsMail::send($agencyId, $email, ['first_name' => $inv->first_name, 'url' => $url, 'audience' => $inv->audience, 'centre_id' => $inv->centre_id]);
                    DB::table('nps_surveys')->where('id', $inv->survey_id)->increment('emailed');
                }
                $done['sent']++;
            } catch (\Throwable $e) {
                DB::table('nps_invites')->where('id', $inv->id)->update(['status' => 'failed', 'updated_at' => now()]);
                Log::warning('NPS invite not sent', ['invite' => $inv->id, 'error' => $e->getMessage()]);
                $done['failed']++;
            }
        }
        foreach ($rows->pluck('survey_id')->unique() as $sid) {
            if (! DB::table('nps_invites')->where('survey_id', $sid)->where('status', 'pending')->exists()) {
                DB::table('nps_surveys')->where('id', $sid)->whereNull('delivered_at')->update(['delivered_at' => now(), 'updated_at' => now()]);
            }
        }

        return $done;
    }

    /** Agencies whose next round is due (enabled, not a test agency, with the parent feedback feature). */
    public static function startDue(): array
    {
        $started = [];
        foreach (DB::table('agencies')->whereNull('deleted_at')->pluck('id') as $aid) {
            $aid = (int) $aid;
            $s = self::settings($aid);
            if (! $s['enabled'] || (! $s['families'] && ! $s['staff'])) continue;
            if ($s['next_due'] > AgencyTime::today($aid)) continue;
            if (TestAgency::isAgency($aid) || Features::skips($aid, 'parent_feedback', 'NPS survey')) continue;
            if (DB::table('nps_surveys')->where('agency_id', $aid)->where('created_at', '>=', now()->subDays(20))->exists()) continue;
            $started[$aid] = self::start($aid, $s['families'], $s['staff'], 'schedule', null);
        }

        return $started;
    }

    /** The invite behind a link, or null (unknown or expired). */
    public static function find(string $token): ?object
    {
        if (! preg_match('/^[A-Za-z0-9]{30,64}$/', $token)) return null;
        $inv = DB::table('nps_invites')->where('token_hash', hash('sha256', $token))->first();
        if (! $inv || ($inv->expires_at && now()->gt(Carbon::parse($inv->expires_at)))) return null;

        return $inv;
    }

    /** Record (or change) the answer of an invite. */
    public static function answer(object $inv, int $score, ?string $comment, string $via): int
    {
        $score = max(0, min(10, $score));
        $comment = trim((string) $comment);
        $comment = $comment !== '' ? mb_substr($comment, 0, 2000) : null;
        $first = ! $inv->response_id;
        $id = (int) ($inv->response_id ?? 0);
        $row = ['score' => $score, 'comment' => $comment, 'updated_at' => now()];
        if ($id && DB::table('nps_responses')->where('id', $id)->exists()) {
            DB::table('nps_responses')->where('id', $id)->update($row);
        } else {
            $id = DB::table('nps_responses')->insertGetId($row + [
                'agency_id' => $inv->agency_id, 'centre_id' => $inv->centre_id, 'family_id' => $inv->family_id,
                'user_id' => $inv->user_id, 'audience' => $inv->audience, 'survey_id' => $inv->survey_id, 'invite_id' => $inv->id,
                'survey_period' => now()->format('Y') . '-Q' . now()->quarter, 'created_at' => now(),
            ]);
        }
        DB::table('nps_invites')->where('id', $inv->id)->update(['response_id' => $id, 'answered_at' => now(), 'updated_at' => now()]);
        try {
            DB::table('audit_logs')->insert([
                'agency_id' => $inv->agency_id, 'user_id' => $inv->user_id, 'action' => $first ? 'nps.answered' : 'nps.answer_changed',
                'entity_type' => 'nps_response', 'entity_id' => $id,
                'payload' => json_encode(['score' => $score, 'via' => $via, 'audience' => $inv->audience]), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
        if ($first && $score <= 6) self::tellManagers($inv, $score, $comment);

        return $id;
    }

    /** A low score (0-6) reaches the managers of that centre (agency admins when no centre): who, not what. */
    private static function tellManagers(object $inv, int $score, ?string $comment): void
    {
        try {
            $ids = CentreScope::managers((int) $inv->agency_id, $inv->centre_id ? [(int) $inv->centre_id] : [])->distinct()->pluck('u.id')->all();
            $rows = [];
            foreach (array_unique(array_map('intval', $ids)) as $uid) {
                if ($uid === (int) $inv->user_id || AgencyOutsiders::isOutsider($uid)) continue;
                $rows[] = ['user_id' => $uid, 'agency_id' => (int) $inv->agency_id, 'type' => 'nps',
                    'title' => 'NPS: a ' . ($inv->audience === 'staff' ? 'staff member' : 'family') . ' answered ' . $score . '/10',
                    'body' => $comment ? 'They left a comment. Open Engagement > NPS to read it.' : 'Open Engagement > NPS to see the results.',
                    'data' => json_encode(['link' => '#nps', 'agency_id' => (int) $inv->agency_id])];
            }
            if ($rows) Notify::write($rows);
        } catch (\Throwable $e) {
            Log::warning('NPS low-score notice failed', ['invite' => $inv->id, 'error' => $e->getMessage()]);
        }
    }

    /** Score, trend by survey round, and comments, inside $scope (null = agency-wide). */
    public static function summary(int $agencyId, ?array $scope, ?int $centreId, ?string $audience, int $months = 24): array
    {
        $base = DB::table('nps_responses as r')->where('r.agency_id', $agencyId)
            ->where('r.created_at', '>=', now()->subMonths($months))
            ->when($scope !== null, fn ($q) => $q->whereIn('r.centre_id', $scope ?: [0]))
            ->when($centreId, fn ($q) => $q->where('r.centre_id', $centreId))
            ->when(in_array($audience, ['family', 'staff'], true), fn ($q) => $q->where('r.audience', $audience));
        $rows = (clone $base)->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')->orderByDesc('r.created_at')->limit(2000)
            ->get(['r.id', 'r.score', 'r.comment', 'r.audience', 'r.survey_id', 'r.centre_id', 'c.name as centre_name', 'r.created_at']);
        $score = function ($set) {
            $n = $set->count();
            if (! $n) return ['count' => 0, 'nps' => null, 'promoters' => 0, 'passive' => 0, 'detractors' => 0];
            $p = $set->where('score', '>=', 9)->count();
            $d = $set->where('score', '<=', 6)->count();
            return ['count' => $n, 'nps' => (int) round(($p - $d) / $n * 100), 'promoters' => $p, 'passive' => $n - $p - $d, 'detractors' => $d];
        };
        // Trend: one point per survey round; answers from before rounds existed group by quarter.
        $trend = [];
        foreach ($rows->groupBy(fn ($r) => $r->survey_id ? 's' . $r->survey_id : 'q' . Carbon::parse($r->created_at)->format('Y') . '-Q' . Carbon::parse($r->created_at)->quarter) as $k => $set) {
            $trend[] = ['key' => $k, 'label' => $k[0] === 's' ? Carbon::parse($set->min('created_at'))->format('M Y') : substr($k, 1),
                'from' => (string) $set->min('created_at')] + $score($set);
        }
        usort($trend, fn ($a, $b) => strcmp($a['from'], $b['from']));
        $surveys = DB::table('nps_surveys')->where('agency_id', $agencyId)->orderByDesc('id')->limit(12)
            ->get(['id', 'source', 'families', 'staff', 'invited', 'emailed', 'created_at', 'delivered_at']);
        $answered = DB::table('nps_invites')->whereIn('survey_id', $surveys->pluck('id')->all() ?: [0])
            ->when($scope !== null, fn ($q) => $q->whereIn('centre_id', $scope ?: [0]))
            ->selectRaw('survey_id, count(*) n, sum(answered_at is not null) a')->groupBy('survey_id')->get()->keyBy('survey_id');

        return [
            'summary' => $score($rows),
            'trend' => $trend,
            'comments' => $rows->filter(fn ($r) => $r->comment !== null && $r->comment !== '')->take(200)->values()->map(fn ($r) => [
                'id' => (int) $r->id, 'score' => (int) $r->score, 'comment' => $r->comment, 'audience' => $r->audience,
                'centre' => $r->centre_name, 'at' => AgencyTime::fmt($r->created_at, AgencyTime::tz($agencyId), 'M j, Y')])->all(),
            'rounds' => $surveys->map(fn ($s) => ['id' => (int) $s->id, 'source' => $s->source, 'families' => (bool) $s->families, 'staff' => (bool) $s->staff,
                'invited' => (int) ($answered[$s->id]->n ?? 0), 'answered' => (int) ($answered[$s->id]->a ?? 0),
                'at' => AgencyTime::fmt($s->created_at, AgencyTime::tz($agencyId), 'M j, Y')])->all(),
        ];
    }
}
