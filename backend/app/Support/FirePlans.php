<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIRE PLANS (2026-11-28, Anthony: "fire drills should allow educators to draw out a fire plan and submit this with
 * details on the fire plan - can you wire this up correctly with a tree fire drill plan"). He wanted BOTH a drawn
 * floor plan and a step-by-step "what we do" decision tree. The screen is the Fire plan tab of the Drill log
 * (screen-drills.js + kt-fire-plan.js); the endpoints are FirePlanController.
 *
 * A plan belongs to a PLACE: a centre (a home, in home child care) and optionally one room. Every version is a row in
 * fire_plans. A draft is its author's to change; once submitted a version never changes - editing it makes the next
 * version, and the older ones stay viewable. A director of that centre or an agency admin approves it or asks for
 * changes. The CURRENT plan of a place is its newest approved version.
 *
 * This class holds what more than one reader needs: the default decision tree (and the agency's edited one), the
 * sanitisers for the three JSON blobs, the private file store, the staff who may be named on a plan, and each
 * centre's plan summary for the Drills tab ("not reviewed in 12 months").
 */
final class FirePlans
{
    public const STATUSES = ['draft', 'submitted', 'approved', 'changes'];

    /** Stamp keys the drawing may hold (kt-fire-plan.js STAMPS - the legend on the printed plan). */
    public const STAMPS = ['exit', 'ext', 'alarm', 'meet', 'aid', 'panel', 'hazard'];

    /** Folder under storage/app/private. Never in the web root: files go out through FirePlanController::file only. */
    public const DIR = 'fire-plans';

    /** The steps every plan starts from, unless the agency edited them (Settings tab → Fire plan steps). */
    public static function defaultTemplate(): array
    {
        $s = fn ($k, $title, $hint) => ['k' => $k, 'title' => $title, 'hint' => $hint];

        return [
            'steps' => [
                $s('s1', 'Alarm sounds / fire found', 'Whoever finds the fire shouts "Fire!", pulls the nearest alarm and closes the door behind them. No one fights a fire that is bigger than a wastebasket.'),
                $s('s2', 'Who sounds the alarm & calls 911', 'Name the person who calls 911 (from outside, on a cell phone) and gives the address.'),
                $s('s3', 'Who takes the attendance / emergency bag', 'Today\'s attendance (tablet or sheet), emergency contacts, medications and the first-aid kit.'),
                $s('s4', 'Infants & non-walkers', 'How they are moved: evacuation crib, carrier or wagon, and who moves them.'),
                $s('s5', 'Primary exit', 'The door you use first. Walk, do not run; close doors behind you.'),
                $s('s6', 'Meeting place', 'Where everyone gathers, a safe distance from the building, and the alternate meeting place.'),
                $s('s7', 'Headcount against attendance', 'Count every child and adult against today\'s attendance, out loud.'),
                $s('s8', 'Who checks rooms / bathrooms', 'The last person out checks bathrooms, nap rooms and closets on the way out, if it is safe.'),
                $s('s9', 'Shelter if the weather is bad', 'Where the children wait if it is cold, wet or dark (a neighbour, a school, a vehicle).'),
                $s('s10', 'Contacting parents & the agency', 'Who calls the families and the agency, and when.'),
                $s('s11', 'All-clear', 'Only the fire department says when it is safe to go back inside.'),
            ],
            'branches' => [
                ['k' => 'b1', 'after' => 's5', 'title' => 'If the primary exit is blocked', 'hint' => 'Use the alternate exit. Name it and how you get there.'],
                ['k' => 'b2', 'after' => 's7', 'title' => 'If a child is missing', 'hint' => 'Tell 911 / the firefighters right away where the child was last seen. Never go back inside.'],
            ],
        ];
    }

    /** The agency's steps (Settings → Fire plan steps), else the default. */
    public static function template(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $t = $s['fire_plan_template'] ?? null;

        return is_array($t) && ! empty($t['steps']) ? self::cleanTree($t, true) : self::defaultTemplate();
    }

    public static function saveTemplate(int $agencyId, ?array $tree): void
    {
        DB::transaction(function () use ($agencyId, $tree) {   // re-read under a lock: only this key changes
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            if ($tree === null) unset($s['fire_plan_template']);
            else $s['fire_plan_template'] = self::cleanTree($tree, true);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
    }

    private static function str($v, int $max): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);

        return mb_substr($v, 0, $max);
    }

    /**
     * A decision tree as stored: steps [{k,title,hint,who,what,where}] and branches [{k,after,title,hint,who,what,
     * where}] that hang off a step. $template: the agency's edited steps (titles and hints only, no answers).
     */
    public static function cleanTree($tree, bool $template = false): array
    {
        $tree = is_array($tree) ? $tree : [];
        $steps = [];
        $keys = [];
        foreach (array_slice(is_array($tree['steps'] ?? null) ? $tree['steps'] : [], 0, 30) as $i => $s) {
            if (! is_array($s)) continue;
            $title = self::str($s['title'] ?? '', 120);
            if ($title === '') continue;
            $k = preg_match('/^[a-z0-9_]{1,12}$/', (string) ($s['k'] ?? '')) ? (string) $s['k'] : 's' . ($i + 1);
            while (isset($keys[$k])) $k .= 'x';
            $keys[$k] = true;
            $row = ['k' => $k, 'title' => $title, 'hint' => self::str($s['hint'] ?? '', 300)];
            if (! $template) $row += ['who' => self::str($s['who'] ?? '', 160), 'what' => self::str($s['what'] ?? '', 600), 'where' => self::str($s['where'] ?? '', 200)];
            $steps[] = $row;
        }
        $branches = [];
        foreach (array_slice(is_array($tree['branches'] ?? null) ? $tree['branches'] : [], 0, 12) as $i => $b) {
            if (! is_array($b)) continue;
            $title = self::str($b['title'] ?? '', 120);
            $after = (string) ($b['after'] ?? '');
            if ($title === '' || ! isset($keys[$after])) continue;
            $k = preg_match('/^[a-z0-9_]{1,12}$/', (string) ($b['k'] ?? '')) ? (string) $b['k'] : 'b' . ($i + 1);
            while (isset($keys[$k])) $k .= 'x';
            $keys[$k] = true;
            $row = ['k' => $k, 'after' => $after, 'title' => $title, 'hint' => self::str($b['hint'] ?? '', 300)];
            if (! $template) $row += ['who' => self::str($b['who'] ?? '', 160), 'what' => self::str($b['what'] ?? '', 600), 'where' => self::str($b['where'] ?? '', 200)];
            $branches[] = $row;
        }

        return ['steps' => $steps, 'branches' => $branches];
    }

    /** Plan details: children and ages, staff (ids at this centre + others by name), exits, date reviewed, notes. */
    public static function cleanDetails($d, array $allowedStaffIds): array
    {
        $d = is_array($d) ? $d : [];
        $int = fn ($v, $max) => is_numeric($v) && (int) $v >= 0 ? min((int) $v, $max) : null;
        $date = (string) ($d['reviewed_on'] ?? '');
        $allowed = array_flip(array_map('intval', $allowedStaffIds));

        return [
            'children_count' => $int($d['children_count'] ?? null, 500),
            'ages' => self::str($d['ages'] ?? '', 200),
            'staff_ids' => array_values(array_unique(array_filter(array_map('intval', is_array($d['staff_ids'] ?? null) ? $d['staff_ids'] : []), fn ($i) => isset($allowed[$i])))),
            'staff_other' => self::str($d['staff_other'] ?? '', 300),
            'exits_count' => $int($d['exits_count'] ?? null, 50),
            'reviewed_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'notes' => self::str($d['notes'] ?? '', 2000),
        ];
    }

    /**
     * The drawing's vector JSON, rebuilt from known fields only (so nothing but numbers, short strings and colours
     * from a fixed set is ever stored). Null when there is nothing drawn.
     */
    public static function cleanDrawing($raw): ?array
    {
        $d = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($d)) return null;
        $num = fn ($v, $lo = -20000, $hi = 20000) => is_numeric($v) ? round(max($lo, min($hi, (float) $v)), 1) : 0.0;
        $pts = function ($v) use ($num) {
            $v = is_array($v) ? array_slice(array_values($v), 0, 4000) : [];
            if (count($v) % 2) array_pop($v);
            return array_map(fn ($x) => $num($x), $v);
        };
        $colour = fn ($v, $def) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? $v : $def;
        $items = [];
        foreach (array_slice(is_array($d['items'] ?? null) ? $d['items'] : [], 0, 3000) as $it) {
            if (! is_array($it)) continue;
            $t = (string) ($it['t'] ?? '');
            switch ($t) {
                case 'wall':
                case 'pen':
                case 'route':
                    $p = $pts($it['pts'] ?? []);
                    if (count($p) < 4) break;
                    $row = ['t' => $t, 'pts' => $p, 'w' => $num($it['w'] ?? 4, 1, 40)];
                    if ($t === 'route') $row['kind'] = ($it['kind'] ?? '') === 'alt' ? 'alt' : 'primary';
                    else $row['c'] = $colour($it['c'] ?? null, '#0F172A');
                    $items[] = $row;
                    break;
                case 'room':
                    $items[] = ['t' => 'room', 'x' => $num($it['x'] ?? 0), 'y' => $num($it['y'] ?? 0), 'w' => $num($it['w'] ?? 0, -20000), 'h' => $num($it['h'] ?? 0, -20000),
                        'c' => $colour($it['c'] ?? null, '#0F172A')];
                    break;
                case 'stamp':
                    $k = (string) ($it['k'] ?? '');
                    if (! in_array($k, self::STAMPS, true)) break;
                    $items[] = ['t' => 'stamp', 'k' => $k, 'x' => $num($it['x'] ?? 0), 'y' => $num($it['y'] ?? 0), 's' => $num($it['s'] ?? 34, 12, 160)];
                    break;
                case 'text':
                    $s = self::str($it['s'] ?? '', 80);
                    if ($s === '') break;
                    $items[] = ['t' => 'text', 's' => $s, 'x' => $num($it['x'] ?? 0), 'y' => $num($it['y'] ?? 0), 'z' => $num($it['z'] ?? 18, 8, 120),
                        'c' => $colour($it['c'] ?? null, '#0F172A')];
                    break;
            }
        }
        $u = is_array($d['underlay'] ?? null) ? $d['underlay'] : null;

        return [
            'v' => 1,
            'w' => $num($d['w'] ?? 1200, 200, 6000), 'h' => $num($d['h'] ?? 800, 200, 6000),
            'grid' => $num($d['grid'] ?? 20, 5, 200),
            'underlay' => $u ? ['x' => $num($u['x'] ?? 0), 'y' => $num($u['y'] ?? 0), 'w' => $num($u['w'] ?? 1200, 1), 'h' => $num($u['h'] ?? 800, 1),
                'o' => $num($u['o'] ?? 0.45, 0.05, 1)] : null,
            'items' => $items,
        ];
    }

    /**
     * Writes a data: URL image into the agency's private folder. $kinds: allowed mime => extension. Returns the
     * path relative to storage/app/private, or null when there is no image. Refuses anything that is not the image
     * it says it is.
     */
    public static function storeDataUrl(int $agencyId, ?string $dataUrl, string $prefix, array $kinds, int $maxBytes): ?string
    {
        if (! is_string($dataUrl) || $dataUrl === '') return null;
        abort_unless(preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $m), 422, 'That picture could not be read.');
        abort_unless(isset($kinds[$m[1]]), 422, 'That kind of picture cannot be used here.');
        $bin = base64_decode($m[2], true);
        abort_unless($bin !== false && strlen($bin) > 0, 422, 'That picture could not be read.');
        abort_if(strlen($bin) > $maxBytes, 422, 'That picture is too big (' . round($maxBytes / 1048576) . ' MB at most).');
        $info = @getimagesizefromstring($bin);
        abort_unless($info && ($info['mime'] ?? '') === $m[1], 422, 'That picture could not be read.');
        $dir = storage_path('app/private/' . self::DIR . '/' . $agencyId);
        if (! is_dir($dir)) @mkdir($dir, 0770, true);
        $name = $prefix . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $kinds[$m[1]];
        abort_unless(@file_put_contents($dir . '/' . $name, $bin) !== false, 500, 'The picture could not be saved.');

        return self::DIR . '/' . $agencyId . '/' . $name;
    }

    /** The absolute path of a stored file, only when it is inside this agency's folder and exists. */
    public static function filePath(int $agencyId, ?string $rel): ?string
    {
        $rel = (string) $rel;
        if ($rel === '' || ! str_starts_with($rel, self::DIR . '/' . $agencyId . '/') || str_contains($rel, '..')) return null;
        $abs = storage_path('app/private/' . $rel);

        return is_file($abs) ? $abs : null;
    }

    /**
     * Staff who may be named on a plan at this centre: educators and directors placed there, and the agency's admins.
     * Never a platform admin, an integration account or anyone signed in through support access - the plan is the
     * agency's own document (kiddietrac-super-admin-not-agency-staff).
     *
     * @return array<int,array{id:int,name:string,first:string}>
     */
    public static function staffAt(int $agencyId, int $centreId): array
    {
        $platform = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $ids = DB::table('role_assignments')->where('active', 1)
            ->where(fn ($w) => $w->where(fn ($c) => $c->where('centre_id', $centreId)->whereIn('role', ['educator', 'centre_director']))
                ->orWhere(fn ($a) => $a->where('agency_id', $agencyId)->where('role', 'agency_admin')))
            ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->diff($platform)->values()->all();

        return DB::table('users')->whereIn('id', $ids ?: [0])->whereNull('deleted_at')->where('email', 'not like', 'integration+%')
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'first' => (string) $u->first_name])->all();
    }

    /** A person's full name for the plan document, or null for someone who is not the agency's own (platform, integration). */
    public static function agencyPersonName(?int $userId): ?string
    {
        if (! $userId) return null;
        if (DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', 1)->exists()) return null;
        $u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'email']);
        if (! $u || str_starts_with((string) $u->email, 'integration+')) return null;

        return trim($u->first_name . ' ' . $u->last_name) ?: null;
    }

    /** The date a plan was last reviewed: the date written on it, else the day it was approved. */
    public static function reviewedOn(object $plan): ?string
    {
        $d = json_decode((string) ($plan->details ?? ''), true) ?: [];
        if (! empty($d['reviewed_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['reviewed_on'])) return (string) $d['reviewed_on'];

        return $plan->reviewed_at ? Carbon::parse($plan->reviewed_at)->toDateString() : null;
    }

    /**
     * Per centre, for the Drills tab: the plan for the whole centre (room NULL) - else any room's - and whether it
     * has gone 12 months without a review. Display only: the reminders are fire-plans:remind (reviewDue below).
     *
     * @param  int[] $centreIds
     * @return array<int,array<string,mixed>>
     */
    public static function centreSummary(array $centreIds, string $tz): array
    {
        if (! $centreIds || ! Schema::hasTable('fire_plans')) return [];
        $rows = DB::table('fire_plans')->whereIn('centre_id', $centreIds)->whereNull('deleted_at')
            ->orderByDesc('version')->get(['id', 'centre_id', 'room_id', 'version', 'status', 'details', 'reviewed_at']);
        $today = Carbon::now($tz)->toDateString();
        $yearAgo = Carbon::now($tz)->subYear()->toDateString();
        $out = [];
        foreach ($centreIds as $cid) {
            $mine = $rows->where('centre_id', $cid);
            $current = $mine->where('status', 'approved')->sortBy(fn ($r) => [$r->room_id === null ? 0 : 1, -$r->version])->first();
            $pending = $mine->where('status', 'submitted')->count();
            if (! $current) {
                $out[$cid] = ['state' => $mine->isEmpty() ? 'none' : 'pending', 'pending' => $pending];
                continue;
            }
            $rev = self::reviewedOn($current);
            $out[$cid] = ['state' => $rev && $rev >= $yearAgo ? 'current' : 'review_due', 'id' => (int) $current->id, 'version' => (int) $current->version,
                'reviewed_on' => $rev, 'review_by' => $rev ? Carbon::parse($rev)->addYear()->toDateString() : $today, 'pending' => $pending];
        }

        return $out;
    }

    /** Who wrote this version: the person who sent it for review, else who started it. Nobody reviews their own plan. */
    public static function authorId(object $plan): int
    {
        return (int) ($plan->submitted_by ?: $plan->created_by);
    }

    /** "Sunflower" or "Sunflower · Toddler room". */
    public static function placeLabel(int $centreId, ?int $roomId): string
    {
        $c = (string) DB::table('centres')->where('id', $centreId)->value('name');
        $r = $roomId ? (string) DB::table('rooms')->where('id', $roomId)->value('name') : '';

        return $r !== '' ? $c . ' · ' . $r : $c;
    }

    /** "Open the fire plan" button. kt-btn keeps its text white in dark mode (the card's link colour would win otherwise). */
    public static function button(int $planId): string
    {
        return '<div style="margin:20px 0;text-align:center;"><a class="kt-btn" href="' . htmlspecialchars(self::link($planId), ENT_QUOTES, 'UTF-8') . '" style="background-color:#1F6080;color:#FFFFFF;padding:12px 26px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;display:inline-block;">Open the fire plan</a></div>';
    }

    public static function link(int $planId): string
    {
        return 'https://app.kiddietrac.com/dashboard.html#drills?tab=fireplan&plan=' . $planId;
    }

    /**
     * The author hears when a reviewer approves their plan or sends it back (Anthony, 2026-11-28): a bell row (type
     * fire_plan: StaffNotifyPrefs topic staff_fire_plans + the agency's drills switch, both checked in Notify::write)
     * and an email with the reviewer's comment and a link to the plan. First names only. Test agencies send nothing.
     */
    public static function tellReviewed(int $agencyId, object $plan, int $reviewerId, string $decision, string $comment): void
    {
        $uid = self::authorId($plan);
        if (! $uid || $uid === $reviewerId) return;
        $ok = $decision === 'approve';
        $who = (string) DB::table('users')->where('id', $reviewerId)->value('first_name');
        $place = self::placeLabel((int) $plan->centre_id, $plan->room_id !== null ? (int) $plan->room_id : null);
        $title = $ok ? 'Your fire plan was approved' : 'Changes asked for on your fire plan';
        $short = $comment !== '' ? mb_strimwidth($comment, 0, 140, '…') : '';
        $body = $place . ' · version ' . (int) $plan->version . ' · ' . ($ok ? ($who !== '' ? $who . ' approved it' : 'approved') : ($who !== '' ? $who . ': ' : '') . $short)
            . ($ok && $short !== '' ? ' - "' . $short . '"' : '');
        Notify::write(['user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'fire_plan', 'title' => ($ok ? "\u{2705} " : "\u{21A9}\u{FE0F} ") . $title,
            'body' => $body, 'data' => json_encode(['link' => '#drills?tab=fireplan&plan=' . (int) $plan->id])]);

        self::email($agencyId, $uid, ($ok ? 'Fire plan approved: ' : 'Changes asked for: fire plan, ') . $place, $title, $place, self::reviewedMail($plan, $who, $ok, $comment, $place));
    }

    /** The body of the "approved / sent back" email (pure: no sending, so it can be rendered in a test). */
    public static function reviewedMail(object $plan, string $who, bool $ok, string $comment, string $place): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return '<p style="margin:0 0 10px;font-size:15px;color:#334155">' . ($ok
                ? 'Your fire plan for <b>' . $e($place) . '</b> (version ' . (int) $plan->version . ') was approved' . ($who !== '' ? ' by ' . $e($who) : '') . '. It is now the plan everyone follows, prints and posts.'
                : ($who !== '' ? $e($who) : 'Your reviewer') . ' asked for changes to your fire plan for <b>' . $e($place) . '</b> (version ' . (int) $plan->version . '). Open the plan, make the changes and send the new version for review.') . '</p>'
            . ($comment !== '' ? EmailTemplate::calloutBox('<b>' . ($who !== '' ? $e($who) : 'Comment') . ':</b> ' . nl2br($e($comment)), $ok ? 'success' : 'warning') : '')
            . self::button((int) $plan->id);
    }

    /**
     * One staff email about fire plans, through the agency's mailer in the house template (dark-mode rules included).
     * Skipped when the person switched fire plan emails off, the agency switched drill notices off for their role,
     * the address is a service one, or the agency is a test agency.
     */
    public static function email(int $agencyId, int $uid, string $subject, string $title, string $subtitle, string $bodyHtml): bool
    {
        if (TestAgency::blocks($agencyId, $uid)) return false;
        if (! StaffNotifyPrefs::wants($uid, 'staff_fire_plans', 'email', $agencyId)) return false;
        if (! StaffTopicNotify::allows($agencyId, 'drills', $uid)) return false;
        $u = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first(['email', 'first_name']);
        if (! $u || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL) || ChatAudience::isServiceEmail((string) $u->email)) return false;
        $hi = trim((string) $u->first_name) !== '' ? '<p style="margin:0 0 10px;font-size:15px;color:#334155">Hi ' . htmlspecialchars((string) $u->first_name, ENT_QUOTES, 'UTF-8') . ',</p>' : '';
        $html = EmailTemplate::wrap($agencyId, $hi . $bodyHtml, ['eyebrow' => 'SAFETY', 'title' => $title, 'subtitle' => $subtitle]);
        try {
            AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to((string) $u->email)->subject($subject));
            return true;
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('fire plan email failed', ['user' => $uid, 'error' => $ex->getMessage()]);
            return false;
        }
    }

    /**
     * Current plans (the newest approved version of each place) that have gone 12 months without a review, in one
     * agency. A place whose newer version is already waiting for review is left out: the review queue has it.
     */
    public static function reviewDue(int $agencyId, ?Carbon $now = null): array
    {
        if (! Schema::hasTable('fire_plans')) return [];
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $now = $now ?: Carbon::now($tz);
        $yearAgo = $now->copy()->subYear()->toDateString();
        $centres = Live::centreIds($agencyId);
        if (! $centres) return [];
        $rows = DB::table('fire_plans')->where('agency_id', $agencyId)->whereIn('centre_id', $centres)->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'submitted'])->orderByDesc('version')->get();
        $out = [];
        foreach ($rows->groupBy(fn ($r) => $r->centre_id . '|' . ($r->room_id ?? '')) as $place) {
            $current = $place->firstWhere('status', 'approved');
            if (! $current) continue;
            if ($place->contains(fn ($r) => $r->status === 'submitted' && (int) $r->version > (int) $current->version)) continue;
            $rev = self::reviewedOn($current);
            if ($rev && $rev >= $yearAgo) continue;
            $out[] = $current;
        }

        return $out;
    }
}
