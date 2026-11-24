<?php

namespace App\Console\Commands;

use App\Support\NotifyAudience;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every notification kind says who it is for (2026-11-06, App\Support\NotifyAudience).
 *
 * Anthony: "add guards to ensure roles and responsibilities see their relevant settings". A parent was shown staff
 * reminders, headcounts and the staff mailbox in Settings → Notifications, and an account switched from educator to
 * parent kept getting staff clock reminders and team chat. The lists and Notify::write now go by NotifyAudience;
 * this fails, nightly, when:
 *
 *   1. a kind in a settings list (sound categories, optional texts, call reasons, "Notify me about" events) or in
 *      Notify's push-category map has no audience;
 *   2. a bell row type written in the last 7 days has no audience (a new kind, counted as "all" until mapped);
 *   3. a staff-only row from the last 24 hours went to somebody with no active staff role (the sender guard failed);
 *   4. the settings screen lists one of those kinds without ktForRole.
 *
 * An alert to the platform admins on a failure; --no-alert for trials.
 */
class KtNotifyAudienceCheck extends Command
{
    protected $signature = 'kt:notify-audience-check {--quiet-ok} {--no-alert : report only} {--root= : parent-portal folder to scan (default: the live one)}';

    protected $description = 'Fail on a notification kind with no audience (staff / family / all), or a staff-only notification sent to a non-staff account.';

    public function handle(): int
    {
        $bad = [];

        // 1. every listed kind has an audience
        $lists = [
            'Sounds & vibration category' => [array_keys(\App\Support\NotificationSounds::CATEGORIES), NotifyAudience::SOUND_CATEGORIES],
            'Optional text' => [array_keys(\App\Support\ContactCategories::SMS), NotifyAudience::SMS],
            'Call reason' => [array_keys(\App\Http\Controllers\Api\VoiceController::CATEGORIES), NotifyAudience::VOICE],
            '"Notify me about" event' => [array_keys(\App\Http\Controllers\Api\NotificationPrefsController::EVENTS), NotifyAudience::PREF_EVENTS],
        ];
        try {
            $tc = (new \ReflectionClass(\App\Support\Notify::class))->getReflectionConstant('TYPE_CATEGORY');
            $lists['Bell type (Notify::TYPE_CATEGORY)'] = [array_keys($tc ? (array) $tc->getValue() : []), NotifyAudience::TYPES];
        } catch (\Throwable $e) {
            $bad['Could not read Notify::TYPE_CATEGORY'][] = $e->getMessage();
        }
        foreach ($lists as $what => [$keys, $map]) {
            foreach ($keys as $k) {
                if (! isset($map[$k]) || ! in_array($map[$k], ['staff', 'family', 'all'], true)) {
                    $bad[$what . ' with no audience in NotifyAudience'][] = $k;
                }
            }
        }

        // 2. new bell types
        $seen = DB::table('notifications')->where('created_at', '>=', now()->subDays(7))->distinct()->pluck('type')->all();
        foreach ($seen as $t) {
            if ($t !== null && $t !== '' && ! isset(NotifyAudience::TYPES[$t])) {
                $bad['Bell type written this week with no audience (add it to NotifyAudience::TYPES)'][] = $t;
            }
        }

        // 3. staff-only rows that reached somebody who is not staff
        $staffTypes = array_keys(array_filter(NotifyAudience::TYPES, fn ($a) => $a === 'staff'));
        // TEST AGENCIES ARE NOT ALERTED ON (2026-11-09, Anthony: "alerts for test agency's should all be turnt off"): people only in test agencies are left out.
        $leaks = DB::table('notifications as n')->where('n.created_at', '>=', now()->subDay())->whereIn('n.type', $staffTypes)
            ->whereNotIn('n.user_id', \App\Support\TestAgencies::testOnlyUserIds() ?: [0])
            ->whereNotExists(function ($q) {
                $q->from('role_assignments as ra')->whereColumn('ra.user_id', 'n.user_id')->where('ra.active', true)
                    ->whereIn('ra.role', NotifyAudience::STAFF_ROLES);
            })
            ->groupBy('n.type')->selectRaw('n.type, count(*) as c, count(distinct n.user_id) as u')->get();
        foreach ($leaks as $l) {
            $bad['Staff-only notifications sent to non-staff in the last 24h'][] = $l->type . ': ' . $l->c . ' to ' . $l->u . ' account(s)';
        }

        // 4. the settings screen narrows every list
        $root = rtrim($this->option('root') ?: base_path('../parent-portal'), '/');
        $js = @file_get_contents($root . '/js/screen-settings.js');
        if ($js === false) {
            $bad['Settings screen'][] = 'js/screen-settings.js not readable';
        } else {
            foreach (['d.categories', 'd.sms', 'd.voice', 'res.preferences'] as $list) {
                if (preg_match('/\(' . preg_quote($list, '/') . ' \|\| \[\]\)\.(forEach|map|slice)\(/', $js)) {
                    $bad['Settings list shown without ktForRole (js/screen-settings.js)'][] = $list;
                }
            }
        }

        foreach ($bad as $what => $items) {
            $this->error($what . ': ' . implode(', ', $items));
        }
        if ($bad && ! $this->option('no-alert')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Notification audiences - ' . count($bad) . ' problem(s)',
                'Every kind of notification must say who it is for (staff, family or all) in App\Support\NotifyAudience, so each role sees only its own settings and staff-only alerts never reach a parent.',
                $bad
            );
        }
        if ($bad) {
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $this->info('Every notification kind has an audience; no staff-only notification reached a non-staff account in the last 24h.');
        }

        return self::SUCCESS;
    }
}
