<?php

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE "SUPPLIES NEEDED" EMAIL TO A FAMILY (2026-11-27, Anthony: "supplies needed email that gets sent out to the
 * parent isn't in the typical format").
 *
 * A supply request (App\Support\Supplies) goes out as a family-scoped announcement. Its email used to be the generic
 * announcement email around a body Supplies wrote itself: no header, no greeting, a bare bullet list and the asking
 * educator's full name as the signature. It is now a family letter in the house frame (the EmailTemplate wrapper, so the
 * agency's own branding, dark mode and footer): "Hi Michael," / what to bring for whom, as a card / needed by (agency
 * date) / a button into the app where they answer Will bring it, Dropped it off or Can't / signed by the agency
 * (FamilyFacingName). AddPlainTextPart gives it the text alternative.
 *
 * AnnouncementController::deliver() hands every supply announcement here, so the first email, the evening-before
 * reminder and a staff resend all look the same. The bell, push and task are unchanged.
 *
 * build() is pure (no mail, no writes) so it can be previewed and tested.
 */
class SupplyMail
{
    public const APP_LINK = 'https://app.kiddietrac.com/dashboard.html#home';

    /** The supply requests behind an announcement (empty = it is not a supply request). */
    public static function rows(int $announcementId)
    {
        try {
            return DB::table('supply_requests')->where('announcement_id', $announcementId)->orderBy('id')->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * $d: guardian (first name), groups [['who' => 'Liam', 'items' => [{label, note}], 'status' => open|coming|cant]],
     *     note, needed_by (Y-m-d or null), asked_by (staff FIRST name or ''), place ("Amna's home child care" /
     *     the centre), team (the agency, FamilyFacingName::agency), reminder (bool), support_email (optional).
     * @return array{subject:string, html:string}
     */
    public static function build(int $agencyId, array $d): array
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $groups = array_values(array_filter((array) ($d['groups'] ?? []), fn ($g) => ! empty($g['items'])));
        $names = array_values(array_unique(array_map(fn ($g) => (string) $g['who'], $groups)));
        $who = self::andList($names) ?: 'your child';
        $guardian = trim((string) ($d['guardian'] ?? ''));
        $place = trim((string) ($d['place'] ?? '')) ?: 'the centre';
        $team = trim((string) ($d['team'] ?? '')) ?: $place;
        $asked = trim((string) ($d['asked_by'] ?? ''));
        $reminder = ! empty($d['reminder']);
        $when = self::when($agencyId, $d['needed_by'] ?? null);   // ['long' => 'Friday, November 28', 'rel' => 'tomorrow' | '' ]
        $byLine = $when ? (($when['rel'] !== '' ? $when['rel'] . ', ' : '') . $when['long']) : '';
        $p = fn (string $t, string $extra = '') => '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#1E293B;' . $extra . '">' . $t . '</p>';

        // The card: one block per child (or per children sharing a list), each item on its own row.
        $multi = count($groups) > 1;
        $card = '';
        foreach ($groups as $gi => $g) {
            $status = (string) ($g['status'] ?? 'open');
            $tag = $status === 'coming' ? 'You said you’ll bring it' : ($status === 'cant' ? 'You said you can’t bring it' : '');
            if ($multi || $tag !== '') {
                $card .= '<tr><td colspan="2" style="padding:' . ($gi ? '14px' : '2px') . ' 0 6px;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">'
                    . 'For ' . $e($g['who'])
                    . ($tag !== '' ? ' <span style="text-transform:none;letter-spacing:0;font-weight:700;color:#1D4ED8;">· ' . $e($tag) . '</span>' : '')
                    . '</td></tr>';
            }
            foreach ($g['items'] as $it) {
                $card .= '<tr>'
                    . '<td width="26" style="padding:7px 0;border-top:1px solid #E2E8F0;font-size:15px;vertical-align:top;">🧺</td>'
                    . '<td style="padding:7px 0;border-top:1px solid #E2E8F0;font-size:15px;line-height:1.4;color:#0F172A;vertical-align:top;">'
                    . '<strong>' . $e($it['label'] ?? '') . '</strong>'
                    . (! empty($it['note']) ? '<br><span class="kt-muted" style="font-size:13px;color:#64748B;">' . $e($it['note']) . '</span>' : '')
                    . '</td></tr>';
            }
        }
        $cardHtml = '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="width:100%;border-collapse:separate;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin:0 0 16px;">'
            . '<tr><td style="padding:12px 16px 8px;">'
            . '<div style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#64748B;margin:0 0 4px;">PLEASE BRING' . ($multi ? '' : ' FOR ' . $e(mb_strtoupper($who))) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">' . $card . '</table>'
            . ($byLine !== '' ? '<div style="margin:8px 0 2px;font-size:14px;color:#0F172A;">📅 Needed by <strong>' . $e($byLine) . '</strong></div>' : '')
            . '</td></tr></table>';

        $colour = EmailTemplate::brandColours($agencyId)['primary'] ?? '#1F6080';
        $button = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 8px;"><tr>'
            . '<td bgcolor="' . $e($colour) . '" style="background:' . $e($colour) . ';border-radius:10px;">'
            . '<a class="kt-btn" href="' . self::APP_LINK . '" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:10px;">Answer in the app</a>'
            . '</td></tr></table>'
            . '<p class="kt-muted" style="margin:0 0 18px;font-size:13px;line-height:1.55;color:#64748B;">On your home screen, tap '
            . '<strong>👍 Will bring it</strong>, <strong>✓ Dropped it off</strong> or <strong>Can’t bring it</strong>, so ' . $e($asked !== '' ? $asked : 'the team') . ' knows what to expect.</p>';

        $intro = $reminder
            ? 'Just a friendly reminder: ' . $e($who) . (count($names) > 1 ? ' still need' : ' still needs') . ' a few things from home' . ($byLine !== '' ? ' by <strong>' . $e($byLine) . '</strong>' : '') . '.'
            : ($asked !== ''
                ? $e($asked) . ' at ' . $e($place) . ' has asked for a few things from home for <strong>' . $e($who) . '</strong>.'
                : 'We need a few things from home for <strong>' . $e($who) . '</strong> at ' . $e($place) . '.');

        $body = $p($guardian !== '' ? 'Hi ' . $e($guardian) . ',' : 'Hello,')
            . $p($intro)
            . $cardHtml
            . (! empty($d['note']) ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px;"><tr>'
                . '<td class="kt-callout" style="background:#EFF6FF;border-radius:8px;padding:12px 14px;font-size:14px;line-height:1.55;color:#1E3A8A;">'
                . '<strong>A note' . ($asked !== '' ? ' from ' . $e($asked) : '') . ':</strong> ' . nl2br($e($d['note'])) . '</td></tr></table>' : '')
            . $button
            . $p('Thank you for helping ' . $e($who) . ' have a great day,<br><strong>The ' . $e($team) . ' team</strong>', 'margin:0;');

        $title = $reminder
            ? 'Reminder: ' . ($when && $when['rel'] === 'tomorrow' ? $who . (count($names) > 1 ? ' need' : ' needs') . ' a few things tomorrow' : 'supplies for ' . $who)
            : 'Supplies needed for ' . $who;
        $flat = [];
        foreach ($groups as $g) foreach ($g['items'] as $it) $flat[] = (string) ($it['label'] ?? '');
        $flat = array_values(array_unique(array_filter($flat)));
        $list = implode(', ', array_slice($flat, 0, 4)) . (count($flat) > 4 ? ' and more' : '');
        $html = EmailTemplate::wrap($agencyId, $body, array_filter([
            'eyebrow' => $reminder ? '🧺 SUPPLIES REMINDER' : '🧺 SUPPLIES FROM HOME',
            'title' => $title,
            'subtitle' => ($when ? 'Needed by ' . $when['long'] . ' · ' : '') . $place,
            'preheader' => 'Please bring ' . $list . ' for ' . $who . ($when ? ' by ' . $when['long'] : '') . '.',
            'support_email' => $d['support_email'] ?? null,
        ]));

        return [
            'subject' => $title . (($when && ! ($reminder && $when['rel'] === 'tomorrow')) ? ' by ' . Carbon::parse($d['needed_by'])->format('D, M j') : ''),
            'html' => $html,
        ];
    }

    /**
     * Email the family about a supply announcement: the first notice ($reminder false) or the evening-before reminder,
     * listing only what is still pending. $userIds is who deliver() worked out (the family's parents, or those not yet done).
     * Queued on 'mail', paced like the announcement email. Returns how many were queued.
     */
    public static function queue(int $announcementId, array $userIds, bool $reminder, $sender): int
    {
        $rows = self::rows($announcementId);
        if (! $rows->count() || ! $userIds) return 0;
        // Only what is still to come (open, coming, can't): a reminder or a resend never lists what was dropped off.
        $rows = $rows->whereIn('status', Supplies::PENDING)->values();
        if (! $rows->count()) return 0;

        $first = $rows->first();
        $agencyId = (int) $first->agency_id;
        $centreId = (int) $first->centre_id;
        $d = self::context($rows, $agencyId, $centreId);

        $people = DB::table('users')->whereIn('id', $userIds)->whereNotNull('email')->where('email', '<>', '')
            ->whereNull('deleted_at')->whereNotIn('status', Audience::OFF_STATUSES)->get(['id', 'email', 'first_name']);
        $replyTo = (! empty($sender->email) && filter_var($sender->email, FILTER_VALIDATE_EMAIL)) ? (string) $sender->email : null;
        $replyName = trim((string) ($sender->first_name ?? '')) ?: null;   // first name only (no staff last names in email)
        $n = 0;
        foreach ($people->values() as $i => $u) {
            $m = self::build($agencyId, array_merge($d, [
                'guardian' => (string) (strtok(trim((string) $u->first_name), ' ') ?: ''),
                'reminder' => $reminder, 'support_email' => $replyTo,
            ]));
            $email = (string) $u->email;
            $subject = $m['subject'];
            $html = $m['html'];
            dispatch(function () use ($email, $subject, $html, $agencyId, $replyTo, $replyName) {
                try {
                    $svc = AgencyMailer::forAgency($agencyId);
                    $from = $svc->fromAddress();
                    $fn = $svc->fromName();
                    $svc->html($html, function ($msg) use ($email, $from, $fn, $subject, $agencyId, $replyTo, $replyName) {
                        MailScope::agency($msg, $agencyId);
                        $msg->to($email)->from($from, $fn)->subject($subject);
                        if ($replyTo) $msg->replyTo($replyTo, $replyName);
                    });
                } catch (\Throwable $e) {
                    Log::warning('Supplies email failed', ['email' => $email, 'error' => $e->getMessage()]);
                }
            })->onQueue('mail')->delay(now()->addSeconds(intdiv($i, 5) * 10));
            $n++;
        }

        return $n;
    }

    /** The shared parts of the letter, from the requests themselves. */
    public static function context($rows, int $agencyId, int $centreId): array
    {
        $kids = DB::table('children')->whereIn('id', $rows->pluck('child_id')->all() ?: [0])->get(['id', 'first_name', 'preferred_name'])->keyBy('id');
        // Children with the same list and answer share one block ("For Liam and Mia").
        $byList = [];
        foreach ($rows as $r) {
            $items = json_decode((string) $r->items, true) ?: [];
            $key = md5(json_encode($items) . '|' . $r->status);
            $byList[$key] ??= ['names' => [], 'items' => $items, 'status' => (string) $r->status];
            $k = $kids[$r->child_id] ?? null;
            $byList[$key]['names'][] = $k ? Supplies::childName($k) : 'your child';
        }
        $groups = array_map(fn ($g) => ['who' => self::andList(array_values(array_unique($g['names']))), 'items' => $g['items'], 'status' => $g['status']], array_values($byList));
        $askedBy = $rows->first()->requested_by ? DB::table('users')->where('id', $rows->first()->requested_by)->value('first_name') : null;
        $needed = $rows->pluck('needed_by')->filter()->sort()->first();

        return [
            'groups' => $groups,
            'note' => $rows->first()->note,
            'needed_by' => $needed ? substr((string) $needed, 0, 10) : null,
            'asked_by' => (string) (strtok(trim((string) $askedBy), ' ') ?: ''),
            'place' => FamilyFacingName::place($centreId ?: null),
            'team' => FamilyFacingName::agency($agencyId) ?: FamilyFacingName::org($centreId ?: null, $agencyId),
        ];
    }

    /** "Liam", "Liam and Mia", "Liam, Mia and Noah". */
    public static function andList(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names)));
        if (count($names) <= 1) return $names[0] ?? '';

        return implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
    }

    /** The needed-by date as the agency reads it: its long form, and "today" / "tomorrow" when it is. */
    private static function when(int $agencyId, ?string $date): ?array
    {
        if (! $date) return null;
        try {
            $day = Carbon::parse(substr($date, 0, 10), AgencyTime::tz($agencyId))->startOfDay();
            $today = Carbon::parse(AgencyTime::today($agencyId), AgencyTime::tz($agencyId))->startOfDay();
            $diff = (int) $today->diffInDays($day, false);

            return ['long' => $day->format('l, F j'), 'rel' => $diff === 0 ? 'today' : ($diff === 1 ? 'tomorrow' : '')];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
