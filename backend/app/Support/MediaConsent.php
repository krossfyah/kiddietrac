<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHOTO CONSENT, PER CHILD - THE ONE RULE (2026-11-06).
 *
 * Anthony: "wire this up and add guards for all new agencies as well so we never run into this issue ever again".
 * Onboarding asked each parent "OK with classroom photos?" and saved the answer on the PARENT, where nothing read it:
 * a family that answered "No, please do not photograph my child" had that child tagged in 19 photos, one of them a
 * group photo another family could see. He chose:
 *   - one answer per parent per child, three levels: none (do not photograph), family (photos for my family only),
 *     group (may appear in photos other families see). Marketing is a separate yes/no, never implied;
 *   - parents disagree -> ANY NO WINS: the child's level is the most restrictive answer given;
 *   - no answer yet -> FAMILY: the child's own family still gets photos, but the child is kept out of photos anyone
 *     else sees, and the parents are asked (kt-photo-consent.js) - silence is not consent;
 *   - a child at "none" cannot be tagged, and photos already tagging them are hidden from everyone (kept, not deleted).
 *
 * Every place a photo reaches a family goes through visibleTo()/filter(); every tag goes through untaggable();
 * notifications and timeline entries go only to familiesWhoMaySee(). The nightly kt:photo-consent-check enforces
 * the data rule and fails on any code that reads the photos table without being listed in READERS below.
 * It applies to every agency, new ones included: there is no switch to turn it off.
 *
 * THE PRIMARY PARENT DECIDES (2026-11-06, Anthony: "the primary parent should be the one allowed to choose the photo
 * permissions"; asked about a second parent's "do not photograph", he chose "primary decides everything"). Only the
 * answers of the family's primary parent(s) (guardians.is_primary) count, see decidersFor(); other parents see the
 * answer and are not asked. A family with no primary parent set falls back to every parent (the old rule), and two
 * primaries still resolve to the more careful answer. Answers other parents gave earlier stay on record, unused.
 */
final class MediaConsent
{
    public const NONE = 'none';
    public const FAMILY = 'family';
    public const GROUP = 'group';
    public const RANK = [self::NONE => 0, self::FAMILY => 1, self::GROUP => 2];

    public const LABELS = [
        self::NONE => 'Do not photograph my child',
        self::FAMILY => 'Photos for my family only',
        self::GROUP => 'Photos, including group photos other families see',
    ];

    /**
     * Every file that reads the photos table, and why it is safe. kt:photo-consent-check fails on any other file:
     * a new reader must either apply this class or say why it does not need to.
     */
    public const READERS = [
        'Support/MediaConsent.php' => 'this rule',
        'Http/Controllers/Api/PhotoFeedController.php' => 'parents: filter(); staff: hidden excluded; tags: untaggable()',
        'Http/Controllers/Api/MediaController.php' => 'child gallery: filter() for a parent, hidden excluded for staff',
        'Console/Commands/ParentDailySummaryCommand.php' => 'daily email to a family: filter()',
        'Http/Controllers/Api/PhotoTagController.php' => 'AI tagging: refuses hidden photos and photos of a "none" child; "none" children never sent',
        'Console/Commands/KtPhotoConsentCheck.php' => 'this guard',
        'Http/Controllers/Api/MediaConsentController.php' => 'counts for the Photo permissions screen',
        'Http/Controllers/Api/DayBriefController.php' => 'staff day brief (centre staff only); hidden excluded',
        'Http/Controllers/Api/RecordHistoryController.php' => 'the agency record of a child, staff only - hidden photos stay on the record by design',
        'Console/Commands/EducatorDailySummaryCommand.php' => 'a count for the educator',
        'Http/Controllers/Api/WidgetsController.php' => 'the parent "photos this week" count: filter()',
        'Http/Controllers/Api/PulseController.php' => 'the newest id, for change polling',
        'Http/Controllers/Api/LicenceController.php' => 'a usage count',
        'Console/Commands/MediaThumbsCommand.php' => 'file maintenance (thumbnails)',
        'Console/Commands/MediaFastStartCommand.php' => 'file maintenance (video index)',
        'Console/Commands/SeedDemoDaily.php' => 'demo seeding (writes)',
        'Support/WebDemoContent.php' => 'demo seeding (writes)',
        'Support/RecordReminders.php' => 'a count',
        'Support/EducatorPoints.php' => 'a count',
        'Console/Commands/PortalTipsCommand.php' => 'a count',
    ];

    /**
     * Who chooses for each child: [child_id => [user_id, ...]]. The primary parent(s) of the child's family; every
     * parent when the family has no primary set.
     */
    public static function decidersFor(array $childIds): array
    {
        $childIds = array_values(array_unique(array_filter(array_map('intval', $childIds))));
        if (! $childIds) return [];
        $out = array_fill_keys($childIds, []);
        $g = DB::table('children as c')->join('guardians as g', 'g.family_id', '=', 'c.family_id')
            ->whereIn('c.id', $childIds)->whereNotNull('g.user_id')->get(['c.id', 'g.user_id', 'g.is_primary']);
        foreach ($g->groupBy('id') as $c => $rows) {
            $prim = $rows->where('is_primary', 1);
            $out[(int) $c] = ($prim->count() ? $prim : $rows)->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        }

        return $out;
    }

    public static function decides(int $childId, int $userId): bool
    {
        return in_array($userId, self::decidersFor([$childId])[$childId] ?? [], true);
    }

    /**
     * Effective permission per child: [child_id => ['level', 'answered' (int), 'parents' (int), 'marketing' (bool)]].
     * 'parents' and 'answered' count the deciders only (the primary parent, see decidersFor()).
     */
    public static function forChildren(array $childIds): array
    {
        $childIds = array_values(array_unique(array_filter(array_map('intval', $childIds))));
        if (! $childIds) return [];
        $out = [];
        foreach ($childIds as $c) $out[$c] = ['level' => self::FAMILY, 'answered' => 0, 'parents' => 0, 'marketing' => false];
        $deciders = self::decidersFor($childIds);
        foreach ($deciders as $c => $ids) $out[(int) $c]['parents'] = count($ids);
        if (! Schema::hasTable('child_media_consents')) return $out;
        $rows = DB::table('child_media_consents')->whereIn('child_id', $childIds)->get(['child_id', 'user_id', 'level', 'marketing']);
        $mk = [];
        foreach ($rows as $r) {
            $c = (int) $r->child_id;
            if (! in_array((int) $r->user_id, $deciders[$c] ?? [], true)) continue;   // not the primary parent: on record, not counted
            $lvl = isset(self::RANK[$r->level]) ? $r->level : self::NONE;   // an unknown value fails closed
            // Two primary parents: the more careful answer applies.
            $out[$c]['level'] = $out[$c]['answered'] === 0 ? $lvl : (self::RANK[$lvl] < self::RANK[$out[$c]['level']] ? $lvl : $out[$c]['level']);
            $out[$c]['answered']++;
            $mk[$c] = ($mk[$c] ?? true) && (bool) $r->marketing;
        }
        foreach ($mk as $c => $v) $out[$c]['marketing'] = $v && $out[$c]['level'] !== self::NONE;

        return $out;
    }

    public static function levelOf(int $childId): string
    {
        return self::forChildren([$childId])[$childId]['level'] ?? self::FAMILY;
    }

    /** The ids a photo row tags. */
    public static function tagged(object $photo): array
    {
        return array_values(array_unique(array_filter(array_map('intval', json_decode((string) ($photo->child_ids ?? '[]'), true) ?: []))));
    }

    /**
     * May the family whose children are $viewerChildIds see this photo? Not if it is hidden, not if it tags a child at
     * "none", and not if it tags any OTHER family's child who is not at "group". $levels from forChildren().
     */
    public static function visibleTo(object $photo, array $viewerChildIds, array $levels): bool
    {
        if (! empty($photo->hidden_at)) return false;
        $mine = array_flip(array_map('intval', $viewerChildIds));
        foreach (self::tagged($photo) as $c) {
            $lvl = $levels[$c]['level'] ?? self::FAMILY;
            if ($lvl === self::NONE) return false;
            if (! isset($mine[$c]) && $lvl !== self::GROUP) return false;
        }

        return true;
    }

    /** Keep only the photos this family may see (rows need child_ids and hidden_at). */
    public static function filter(Collection $photos, array $viewerChildIds): Collection
    {
        $levels = self::forChildren($photos->flatMap(fn ($p) => self::tagged($p))->all());

        return $photos->filter(fn ($p) => self::visibleTo($p, $viewerChildIds, $levels))->values();
    }

    /** Every child in the families of these children (a family sees with all its children). */
    public static function familyChildren(array $familyIds): array
    {
        return DB::table('children')->whereIn('family_id', $familyIds ?: [0])->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** The families allowed to see a photo tagging these children: [family_id => child ids of theirs on it]. */
    public static function familiesWhoMaySee(object $photo): array
    {
        $tagged = self::tagged($photo);
        if (! $tagged) return [];
        $levels = self::forChildren($tagged);
        $byFamily = [];
        foreach (DB::table('children')->whereIn('id', $tagged)->pluck('family_id', 'id') as $cid => $fid) if ($fid) $byFamily[(int) $fid][] = (int) $cid;
        $ok = [];
        foreach ($byFamily as $fid => $kids) {
            if (self::visibleTo($photo, self::familyChildren([$fid]), $levels)) $ok[$fid] = $kids;
        }

        return $ok;
    }

    /** Refuse a tag on a child whose family said no. Returns the friendly message, or null when every child is fine. */
    public static function untaggable(array $childIds): ?string
    {
        $levels = self::forChildren($childIds);
        $no = array_keys(array_filter($levels, fn ($l) => $l['level'] === self::NONE));
        if (! $no) return null;
        $names = DB::table('children')->whereIn('id', $no)->pluck('first_name')->filter()->implode(', ');

        return ($names ?: 'One of these children') . ($names && count($no) > 1 ? ' - their families have' : ' - their family has')
            . ' asked that they not be photographed. Untag them to share this photo.';
    }

    /** For the person who shared a group photo: whose families will not see it, and why. First names only. */
    public static function keptFromNotice(array $childIds): ?string
    {
        $photo = (object) ['child_ids' => json_encode(array_values($childIds)), 'hidden_at' => null];
        $seen = array_merge([], ...array_values(self::familiesWhoMaySee($photo)));
        $out = array_values(array_diff($childIds, $seen));
        if (! $out) return null;
        $levels = self::forChildren($childIds);
        $why = array_keys(array_filter($levels, fn ($l) => $l['level'] === self::FAMILY));
        $names = DB::table('children')->whereIn('id', array_merge($out, $why))->pluck('first_name', 'id');
        // "A", "A and B", "A, B and C"
        $list = function (array $ids) use ($names): string {
            $n = array_values(array_filter(array_map(fn ($i) => $names[$i] ?? null, $ids)));
            return count($n) < 2 ? (string) ($n[0] ?? 'a child') : implode(', ', array_slice($n, 0, -1)) . ' and ' . end($n);
        };
        $are = count($why) === 1 ? ' is' : ' are';
        if (count($out) === count(array_unique($childIds))) {
            return 'Saved, but no family will see it: ' . $list($why) . $are . ' set to family-only photos, so a photo of them together '
                . 'goes to no one. Share a photo of each child on their own.';
        }

        return 'Shared, but not with ' . $list($out) . (count($out) === 1 ? '\'s family' : '\'s families') . ': '
            . $list($why) . $are . ' set to family-only photos.';
    }

    /**
     * Save one parent's answer for one child. Returns the child's new effective level. A child who becomes "none" has
     * every photo tagging them hidden at once (kept on file), so a withdrawal takes effect immediately.
     */
    public static function record(int $childId, int $userId, string $level, bool $marketing, string $source, ?int $recordedBy = null, ?string $note = null): string
    {
        abort_unless(isset(self::RANK[$level]), 422, 'Choose one of the photo options.');
        $before = self::levelOf($childId);
        $now = now();
        $exists = DB::table('child_media_consents')->where('child_id', $childId)->where('user_id', $userId)->exists();
        $row = ['level' => $level, 'marketing' => $level === self::NONE ? false : $marketing, 'source' => $source,
            'recorded_by_id' => $recordedBy, 'note' => $note ? mb_substr($note, 0, 300) : null, 'updated_at' => $now];
        if ($exists) DB::table('child_media_consents')->where('child_id', $childId)->where('user_id', $userId)->update($row);
        else DB::table('child_media_consents')->insert($row + ['child_id' => $childId, 'user_id' => $userId, 'created_at' => $now]);
        $eff = self::levelOf($childId);
        $hidden = $eff === self::NONE ? self::hidePhotosOf($childId, 'no_consent', $recordedBy ?? $userId) : 0;
        if ($eff !== $before) self::tellStaff($childId, $eff, $hidden, $recordedBy ?? $userId);

        return $eff;
    }

    /**
     * The people holding the camera hear about it (2026-11-06, Anthony: alert on change). When a child's permission
     * CHANGES, the educators of the child's room(s) and the centre's directors get a bell + push (Notify), first name
     * only. Not on an answer that leaves it where it was, and never from the backfill (it does not come through here).
     * Respects the agency's notification switch. Never fails the save.
     */
    public static function tellStaff(int $childId, string $level, int $hidden = 0, ?int $actorId = null): int
    {
        try {
            $c = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('c.id', $childId)->first(['c.first_name', 'f.centre_id', 'ce.agency_id']);
            if (! $c || ! Suppression::agencyNotificationsEnabled((int) $c->agency_id)) return 0;
            $rooms = DB::table('enrollments')->where('child_id', $childId)->whereNull('end_date')->pluck('room_id')->filter()->all();
            $who = DB::table('educator_rooms')->whereIn('room_id', $rooms ?: [0])->pluck('user_id')
                ->merge(DB::table('role_assignments')->where('agency_id', $c->agency_id)->where('role', 'centre_director')->where('active', 1)
                    ->where(fn ($q) => $q->where('centre_id', $c->centre_id)->orWhereNull('centre_id'))->pluck('user_id'))
                ->map(fn ($v) => (int) $v)->unique()->reject(fn ($v) => $v === (int) $actorId)->values()->all();
            if (! $who) return 0;
            $name = $c->first_name ?: 'A child';
            $body = [
                self::NONE => $name . '\'s family asked that they not be photographed. Do not take or share photos or videos of them.'
                    . ($hidden ? ' ' . $hidden . ' existing photo' . ($hidden === 1 ? ' is' : 's are') . ' now hidden from families.' : ''),
                self::FAMILY => $name . ' is now in photos for their own family only. Keep them out of group photos other families see.',
                self::GROUP => $name . ' may now appear in group photos other families see.',
            ][$level] ?? '';
            $rows = array_map(fn ($uid) => ['user_id' => $uid, 'type' => 'photo_permission',
                'title' => ($level === self::NONE ? '🚫' : ($level === self::FAMILY ? '👪' : '✅')) . ' Photo permission: ' . $name,
                'body' => $body,
                'data' => json_encode(['link' => '#photo-permissions', 'child_id' => $childId, 'level' => $level, 'kt_category' => 'staff',
                    'severity' => $level === self::NONE ? AlertSeverity::WARNING : AlertSeverity::INFO])], $who);
            Notify::write($rows);

            return count($rows);
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    /** Hide every visible photo tagging this child, and the timeline entries that point at them. Returns the count. */
    public static function hidePhotosOf(int $childId, string $reason, ?int $byId): int
    {
        $ids = DB::table('photos')->whereNull('hidden_at')->whereJsonContains('child_ids', $childId)->pluck('id')->all();
        if (! $ids) return 0;
        DB::table('photos')->whereIn('id', $ids)->update(['hidden_at' => now(), 'hidden_reason' => $reason, 'hidden_by_id' => $byId]);
        DB::table('daily_events')->whereIn('photo_id', $ids)->whereNull('deleted_at')->update(['deleted_at' => now()]);

        return count($ids);
    }

    /** The children a parent still has to answer for - only those they decide for: [['id','first_name','family_id']]. */
    public static function pendingFor(int $userId): array
    {
        $fams = DB::table('guardians')->where('user_id', $userId)->pluck('family_id')->filter()->all();
        if (! $fams) return [];
        $done = DB::table('child_media_consents')->where('user_id', $userId)->pluck('child_id')->map(fn ($v) => (int) $v)->all();
        $kids = DB::table('children')->whereIn('family_id', $fams)->whereNull('deleted_at')->whereNull('withdrawn_at')
            ->whereNotIn('id', $done ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'family_id']);
        $deciders = self::decidersFor($kids->pluck('id')->all());

        return $kids->filter(fn ($c) => in_array($userId, $deciders[(int) $c->id] ?? [], true))->map(fn ($c) => (array) $c)->values()->all();
    }

    /**
     * One-off: the onboarding answer each parent gave ("OK with classroom photos?", users.profile_extras) becomes
     * their answer for every child in their families. Yes -> group (classroom photos are group photos);
     * No -> none. Marketing is never inferred. An answer already given here is kept.
     */
    public static function backfill(bool $dry = false): array
    {
        $out = ['answers' => 0, 'none' => 0, 'group' => 0, 'hidden' => 0, 'skipped' => 0];
        foreach (DB::table('users')->where('profile_extras', 'like', '%photo_consent%')->get(['id', 'profile_extras']) as $u) {
            $x = json_decode((string) $u->profile_extras, true) ?: [];
            $v = $x['role_extras']['photo_consent'] ?? ($x['photo_consent'] ?? null);
            if ($v === null || trim((string) $v) === '') continue;
            $level = preg_match('/^\s*no\b/i', (string) $v) ? self::NONE : (preg_match('/^\s*yes\b/i', (string) $v) ? self::GROUP : null);
            if (! $level) { $out['skipped']++; continue; }
            $fams = DB::table('guardians')->where('user_id', $u->id)->pluck('family_id')->filter()->all();
            foreach (DB::table('children')->whereIn('family_id', $fams ?: [0])->whereNull('deleted_at')->pluck('id') as $cid) {
                if (DB::table('child_media_consents')->where('child_id', $cid)->where('user_id', $u->id)->exists()) { $out['skipped']++; continue; }
                $out['answers']++; $out[$level]++;
                if (! $dry) {
                    DB::table('child_media_consents')->insert(['child_id' => (int) $cid, 'user_id' => (int) $u->id, 'level' => $level, 'marketing' => false,
                        'source' => 'onboarding', 'note' => 'From the onboarding answer: ' . mb_substr((string) $v, 0, 120), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        if (! $dry) {
            foreach (DB::table('child_media_consents')->where('level', self::NONE)->distinct()->pluck('child_id') as $cid) {
                if (self::levelOf((int) $cid) === self::NONE) $out['hidden'] += self::hidePhotosOf((int) $cid, 'no_consent', null);
            }
        }

        return $out;
    }
}
