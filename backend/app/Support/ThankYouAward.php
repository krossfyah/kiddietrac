<?php

namespace App\Support;

use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * THE THANK-YOU AWARD (2026-10-30). Anthony: "for educator points add kebab to issue giftcard, send email with an award
 * thanking the educator (nicely designed and signed and dated by the agency owner)".
 *
 * A certificate-style email, in the agency's own colours (Brand::forAgency) inside EmailTemplate::wrap: "Certificate of
 * appreciation", the educator's name, the month and their points, the top activities, an optional personal message, and a
 * signature block - the signer's FIRST name only (no last names in our email), their title and the agency, dated in the
 * agency's time zone.
 *
 * WHO SIGNS: the agency's owner. agencies.settings.owner {name, email} when it is set (matched to that person's account
 * when they have one), otherwise the agency admin who has been one longest. The sender can pick another agency admin.
 *
 * Same people as RewardMail: To the educator, BCC the agency admins and the director(s) of their centre(s); real people
 * only, suppression respected. Recorded as an educator_rewards row (method thank_you, $0, status sent) so it shows in the
 * educator's rewards history, plus a bell notice.
 *
 * 2026-10-31 (Anthony: "add the agency logo to the award, make this look nice, use AI to write a nice note in the body of
 * the email about their quality of services ... add things to encourage and to enhance their work going forward"):
 *  - the agency's own logo on a white tile at the top of the certificate (white-label aware), a rosette, corner ornaments;
 *  - a letter in the email body: note() writes it from the educator's real month (EducatorPoints counts, centres, days
 *    active) with the AI Bundle, or a written template when the agency has no AI. The sender sees and edits it before
 *    sending (POST /educator-points/thanks/note); the final text is stored on the award (educator_rewards.letter);
 *  - pdf(): the same certificate as a landscape PDF, attached as "Certificate - {Name} - {Month}.pdf".
 */
final class ThankYouAward
{
    private const APP = 'https://app.kiddietrac.com/dashboard.html#my-points';
    public const TONES = ['warm', 'formal', 'short'];

    /** Who may sign: [{key, user_id|null, first, title}], the default (the owner) first. */
    public static function signers(int $agencyId): array
    {
        $isPerson = fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL) && ! preg_match('/^(noreply|no-reply)@|integration\+|@kiddietrac\.(com|ca)$/i', $e);
        $admins = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')->where('ra.active', 1)
            ->whereNull('u.deleted_at')->whereNotIn('u.status', Audience::OFF_STATUSES)
            ->orderBy('ra.created_at')->orderBy('ra.id')
            ->get(['u.id', 'u.first_name', 'u.email'])
            ->filter(fn ($u) => $isPerson((string) $u->email) && trim((string) $u->first_name) !== '')
            ->unique('id')->values();

        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $owner = is_array($s['owner'] ?? null) ? $s['owner'] : [];
        $ownerEmail = strtolower(trim((string) ($owner['email'] ?? '')));
        $ownerFirst = trim((string) strtok(trim((string) ($owner['name'] ?? '')), ' '));

        $out = [];
        $ownerUser = $ownerEmail ? $admins->first(fn ($u) => strtolower(trim((string) $u->email)) === $ownerEmail) : null;
        if ($ownerUser) {
            $out[] = ['key' => 'u' . $ownerUser->id, 'user_id' => (int) $ownerUser->id, 'first' => trim($ownerUser->first_name), 'title' => 'Owner'];
        } elseif ($ownerFirst !== '') {
            $out[] = ['key' => 'owner', 'user_id' => null, 'first' => $ownerFirst, 'title' => 'Owner'];
        } elseif ($admins->isNotEmpty()) {
            $f = $admins->first();
            $out[] = ['key' => 'u' . $f->id, 'user_id' => (int) $f->id, 'first' => trim($f->first_name), 'title' => 'Owner'];
        }
        foreach ($admins as $u) {
            if ($out && $out[0]['user_id'] === (int) $u->id) continue;
            $out[] = ['key' => 'u' . $u->id, 'user_id' => (int) $u->id, 'first' => trim($u->first_name), 'title' => 'Administrator'];
        }
        return $out;
    }

    public static function signer(int $agencyId, ?string $key): ?array
    {
        $all = self::signers($agencyId);
        if (! $all) return null;
        foreach ($all as $s) { if ($key !== null && $s['key'] === $key) return $s; }
        return $all[0];
    }

    /** The agency's own logo (absolute URL), or null. White label: the white-label brand's logo, never KiddieTrac's. */
    public static function logoUrl(int $agencyId): ?string
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first(['brand_logo_url', 'logo_url']);
        $logo = $a ? (($a->brand_logo_url ?? null) ?: ($a->logo_url ?? null)) : null;
        if (! $logo) {
            try {
                $b = Brand::forAgency($agencyId);
                if (! empty($b['white_label'])) {
                    foreach (['logo_wordmark_url', 'logo_square_url'] as $k) {
                        $u = (string) ($b[$k] ?? '');
                        if ($u !== '' && ! str_contains($u, 'app.kiddietrac.com/')) { $logo = $u; break; }
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        if (! $logo) return null;
        $logo = (string) $logo;
        if (ProtectedMedia::isProtected($logo)) return (string) ProtectedMedia::signForEmail($logo);
        if (preg_match('#^https?://#i', $logo)) return $logo;
        $base = preg_replace('#/api/v1/?$#', '', rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/'));
        return $base . '/' . ltrim($logo, '/');
    }

    /** [primary, accent] colours of the agency's brand, safe hex. */
    private static function colours(int $agencyId): array
    {
        $b = Brand::forAgency($agencyId);
        $c1 = preg_match('/^#[0-9a-f]{6}$/i', (string) ($b['primary_color'] ?? '')) ? $b['primary_color'] : '#13708A';
        $c2 = preg_match('/^#[0-9a-f]{6}$/i', (string) ($b['accent_color'] ?? '')) ? $b['accent_color'] : '#F59E0B';
        return [$c1, $c2];
    }

    /** The letter as email HTML: paragraphs, and "• " lines as a tidy list. */
    private static function letterHtml(string $note, string $F, string $c1): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $out = '';
        foreach (preg_split('/\n\s*\n/', trim(str_replace("\r", '', $note))) as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
            if (! $lines) continue;
            $bul = array_values(array_filter($lines, fn ($l) => preg_match('/^[•\-\*]\s*/u', $l)));
            $lead = array_values(array_filter($lines, fn ($l) => ! preg_match('/^[•\-\*]\s*/u', $l)));
            if ($bul && $lead && count($bul) + count($lead) === count($lines) && preg_match('/^[•\-\*]/u', end($lines))) {
                // "Looking ahead, ..." then its bullets: the lead line as a paragraph, the bullets as a list.
                $out .= '<div style="' . $F . 'font-size:15px;line-height:1.65;color:#0F172A;font-weight:700;margin:0 0 6px;">' . nl2br($e(implode("
", $lead))) . '</div>';
                $lines = $bul;
            }
            if ($bul && count($bul) === count($lines)) {
                $out .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px;">';
                foreach ($bul as $l) {
                    $out .= '<tr><td valign="top" style="padding:3px 10px 3px 2px;' . $F . 'font-size:15px;line-height:1.6;color:' . $c1 . ';">✦</td>'
                        . '<td style="padding:3px 0;' . $F . 'font-size:15px;line-height:1.6;color:#334155;">' . $e(preg_replace('/^[•\-\*]\s*/u', '', $l)) . '</td></tr>';
                }
                $out .= '</table>';
            } else {
                $out .= '<div style="' . $F . 'font-size:15px;line-height:1.65;color:#334155;margin:0 0 14px;">' . nl2br($e(implode("\n", $lines))) . '</div>';
            }
        }
        return $out;
    }

    /**
     * Subject + HTML. $ctx: name (educator's full name), first, month (YYYY-MM), points, by_activity [[icon,label,points,count]],
     * message, note (the letter), signer {first,title}, agency (name), date (Carbon in the agency tz).
     */
    public static function build(int $agencyId, array $ctx): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        [$c1, $c2] = self::colours($agencyId);
        $F = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;";
        $SERIF = "font-family:Georgia,'Times New Roman',Times,serif;";
        $SCRIPT = "font-family:'Brush Script MT','Segoe Script','Lucida Handwriting','Apple Chancery',cursive;";
        $month = Carbon::parse($ctx['month'] . '-01')->format('F Y');
        $date = $ctx['date']->format('F j, Y');
        $logo = self::logoUrl($agencyId);

        $acts = array_values(array_filter($ctx['by_activity'] ?? [], fn ($x) => $x[2] > 0));
        usort($acts, fn ($x, $y) => $y[2] <=> $x[2]);
        $chips = '';
        foreach (array_slice($acts, 0, 3) as $a) {
            [$ai, $al, $ap] = $a;
            $chips .= '<td align="center" valign="top" style="padding:0 8px;' . $F . '"><div style="font-size:22px;line-height:1;">' . $ai . '</div>'
                . '<div style="font-size:12.5px;font-weight:800;color:#0F172A;margin-top:5px;">' . number_format($ap) . ' pts</div>'
                . '<div style="font-size:11px;color:#64748B;">' . $e($al) . '</div></td>';
        }

        // Corner ornaments: small accent marks in each inner corner (pure table cells, safe in every client).
        $orn = fn ($align) => '<td width="24" align="' . $align . '" valign="top" style="font-size:16px;line-height:1;color:' . $c2 . ';' . $SERIF . '">❖</td>';

        $msg = trim((string) ($ctx['message'] ?? ''));
        $logoTile = $logo
            ? '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 14px;"><tr>'
                . '<td class="kt-logobox" bgcolor="#FFFFFF" align="center" style="background:#FFFFFF;border:1px solid #E2E8F0;border-radius:14px;padding:10px 16px;">'
                . '<img src="' . $e($logo) . '" alt="' . $e($ctx['agency']) . '" height="54" style="display:block;height:54px;max-height:54px;width:auto;border:0;"></td></tr></table>'
            : '';
        $rosette = '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 8px;"><tr>'
            . '<td align="center" valign="middle" bgcolor="' . $c2 . '" style="background:' . $c2 . ';width:52px;height:52px;border-radius:26px;border:3px solid #FFFFFF;box-shadow:0 0 0 2px ' . $c2 . ';'
            . $F . 'font-size:24px;line-height:52px;color:#FFFFFF;">🏅</td></tr></table>';

        $cert = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 22px;">'
            . '<tr><td bgcolor="' . $c1 . '" style="background:' . $c1 . ';border-radius:20px;padding:7px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            // kt-panel: in dark mode the template turns this panel dark and its text light, together (EmailTemplate::wrap).
            . '<td class="kt-panel" bgcolor="#FFFDF7" style="background:#FFFDF7;border:2px solid ' . $c2 . ';border-radius:14px;padding:14px 12px 26px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . $orn('left') . '<td></td>' . $orn('right') . '</tr></table>'
            . '<div style="text-align:center;padding:0 14px;">'
            . $logoTile . $rosette
            . '<div style="' . $F . 'font-size:11.5px;font-weight:800;letter-spacing:3.5px;color:' . $c1 . ';text-transform:uppercase;">Certificate of appreciation</div>'
            . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:12px auto 14px;"><tr>'
            . '<td width="60" style="border-top:1px solid ' . $c2 . ';font-size:0;line-height:0;">&nbsp;</td>'
            . '<td style="padding:0 8px;font-size:11px;line-height:1;color:' . $c2 . ';">◆</td>'
            . '<td width="60" style="border-top:1px solid ' . $c2 . ';font-size:0;line-height:0;">&nbsp;</td></tr></table>'
            . '<div style="' . $F . 'font-size:13px;color:#64748B;">Proudly presented to</div>'
            . '<div style="' . $SERIF . 'font-size:32px;font-weight:700;color:#0F172A;line-height:1.2;margin:6px 0 4px;">' . $e($ctx['name']) . '</div>'
            . '<div style="' . $F . 'font-size:14.5px;line-height:1.6;color:#334155;max-width:440px;margin:8px auto 0;">'
            . 'In grateful recognition of the quality of your care, your dedication, and the moments you shared with our families in <strong>' . $e($month) . '</strong>.</div>'
            . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:18px auto 4px;"><tr>'
            . '<td class="kt-btn" bgcolor="' . $c1 . '" style="background:' . $c1 . ';border-radius:999px;padding:8px 20px;' . $F . 'font-size:15px;font-weight:800;color:#FFFFFF;">⭐ ' . number_format((int) $ctx['points']) . ' points</td></tr></table>'
            . ($chips ? '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:14px auto 0;"><tr>' . $chips . '</tr></table>' : '')
            . ($msg !== '' ? '<div style="' . $SERIF . 'font-size:16px;font-style:italic;line-height:1.6;color:#1E293B;max-width:460px;margin:20px auto 0;">“' . nl2br($e($msg)) . '”</div>' : '')
            . '</div>'
            // signature + date
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:26px;"><tr>'
            . '<td width="50%" align="center" valign="bottom" style="padding:0 14px;">'
            . '<div style="' . $SCRIPT . 'font-size:32px;line-height:1.1;color:' . $c1 . ';">' . $e($ctx['signer']['first']) . '</div>'
            . '<div style="border-top:1px solid #CBD5E1;margin-top:4px;padding-top:6px;' . $F . 'font-size:12px;color:#475569;">'
            . '<strong style="color:#0F172A;">' . $e($ctx['signer']['first']) . '</strong> · ' . $e($ctx['signer']['title']) . '<br>' . $e($ctx['agency']) . '</div></td>'
            . '<td width="50%" align="center" valign="bottom" style="padding:0 14px;">'
            . '<div style="' . $SERIF . 'font-size:17px;color:#0F172A;line-height:1.2;">' . $e($date) . '</div>'
            . '<div style="border-top:1px solid #CBD5E1;margin-top:9px;padding-top:6px;' . $F . 'font-size:12px;color:#475569;">Date</div></td>'
            . '</tr></table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;"><tr>' . $orn('left') . '<td></td>' . $orn('right') . '</tr></table>'
            . '</td></tr></table></td></tr></table>';

        $btn = '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 8px;"><tr>'
            . '<td class="kt-btn" align="center" bgcolor="' . $c1 . '" style="background:' . $c1 . ';border-radius:12px;">'
            . '<a class="kt-btn" href="' . self::APP . '" style="display:inline-block;padding:14px 30px;' . $F . 'font-size:15px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:12px;">⭐ See my points</a>'
            . '</td></tr></table>';

        $note = trim((string) ($ctx['note'] ?? ''));
        $intro = '<div style="' . $F . 'font-size:15px;line-height:1.6;color:#334155;margin:0 0 14px;">Hi ' . $e($ctx['first']) . ',</div>'
            . ($note !== '' ? self::letterHtml($note, $F, $c1)
                : '<div style="' . $F . 'font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">Thank you. The work you do every day matters to the children and the families in our care, and we noticed.</div>')
            . ($note !== '' ? '<div style="' . $F . 'font-size:15px;line-height:1.6;color:#334155;margin:0 0 18px;">With gratitude,<br><strong style="color:#0F172A;">' . $e($ctx['signer']['first']) . '</strong><br>'
                . '<span style="color:#64748B;">' . $e($ctx['signer']['title']) . ', ' . $e($ctx['agency']) . '</span></div>' : '');
        $pdfNote = ! empty($ctx['pdf']) ? '<div style="' . $F . 'font-size:12.5px;color:#64748B;text-align:center;margin:4px 0 0;">📎 Your certificate is attached as a PDF, ready to print or keep.</div>' : '';

        $html = EmailTemplate::wrap($agencyId, $intro . $cert . $btn . $pdfNote
            . '<div style="' . $F . 'font-size:12.5px;color:#64748B;text-align:center;margin-top:10px;">With thanks from everyone at ' . $e($ctx['agency']) . '.</div>'
            . (! empty($ctx['auto']) ? '<div style="' . $F . 'font-size:11.5px;color:#94A3B8;text-align:center;margin-top:6px;">' . $e($ctx['agency']) . ' recognises its educators every month.</div>' : ''), [
            'eyebrow' => 'EDUCATOR APPRECIATION',
            'title' => 'Thank you, ' . $ctx['first'] . '!',
            'subtitle' => $month . ' · ' . number_format((int) $ctx['points']) . ' points',
            'preheader' => 'A certificate of appreciation for ' . $month . ', from ' . $ctx['signer']['first'] . ' at ' . $ctx['agency'] . '.',
        ]);

        return ['🏅 Thank you, ' . $ctx['first'] . ' — a certificate of appreciation', $html];
    }

    /** Everything the email needs for one educator and month. */
    public static function context(int $agencyId, int $userId, string $month, ?string $message, array $signer): array
    {
        $u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name']);
        $earners = EducatorPoints::earners($agencyId);
        $by = [];
        $points = 0;
        $days = 0;
        if (isset($earners[$userId])) {
            $r = EducatorPoints::compute($agencyId, $month, [$userId => $earners[$userId]])[$userId];
            $points = (int) $r['total'];
            $days = count(array_filter((array) ($r['days'] ?? []), fn ($p) => $p > 0));
            foreach (EducatorPoints::ACTIVITIES as $k => $def) {
                $by[] = [$def[1], $def[0], (int) ($r['by'][$k]['points'] ?? 0), (int) ($r['by'][$k]['count'] ?? 0)];
            }
        }
        $centres = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $userId)->where('ra.agency_id', $agencyId)->where('ra.active', 1)->whereNull('c.deleted_at')
            ->distinct()->pluck('c.name')->filter()->values()->all();
        $first = trim((string) ($u->first_name ?? '')) ?: 'there';
        return [
            'first' => $first,
            'name' => trim($first . ' ' . trim((string) ($u->last_name ?? ''))),   // the recipient's own name (not a staff sign-off)
            'month' => $month, 'points' => $points, 'by_activity' => $by, 'days_active' => $days, 'centres' => $centres,
            'message' => $message, 'signer' => $signer, 'note' => null,
            'agency' => (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: Brand::name($agencyId)),
            'date' => Carbon::now()->setTimezone(AgencyTime::tz($agencyId)),
        ];
    }

    /**
     * The letter in the email body: ['note' => text, 'ai' => bool]. With the AI Bundle, written from the educator's real
     * month (counts per activity, days active, centres, points), first names only, no invented facts, 2-3 forward-looking
     * suggestions; otherwise (or on any failure) a written template from the same facts.
     */
    public static function note(int $agencyId, array $ctx, string $tone = 'warm'): array
    {
        $tone = in_array($tone, self::TONES, true) ? $tone : 'warm';
        $facts = [
            'educator_first_name' => $ctx['first'],
            'month' => Carbon::parse($ctx['month'] . '-01')->format('F Y'),
            'agency' => $ctx['agency'],
            'centres' => $ctx['centres'] ?? [],
            'points_total' => (int) $ctx['points'],
            'days_with_activity' => (int) ($ctx['days_active'] ?? 0),
            'activities' => array_values(array_map(fn ($a) => ['activity' => $a[1], 'count' => (int) ($a[3] ?? 0), 'points' => (int) $a[2]],
                array_filter($ctx['by_activity'] ?? [], fn ($a) => ($a[3] ?? 0) > 0 || $a[2] > 0))),
            'signed_by' => $ctx['signer']['first'] . ' (' . $ctx['signer']['title'] . ')',
        ];
        $ai = self::aiNote($agencyId, $facts, $tone);
        if ($ai !== null) return ['note' => $ai, 'ai' => true];
        return ['note' => self::templateNote($facts, $tone), 'ai' => false];
    }

    private static function aiNote(int $agencyId, array $facts, string $tone): ?string
    {
        if (! AiBundle::on($agencyId) || Cache::has('ai-summaries:resting')) return null;
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) return null;
        $toneText = [
            'warm' => 'Warm, heartfelt and personal, but professional.',
            'formal' => 'Formal and polished, as from a director to a valued professional.',
            'short' => 'Brief: about 70-90 words in total, still warm.',
        ][$tone];
        $prompt = 'Write a thank-you letter from the owner of a childcare agency to one of their early-childhood educators, for the month in the facts below. '
            . 'Tone: ' . $toneText . "\n"
            . 'Structure: 1) one or two short paragraphs (about 100-140 words together' . ($tone === 'short' ? ', or less' : '') . ') praising the quality of their care and service, '
            . 'referring to the real activities and numbers in the facts exactly as labelled (for example the observations they wrote, photos shared with families, daily logs, walks); '
            . '2) a line "Looking ahead, a few ideas to keep growing:" followed by 2-3 short bullet lines starting with "• ", each a concrete, encouraging suggestion to enhance their practice '
            . '(for example linking observations to developmental domains, adding captions to photos so families see the learning, sharing a weekly highlight with families, planning activities from what they observe) '
            . '- framed as opportunities, never as criticism.' . "\n"
            . 'Rules: the greeting ("Hi {first name},") is already written, so do NOT start with their name, "Hi" or "Dear" - start straight with the thank-you; use their first name at most once later; Canadian English; no sign-off (it is added after); '
            . 'never invent facts, children\'s names, events or numbers that are not in the facts; do not mention points or AI; no headings, no markdown other than the "• " bullets; '
            . 'if an activity count is 0 do not praise it.';
        try {
            UsageMeter::forAgency($agencyId);
            \App\Support\AiFresh::as('thank_you', $agencyId);   // fresh writing (AiFresh)
            $r = Http::timeout(40)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model') ?: 'claude-haiku-4-5-20251001',
                    'max_tokens' => 700,
                    'system' => 'You write warm, specific appreciation letters for a licensed childcare agency. Reply with only the letter text.',
                    'messages' => [['role' => 'user', 'content' => $prompt . "\n\nFacts (JSON):\n" . json_encode($facts, JSON_UNESCAPED_UNICODE)]],
                ]);
            if (! $r->successful()) {
                Log::warning('Thank-you letter: AI failed', ['agency' => $agencyId, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);
                return null;
            }
            $text = trim((string) (AiConfig::text($r->json()) ?? ''));
            $text = preg_replace('/^(hi|dear|hello)\b[^\n]*\n+/i', '', $text);          // the greeting is ours
            $text = preg_replace('/\n+(with gratitude|warmly|sincerely|best|thank you)[,!]?\s*\n.*$/is', '', $text);   // so is the sign-off
            $text = str_replace(['**', '__', '##'], '', (string) $text);
            return mb_strlen($text) >= 80 ? mb_substr($text, 0, 2500) : null;
        } catch (\Throwable $e) {
            Log::warning('Thank-you letter: no AI answer', ['agency' => $agencyId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** No AI: a good letter from the same facts. */
    private static function templateNote(array $f, string $tone): string
    {
        $acts = array_values(array_filter($f['activities'], fn ($a) => $a['count'] > 0));
        usort($acts, fn ($a, $b) => $b['count'] <=> $a['count']);
        $bits = array_map(fn ($a) => $a['count'] . ' ' . mb_strtolower($a['activity']), array_slice($acts, 0, 3));
        $list = count($bits) > 1 ? implode(', ', array_slice($bits, 0, -1)) . ' and ' . end($bits) : ($bits[0] ?? '');
        $where = $f['centres'] ? ' at ' . implode(' and ', array_slice($f['centres'], 0, 2)) : '';
        $p1 = 'Thank you for everything you gave to the children and families' . $where . ' in ' . $f['month'] . '. '
            . ($list !== '' ? 'Your ' . $list . ' showed families real moments of their children\'s day and the care behind them. ' : '')
            . 'The consistency and warmth you bring make our program what it is, and it does not go unnoticed.';
        if ($tone === 'short') {
            return $p1 . "\n\nLooking ahead, a few ideas to keep growing:\n• Link a few observations each week to a developmental domain.\n• Add a short caption to photos so families see the learning.";
        }
        return $p1 . "\n\n" . 'Families feel secure when they can see their children thriving, and your attention to detail helps them feel connected to every part of the day.'
            . "\n\nLooking ahead, a few ideas to keep growing:\n"
            . "• Link a few observations each week to a developmental domain, so families can follow each child's progress.\n"
            . "• Add a short caption to the photos you share, so families see the learning behind the moment.\n"
            . "• Use what you observe to plan one new activity each week that builds on the children's interests.";
    }

    /** The certificate as a landscape PDF (Dompdf), or null if it can't be made. */
    public static function pdf(int $agencyId, array $ctx): ?string
    {
        try {
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            [$c1, $c2] = self::colours($agencyId);
            $month = Carbon::parse($ctx['month'] . '-01')->format('F Y');
            $logo = self::logoUrl($agencyId);
            $img = '';
            if ($logo) {
                try {
                    $res = Http::timeout(10)->get($logo);
                    $type = strtolower((string) $res->header('Content-Type'));
                    if ($res->successful() && str_starts_with($type, 'image/') && ! str_contains($type, 'svg') && strlen($res->body()) < 3000000) {
                        $img = '<img src="data:' . $e(explode(';', $type)[0]) . ';base64,' . base64_encode($res->body()) . '" style="height:62px;width:auto;">';
                    }
                } catch (\Throwable $x) {
                }
            }
            $acts = array_values(array_filter($ctx['by_activity'] ?? [], fn ($x) => $x[2] > 0));
            usort($acts, fn ($x, $y) => $y[2] <=> $x[2]);
            $actLine = implode('   ·   ', array_map(fn ($a) => number_format($a[2]) . ' pts ' . $a[1], array_slice($acts, 0, 3)));
            $msg = trim((string) ($ctx['message'] ?? ''));
            $html = '<html><head><meta charset="UTF-8"><style>'
                . '@page{margin:0} body{margin:0;font-family:"DejaVu Sans",sans-serif;color:#0F172A}'
                . '.page{position:absolute;left:0;top:0;width:100%;height:100%;}'
                . '.outer{position:absolute;left:22px;top:22px;right:22px;bottom:22px;border:8px solid ' . $c1 . ';border-radius:16px;}'
                . '.inner{position:absolute;left:36px;top:36px;right:36px;bottom:36px;border:2px solid ' . $c2 . ';border-radius:10px;background:#FFFDF7;}'
                . '.c{text-align:center} .orn{position:absolute;color:' . $c2 . ';font-size:22px;font-family:"DejaVu Sans"}'
                . '</style></head><body><div class="page"><div class="outer"></div><div class="inner">'
                . '<div class="orn" style="left:14px;top:8px">❖</div><div class="orn" style="right:14px;top:8px">❖</div>'
                . '<div class="orn" style="left:14px;top:676px">❖</div><div class="orn" style="right:14px;top:676px">❖</div>'
                . '<div class="c" style="margin-top:52px">' . $img . '</div>'
                . '<table align="center" style="margin:14px auto 0;border-collapse:collapse"><tr><td style="width:42px;height:42px;border-radius:21px;background:' . $c2 . ';color:#fff;font-size:22px;text-align:center;vertical-align:middle;padding:0">★</td></tr></table>'
                . '<div class="c" style="margin-top:10px;font-size:14px;font-weight:bold;letter-spacing:5px;color:' . $c1 . '">CERTIFICATE OF APPRECIATION</div>'
                . '<div class="c" style="margin-top:6px;color:' . $c2 . ';font-size:11px">— ◆ —</div>'
                . '<div class="c" style="margin-top:8px;font-size:13px;color:#64748B">Proudly presented to</div>'
                . '<div class="c" style="margin-top:8px;font-family:\'DejaVu Serif\',serif;font-size:40px;font-weight:bold">' . $e($ctx['name']) . '</div>'
                . '<div class="c" style="margin:10px auto 0;width:70%;font-size:13.5px;line-height:1.5;color:#334155">In grateful recognition of the quality of your care, your dedication, and the moments you shared with our families in <b>' . $e($month) . '</b>.</div>'
                . '<div class="c" style="margin-top:12px"><span style="display:inline-block;background:' . $c1 . ';color:#fff;border-radius:16px;padding:5px 16px;font-size:13px;font-weight:bold">' . number_format((int) $ctx['points']) . ' points</span></div>'
                . ($actLine !== '' ? '<div class="c" style="margin-top:8px;font-size:11px;color:#64748B">' . $e($actLine) . '</div>' : '')
                . ($msg !== '' ? '<div class="c" style="margin:12px auto 0;width:70%;font-family:\'DejaVu Serif\',serif;font-style:italic;font-size:13.5px;color:#1E293B">“' . $e($msg) . '”</div>' : '')
                . '<table align="center" style="margin:' . ($msg !== '' ? '64' : '84') . 'px auto 0;width:86%;border-collapse:collapse"><tr>'
                . '<td style="width:50%;text-align:center;vertical-align:bottom;padding:0 30px">'
                . '<div style="font-family:Times-Roman,serif;font-style:italic;font-size:30px;color:' . $c1 . '">' . $e($ctx['signer']['first']) . '</div>'
                . '<div style="border-top:1px solid #94A3B8;margin-top:2px;padding-top:4px;font-size:11px;color:#475569"><b style="color:#0F172A">' . $e($ctx['signer']['first']) . '</b> · ' . $e($ctx['signer']['title']) . ', ' . $e($ctx['agency']) . '</div></td>'
                . '<td style="width:50%;text-align:center;vertical-align:bottom;padding:0 30px">'
                . '<div style="font-family:\'DejaVu Serif\',serif;font-size:16px">' . $e($ctx['date']->format('F j, Y')) . '</div>'
                . '<div style="border-top:1px solid #94A3B8;margin-top:6px;padding-top:4px;font-size:11px;color:#475569">Date</div></td>'
                . '</tr></table></div></div></body></html>';
            $d = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $d->loadHtml($html, 'UTF-8');
            $d->setPaper('letter', 'landscape');
            $d->render();
            $out = $d->output();
            return strlen((string) $out) > 1000 ? $out : null;
        } catch (\Throwable $e) {
            Log::warning('Thank-you certificate PDF failed', ['agency' => $agencyId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public static function pdfName(array $ctx): string
    {
        $n = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $ctx['name']);
        return 'Certificate - ' . $n . ' - ' . Carbon::parse($ctx['month'] . '-01')->format('F Y') . '.pdf';
    }

    /** Queue it: To the educator, BCC the managers (RewardMail::recipients). Returns who it went to. */
    public static function send(int $agencyId, array $ctx, int $userId, array $opts = []): array
    {
        $rc = RewardMail::recipients($agencyId, $userId);
        if (array_key_exists('bcc', $opts) && ! $opts['bcc']) $rc['bcc'] = [];
        $pdf = (array_key_exists('pdf', $opts) && ! $opts['pdf']) ? null : self::pdf($agencyId, $ctx);
        $ctx['pdf'] = $pdf !== null;
        [$subject, $html] = self::build($agencyId, $ctx);
        $to = $rc['to'];
        $bcc = $rc['bcc'];
        if (! $to && $bcc) {
            $to = array_shift($bcc);
            $rc['skipped'][] = 'sent to ' . $to['email'] . ' instead';
        }
        if (! $to) return $rc + ['sent' => false];
        $toE = $to['email'];
        $bccE = array_column($bcc, 'email');
        $pdf64 = $pdf !== null ? base64_encode($pdf) : null;
        $pdfName = self::pdfName($ctx);
        dispatch(function () use ($agencyId, $toE, $bccE, $subject, $html, $pdf64, $pdfName) {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($toE, $bccE, $subject, $pdf64, $pdfName) {
                $m->to($toE)->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')->subject($subject);
                if ($bccE) $m->bcc($bccE);
                if ($pdf64) $m->attachData(base64_decode($pdf64), $pdfName, ['mime' => 'application/pdf']);
            });
        })->onQueue('mail');
        return $rc + ['sent' => true, 'to_email' => $toE, 'bcc_emails' => $bccE, 'pdf' => $pdf !== null];
    }
}
