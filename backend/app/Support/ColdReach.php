<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * COLD REACH (2026-10-30). Anthony: "under leads as a subtab for cold call/reach with ability to cold call and reach out
 * that is not necessarily a real lead and have the ability to convert to a real lead add stats, graphs and indicators as
 * to who opened emails etc", then "cold call list should have settings to reach back out if no response after x days etc
 * - full settings options with follow ups etc".
 *
 * A prospect (sales_prospects) is someone we reach out to before they are a lead. Every call / email / note is a touch
 * (sales_prospect_touches). Emails go from sales@ (through the connected Microsoft mailbox when there is one, so replies
 * land in Sales → Email and the sent copy is in Sent Items), carry an open pixel and a CASL footer with an unsubscribe
 * link, and never go to a suppressed address (sales_suppressions - the same list the campaigns honour).
 *
 * FOLLOW-UPS: a sequence of steps ("after N days, email / call"), set under Cold reach → Settings. After the first
 * outreach the prospect is due for step 1 after its delay; doing that step (by hand, or automatically for an email step
 * when auto-send is on) schedules the next. A reply, interest, "not interested", unsubscribe or conversion ends it.
 * Auto-send is OFF by default: due follow-ups are listed and the owner gets a bell - nothing goes out unseen.
 */
final class ColdReach
{
    public const SETTINGS_KEY = 'sales.cold_reach';
    public const TZ = 'America/Toronto';
    public const STATUSES = [
        'new' => 'New', 'contacted' => 'Contacted', 'engaged' => 'Opened', 'replied' => 'Replied', 'interested' => 'Interested',
        'not_interested' => 'Not interested', 'no_response' => 'No response', 'do_not_contact' => 'Do not contact', 'converted' => 'Converted to lead',
    ];
    /** Statuses still in the follow-up sequence. */
    public const ACTIVE = ['contacted', 'engaged'];
    public const CALL_OUTCOMES = [
        'no_answer' => 'No answer', 'voicemail' => 'Left a voicemail', 'connected' => 'Spoke with them', 'interested' => 'Interested',
        'not_interested' => 'Not interested', 'call_back' => 'Asked us to call back', 'wrong_number' => 'Wrong number',
    ];
    private const API = 'https://api.kiddietrac.com/api/v1';

    public static function defaults(): array
    {
        return [
            'follow_ups' => true,
            'mode' => 'remind',            // remind: list + bell, a person sends · auto: email steps send themselves
            'business_days' => true,       // follow-up waits count only Mon-Fri
            /* ALL DAY, EVERY DAY (2026-11-11, Anthony: "increase the campaign sent timing to all day every day with the
               same incremental sends ... to get through a bigger list quicker"). It was 09:00-17:00 on weekdays at 40 a
               day: 2 every 15 minutes, done by 13:45. Now the same 2 every 15 minutes around the clock, seven days -
               96 passes x 2 = 192 a day. The same start and end time means all day. */
            'send_weekends' => true,       // queued and automatic emails go on Saturday and Sunday too
            'window_start' => '00:00',     // queued and automatic emails only inside this window (Toronto time)
            'window_end' => '00:00',
            'daily_cap' => 192,            // cold emails per day, at most (spread evenly over the window's 15-minute passes)
            'stop_on_open' => false,       // an open alone does not end the sequence (opens are unreliable)
            'after_last' => 'no_response', // when the last step is done with no reply: no_response | leave
            'remind_owner' => true,        // bell the owner when a step comes due
            'first' => [
                'subject' => 'Making the day easier at {organization}',
                'body' => "Hi {first_name},\n\nI'm {sender_first_name} with KiddieTrac, a childcare management platform built in Ontario for centres and home daycares: attendance, billing and subsidies, parent messaging and daily reports, all in one place.\n\nWould you be open to a 20-minute call to see whether it could save your team time at {organization}?\n\nThanks,",
            ],
            'steps' => [
                ['after_days' => 3, 'channel' => 'email', 'subject' => 'Re: Making the day easier at {organization}',
                    'body' => "Hi {first_name},\n\nJust following up on my note from earlier this week. If it's easier, reply with a good time and I'll work around you.\n\nThanks,"],
                ['after_days' => 4, 'channel' => 'call', 'subject' => '', 'body' => 'Call {first_name} at {organization}.'],
                ['after_days' => 7, 'channel' => 'email', 'subject' => 'Should I close your file?',
                    'body' => "Hi {first_name},\n\nI haven't heard back, so I'll assume the timing isn't right and won't keep filling your inbox. If things change, just reply to this email and I'll be glad to help.\n\nAll the best,"],
            ],
        ];
    }

    public static function settings(): array
    {
        $d = self::defaults();
        $s = PlatformSettings::get(self::SETTINGS_KEY);
        $s = is_string($s) ? (json_decode($s, true) ?: []) : (is_array($s) ? $s : []);
        $out = array_merge($d, array_intersect_key($s, $d));
        if (! isset($s['steps']) || ! is_array($s['steps'])) $out['steps'] = $d['steps'];
        if (! isset($s['first']) || ! is_array($s['first'])) $out['first'] = $d['first'];
        return $out;
    }

    public static function saveSettings(array $in): array
    {
        $d = self::defaults();
        $hm = fn ($v, $def) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $def;
        $steps = [];
        foreach (array_slice((array) ($in['steps'] ?? []), 0, 8) as $st) {
            $steps[] = [
                'after_days' => max(1, min(60, (int) ($st['after_days'] ?? 3))),
                'channel' => in_array($st['channel'] ?? '', ['email', 'call'], true) ? $st['channel'] : 'email',
                'subject' => mb_substr(trim((string) ($st['subject'] ?? '')), 0, 200),
                'body' => mb_substr(trim((string) ($st['body'] ?? '')), 0, 5000),
            ];
        }
        $camp = function ($v) {
            $id = (int) $v;
            return $id > 0 && DB::table('sales_campaigns')->where('id', $id)->whereNull('deleted_at')->whereNull('template')->exists() ? $id : null;
        };
        foreach (array_slice((array) ($in['steps'] ?? []), 0, 8) as $i => $st) { if (isset($steps[$i])) $steps[$i]['campaign_id'] = $camp($st['campaign_id'] ?? null); }
        $s = [
            'follow_ups' => (bool) ($in['follow_ups'] ?? $d['follow_ups']),
            'mode' => in_array($in['mode'] ?? '', ['remind', 'auto'], true) ? $in['mode'] : $d['mode'],
            'business_days' => (bool) ($in['business_days'] ?? $d['business_days']),
            'send_weekends' => (bool) ($in['send_weekends'] ?? $d['send_weekends']),
            'window_start' => $hm($in['window_start'] ?? null, $d['window_start']),
            'window_end' => $hm($in['window_end'] ?? null, $d['window_end']),
            'daily_cap' => max(1, min(500, (int) ($in['daily_cap'] ?? $d['daily_cap']))),
            'stop_on_open' => (bool) ($in['stop_on_open'] ?? false),
            'after_last' => in_array($in['after_last'] ?? '', ['no_response', 'leave'], true) ? $in['after_last'] : $d['after_last'],
            'remind_owner' => (bool) ($in['remind_owner'] ?? $d['remind_owner']),
            'first' => [
                'subject' => mb_substr(trim((string) ($in['first']['subject'] ?? $d['first']['subject'])), 0, 200) ?: $d['first']['subject'],
                'body' => mb_substr(trim((string) ($in['first']['body'] ?? $d['first']['body'])), 0, 5000) ?: $d['first']['body'],
                'campaign_id' => $camp($in['first']['campaign_id'] ?? null),
            ],
            'steps' => $steps,
        ];
        PlatformSettings::set(self::SETTINGS_KEY, json_encode($s));
        return $s;
    }

    /** The sending window: true all day when the start and end are the same time. */
    public static function allDay(array $cfg): bool
    {
        return $cfg['window_start'] === $cfg['window_end'];
    }

    /** The window in words, for the toast after queuing: "all day, every day" / "09:00-17:00 on weekdays". */
    public static function windowLabel(array $cfg): string
    {
        $w = self::allDay($cfg) ? 'all day' : $cfg['window_start'] . '-' . $cfg['window_end'];
        return $w . (! empty($cfg['send_weekends']) ? ', every day' : ' on weekdays');
    }

    /** Add N days (business days when set) to a moment. */
    public static function addDays(Carbon $from, int $days, bool $business): Carbon
    {
        $t = $from->copy()->setTimezone(self::TZ);
        if (! $business) return $t->addDays($days)->utc();
        $n = 0;
        while ($n < $days) { $t->addDay(); if (! $t->isWeekend()) $n++; }
        return $t->utc();
    }

    /** When the step after `$done` follow-ups comes due, from `$from` - or null when the sequence is finished. */
    public static function nextDue(int $done, Carbon $from, ?array $cfg = null): ?Carbon
    {
        $cfg = $cfg ?: self::settings();
        if (! $cfg['follow_ups']) return null;
        $st = $cfg['steps'][$done] ?? null;
        return $st ? self::addDays($from, (int) $st['after_days'], (bool) $cfg['business_days']) : null;
    }

    public static function fill(string $text, object $p, ?int $senderId): string
    {
        $first = trim((string) strtok(trim((string) $p->name), ' '));
        $sender = $senderId ? trim((string) DB::table('users')->where('id', $senderId)->value('first_name')) : '';
        return strtr($text, [
            '{first_name}' => $first !== '' ? $first : 'there',
            '{name}' => trim((string) $p->name) ?: 'there',
            '{organization}' => trim((string) $p->organization) ?: 'your centre',
            '{city}' => trim((string) $p->city),
            '{sender_first_name}' => $sender !== '' ? $sender : 'the KiddieTrac team',
        ]);
    }

    /** A personal-looking email: the text, a first-name sign-off, the CASL footer, the pixel. */
    public static function html(object $p, string $body, ?int $senderId, ?string $token): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $paras = array_filter(preg_split("/\n{2,}/", str_replace("\r", '', $body)), fn ($x) => trim($x) !== '');
        $html = '';
        foreach ($paras as $para) $html .= '<p style="margin:0 0 14px;font:400 15px/1.6 ' . $font . ';color:#1F2937;">' . nl2br($e(trim($para))) . '</p>';
        $sender = SalesCampaigns::senderName($senderId);
        $consent = SalesCampaigns::CONSENT[$p->consent_basis] ?? SalesCampaigns::CONSENT['implied_published'];
        $unsub = $token ? self::API . '/c/pu/' . $token : 'mailto:' . SalesCampaigns::FROM . '?subject=unsubscribe';
        $pixel = $token ? '<img src="' . self::API . '/c/po/' . $token . '.gif" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">' : '';
        return '<!doctype html><html><body style="margin:0;padding:0;background:#ffffff;"><div style="max-width:620px;padding:8px 4px;">'
            . $html
            . '<p style="margin:0 0 4px;font:400 15px/1.55 ' . $font . ';color:#1F2937;"><b>' . $e($sender) . '</b><br>'
            . '<span style="color:#64748B;font-size:13.5px;">' . $e(SalesCampaigns::FROM) . ' · ' . $e(SalesCampaigns::PHONE) . ' · kiddietrac.com</span></p>'
            . '<p style="margin:26px 0 0;padding-top:10px;border-top:1px solid #EEF2F6;font:400 11.5px/1.6 ' . $font . ';color:#8A94A6;">'
            . $e($consent[1]) . ' ' . $e(SalesCampaigns::ADDRESS) . '. <a href="' . $e($unsub) . '" style="color:#8A94A6;">Unsubscribe</a></p>'
            // KiddieTrac's pages, quietly, under the footer (2026-11-03; App\Support\SocialLinks).
            . '<div style="margin:8px 0 0;">' . SocialLinks::block(SocialLinks::row('#8A94A6', 'font-family:' . $font . ';', 'Follow us', '#8A94A6', 'left')) . '</div>'
            . $pixel . '</div></body></html>';
    }

    public static function suppressed(?string $email): bool
    {
        return ! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL) || SalesCampaigns::suppressed($email);
    }

    /**
     * A DESIGNED EMAIL FROM A SALES CAMPAIGN (2026-10-30, Anthony: "we created the cold call campaign with the new email
     * this needs to be wired up to be used here in cold reach, and we need to wire up the follow up emails each unique").
     * The prospect becomes a recipient of that campaign, and SalesCampaigns sends it exactly as a campaign send: the same
     * design, first name, CASL footer, tracked links, "send me more information" and one-click unsubscribe. Its token is
     * the touch's token, so opens / clicks / replies land on the prospect too (SalesCampaigns calls back into
     * fromCampaign()). Returns [ok, error].
     */
    public static function sendCampaign(object $p, int $campaignId, ?int $senderId, int $step, bool $automatic): array
    {
        $c = DB::table('sales_campaigns')->where('id', $campaignId)->whereNull('deleted_at')->first();
        if (! $c || ! empty($c->template)) return [false, 'That campaign email is no longer available.'];
        if (self::suppressed($p->email)) return [false, 'This address unsubscribed or is not a valid email.'];
        if ($p->status === 'do_not_contact') return [false, 'This prospect is marked do not contact.'];
        $addr = mb_strtolower(trim((string) $p->email));
        $have = DB::table('sales_campaign_recipients')->where('campaign_id', $c->id)->where('email', $addr)->first();
        if ($have && ($have->sent_at || $have->status === 'sent')) return [false, 'This address already received this email.'];
        if ($have) {
            $rid = (int) $have->id; $token = $have->token;
            DB::table('sales_campaign_recipients')->where('id', $rid)->update([
                'name' => $have->name ?: ($p->name ?: null), 'company' => $have->company ?: ($p->organization ?: null),
                'consent_basis' => $have->consent_basis ?: ($p->consent_basis ?: $c->consent_basis), 'status' => 'queued', 'error' => null, 'updated_at' => now(),
            ]);
        } else {
            $token = SalesCampaigns::token();
            $rid = DB::table('sales_campaign_recipients')->insertGetId([
                'campaign_id' => $c->id, 'email' => $addr, 'name' => $p->name ?: null, 'company' => $p->organization ?: null,
                'consent_basis' => $p->consent_basis ?: $c->consent_basis, 'token' => $token, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $r = DB::table('sales_campaign_recipients')->find($rid);
        [$ok, $err] = SalesCampaigns::sendOne($c, $r, null, $senderId);
        DB::table('sales_campaign_recipients')->where('id', $rid)->update($ok
            ? ['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]
            : ['status' => 'failed', 'error' => mb_substr((string) $err, 0, 290), 'updated_at' => now()]);
        SalesCampaigns::recount((int) $c->id);
        DB::table('sales_prospect_touches')->insert([
            'prospect_id' => $p->id, 'kind' => 'email', 'outcome' => $ok ? 'sent' : 'failed', 'step' => $step, 'automatic' => $automatic,
            'subject' => mb_substr((string) $c->subject, 0, 255), 'body' => 'Designed email: ' . $c->name, 'token' => $ok ? $token : null,
            'error' => $ok ? null : mb_substr((string) $err, 0, 290), 'user_id' => $senderId, 'created_at' => now(),
        ]);
        if (! $ok) return [false, $err ?: 'The email could not be sent.'];
        DB::table('sales_prospects')->where('id', $p->id)->update(['emails_sent' => DB::raw('emails_sent + 1'), 'updated_at' => now()]);
        self::afterOutbound(DB::table('sales_prospects')->where('id', $p->id)->first(), $step);
        return [true, null];
    }

    /** A campaign recipient opened / clicked / responded: if it was a cold-reach send, the prospect shows it too. */
    public static function fromCampaign(object $r, string $what, ?Request $req = null): void
    {
        try {
            $t = DB::table('sales_prospect_touches')->where('token', $r->token)->first();
            if (! $t) return;
            if ($what === 'opened' && $req) { self::opened($t, $req); return; }
            $p = DB::table('sales_prospects')->where('id', $t->prospect_id)->first();
            if (! $p) return;
            if ($what === 'unsubscribed') { self::stop((int) $p->id, 'do_not_contact'); }
            elseif (in_array($what, ['interested', 'booked'], true)) { self::stop((int) $p->id, 'interested'); }
            elseif ($what === 'replied') { self::stop((int) $p->id, 'replied', ['replied_at' => now()]); }
            elseif ($what === 'clicked' && $p->status === 'contacted') { DB::table('sales_prospects')->where('id', $p->id)->update(['status' => 'engaged', 'updated_at' => now()]); }
            if ($what !== 'clicked') {
                DB::table('sales_prospect_touches')->insert(['prospect_id' => $p->id, 'kind' => 'note', 'outcome' => $what === 'booked' ? 'interested' : $what,
                    'body' => ['unsubscribed' => 'Unsubscribed from the link in our email.', 'interested' => 'Asked for more information from our email.',
                        'booked' => 'Booked a demo from our email.', 'replied' => 'Replied to our email.'][$what] ?? ucfirst($what), 'created_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('cold reach campaign sync failed', ['what' => $what, 'error' => $e->getMessage()]);
        }
    }

    /** The email for a step: the designed campaign when one is chosen, otherwise the plain email. */
    public static function sendStep(object $p, array $tpl, ?int $senderId, int $step, bool $automatic): array
    {
        if (! empty($tpl['campaign_id'])) return self::sendCampaign($p, (int) $tpl['campaign_id'], $senderId, $step, $automatic);
        return self::sendEmail($p, (string) ($tpl['subject'] ?? '') ?: 'Following up', (string) ($tpl['body'] ?? ''), $senderId, $step, $automatic);
    }

    /**
     * Send one email to a prospect and record it. $step: 0 = the first outreach, n = follow-up n. Returns [ok, error].
     */
    public static function sendEmail(object $p, string $subject, string $body, ?int $senderId, int $step, bool $automatic): array
    {
        if (self::suppressed($p->email)) return [false, 'This address unsubscribed or is not a valid email.'];
        if ($p->status === 'do_not_contact') return [false, 'This prospect is marked do not contact.'];
        $token = Str::lower(Str::random(40));
        $subject = trim(self::fill($subject, $p, $senderId)) ?: 'KiddieTrac';
        $text = self::fill($body, $p, $senderId);
        $html = self::html($p, $text, $senderId, $token);
        $err = null;
        try {
            /* Always the full MIME send (GraphTransport, as sales@): it carries the plain-text part, List-Unsubscribe
               and One-Click headers. The mailbox's simple compose form carried HTML only and no headers, which spam
               filters score (2026-10-30, deliverability). */
            {
                Mail::html($html, function ($m) use ($p, $subject, $token) {
                    MailScope::platform($m);
                    $m->to($p->email, $p->name ?: null)->subject($subject)->replyTo(SalesCampaigns::FROM, 'KiddieTrac Sales');
                    $h = $m->getHeaders();
                    $h->addTextHeader('X-KT-Send-As', SalesCampaigns::FROM);
                    $h->addTextHeader('List-Unsubscribe', '<' . self::API . '/c/pu/' . $token . '>, <mailto:' . SalesCampaigns::FROM . '?subject=unsubscribe>');
                    $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                });
            }
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 290);
            Log::warning('cold reach email failed', ['prospect' => $p->id, 'error' => $err]);
        }
        DB::table('sales_prospect_touches')->insert([
            'prospect_id' => $p->id, 'kind' => 'email', 'outcome' => $err ? 'failed' : 'sent', 'step' => $step, 'automatic' => $automatic,
            'subject' => mb_substr($subject, 0, 255), 'body' => $text, 'token' => $err ? null : $token, 'error' => $err,
            'user_id' => $senderId, 'created_at' => now(),
        ]);
        if ($err) return [false, $err];
        DB::table('sales_prospects')->where('id', $p->id)->update(['emails_sent' => DB::raw('emails_sent + 1'), 'updated_at' => now()]);
        self::afterOutbound(DB::table('sales_prospects')->where('id', $p->id)->first(), $step);
        return [true, null];
    }

    /**
     * An outbound touch happened (email sent / call made): move the sequence along. A first touch starts it; a touch made
     * while a follow-up is due (or within 12 hours of it) counts as that step.
     */
    public static function afterOutbound(object $p, ?int $step = null): void
    {
        $cfg = self::settings();
        $now = now();
        $upd = ['last_contacted_at' => $now, 'updated_at' => $now];
        if ($p->status === 'new') {
            $upd['status'] = 'contacted';
            $upd['follow_up_step'] = 0;
            $upd['reminded_step'] = null;
            $upd['next_follow_up_at'] = self::nextDue(0, $now, $cfg);
        } elseif (in_array($p->status, self::ACTIVE, true)) {
            $due = $p->next_follow_up_at ? Carbon::parse($p->next_follow_up_at) : null;
            if ($due && $due->lte($now->copy()->addHours(12))) {
                $done = (int) $p->follow_up_step + 1;
                $upd['follow_up_step'] = $done;
                $upd['reminded_step'] = null;
                $next = self::nextDue($done, $now, $cfg);
                $upd['next_follow_up_at'] = $next;
                if (! $next && $cfg['after_last'] === 'no_response') {
                    // The last step is done; give them that step's wait before calling it "no response".
                    $upd['next_follow_up_at'] = self::addDays($now, 5, (bool) $cfg['business_days']);
                    $upd['follow_up_step'] = $done;   // > count(steps) marks "finishing"
                }
            }
        }
        DB::table('sales_prospects')->where('id', $p->id)->update($upd);
    }

    /** The sequence ends: replied / interested / not interested / do not contact / converted. */
    public static function stop(int $id, string $status, array $extra = []): void
    {
        DB::table('sales_prospects')->where('id', $id)->update(array_merge([
            'status' => $status, 'next_follow_up_at' => null, 'reminded_step' => null, 'updated_at' => now(),
        ], $extra));
    }

    public static function opened(object $touch, Request $req): void
    {
        $ua = strtolower((string) $req->userAgent());
        if ($touch->created_at && now()->diffInSeconds(Carbon::parse($touch->created_at), true) < 20) return;   // scanner
        if ($ua === '' || preg_match('/bot|crawler|spider|preview|scanner|safelinks|proofpoint|mimecast|barracuda|urldefense/', $ua)) return;
        DB::table('sales_prospect_touches')->where('id', $touch->id)->update([
            'opened_at' => $touch->opened_at ?: now(), 'open_count' => DB::raw('open_count + 1'),
        ]);
        $p = DB::table('sales_prospects')->where('id', $touch->prospect_id)->first();
        if (! $p) return;
        $upd = ['opens' => DB::raw('opens + 1'), 'last_opened_at' => now(), 'updated_at' => now()];
        if ($p->status === 'contacted') $upd['status'] = 'engaged';
        DB::table('sales_prospects')->where('id', $p->id)->update($upd);
        if (self::settings()['stop_on_open'] && in_array($p->status, self::ACTIVE, true)) {
            DB::table('sales_prospects')->where('id', $p->id)->update(['next_follow_up_at' => null]);
        }
    }

    public static function unsubscribe(object $touch): void
    {
        $p = DB::table('sales_prospects')->where('id', $touch->prospect_id)->first();
        if (! $p || ! $p->email) return;
        DB::table('sales_suppressions')->updateOrInsert(['email' => mb_strtolower(trim($p->email))],
            ['reason' => 'unsubscribed', 'updated_at' => now(), 'created_at' => now()]);
        self::stop((int) $p->id, 'do_not_contact');
        DB::table('sales_prospect_touches')->insert(['prospect_id' => $p->id, 'kind' => 'note', 'outcome' => 'unsubscribed',
            'body' => 'Unsubscribed from the link in our email.', 'created_at' => now()]);
    }

    /** Did this address write to sales@ since $since? (Microsoft mailbox only; null = cannot tell.) */
    public static function repliedSince(string $email, Carbon $since): ?Carbon
    {
        try {
            $acct = SalesMailbox::account();
            if (! $acct || $acct->provider !== 'microsoft') return null;
            $t = BookingTeams::appToken();
            if (! $t) return null;
            $flt = "from/emailAddress/address eq '" . str_replace("'", "''", mb_strtolower($email)) . "' and receivedDateTime ge " . $since->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            $r = Http::withToken($t)->timeout(15)->get('https://graph.microsoft.com/v1.0/users/' . rawurlencode((string) $acct->email_address) . '/messages',
                ['$filter' => $flt, '$top' => 1, '$select' => 'id,receivedDateTime']);
            if (! $r->successful()) return null;
            $m = $r->json('value.0');
            return $m ? Carbon::parse($m['receivedDateTime']) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The scheduled pass (every 15 minutes): spot replies, then handle due follow-ups - bell the owner, or send an email
     * step when auto-send is on and it is inside the window. Returns a summary for the command's output.
     */
    public static function run(bool $dry = false): array
    {
        $cfg = self::settings();
        $now = now();
        $out = ['replies' => 0, 'sent' => 0, 'reminded' => 0, 'finished' => 0, 'skipped' => 0];

        // 1) Replies, soonest-due first, a bounded number per pass.
        $watch = DB::table('sales_prospects')->whereNull('deleted_at')->whereIn('status', self::ACTIVE)->where('emails_sent', '>', 0)
            ->whereNotNull('email')->orderByRaw('next_follow_up_at IS NULL, next_follow_up_at')->limit(40)->get();
        foreach ($watch as $p) {
            $since = Carbon::parse($p->last_contacted_at ?: $p->created_at)->subMinutes(1);
            $at = self::repliedSince((string) $p->email, $since);
            if (! $at) continue;
            $out['replies']++;
            if ($dry) continue;
            self::stop((int) $p->id, 'replied', ['replied_at' => $at]);
            DB::table('sales_prospect_touches')->insert(['prospect_id' => $p->id, 'kind' => 'note', 'outcome' => 'replied',
                'body' => 'Replied by email (in the sales@ mailbox).', 'created_at' => now()]);
            self::bell($p, '↩️ ' . (self::who($p)) . ' replied', 'They answered your cold-reach email. Their reply is in Sales → Email.');
        }

        // 2) The queue, then due follow-ups.
        $tz = $now->copy()->setTimezone(self::TZ);
        $inWindow = (self::allDay($cfg) || ($tz->format('H:i') >= $cfg['window_start'] && $tz->format('H:i') < $cfg['window_end']))
            && (! empty($cfg['send_weekends']) || ! $tz->isWeekend());
        // EVERY cold email today counts toward the cap - by hand, queued or automatic (deliverability, 2026-10-30).
        $sentToday = (int) DB::table('sales_prospect_touches')->where('kind', 'email')->where('outcome', 'sent')
            ->where('created_at', '>=', $tz->copy()->startOfDay()->utc())->count();

        /* THE QUEUE: first emails from "Email selected" go out a few at a time through the sending window, never in one
           burst - a burst of first-contact mail is what gets a domain flagged as spam. Per 15-minute pass: the day's
           cap spread over the window's passes (at least 1, at most 8). */
        if ($inWindow && $sentToday < (int) $cfg['daily_cap']) {
            [$h1, $m1] = array_map('intval', explode(':', $cfg['window_start'])); [$h2, $m2] = array_map('intval', explode(':', $cfg['window_end']));
            $passes = max(1, (int) floor((self::allDay($cfg) ? 1440 : (($h2 * 60 + $m2) - ($h1 * 60 + $m1))) / 15));
            $perPass = max(1, min(8, (int) ceil((int) $cfg['daily_cap'] / $passes)));
            $room = min($perPass, (int) $cfg['daily_cap'] - $sentToday);
            $queued = DB::table('sales_prospects')->whereNull('deleted_at')->whereNotNull('queued_at')->orderBy('queued_at')->orderBy('id')->limit($room)->get();
            foreach ($queued as $q) {
                $tpl = json_decode((string) $q->queued_email, true) ?: [];
                if ($dry) { $out['queued_sent'] = ($out['queued_sent'] ?? 0) + 1; continue; }
                DB::table('sales_prospects')->where('id', $q->id)->update(['queued_at' => null, 'queued_email' => null]);
                if ($q->status !== 'new' || ! $q->email || self::suppressed($q->email)) continue;
                try {
                    [$ok] = self::sendStep($q, $tpl, $q->queued_by ? (int) $q->queued_by : ($q->owner_id ? (int) $q->owner_id : null), 0, false);
                } catch (\Throwable $e) {
                    DB::table('sales_prospects')->where('id', $q->id)->update(['queued_at' => now(), 'queued_email' => $q->queued_email]);
                    Log::warning('cold reach queued send failed', ['prospect' => $q->id, 'error' => mb_substr($e->getMessage(), 0, 290)]);
                    continue;
                }
                if ($ok) { $sentToday++; $out['queued_sent'] = ($out['queued_sent'] ?? 0) + 1; }
            }
        }
        if (! $cfg['follow_ups']) return $out;   // the queue above still runs with follow-ups off
        $due = DB::table('sales_prospects')->whereNull('deleted_at')->whereIn('status', self::ACTIVE)->where('follow_ups_paused', false)
            ->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', $now)->orderBy('next_follow_up_at')->limit(200)->get();
        $n = count($cfg['steps']);
        foreach ($due as $p) {
            $i = (int) $p->follow_up_step;
            if ($i >= $n) {   // the last step was done and its wait passed without a reply
                $out['finished']++;
                if (! $dry) self::stop((int) $p->id, $cfg['after_last'] === 'no_response' ? 'no_response' : $p->status);
                continue;
            }
            $st = $cfg['steps'][$i];
            if ($cfg['mode'] === 'auto' && $st['channel'] === 'email' && $p->email && ! self::suppressed($p->email)) {
                if (! $inWindow || $sentToday >= (int) $cfg['daily_cap']) { $out['skipped']++; continue; }
                if ($dry) { $out['sent']++; continue; }
                [$ok] = self::sendStep($p, $st, $p->owner_id ? (int) $p->owner_id : null, $i + 1, true);
                if ($ok) { $out['sent']++; $sentToday++; }
                continue;
            }
            // A person does this one: tell the owner once per step.
            if ($cfg['remind_owner'] && (int) ($p->reminded_step ?? -1) !== $i) {
                $out['reminded']++;
                if ($dry) continue;
                DB::table('sales_prospects')->where('id', $p->id)->update(['reminded_step' => $i]);
                self::bell($p, ($st['channel'] === 'call' ? '📞 Call ' : '📧 Follow up with ') . self::who($p),
                    'Cold reach follow-up ' . ($i + 1) . ' of ' . $n . ' is due.');
            }
        }
        return $out;
    }

    private static function who(object $p): string
    {
        return trim((string) ($p->organization ?: $p->name)) ?: ('Prospect #' . $p->id);
    }

    private static function bell(object $p, string $title, string $body): void
    {
        try {
            $uids = $p->owner_id ? [(int) $p->owner_id] : DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->map(fn ($i) => (int) $i)->unique()->all();
            foreach ($uids as $uid) {
                Notify::write(['user_id' => $uid, 'type' => 'cold_reach', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#sales-prospects?id=' . (int) $p->id]), 'created_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('cold reach bell failed', ['prospect' => $p->id, 'error' => $e->getMessage()]);
        }
    }
}
