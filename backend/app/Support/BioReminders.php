<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reminders for providers to write their bio (2026-10-09).
 *
 * Anthony: "add reminders for educators to write a bio and show them where to do this if
 * they have not done so and the importance behind this - send out reminders and have this
 * configurable in the settings as well". Found while wiring the provider welcome into
 * transfers: Lorraine Cooper's family got the placeholder "Your provider will share a
 * little about themselves here soon" because her bio was empty.
 *
 * A provider's bio lives on their centre (centres.provider_bio); a provider is the staff
 * member whose email is that centre's email (the same match AuthController uses for
 * is_provider and PATCH /auth/me/provider-bio). Whoever has fewer than min_chars gets a
 * reminder on the agency's schedule (Settings → Notifications): why it matters, exactly
 * where to write it (a link that opens Settings with the bio box highlighted), and what to
 * say. It stops by itself once the bio is written, and after max_reminders to one person.
 * Built like CountersignReminders: settings in agencies.settings->bio_reminders.
 */
final class BioReminders
{
    public const KEY = 'bio_reminders';

    public const DEFAULTS = [
        'enabled' => true,
        'frequency' => 'weekly',       // daily | weekdays | weekly | every_n_days
        'weekly_day' => 1,
        'every_n_days' => 3,
        'time' => '09:00',             // agency timezone
        'min_chars' => 40,             // the same floor the bio box enforces
        'max_reminders' => 6,          // per person; 0 = no limit
        'email' => true,
        'in_app' => true,
        'push' => false,
        'banner' => true,              // the in-app "write your bio" card
        'cc_summary' => true,          // admins get one line of who is still missing
    ];

    public const FREQUENCIES = ['daily', 'weekdays', 'weekly', 'every_n_days'];

    public const LINK = 'https://app.kiddietrac.com/dashboard.html#settings?tab=profile&focus=bio';

    public static function read(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = (isset($s[self::KEY]) && is_array($s[self::KEY])) ? $s[self::KEY] : [];

        return array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
    }

    public static function write(int $agencyId, array $next): array
    {
        $clean = array_merge(self::DEFAULTS, array_intersect_key($next, self::DEFAULTS));
        $clean['min_chars'] = max(40, min(400, (int) $clean['min_chars']));
        $clean['max_reminders'] = max(0, min(52, (int) $clean['max_reminders']));
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return $clean;
    }

    public static function lastSent(int $agencyId): ?object
    {
        return DB::table('bio_reminder_runs')->where('agency_id', $agencyId)->first();
    }

    public static function isDue(int $agencyId, array $s, ?Carbon $now = null): bool
    {
        if (empty($s['enabled'])) return false;
        $tz = AgencyTime::tz($agencyId);
        $now = ($now ?: now())->copy()->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', (string) ($s['time'] ?: '09:00')) + [0, 0]);
        if ($now->lt($now->copy()->setTime($h, $m))) return false;
        $dow = (int) $now->dayOfWeekIso;
        $freq = in_array($s['frequency'], self::FREQUENCIES, true) ? $s['frequency'] : 'weekly';
        if ($freq === 'weekdays' && $dow > 5) return false;
        if ($freq === 'weekly' && $dow !== (int) $s['weekly_day']) return false;
        $last = self::lastSent($agencyId);
        $today = $now->toDateString();
        if ($last && $last->last_sent_on && (string) $last->last_sent_on >= $today) return false;
        if ($freq === 'every_n_days' && $last && $last->last_sent_on) {
            if (Carbon::parse((string) $last->last_sent_on, $tz)->diffInDays($now->copy()->startOfDay()) < max(1, (int) $s['every_n_days'])) return false;
        }

        return true;
    }

    public static function claim(int $agencyId, ?Carbon $now = null): bool
    {
        $today = ($now ?: now())->copy()->setTimezone(AgencyTime::tz($agencyId))->toDateString();
        DB::table('bio_reminder_runs')->insertOrIgnore(['agency_id' => $agencyId, 'last_sent_on' => null, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('bio_reminder_runs')->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereNull('last_sent_on')->orWhere('last_sent_on', '<', $today))
            ->update(['last_sent_on' => $today, 'last_sent_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * Providers in the agency whose bio is missing or short. One row per provider:
     * user + centre + current length + whether they have a photo + reminders so far.
     */
    public static function missing(int $agencyId, array $s): Collection
    {
        $min = max(40, (int) $s['min_chars']);
        $centres = DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0])->whereNotNull('email')->where('email', '!=', '')
            ->get(['id', 'name', 'email', 'provider_bio']);
        $out = collect();
        foreach ($centres as $c) {
            $len = mb_strlen(trim((string) $c->provider_bio));
            if ($len >= $min) continue;
            $u = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->whereRaw('LOWER(u.email) = ?', [mb_strtolower(trim((string) $c->email))])
                ->whereNull('u.deleted_at')->where('ra.active', true)->where('ra.agency_id', $agencyId)
                ->whereIn('ra.role', ['educator', 'centre_director'])
                ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.photo_url')->first();
            if (! $u || ChatAudience::isServiceEmail($u->email)) continue;
            $count = (int) (DB::table('bio_reminder_people')->where(['agency_id' => $agencyId, 'user_id' => $u->id])->value('reminders') ?? 0);
            $out->push((object) [
                'user_id' => (int) $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'email' => $u->email,
                'has_photo' => trim((string) $u->photo_url) !== '', 'centre_id' => (int) $c->id, 'centre' => $c->name,
                'length' => $len, 'reminders' => $count,
            ]);
        }

        return $out;
    }

    /** [subject, html] for one provider. */
    public static function email(int $agencyId, object $p, array $s, bool $test = false): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $min = max(40, (int) $s['min_chars']);
        $first = trim((string) $p->first_name) ?: 'there';
        $state = $p->length === 0 ? 'Right now your provider profile has <b>no bio</b>' : 'Right now your bio is only <b>' . $p->length . ' characters</b>';
        $tips = ['How long you have cared for children, and any training or certificates (ECE, First Aid, CPR)',
            'What a day looks like in your home: play, outdoor time, meals, naps',
            'What makes your care special: languages you speak, music, nature, a garden, pets',
            'Something personal, so families feel they know you'];
        $tipsHtml = '';
        foreach ($tips as $t) $tipsHtml .= '<li style="margin:3px 0">' . $e($t) . '</li>';
        $body = '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Hi ' . $e($first) . ',</p>'
            . '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Families want to know who will care for their child. ' . $state
            . ', so they get a placeholder line instead of hearing about you.</p>'
            . '<div style="background:#E4EEF2;border-radius:10px;padding:14px 16px;margin:6px 0 14px">'
            . '<div style="font-size:12px;font-weight:800;color:#1F6080;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px">Why it matters</div>'
            . '<ul style="margin:0;padding-left:18px;font-size:14.5px;color:#0F172A;line-height:1.55">'
            . '<li style="margin:3px 0">It is the <b>first thing a new family reads</b> about you: it goes in their welcome email when a child joins you.</li>'
            . '<li style="margin:3px 0">It shows on <b>your provider card</b> in the parents\' app, every time they check in on their child.</li>'
            . '<li style="margin:3px 0">Parents who feel they know their provider <b>settle in faster</b> and trust you sooner.</li></ul></div>'
            . '<div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;margin:0 0 14px">'
            . '<div style="font-size:12px;font-weight:800;color:#475569;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px">Where to write it (2 minutes)</div>'
            . '<div style="font-size:14.5px;color:#0F172A;line-height:1.6">Open KiddieTrac → <b>Settings</b> → <b>Profile</b> → <b>Your bio</b>. Write at least ' . $min . ' characters (a sentence or two) and press <b>Save bio</b>. '
            . 'The button below opens it for you.</div></div>'
            . '<div style="margin:4px 0 16px"><a href="' . self::LINK . '" style="display:inline-block;padding:12px 20px;border-radius:10px;background:#1F6080;color:#fff;text-decoration:none;font-weight:700;font-size:15px">✍️ Write my bio</a></div>'
            . '<div style="font-size:14px;color:#334155;line-height:1.6"><b>Not sure what to say?</b> A few lines on any of these is plenty:'
            . '<ul style="margin:6px 0 0;padding-left:18px">' . $tipsHtml . '</ul></div>'
            . '<div style="font-size:13.5px;color:#64748B;margin:12px 0 0;font-style:italic;line-height:1.55">For example: "Hi, I\'m Maria! I have run a licensed home daycare in Orangeville for 8 years. '
            . 'Our days are full of outdoor play, music and story time, and I speak English and Spanish. I love watching each child grow in confidence."</div>'
            . (! $p->has_photo ? '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6">📷 <b>While you are there:</b> add a profile photo too (Settings → Profile). It appears beside your bio, and families love putting a face to the name.</p>' : '')
            . '<p style="margin:16px 0 0;font-size:12px;color:#94A3B8">These reminders stop as soon as your bio is saved. Your agency sets how often they are sent.</p>';
        $subject = ($test ? '[Test] ' : '') . 'Families would love to meet you — add your bio (2 minutes)';
        $html = EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'YOUR PROVIDER PROFILE', 'title' => 'Tell families about yourself',
            'subtitle' => $p->centre ?? '', 'preheader' => 'Your bio goes in every new family\'s welcome email. Here is where to add it.']);

        return [$subject, $html];
    }

    /** The admins' one-line summary of who is still missing a bio. */
    public static function summary(int $agencyId, Collection $people, bool $test = false): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($people as $p) {
            $rows .= '<tr><td style="padding:7px 0;border-top:1px solid #E2E8F0;font-size:14px;color:#0F172A">' . $e(trim($p->first_name . ' ' . $p->last_name))
                . ' <span style="color:#64748B">· ' . ($p->length ? $p->length . ' characters' : 'no bio') . ($p->has_photo ? '' : ' · no photo')
                . ' · reminded ' . $p->reminders . ' time' . ($p->reminders === 1 ? '' : 's') . '</span></td></tr>';
        }
        $n = $people->count();
        $body = '<p style="margin:0 0 8px;font-size:15px;color:#334155;line-height:1.6">' . $n . ' provider' . ($n === 1 ? ' has' : 's have')
            . ' not written a bio yet, so new families get a placeholder in their welcome email. Each has been sent a reminder with a link to write it.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $rows . '</table>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">You can write a bio for a provider yourself: Admin → Providers → the provider → Edit. Reminder settings: Settings → Notifications.</p>';
        $subject = ($test ? '[Test] ' : '') . $n . ' provider' . ($n === 1 ? '' : 's') . ' still need a bio';
        $html = EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'PROVIDER PROFILES', 'title' => 'Providers without a bio', 'subtitle' => $n . ' to go', 'preheader' => $subject]);

        return [$subject, $html];
    }

    public static function sendAgency(int $agencyId, array $s): array
    {
        $res = ['agency_id' => $agencyId, 'missing' => 0, 'reminded' => 0, 'emails' => 0, 'in_app' => 0, 'push' => 0, 'capped' => 0, 'admins' => 0, 'skipped' => null];
        if (! Suppression::agencyNotificationsEnabled($agencyId)) { $res['skipped'] = 'agency notifications are switched off'; return $res; }
        $people = self::missing($agencyId, $s);
        $res['missing'] = $people->count();
        if (! $people->count()) { $res['skipped'] = 'every provider has a bio'; return $res; }
        $cap = (int) $s['max_reminders'];
        $mailed = [];
        foreach ($people as $p) {
            if ($cap > 0 && $p->reminders >= $cap) { $res['capped']++; continue; }
            if (Suppression::isUser($p->user_id)) continue;
            [$subject, $html] = self::email($agencyId, $p, $s);
            $inbox = mb_strtolower(trim((string) $p->email));
            if (! empty($s['email']) && filter_var((string) $p->email, FILTER_VALIDATE_EMAIL) && ! isset($mailed[$inbox])) {
                $mailed[$inbox] = true;
                try {
                    $name = trim($p->first_name . ' ' . $p->last_name);
                    AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($p, $name, $subject) { $m->to($p->email, $name ?: null)->subject($subject); });
                    $res['emails']++;
                } catch (\Throwable $ex) { Log::warning('bio reminder email failed', ['agency' => $agencyId, 'user' => $p->user_id, 'error' => $ex->getMessage()]); }
            }
            if (! empty($s['in_app'])) {
                Notify::write(['user_id' => $p->user_id, 'type' => 'bio_reminder', 'title' => '✍️ Add your bio for families',
                    'body' => 'New families read it in their welcome email and on your provider card. Settings → Profile → Your bio — about 2 minutes.',
                    'data' => json_encode(['link' => '#settings?tab=profile&focus=bio']), 'created_at' => now()]);
                $res['in_app']++;
            }
            if (! empty($s['push'])) {
                try { app(\App\Services\FcmService::class)->sendToUser($p->user_id, '✍️ Add your bio for families', 'Families read it in their welcome email. Tap to write it — about 2 minutes.', '#settings?tab=profile&focus=bio'); $res['push']++; }
                catch (\Throwable $ex) { Log::warning('bio reminder push failed', ['user' => $p->user_id, 'error' => $ex->getMessage()]); }
            }
            $key = ['agency_id' => $agencyId, 'user_id' => $p->user_id];
            if (DB::table('bio_reminder_people')->where($key)->exists()) {
                DB::table('bio_reminder_people')->where($key)->update(['reminders' => DB::raw('reminders + 1'), 'last_reminded_at' => now()]);
            } else {
                DB::table('bio_reminder_people')->insert($key + ['reminders' => 1, 'last_reminded_at' => now()]);
            }
            $p->reminders++;
            $res['reminded']++;
        }
        if (! empty($s['cc_summary']) && ! empty($s['email']) && $res['reminded']) {
            $admins = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')->where('ra.active', true)->whereNull('u.deleted_at')
                ->get(['u.id', 'u.email', 'u.first_name', 'u.last_name'])->reject(fn ($a) => ChatAudience::isServiceEmail($a->email))
                ->unique(fn ($a) => mb_strtolower((string) $a->email));
            [$subject, $html] = self::summary($agencyId, $people);
            foreach ($admins as $a) {
                if (Suppression::isUser((int) $a->id) || ! filter_var((string) $a->email, FILTER_VALIDATE_EMAIL)) continue;
                try {
                    AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($a, $subject) { $m->to($a->email, trim($a->first_name . ' ' . $a->last_name) ?: null)->subject($subject); });
                    $res['admins']++;
                } catch (\Throwable $ex) { Log::warning('bio reminder summary failed', ['agency' => $agencyId, 'error' => $ex->getMessage()]); }
            }
        }
        try {
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'providers.bio_reminders_sent', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Bio reminder sent to ' . $res['reminded'] . ' provider(s); ' . $res['missing'] . ' without a bio'] + $res), 'created_at' => now()]);
        } catch (\Throwable $ex) {}

        return $res;
    }
}
