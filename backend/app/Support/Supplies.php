<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SUPPLIES FROM HOME (2026-10-23, Anthony: "if an educator needs to request supplies from
 * parent how can we wire this up to allow this to happen easily for each child?").
 *
 * An educator (or a director / agency admin) ticks what a child needs from the agency's
 * list, or writes anything under "Other". The family hears about it four ways, as Anthony
 * chose: push, email, a task on each parent's list, and an announcement (family-scoped,
 * so only they see it) with the reminder the evening before it is needed. The parent
 * answers Will bring / Dropped it off / Can't; staff mark it received at drop-off; One-Tap
 * check-in asks "Did you bring…?". "Can't" and a missed day tell whoever asked.
 *
 * Statuses: open → coming | cant | dropped (the parent) → received (staff); cancelled.
 * Pending = open, coming, cant. Once nothing on an announcement is pending, its tasks are
 * closed and it is marked done for the family, which stops the reminder.
 */
class Supplies
{
    public const PENDING = ['open', 'coming', 'cant'];

    public const DEFAULT_LIST = [
        ['name' => 'Diapering', 'items' => ['Diapers', 'Wipes', 'Diaper cream', 'Pull-ups']],
        ['name' => 'Clothing', 'items' => ['Spare clothes', 'Socks', 'Extra layer', 'Indoor shoes']],
        ['name' => 'Outdoor & seasonal', 'items' => ['Sunscreen', 'Sun hat', 'Bug spray', 'Mittens', 'Snow pants', 'Rain boots']],
        ['name' => 'Food & sleep', 'items' => ['Formula', 'Bottles', 'Milk', 'Crib sheet', 'Blanket', 'Comfort item']],
    ];

    /** The agency's list (agencies.settings.supplies), or the default. */
    public static function catalogue(?int $agencyId): array
    {
        if ($agencyId) {
            try {
                $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
                if (is_array($s) && ! empty($s['supplies']) && is_array($s['supplies'])) return $s['supplies'];
            } catch (Throwable $e) {
            }
        }
        return self::DEFAULT_LIST;
    }

    public static function saveCatalogue(int $agencyId, array $groups): array
    {
        $clean = [];
        foreach ($groups as $g) {
            $name = mb_substr(trim((string) ($g['name'] ?? '')), 0, 40);
            $items = array_values(array_unique(array_filter(array_map(fn ($i) => mb_substr(trim((string) $i), 0, 40), (array) ($g['items'] ?? [])))));
            if ($name !== '' && $items) $clean[] = ['name' => $name, 'items' => array_slice($items, 0, 30)];
        }
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        if (! is_array($s)) $s = [];
        if ($clean) $s['supplies'] = array_slice($clean, 0, 12); else unset($s['supplies']);   // empty = back to the default
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s)]);
        return self::catalogue($agencyId);
    }

    public static function childName(object $c): string
    {
        return trim((string) ($c->preferred_name ?: $c->first_name)) ?: 'your child';
    }

    public static function itemsText(array $items): string
    {
        return implode(', ', array_map(fn ($i) => $i['label'] . (! empty($i['note']) ? ' (' . $i['note'] . ')' : ''), $items));
    }

    /**
     * Create requests for several children. $items [{label, note}]. Children of one family
     * share one announcement and one task per parent. Returns the new request ids.
     */
    public static function create(array $childIds, array $items, ?string $note, ?string $neededBy, object $sender): array
    {
        $kids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->whereIn('c.id', $childIds)->whereNull('c.deleted_at')
            ->get(['c.id', 'c.first_name', 'c.preferred_name', 'c.family_id', 'f.centre_id', 'ce.agency_id', 'ce.name as centre_name']);
        $ids = [];
        foreach ($kids->groupBy('family_id') as $familyId => $group) {
            $first = $group->first();
            $agencyId = (int) $first->agency_id;
            $rows = [];
            foreach ($group as $k) {
                $rows[$k->id] = DB::table('supply_requests')->insertGetId([
                    'agency_id' => $agencyId, 'centre_id' => $k->centre_id, 'family_id' => (int) $familyId, 'child_id' => $k->id,
                    'requested_by' => $sender->id ?? null, 'items' => json_encode(array_values($items)),
                    'note' => $note ?: null, 'needed_by' => $neededBy ?: null, 'status' => 'open',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $ids[] = $rows[$k->id];
            }
            $names = $group->map(fn ($k) => self::childName($k))->values()->all();
            $who = count($names) > 1 ? implode(' and ', [implode(', ', array_slice($names, 0, -1)), end($names)]) : $names[0];
            try {
                $annId = self::announce((int) $familyId, $agencyId, $who, $items, $note, $neededBy, $sender, (string) $first->centre_name);
                DB::table('supply_requests')->whereIn('id', array_values($rows))->update(['announcement_id' => $annId]);
                self::tasks((int) $familyId, $agencyId, (int) $first->centre_id, $annId, $who, $items, $note, $neededBy, $sender);
            } catch (Throwable $e) {
                Log::error('Supply request notice failed', ['family' => $familyId, 'error' => $e->getMessage()]);
            }
        }
        return $ids;
    }

    /** The family-scoped announcement: push + email now, a reminder the evening before. */
    private static function announce(int $familyId, int $agencyId, string $who, array $items, ?string $note, ?string $neededBy, object $sender, string $centreName): int
    {
        $tz = AgencyTime::tz($agencyId);
        $list = '<ul>' . implode('', array_map(fn ($i) => '<li><strong>' . e($i['label']) . '</strong>' . (! empty($i['note']) ? ' — ' . e($i['note']) : '') . '</li>', $items)) . '</ul>';
        $body = '<p>Please bring the following for <strong>' . e($who) . '</strong>:</p>' . $list
            . ($note ? '<p>' . nl2br(e($note)) . '</p>' : '')
            . '<p>Let us know in the app: <strong>Will bring it</strong>, <strong>Dropped it off</strong>, or <strong>Can\'t</strong>.</p>';
        $due = $neededBy ? Carbon::parse($neededBy . ' 09:00:00', $tz)->utc() : null;
        // The reminder: 6 PM the evening before it is needed; with no date, tomorrow evening.
        $remind = $neededBy ? Carbon::parse($neededBy . ' 18:00:00', $tz)->subDay() : Carbon::now($tz)->addDay()->setTime(18, 0);
        $remind = $remind->utc();
        $title = 'Supplies needed for ' . $who;
        $annId = DB::table('announcements')->insertGetId([
            'scope_type' => 'family', 'scope_id' => $familyId, 'audience' => 'parents',
            'title' => $title, 'body' => $body, 'send_email' => 1, 'send_sms' => 0, 'send_push' => 1, 'send_voice' => 0,
            'due_at' => $due ? $due->format('Y-m-d H:i:s') : null, 'remind_who' => 'pending',
            'sent_at' => now(), 'created_by_id' => $sender->id ?? null, 'created_at' => now(),
        ]);
        if ($remind->gt(now()->addMinutes(5))) {
            DB::table('announcement_reminders')->insert(['announcement_id' => $annId, 'remind_at' => $remind->format('Y-m-d H:i:s'), 'created_at' => now()]);
        }
        app(\App\Http\Controllers\Api\AnnouncementController::class)->sendFamilyNotice($annId, [
            'scope_type' => 'family', 'scope_id' => $familyId, 'audience' => 'parents', 'title' => $title, 'body' => $body,
            'send_email' => true, 'send_sms' => false, 'send_push' => true, 'send_voice' => false, 'voice_category' => null,
            'image' => null, 'pdf' => null, 'due_at' => $due ? $due->format('Y-m-d H:i:s') : null, 'remind_who' => 'pending',
            'notif_title' => '🧺 Supplies needed · ' . ($centreName ?: 'your centre'), 'link' => '#home',
        ], $sender);
        return (int) $annId;
    }

    /** A task on each parent's list (the parents of this family with a login). */
    private static function tasks(int $familyId, int $agencyId, int $centreId, int $annId, string $who, array $items, ?string $note, ?string $neededBy, object $sender): void
    {
        $parents = DB::table('guardians')->where('family_id', $familyId)->whereNotNull('user_id')->pluck('user_id')->unique();
        foreach ($parents as $uid) {
            DB::table('tasks')->insert([
                'agency_id' => $agencyId, 'centre_id' => $centreId ?: null, 'assigned_to' => (int) $uid, 'assigned_by' => $sender->id ?? null,
                'title' => mb_substr('🧺 Bring for ' . $who . ': ' . self::itemsText($items), 0, 200),
                'description' => $note ?: 'Requested by the centre. Mark it done once you have dropped it off.',
                'priority' => 'normal', 'due_date' => $neededBy ?: null, 'status' => 'open',
                'source' => 'supplies', 'source_id' => $annId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Set statuses, then close the tasks / reminder once nothing on the announcement is pending. */
    public static function setStatus(array $requestIds, string $status, int $userId, ?string $note = null): void
    {
        $rows = DB::table('supply_requests')->whereIn('id', $requestIds)->get();
        if (! $rows->count()) return;
        $upd = ['status' => $status, 'updated_at' => now()];
        if (in_array($status, ['coming', 'cant', 'dropped'], true)) { $upd['responded_by'] = $userId; $upd['responded_at'] = now(); if ($note !== null) $upd['response_note'] = mb_substr($note, 0, 500); }
        if ($status === 'received') { $upd['received_by'] = $userId; $upd['received_at'] = now(); }
        DB::table('supply_requests')->whereIn('id', $rows->pluck('id'))->update($upd);
        if ($status === 'cant') self::tellRequester($rows, 'can\'t bring', $note);
        foreach ($rows->pluck('announcement_id')->filter()->unique() as $annId) self::settle((int) $annId, $userId);
    }

    private static function settle(int $annId, int $userId): void
    {
        $pending = DB::table('supply_requests')->where('announcement_id', $annId)->whereIn('status', self::PENDING)->exists();
        if ($pending) return;
        DB::table('tasks')->where('source', 'supplies')->where('source_id', $annId)->where('status', '!=', 'done')
            ->update(['status' => 'done', 'completed_at' => now(), 'updated_at' => now()]);
        $fam = DB::table('announcements')->where('id', $annId)->value('scope_id');
        $doer = DB::table('guardians')->where('family_id', $fam)->where('user_id', $userId)->exists()
            ? $userId : DB::table('guardians')->where('family_id', $fam)->whereNotNull('user_id')->value('user_id');
        if ($doer) DB::table('announcement_done')->updateOrInsert(['announcement_id' => $annId, 'user_id' => $doer], ['done_at' => now()]);
    }

    /** The parent tapped Done on the announcement or the task: it was dropped off. */
    public static function doneByParent(int $annId, int $userId): void
    {
        $ids = DB::table('supply_requests')->where('announcement_id', $annId)->whereIn('status', self::PENDING)->pluck('id')->all();
        if ($ids) self::setStatus($ids, 'dropped', $userId);
    }

    private static function tellRequester($rows, string $what, ?string $note): void
    {
        foreach ($rows as $r) {
            if (! $r->requested_by) continue;
            $c = DB::table('children')->where('id', $r->child_id)->first(['first_name', 'preferred_name']);
            $items = json_decode((string) $r->items, true) ?: [];
            $title = '🧺 ' . ($c ? self::childName($c) : 'A child') . '\'s family ' . $what . ' ' . self::itemsText($items);
            try {
                Notify::write([['user_id' => (int) $r->requested_by, 'type' => 'supplies', 'title' => mb_substr($title, 0, 190),
                    'body' => $note ? 'They said: ' . $note : 'You may want to call them.', 'data' => json_encode(['link' => '#child-detail?id=' . $r->child_id]), 'created_at' => now()]]);
                app(\App\Services\FcmService::class)->sendToUser((int) $r->requested_by, mb_substr($title, 0, 120), $note ?: 'You may want to call them.', '#notifications', true);
            } catch (Throwable $e) {
            }
        }
    }

    /** Hourly: a request still pending after its day tells whoever asked, once. */
    public static function overdue(bool $dry = false): array
    {
        $out = [];
        $rows = DB::table('supply_requests')->whereIn('status', self::PENDING)->whereNotNull('needed_by')->whereNull('overdue_notified_at')->limit(200)->get();
        foreach ($rows as $r) {
            if ((string) $r->needed_by >= Carbon::now(AgencyTime::tz((int) $r->agency_id))->toDateString()) continue;
            $out[] = '#' . $r->id . ' child ' . $r->child_id . ' needed ' . $r->needed_by;
            if ($dry) continue;
            DB::table('supply_requests')->where('id', $r->id)->update(['overdue_notified_at' => now()]);
            self::tellRequester(collect([$r]), 'still hasn\'t brought', null);
        }
        return $out;
    }

    /** One request as the screens use it. */
    public static function shape(object $r, array $names = []): array
    {
        return [
            'id' => (int) $r->id, 'child_id' => (int) $r->child_id, 'child_name' => $names[$r->child_id] ?? null,
            'items' => json_decode((string) $r->items, true) ?: [], 'note' => $r->note, 'needed_by' => $r->needed_by,
            'status' => $r->status, 'response_note' => $r->response_note, 'responded_at' => $r->responded_at ? Carbon::parse($r->responded_at)->toIso8601String() : null,
            'received_at' => $r->received_at ? Carbon::parse($r->received_at)->toIso8601String() : null,
            'requested_by_name' => $r->requested_by_name ?? null, 'created_at' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null,
        ];
    }
}
