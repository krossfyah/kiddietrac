<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Details line for audit rows whose writers stored data but no sentence (2026-10-31).
 *
 * Anthony: "audit log using raw for 'your payslip is ready' - we had a rule to never use raw
 * logging and to put as much detail as we can to understand what and who - full sweep".
 * Push, email, sign-in, sync and check-in rows were stored as key/value data, so the Details
 * column printed "channel: fcm, to user: 146, title: …, link: #my-hours". Each describer
 * below turns that row into a plain sentence: what happened, to whom, through what, and
 * the result. Return null to fall back to the row's own summary / the generic field list.
 *
 * $describeIt: some writers DO store a summary that is itself code ("Refused to check a
 * child: not_clocked_in", "ev=first_fix src=web …"); those actions are listed in OVERRIDE
 * so the describer replaces the stored summary.
 */
final class AuditDescriber
{
    /** Actions whose stored summary is machine text and must be rewritten. */
    public const OVERRIDE = ['walk.gps', 'child.check_refused', 'child.care_schedule_updated'];

    private static array $names = [];

    public static function describe(string $action, array $p, ?string $tz = null): ?string
    {
        $action = (string) preg_replace('/ \[fail\]$/i', '', $action);
        if (! empty($p['summary']) && is_string($p['summary']) && ! in_array($action, self::OVERRIDE, true)) {
            return null; // the writer already said it in words
        }
        try {
            $fn = self::MAP[$action] ?? null;
            if ($fn === null) {
                foreach (self::PREFIX as $prefix => $f) {
                    if (str_starts_with($action, $prefix)) { $fn = $f; break; }
                }
            }
            if ($fn === null) {
                foreach (self::ROUTES as $re => $f) {
                    if (preg_match($re, $action)) { $fn = $f; break; }
                }
                /* A refused request keeps the controller's "Refused: ..." wording. */
                if ($fn !== null && (int) ($p['status'] ?? 200) >= 300) return null;
            }
            if ($fn === null) return null;
            $s = self::$fn($action, $p, $tz);
            return $s !== null && trim($s) !== '' ? trim($s) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private const MAP = [
        'login' => 'login', 'login_failed' => 'loginFailed', 'login_blocked' => 'loginBlocked',
        'login_brand_blocked' => 'brandBlocked', 'session.expired' => 'sessionExpired',
        'signin.new_device' => 'newSignin', 'signin.new_location' => 'newSignin', 'signin.new_device_and_location' => 'newSignin',
        'impersonate.start' => 'impersonate', 'password_reset_requested' => 'resetRequested', 'password_changed' => 'passwordChanged',
        'child.check_in' => 'checkEvent', 'child.check_out' => 'checkEvent', 'child.check_in_by_staff' => 'checkEvent',
        'child.check_out_by_staff' => 'checkEvent', 'child.check_refused' => 'checkRefused',
        'child.care_schedule_updated' => 'careSchedule',
        'integration.child_updated' => 'syncChild', 'integration.child_created' => 'syncChild',
        'shift.autofilled' => 'shiftAutofilled', 'chat.email_notified' => 'chatEmailed', 'announcement.sent' => 'announcementSent',
        'billing_reminders_updated' => 'remindersUpdated', 'managed_form.bulk_assign' => 'formsAssigned',
        'managed_form.signed_via_link' => 'formSignedLink', 'staff.auto_clock_out' => 'autoClockOut',
        'create_family' => 'familyCreated', 'family.deleted' => 'familyClosed', 'walk.gps' => 'walkGps',
        'sales.library_downloaded' => 'library', 'sales.library_uploaded' => 'library', 'sales.library_removed' => 'library',
        'sales.library_folder_created' => 'library', 'sales.library_moved' => 'library',
        'payroll_document.emailed' => 'payslipEmailed',
        'onboarding_started' => 'onboardingStarted', 'child.document_uploaded' => 'childDocument', 'message.sent' => 'familyMessage',
    ];

    /* Request rows (the AuditActivity middleware) whose input is codes and ids, most frequent first
       (2026-11-02 audit sweep, Anthony: "we in memory to always wire up enough detail in audit log"). */
    private const ROUTES = [
        '#^put:api/v1/me/markers$#' => 'marker',
        '#^post:api/v1/care/logs$#' => 'careLog',
        '#^post:api/v1/me/device-permissions$#' => 'devicePerms',
        '#^post:api/v1/managed-forms/\d+/sign$#' => 'formSigned',
        '#^post:api/v1/auth/active-agency$#' => 'agencySwitch',
        '#^post:api/v1/headcounts$#' => 'headcount',
        '#^post:api/v1/sales/activities/\d+/upload$#' => 'uploadPart',
        '#^put:api/v1/me/notification-sounds$#' => 'tones',
        '#^post:api/v1/parent/onboarding/children/\d+$#' => 'onboardingChild',
        '#^post:api/v1/reactions$#' => 'reaction',
        '#^post:api/v1/provider/medications/give$#' => 'medicationGiven',
        '#^post:api/v1/ai/assist/write$#' => 'aiWrite',
        '#^post:api/v1/provider/team-threads/start$#' => 'teamStart',
    ];

    /** The request's own fields (route rows keep them under "input"). */
    private static function in(array $p): array
    {
        return is_array($p['input'] ?? null) ? $p['input'] : $p;
    }

    /** A centre is named only inside the agency the request was made in (never across tenants). */
    private static function centreInAgency($centreId, array $p): bool
    {
        $agency = (int) ($p['active_agency_id'] ?? 0);
        if ($agency <= 0 || (int) $centreId <= 0) return false;
        return (int) DB::table('centres')->where('id', (int) $centreId)->value('agency_id') === $agency;
    }

    /** Children's names, only those in the request's agency; [names, how many asked for]. */
    private static function childNames(array $ids, array $p): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) return [[], 0];
        $agency = (int) ($p['active_agency_id'] ?? 0);
        $names = [];
        if ($agency > 0) {
            foreach (DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->whereIn('c.id', array_slice($ids, 0, 12))->where('ce.agency_id', $agency)
                ->get(['c.first_name', 'c.last_name', 'c.preferred_name']) as $c) {
                $n = trim(($c->preferred_name ?: $c->first_name) . ' ' . ($c->last_name ?? ''));
                if ($n !== '') $names[] = $n;
            }
        }
        return [$names, count($ids)];
    }

    private static function kids(array $ids, array $p): string
    {
        [$names, $n] = self::childNames($ids, $p);
        if (! $n) return '';
        if ($names && count($names) === $n && $n <= 4) return self::list($names, 4);
        return $n . ' ' . ($n === 1 ? 'child' : 'children') . ($names ? ' (' . self::list($names, 3) . ')' : '');
    }

    private const PREFIX = ['push.' => 'push', 'email.' => 'email', 'sms.' => 'sms'];

    /* ── helpers ─────────────────────────────────────────────────────────────── */

    private static function userName($id): ?string
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        if (! array_key_exists($id, self::$names)) {
            $u = DB::table('users')->where('id', $id)->first(['first_name', 'last_name', 'email']);
            self::$names[$id] = $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: (string) $u->email) : null;
        }
        return self::$names[$id];
    }

    private static function words(?string $code): string
    {
        $code = trim((string) $code);
        return $code === '' ? '' : str_replace('_', ' ', $code);
    }

    private static function when($v, ?string $tz): string
    {
        try {
            if (! $v) return '';
            return Carbon::parse((string) $v, 'UTC')->setTimezone($tz ?: config('app.timezone'))->format('M j, g:i A');
        } catch (Throwable $e) {
            return (string) $v;
        }
    }

    /** "Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 …) … Safari" -> "Safari on iPhone". */
    public static function device(?string $ua, ?string $kind = null): string
    {
        $ua = (string) $ua;
        $k = strtolower((string) $kind);
        if (str_contains($ua, 'KiddieTrac') || $k === 'android' || $k === 'ios' || str_contains($ua, '; wv)')) {
            return 'the KiddieTrac app on ' . (str_contains($ua, 'iPhone') || $k === 'ios' ? 'iPhone' : 'Android');
        }
        if ($ua === '') return $k !== '' && $k !== 'web' ? $k : 'a web browser';
        $os = str_contains($ua, 'iPhone') ? 'iPhone' : (str_contains($ua, 'iPad') ? 'iPad' : (str_contains($ua, 'Android') ? 'Android'
            : (str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Mac OS X') ? 'Mac' : ''))));
        $br = str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') ? 'Chrome'
            : (str_contains($ua, 'Firefox/') ? 'Firefox' : (str_contains($ua, 'Safari/') ? 'Safari' : 'a browser')));
        return $br . ($os ? ' on ' . $os : '');
    }

    private static function list($v, int $max = 4): string
    {
        $arr = is_array($v) ? array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? (string) $x : (is_array($x) ? (string) ($x['name'] ?? $x['email'] ?? '') : ''), $v))) : array_filter([(string) $v]);
        if (! $arr) return '';
        $more = count($arr) > $max ? ' and ' . (count($arr) - $max) . ' more' : '';
        return implode(', ', array_slice($arr, 0, $max)) . $more;
    }

    /* ── describers ──────────────────────────────────────────────────────────── */

    private static function push(string $a, array $p, ?string $tz): ?string
    {
        $who = self::userName($p['to_user'] ?? null) ?: 'someone';
        $title = trim((string) ($p['title'] ?? ''));
        $chan = ($p['channel'] ?? '') === 'webpush' ? 'web browser' : 'phone app';
        $t = $title !== '' ? '“' . $title . '”' : 'a notification';
        $outcome = substr($a, 5);
        return match ($outcome) {
            'sent' => 'Notification ' . $t . ' delivered to ' . $who . ' (' . $chan . ', ' . max(1, (int) ($p['sent'] ?? $p['devices'] ?? 1)) . ' device' . ((int) ($p['sent'] ?? 1) === 1 ? '' : 's') . ')'
                . (! empty($p['failed']) ? '; ' . (int) $p['failed'] . ' device(s) failed' : '') . '.',
            'no_device' => 'Notification ' . $t . ' for ' . $who . ' was not delivered by ' . $chan . ': they have no ' . ($chan === 'web browser' ? 'browser' : 'phone') . ' registered for notifications.',
            'suppressed' => 'Notification ' . $t . ' for ' . $who . ' was held back' . (! empty($p['reason']) ? ': ' . self::words((string) $p['reason']) : ' (notifications are switched off for this account or agency)') . '.',
            'failed' => 'Notification ' . $t . ' to ' . $who . ' failed (' . $chan . ')' . (! empty($p['error']) ? ': ' . mb_substr((string) $p['error'], 0, 120) : '') . '.',
            'not_configured' => 'Notification ' . $t . ' for ' . $who . ' was not sent: ' . $chan . ' notifications are not set up.',
            default => null,
        };
    }

    private static function email(string $a, array $p, ?string $tz): ?string
    {
        $to = self::list($p['to'] ?? '', 3);
        $subj = trim((string) ($p['subject'] ?? ''));
        $s = $subj !== '' ? '“' . $subj . '”' : 'An email';
        $cc = ! empty($p['cc']) ? ' (copy to ' . self::list($p['cc'], 3) . ')' : '';
        return match (substr($a, 6)) {
            'sent' => 'Email ' . $s . ' sent to ' . ($to ?: 'its recipient') . $cc . '.',
            'suppressed' => 'Email ' . $s . ' to ' . ($to ?: 'its recipient') . ' was held back' . (! empty($p['reason']) ? ': ' . rtrim((string) $p['reason'], '.') : '') . '.',
            'failed' => 'Email ' . $s . ' to ' . ($to ?: 'its recipient') . ' failed' . (! empty($p['error']) ? ': ' . mb_substr((string) $p['error'], 0, 160) : '') . '.',
            'bounced' => 'Email ' . $s . ' to ' . ($to ?: 'its recipient') . ' bounced' . (! empty($p['reason']) ? ': ' . mb_substr((string) $p['reason'], 0, 160) : '') . '.',
            'deferred' => 'Email ' . $s . ' to ' . ($to ?: 'its recipient') . ' was delayed by the receiving server.',
            'staff_welcome' => 'Staff welcome email sent to ' . ($p['person'] ?? $to) . ($to ? ' (' . $to . ')' : '') . (! empty($p['role']) ? ', ' . self::words((string) $p['role']) : '') . (! empty($p['centre']) ? ' at ' . $p['centre'] : '') . $cc . '.',
            default => null,
        };
    }

    private static function sms(string $a, array $p, ?string $tz): ?string
    {
        $who = trim((string) ($p['person'] ?? '')) ?: 'someone';
        $to = (string) ($p['to'] ?? '');
        $prev = ! empty($p['preview']) ? ': “' . mb_substr((string) $p['preview'], 0, 120) . '”' : '';
        return match (substr($a, 4)) {
            'sent' => 'Text to ' . $who . ($to ? ' (' . $to . ')' : '') . ' sent' . $prev,
            'received' => 'Text received from ' . $who . ($to ? ' (' . $to . ')' : '') . $prev,
            'skipped' => 'Text to ' . $who . ($to ? ' (' . $to . ')' : '') . ' not sent: ' . self::words((string) ($p['error'] ?? 'skipped')) . $prev,
            'failed' => 'Text to ' . $who . ($to ? ' (' . $to . ')' : '') . ' failed: ' . self::words((string) ($p['error'] ?? 'unknown error')) . $prev,
            default => null,
        };
    }

    private static function login(string $a, array $p, ?string $tz): ?string
    {
        $who = trim((string) ($p['account'] ?? '')) ?: (string) ($p['identifier'] ?? $p['email'] ?? 'an account');
        $id = (string) ($p['identifier'] ?? $p['email'] ?? '');
        return 'Signed in as ' . $who . ($id && $id !== $who ? ' (' . $id . ')' : '') . ' using ' . self::device($p['device_name'] ?? null, $p['device'] ?? null)
            . (! empty($p['method']) ? ' by ' . self::words((string) $p['method']) : '') . '.';
    }

    private static function loginFailed(string $a, array $p, ?string $tz): ?string
    {
        $why = match ((string) ($p['reason'] ?? '')) {
            'no_such_account' => 'no account uses that email or username',
            'bad_password', 'wrong_password' => 'wrong password',
            default => self::words((string) ($p['reason'] ?? 'not accepted')),
        };
        return 'Sign-in failed for “' . ($p['login'] ?? $p['identifier'] ?? '?') . '”: ' . $why . '.';
    }

    private static function loginBlocked(string $a, array $p, ?string $tz): ?string
    {
        $why = ($p['reason'] ?? '') === 'account_status' ? 'the account is ' . self::words((string) ($p['account_status'] ?? 'not active')) : self::words((string) ($p['reason'] ?? 'refused'));
        return 'Sign-in refused for ' . ($p['login'] ?? 'an account') . ': ' . $why . '.';
    }

    private static function brandBlocked(string $a, array $p, ?string $tz): ?string
    {
        if (! empty($p['context'])) {
            return 'Sign-in refused: this account belongs to the ' . ($p['their_app'] ?? $p['user_brands'] ?? '?') . ' app but was used in the ' . $p['context'] . ' app'
                . (! empty($p['path']) ? ' (' . self::words((string) $p['path']) . ')' : '') . '. The person was shown the normal wrong-password message.';
        }
        return 'Sign-in refused because the account was used in another brand’s app (recorded before details were kept).';
    }

    private static function sessionExpired(string $a, array $p, ?string $tz): ?string
    {
        return 'Signed out automatically after being ' . (($p['reason'] ?? '') === 'idle' ? 'idle' : self::words((string) ($p['reason'] ?? 'inactive')))
            . (! empty($p['last_used']) ? ' (last active ' . self::when($p['last_used'], $tz) . ')' : '')
            . (! empty($p['device']) ? ' on ' . self::device((string) $p['device']) : '') . '.';
    }

    private static function newSignin(string $a, array $p, ?string $tz): ?string
    {
        $kind = $a === 'signin.new_location' ? 'from a new place' : ($a === 'signin.new_device' ? 'on a new device' : 'on a new device from a new place');
        return 'Signed in ' . $kind . ': ' . ($p['device'] ?? 'unknown device') . (! empty($p['location']) ? ', ' . $p['location'] : '')
            . (! empty($p['method']) ? ' (' . self::words((string) $p['method']) . ')' : '') . (! empty($p['emailed']) ? '. A security email was sent to the account.' : '.');
    }

    private static function impersonate(string $a, array $p, ?string $tz): ?string
    {
        return 'Started viewing the portal as ' . ($p['target_name'] ?? 'another person') . (! empty($p['target_email']) ? ' (' . $p['target_email'] . ')' : '') . '. Everything done while viewing is recorded under their own name.';
    }

    private static function resetRequested(string $a, array $p, ?string $tz): ?string
    {
        return 'Asked for a password reset for ' . ($p['email'] ?? $p['username'] ?? 'an account') . (! empty($p['account_status']) ? ' (account ' . self::words((string) $p['account_status']) . ')' : '') . '.';
    }

    private static function passwordChanged(string $a, array $p, ?string $tz): ?string
    {
        return 'Changed their password' . (! empty($p['cleared_forced_change']) ? ', which cleared a required password change' : '') . '.';
    }

    private static function checkEvent(string $a, array $p, ?string $tz): ?string
    {
        $in = str_contains($a, 'check_in') || ($p['event_type'] ?? '') === 'check_in';
        $by = (string) ($p['by_name'] ?? '');
        $child = (string) ($p['child_name'] ?? 'a child');
        $role = ($p['actor_kind'] ?? '') === 'guardian' ? ' (parent)' : (($p['actor_kind'] ?? '') === 'staff' ? ' (staff)' : '');
        return ($by !== '' ? $by . $role . ' checked ' : 'Checked ') . $child . ($in ? ' in' : ' out') . (! empty($p['on_behalf']) ? ' on the family’s behalf' : '') . '.';
    }

    private static function checkRefused(string $a, array $p, ?string $tz): ?string
    {
        $why = match ((string) ($p['reason'] ?? '')) {
            'not_clocked_in' => 'the educator was not clocked in',
            default => self::words((string) ($p['reason'] ?? 'not allowed')),
        };
        return 'Was refused when checking a child in or out: ' . $why . '.';
    }

    private static function careSchedule(string $a, array $p, ?string $tz): ?string
    {
        $days = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
        $on = [];
        foreach ((array) ($p['week'] ?? []) as $d => $room) {
            if ($room) $on[] = $days[$d] ?? $d;
        }
        $with = trim((string) preg_replace('/\s+(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(\s|$).*/', '', (string) ($p['summary'] ?? '')));
        return 'Care schedule set: ' . ($on ? implode(', ', $on) : 'no days') . ($with !== '' ? ' with ' . $with : '') . '.';
    }

    private static function syncChild(string $a, array $p, ?string $tz): ?string
    {
        $fam = ! empty($p['family_id']) ? (string) (DB::table('families')->where('id', (int) $p['family_id'])->value('family_name') ?? '') : '';
        return ($a === 'integration.child_created' ? 'The ' : 'The ') . (($p['source'] ?? '') === 'ilearn' ? 'iLearn' : 'integration') . ' sync '
            . ($a === 'integration.child_created' ? 'added ' : 'updated ') . ($p['name'] ?? 'a child') . ($fam !== '' ? ' (' . $fam . ' family)' : '')
            . (! empty($p['placed_in_room']) ? '; placed in room ' . (DB::table('rooms')->where('id', (int) $p['placed_in_room'])->value('name') ?: '#' . (int) $p['placed_in_room']) : '') . '.';
    }

    private static function shiftAutofilled(string $a, array $p, ?string $tz): ?string
    {
        return 'Nightly schedule fill added ' . (int) ($p['created'] ?? 0) . ' shift(s) for ' . (self::list($p['staff'] ?? []) ?: 'staff') . (! empty($p['centre']) ? ' at ' . $p['centre'] : '')
            . (! empty($p['range']) ? ', ' . $p['range'] : '') . (! empty($p['hours']) ? ' (' . preg_replace('/:00(?=-|$)/', '', (string) $p['hours']) . ')' : '') . '.';
    }

    private static function chatEmailed(string $a, array $p, ?string $tz): ?string
    {
        return 'Emailed ' . (int) ($p['sent'] ?? 0) . ' people about unread chat messages' . (! empty($p['failed']) ? '; ' . (int) $p['failed'] . ' failed' : '') . '.';
    }

    private static function announcementSent(string $a, array $p, ?string $tz): ?string
    {
        $scope = '';
        $t = (string) ($p['scope_type'] ?? '');
        $id = (int) ($p['scope_id'] ?? 0);
        if ($id > 0 && in_array($t, ['room', 'centre', 'agency'], true)) {
            $table = ['room' => 'rooms', 'centre' => 'centres', 'agency' => 'agencies'][$t];
            $scope = (string) (DB::table($table)->where('id', $id)->value('name') ?? '');
        }
        return 'Announcement sent to ' . self::words((string) ($p['audience'] ?? 'everyone')) . ($scope !== '' ? ' of ' . $scope : '')
            . ': delivered to ' . (int) ($p['delivered_to'] ?? 0) . ' people' . (! empty($p['scheduled']) ? ' (scheduled)' : '') . (! empty($p['has_image']) ? ', with an image' : '') . '.';
    }

    private static function remindersUpdated(string $a, array $p, ?string $tz): ?string
    {
        if (! empty($p['changes']) && is_array($p['changes'])) {
            $bits = [];
            foreach ($p['changes'] as $k => $c) {
                $f = fn ($v) => is_bool($v) ? ($v ? 'on' : 'off') : (is_array($v) ? (count($v) . ' people') : (string) $v);
                $bits[] = self::words((string) $k) . ' ' . $f($c['from'] ?? null) . ' → ' . $f($c['to'] ?? null);
            }
            return 'Changed invoice reminder settings: ' . implode('; ', $bits) . '.';
        }
        return 'Saved the invoice reminder settings (' . self::list(array_map(fn ($x) => self::words((string) $x), (array) ($p['fields'] ?? [])), 8) . ').';
    }

    private static function formsAssigned(string $a, array $p, ?string $tz): ?string
    {
        return 'Assigned ' . (self::list($p['forms'] ?? []) ?: 'forms') . ' to ' . (self::list($p['recipients'] ?? []) ?: 'people')
            . ': ' . (int) ($p['new_assignments'] ?? 0) . ' new assignment(s), ' . (int) ($p['emailed'] ?? 0) . ' emailed.';
    }

    private static function formSignedLink(string $a, array $p, ?string $tz): ?string
    {
        return 'Signed “' . ($p['title'] ?? 'a form') . '” from a ' . ($p['via'] ?? 'link') . '.';
    }

    private static function autoClockOut(string $a, array $p, ?string $tz): ?string
    {
        return 'Clocked ' . ($p['staff'] ?? 'a staff member') . ' out automatically' . (! empty($p['punched_in_at']) ? ' (clocked in ' . self::when($p['punched_in_at'], $tz) . ')' : '')
            . (! empty($p['reason']) ? ': ' . rtrim((string) $p['reason'], '.') : '') . '.';
    }

    private static function familyCreated(string $a, array $p, ?string $tz): ?string
    {
        $centre = ! empty($p['centre_id']) ? (string) (DB::table('centres')->where('id', (int) $p['centre_id'])->value('name') ?? '') : '';
        return 'Created a family with ' . (int) ($p['guardians'] ?? 0) . ' guardian(s) and ' . (int) ($p['children'] ?? 0) . ' child(ren)' . ($centre !== '' ? ' at ' . $centre : '') . '.';
    }

    private static function familyClosed(string $a, array $p, ?string $tz): ?string
    {
        return 'Closed the ' . ($p['family_name'] ?? 'family') . ' account' . (! empty($p['last_day']) ? ', last day ' . $p['last_day'] : '')
            . (! empty($p['reason']) ? ' (' . $p['reason'] . ')' : '') . '; ' . (int) ($p['children_withdrawn'] ?? 0) . ' child(ren) withdrawn, ' . (int) ($p['accounts_closed'] ?? 0) . ' login(s) closed'
            . (isset($p['outstanding_at_departure']) ? ', balance at departure $' . number_format((float) $p['outstanding_at_departure'], 2) : '') . '.';
    }

    private static function walkGps(string $a, array $p, ?string $tz): ?string
    {
        $raw = (string) ($p['summary'] ?? '');
        $kv = [];
        if (preg_match_all('/(\w+)=([^\s]+)/', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $kv[$x[1]] = $x[2];
        }
        $ev = match ($kv['ev'] ?? '') {
            'first_fix' => 'got its first GPS fix',
            'no_fix' => 'could not get a GPS fix',
            'denied' => 'was refused location permission',
            'stale' => 'stopped receiving GPS updates',
            default => 'reported ' . self::words($kv['ev'] ?? 'a GPS update'),
        };
        $where = ($kv['plat'] ?? '') === 'web' ? 'in a web browser' : 'in the ' . ($kv['plat'] ?? 'app');
        return 'Walk Watch ' . $ev . ' ' . $where
            . (isset($kv['nofix_s']) ? ' after ' . (int) $kv['nofix_s'] . 's' : '')
            . (isset($kv['acc']) ? ', accuracy about ' . (int) $kv['acc'] . ' m' : '')
            . (isset($kv['perm']) && $kv['perm'] !== '-' ? ', permission ' . $kv['perm'] : '')
            . (isset($kv['vis']) ? ', screen ' . $kv['vis'] : '') . '.';
    }

    private static function library(string $a, array $p, ?string $tz): ?string
    {
        $what = (string) ($p['title'] ?? $p['name'] ?? 'a file');
        return match ($a) {
            'sales.library_downloaded' => 'Downloaded “' . $what . '” from the Files repository.',
            'sales.library_uploaded' => 'Uploaded “' . $what . '” to the Files repository' . (! empty($p['category']) ? ' (' . $p['category'] . ')' : '') . (! empty($p['file_size']) ? ', ' . max(1, (int) round(((int) $p['file_size']) / 1024)) . ' KB' : '') . '.',
            'sales.library_removed' => 'Removed “' . $what . '” from the Files repository.',
            'sales.library_folder_created' => 'Created the folder “' . $what . '” in the Files repository.',
            default => 'Moved “' . $what . '” in the Files repository.',
        };
    }

    private static function marker(string $a, array $p, ?string $tz): ?string
    {
        $key = (string) (self::in($p)['key'] ?? '');
        return match ($key) {
            'kt_notif_seen' => 'Opened their notifications list; everything up to now counts as seen.',
            'kt_whatsnew_seen' => 'Read the “What’s new” notes.',
            'kt_sales_chat_seen' => 'Opened the website chats; the waiting ones count as seen.',
            'kt_passkey_offer_snooze' => 'Chose “not now” when offered a passkey (face or fingerprint sign-in).',
            default => $key !== '' ? 'Marked “' . trim(str_replace(['kt_', '_'], ['', ' '], $key)) . '” as seen.' : null,
        };
    }

    private static function careLog(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $ids = is_array($in['child_ids'] ?? null) ? $in['child_ids'] : (! empty($in['child_id']) ? [$in['child_id']] : []);
        $who = self::kids($ids, $p);
        $type = ucfirst(self::words((string) ($in['log_type'] ?? 'care moment')));
        $when = self::when($in['occurred_at'] ?? null, $tz);
        $end = ! empty($in['ended_at']) ? self::when($in['ended_at'], $tz) : '';
        if ($end !== '' && substr($end, 0, 6) === substr($when, 0, 6)) $end = substr($end, strpos($end, ', ') + 2);
        $s = $type . ($who !== '' ? ' for ' . $who : '') . ($when !== '' ? ', ' . $when . ($end !== '' ? ' to ' . $end : '') : '');
        $d = trim((string) ($in['details'] ?? ''));
        $n = trim((string) ($in['notes'] ?? ''));
        return $s . '.' . ($d !== '' ? ' ' . mb_substr($d, 0, 140) . (str_ends_with($d, '.') ? '' : '.') : '') . ($n !== '' && $n !== $d ? ' Notes: ' . mb_substr($n, 0, 140) : '');
    }

    private static function devicePerms(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $where = match ((string) ($in['platform'] ?? '')) { 'android' => 'Android app', 'ios' => 'iPhone app', default => 'Web browser' };
        $say = fn ($v) => match (strtolower((string) $v)) {
            'granted', 'yes', 'true', 'allowed' => 'allowed', 'denied', 'no', 'false', 'blocked' => 'blocked',
            'prompt', 'default' => 'not asked yet', default => 'unknown',
        };
        $perms = is_array($in['perms'] ?? null) ? $in['perms'] : [];
        $bits = [];
        foreach (['notifications' => 'notifications', 'location' => 'location', 'microphone' => 'microphone', 'camera' => 'camera', 'photos' => 'photos'] as $k => $label) {
            if (array_key_exists($k, $perms)) $bits[] = $label . ' ' . $say($perms[$k]);
        }
        if (array_key_exists('biometric', $perms)) {
            $bits[] = $say($perms['biometric']) === 'allowed'
                ? 'face/fingerprint unlock ' . ($say($perms['biometric_enrolled'] ?? '') === 'allowed' ? 'set up' : 'available, not set up')
                : 'no face/fingerprint unlock';
        }
        return $where . ' reported its permissions' . ($bits ? ': ' . implode(', ', $bits) : '') . '.';
    }

    private static function formSigned(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $fv = $in['field_values'] ?? [];
        if (is_string($fv)) $fv = json_decode($fv, true);
        $filled = is_array($fv) ? count(array_filter($fv, fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false)) : 0;
        return 'Signed and submitted the form' . ($filled ? ' with ' . $filled . ' answer' . ($filled === 1 ? '' : 's') . ' filled in' : '')
            . (! empty($in['signature']) ? ', signature attached' : '') . '.';
    }

    private static function agencySwitch(string $a, array $p, ?string $tz): ?string
    {
        $to = (int) (self::in($p)['agency_id'] ?? 0);
        $from = (int) ($p['active_agency_id'] ?? 0);
        // Only the agency the row is filed under is named; another tenant's name stays out of this log.
        if ($to > 0 && $to === $from) {
            $name = (string) DB::table('agencies')->where('id', $to)->value('name');
            return 'Opened ' . ($name !== '' ? $name : 'this agency') . ' as the agency they are working in.';
        }
        return $to > 0 ? 'Switched to working in a different agency they have access to.' : null;
    }

    private static function headcount(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $shown = is_array($in['shown_ids'] ?? null) ? count($in['shown_ids']) : 0;
        $seen = is_array($in['seen_ids'] ?? null) ? count($in['seen_ids']) : 0;
        $room = '';
        if (! empty($in['room_id'])) {
            $r = DB::table('rooms')->where('id', (int) $in['room_id'])->first(['name', 'centre_id']);
            if ($r && self::centreInAgency($r->centre_id, $p)) $room = (string) $r->name;
        }
        $ctx = self::words((string) ($in['context'] ?? ''));
        return ($ctx !== '' ? ucfirst($ctx) . ' headcount' : 'Headcount') . ($room !== '' ? ' in ' . $room : '') . ': '
            . $seen . ' of ' . $shown . ' ' . ($shown === 1 ? 'child' : 'children') . ' seen'
            . ($seen < $shown ? ' — ' . ($shown - $seen) . ' not seen' : '') . '.'
            . (! empty($in['location']) ? ' Location: ' . mb_substr((string) $in['location'], 0, 80) . '.' : '')
            . (! empty($in['note']) ? ' Note: ' . mb_substr((string) $in['note'], 0, 120) : '');
    }

    private static function uploadPart(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $name = (string) ($in['name'] ?? 'a file');
        $mb = (int) ($in['size'] ?? 0) > 0 ? ' (' . max(1, (int) round(((int) $in['size']) / 1048576)) . ' MB)' : '';
        $i = (int) ($in['index'] ?? 0) + 1;
        $t = (int) ($in['total'] ?? 0);
        return $t > 1 ? 'Uploaded part ' . $i . ' of ' . $t . ' of “' . $name . '”' . $mb . ($i === $t ? '; the file is complete.' : '.') : 'Uploaded “' . $name . '”' . $mb . '.';
    }

    private static function tones(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $tone = trim(str_replace(['kt_tone_', 'kt_', '_'], ['', '', ' '], (string) ($in['tone'] ?? '')));
        $cat = self::words((string) ($in['category'] ?? ''));
        return 'Set ' . ($cat !== '' ? $cat : 'their') . ' notifications to the “' . ($tone !== '' ? ucfirst($tone) : 'default') . '” tone'
            . (! empty($in['vibration']) ? ' with the ' . self::words((string) $in['vibration']) . ' vibration' : '') . '.';
    }

    private static function onboardingChild(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $n = fn ($k) => is_array($in[$k] ?? null) ? count($in[$k]) : 0;
        $bits = [
            $n('allergies') ? $n('allergies') . ' allerg' . ($n('allergies') === 1 ? 'y' : 'ies') : 'no allergies',
            $n('dietary_restrictions') ? $n('dietary_restrictions') . ' dietary need' . ($n('dietary_restrictions') === 1 ? '' : 's') : 'no dietary needs',
            $n('immunizations') . ' immunization' . ($n('immunizations') === 1 ? '' : 's') . ' recorded',
        ];
        if (! empty($in['medical_notes'])) $bits[] = 'medical notes added';
        return 'Filled in their child’s health details during sign-up: ' . implode(', ', $bits) . '.';
    }

    private static function reaction(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $on = self::words((string) ($in['target_type'] ?? 'post'));
        return 'Reacted “' . self::words((string) ($in['reaction'] ?? 'like')) . '” to a ' . ($on !== '' ? $on : 'post') . '.';
    }

    private static function medicationGiven(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $m = ! empty($in['medication_id']) ? DB::table('medications')->where('id', (int) $in['medication_id'])->first(['name', 'child_id', 'centre_id']) : null;
        $ok = $m && self::centreInAgency($m->centre_id, $p);
        $child = $ok ? self::kids([$m->child_id], $p) : '';
        $what = $ok && trim((string) $m->name) !== '' ? trim((string) $m->name) : 'a medication';
        return 'Gave ' . $what . ($child !== '' ? ' to ' . $child : '') . (! empty($in['dose_given']) ? ': ' . $in['dose_given'] : '')
            . (! empty($in['outcome']) ? ' (' . self::words((string) $in['outcome']) . ')' : '') . '.'
            . (! empty($in['witness_id']) && ($w = self::userName($in['witness_id'])) ? ' Witness: ' . $w . '.' : '')
            . (! empty($in['notes']) ? ' Notes: ' . mb_substr((string) $in['notes'], 0, 140) : '');
    }

    private static function aiWrite(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $act = self::words((string) ($in['action'] ?? 'improve'));
        $kind = self::words((string) ($in['kind'] ?? ''));
        return 'Asked the AI writing helper to ' . ($act !== '' ? $act : 'help with') . ' ' . ($kind !== '' ? (preg_match('/^[aeiou]/i', $kind) ? 'an ' : 'a ') . $kind . ' write-up' : 'some text')
            . ' (a suggestion only; nothing was saved by this).';
    }

    private static function teamStart(string $a, array $p, ?string $tz): ?string
    {
        $in = self::in($p);
        $to = (int) ($in['recipient_user_id'] ?? 0);
        $agency = (int) ($p['active_agency_id'] ?? 0);
        // A colleague is named only when they belong to the agency the chat was started in.
        $ok = $to > 0 && $agency > 0 && DB::table('role_assignments')->where('user_id', $to)->where('agency_id', $agency)->where('active', 1)->exists();
        $who = $ok ? self::userName($to) : null;
        $body = trim((string) ($in['body'] ?? ''));
        return 'Started a staff chat' . ($who ? ' with ' . $who : '') . ($body !== '' ? ': “' . mb_substr($body, 0, 120) . '”' : '') . '.';
    }

    private static function onboardingStarted(string $a, array $p, ?string $tz): ?string
    {
        return 'Opened their invitation link' . (! empty($p['email']) ? ' (sent to ' . $p['email'] . ')' : '') . ' and started setting up their account.';
    }

    private static function childDocument(string $a, array $p, ?string $tz): ?string
    {
        return 'Added “' . (string) ($p['title'] ?? 'a document') . '” to the child’s documents.';
    }

    private static function familyMessage(string $a, array $p, ?string $tz): ?string
    {
        $n = is_array($p['participants'] ?? null) ? count($p['participants']) : 0;
        return 'Sent a message in a family chat' . ($n ? ' (' . $n . ' people in it)' : '') . (! empty($p['has_attachment']) ? ', with a photo or file attached' : '') . '.';
    }

    private static function payslipEmailed(string $a, array $p, ?string $tz): ?string
    {
        return null; // writer stores its own sentence
    }
}
