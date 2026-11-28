<?php

namespace App\Console\Commands;

use App\Support\Brand;
use App\Support\FamilyFacingName;
use App\Support\SafeArrivalAbsentMail;
use App\Support\SafeArrivalCall;
use App\Support\SafeArrivalFamilyText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SAFE ARRIVAL TO FAMILIES: EDUCATORS BY FIRST NAME, FROM THE AGENCY (2026-11-27, Anthony: "safe arrival messages to
 * parents should only announce first name of educator and from agency name - full sweep").
 *
 * The rule lives in App\Support\FamilyFacingName (sender / textFrom / educator / educatorOf / place) and the words in
 * App\Support\SafeArrivalFamilyText. This fails when:
 *
 *   1. CODE: a family-facing Safe Arrival builder (FAMILY below) joins a person's first and last name
 *      ("$u->first_name . ' ' . $u->last_name"), reads supervisor_last_name, sends email From "KiddieTrac", or starts a
 *      text with 'KiddieTrac: ' - instead of FamilyFacingName::educator / sender / textFrom;
 *   2. RENDERED: for every agency and each of its open centres, the real builders (the "has X arrived?" app/email/text,
 *      the pickup question, the "We missed X today" email, the Safe Arrival call, the email From name, the
 *      attendance-reply page) contain the full name of anyone on staff at that centre (or its supervisor), a text does
 *      not start with the agency's name, or a white-label agency's sender is "KiddieTrac".
 *
 * Pure: reads names and renders text; writes, queues and sends nothing. --show=6,26 prints what those agencies render.
 * A new family-facing Safe Arrival builder goes in FAMILY.
 */
class KtSafeArrivalNamesCheck extends Command
{
    protected $signature = 'kt:safe-arrival-names-check {--quiet-ok} {--no-alert : report only} {--show= : agency ids whose rendered messages to print}';

    protected $description = 'Fail when a Safe Arrival message to a family names an educator by full name or is not from the agency.';

    /** file (under app/) => methods that build words a family reads ('*' = every method). */
    public const FAMILY = [
        'Support/SafeArrivalFamilyText.php' => ['*'],
        'Support/FamilyFacingName.php' => ['sender', 'textFrom', 'educator', 'educatorOf', 'org', 'team', 'place', 'who'],
        'Console/Commands/SafeArrivalCommand.php' => ['askParents', 'askParentsPickup', 'email'],
        'Support/SafeArrivalAbsentMail.php' => ['build', 'contact', 'markAbsent', 'send'],
        'Support/SafeArrivalCall.php' => ['fill', 'question', 'reply', 'testVars', 'vars'],
        'Console/Commands/CheckinReminderCommand.php' => ['smsBody', 'email'],
        'Support/AttendanceReply.php' => ['describe', 'brand', 'buttonsHtml'],
        'Support/SmsAttendance.php' => ['line', 'handle'],
        'Support/ParentLine.php' => ['sendCode', 'apply', 'textOutcome'],
        'Http/Controllers/Api/ParentLineController.php' => ['start', 'run', 'actionMenu', 'timeMenu', 'confirm', 'approve', 'decline'],
        'Http/Controllers/Api/ParentRunningLateController.php' => ['index', 'store'],
        'Http/Controllers/Api/SmsConsentController.php' => ['handleKeyword'],
    ];

    /** Variables that hold a CHILD (whose own family may read the full name) - everything else is a person on staff or a parent. */
    private const CHILD_VARS = ['child', 'kid', 'k', 'c', 'ch', 'row', 'r'];

    public function handle(): int
    {
        $bad = [];
        foreach (self::scanCode(app_path()) as $what => $lines) {
            foreach ($lines as $l) $bad[$what][] = $l;
        }
        $show = array_filter(array_map('intval', explode(',', (string) $this->option('show'))));
        foreach (DB::table('agencies')->orderBy('id')->get(['id', 'name']) as $a) {
            foreach (self::renderAgency((int) $a->id, $samples) as $what => $lines) {
                foreach ($lines as $l) $bad[$what][] = $l;
            }
            if (in_array((int) $a->id, $show, true)) {
                $this->line("\n=== Agency {$a->id} ({$a->name}) ===");
                foreach ($samples as $label => $text) {
                    $this->line("-- {$label}:\n" . $text);
                }
            }
        }

        foreach ($bad as $what => $items) {
            $this->error($what . ' (' . count($items) . ')');
            foreach (array_slice($items, 0, 40) as $i) $this->line('   ' . $i);
        }
        if ($bad && ! $this->option('no-alert')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Safe Arrival family wording - ' . count($bad) . ' problem(s)',
                'Safe Arrival messages to families name educators by first name only and come from the agency (App\Support\FamilyFacingName, App\Support\SafeArrivalFamilyText).',
                $bad
            );
        }
        if ($bad) {
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $this->info('Safe Arrival family messages: educators by first name only, every text and email from the agency.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, string[]> */
    public static function scanCode(string $appPath): array
    {
        $out = [];
        foreach (self::FAMILY as $file => $methods) {
            $path = rtrim($appPath, '/') . '/' . $file;
            if (! is_file($path)) {
                $out['Family-facing Safe Arrival file is missing (update FAMILY)'][] = $file;
                continue;
            }
            $src = (string) file_get_contents($path);
            foreach (self::methodBodies($src, $methods) as $name => [$body, $line]) {
                $where = $file . '::' . $name;
                // first + last of anyone but a child: "$u->first_name . ' ' . $u->last_name", "'u.last_name'" joins
                if (preg_match_all('/\$(\w+)->first_name\b[^;]{0,80}?\.\s*[\'"] [\'"]\s*\.\s*[^;]{0,40}?\$(\w+)->last_name\b/', $body, $m, PREG_SET_ORDER)) {
                    foreach ($m as $hit) {
                        if (! in_array(strtolower($hit[2]), self::CHILD_VARS, true)) {
                            $out['A family message joins a first and last name (use FamilyFacingName::educator)'][] = $where . ': ' . trim($hit[0]);
                        }
                    }
                }
                if (preg_match('/supervisor_last_name/', $body) && $name !== 'educator') {
                    $out['A family message reads a supervisor\'s last name'][] = $where;
                }
                if (preg_match('/->from\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]Kiddie ?[Tt]rac[\'"]\s*\)/', $body)) {
                    $out['A family email is From "KiddieTrac" (use FamilyFacingName::sender)'][] = $where;
                }
                if (preg_match('/[\'"]Kiddie ?[Tt]rac: /', $body)) {
                    $out['A family text starts with "KiddieTrac:" (use FamilyFacingName::textFrom)'][] = $where;
                }
            }
            foreach ($methods as $mname) {
                if ($mname !== '*' && ! preg_match('/function\s+' . preg_quote($mname, '/') . '\s*\(/', $src)) {
                    $out['Family-facing method not found (renamed? update FAMILY)'][] = $file . '::' . $mname;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array{0:string,1:int}> method name => [body, line] */
    private static function methodBodies(string $src, array $methods): array
    {
        $out = [];
        if (! preg_match_all('/function\s+(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($m[1] as [$name, $off]) {
            if ($methods !== ['*'] && ! in_array($name, $methods, true)) continue;
            $open = strpos($src, '{', $off);
            if ($open === false) continue;
            $depth = 0;
            $len = strlen($src);
            for ($i = $open; $i < $len; $i++) {
                $ch = $src[$i];
                if ($ch === '{') $depth++;
                elseif ($ch === '}' && --$depth === 0) break;
            }
            $out[$name] = [substr($src, $open, $i - $open + 1), substr_count(substr($src, 0, $off), "\n") + 1];
        }

        return $out;
    }

    /**
     * Render every family Safe Arrival message for one agency, per open centre, and check it.
     *
     * @return array<string, string[]>
     */
    public static function renderAgency(int $agencyId, ?array &$samples = null): array
    {
        $out = [];
        $samples = [];
        FamilyFacingName::flush();
        $sender = FamilyFacingName::sender($agencyId);
        $wl = false;
        try {
            $wl = Brand::whiteLabel($agencyId);
        } catch (\Throwable $e) {
        }
        if ($sender === '' || ($wl && preg_match('/kiddie ?trac/i', $sender))) {
            $out['Agency sender name is empty or "KiddieTrac" for a white-label agency'][] = "agency {$agencyId}: '{$sender}'";
        }
        $centres = \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId)->orderBy('id')
            ->get(['id', 'name', 'supervisor_first_name', 'supervisor_last_name']);
        $callCfg = SafeArrivalCall::config($agencyId);
        foreach ($centres as $c) {
            $cid = (int) $c->id;
            $texts = [];
            $ask = SafeArrivalFamilyText::ask($cid, (string) $c->name, 'Ava', '9:15 AM');
            $texts['App + email: has Ava arrived?'] = $ask['title'] . "\n" . $ask['body'];
            $texts['Text: has Ava arrived?'] = SafeArrivalFamilyText::askText($agencyId, $ask, 'Reply 1 if running late, 2 if absent today.');
            $pick = SafeArrivalFamilyText::pickup($cid, (string) $c->name, 'Ava', 'the usual pickup at 5:30 PM');
            $texts['App + email: has Ava been picked up?'] = $pick['title'] . "\n" . $pick['body'];
            $texts['Text: has Ava been picked up?'] = SafeArrivalFamilyText::text($agencyId, $pick['body']);
            $check = (object) ['child_id' => 0, 'agency_id' => $agencyId, 'centre_id' => $cid, 'room_id' => null, 'expected_time' => '09:00:00'];
            $vars = SafeArrivalCall::vars($check);
            $vars['child'] = 'Ava';
            $vars['child_full'] = 'Ava';
            $texts['Phone call'] = SafeArrivalCall::question($callCfg, $vars) . "\n  (1) " . SafeArrivalCall::reply($callCfg, 'absent', $vars)
                . "\n  (no key) " . SafeArrivalCall::reply($callCfg, 'no_answer', $vars);
            $team = FamilyFacingName::team($cid, $agencyId);
            $mail = SafeArrivalAbsentMail::build($agencyId, ['child' => 'Ava', 'guardian' => 'Sam', 'centre' => FamilyFacingName::place($cid),
                'date' => now()->toDateString(), 'expected' => '9:00 AM', 'marked_at' => '10:45 AM', 'phone' => '', 'email' => '',
                'tried' => [['at' => '9:15 AM', 'what' => 'We sent you a check-in message'], ['at' => '9:35 AM', 'what' => ucfirst($team) . ' was alerted']],
                'team' => FamilyFacingName::agency($agencyId)]);
            $texts['Email: we missed Ava today'] = $mail['subject'] . "\n" . self::plain($mail['html']);
            $texts['Email From name'] = SafeArrivalFamilyText::fromName($agencyId);
            $texts['Attendance-reply page centre + footer'] = FamilyFacingName::place($cid, '') . ' | ' . (string) (\App\Support\AttendanceReply::brand($agencyId)['from'] ?? '');
            $texts['Running late screen centre'] = FamilyFacingName::place($cid, (string) $c->name);

            // Whose full name must never appear: everyone on staff at this centre, and its supervisor.
            $people = [];
            if (trim((string) $c->supervisor_last_name) !== '') {
                $people[] = trim($c->supervisor_first_name . ' ' . $c->supervisor_last_name);
            }
            foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.centre_id', $cid)->where('ra.active', 1)->whereIn('ra.role', ['educator', 'centre_director', 'home_visitor', 'agency_admin'])
                ->whereNull('u.deleted_at')->distinct()->get(['u.first_name', 'u.last_name']) as $u) {
                if (trim((string) $u->last_name) !== '' && trim((string) $u->first_name) !== '') {
                    $people[] = trim(preg_replace('/\s+/', ' ', $u->first_name . ' ' . $u->last_name));
                }
            }
            foreach ($texts as $label => $t) {
                foreach (array_unique($people) as $full) {
                    if (mb_stripos($t, $full) !== false) {
                        $out['A Safe Arrival family message names an educator by full name'][] = "agency {$agencyId} centre {$cid}: {$label} contains '{$full}'";
                    }
                }
                if (str_starts_with($label, 'Text:') && mb_stripos($t, $sender . ':') !== 0) {
                    $out['A Safe Arrival text does not start with the agency name'][] = "agency {$agencyId} centre {$cid}: {$label}";
                }
            }
            if ($wl && preg_match('/kiddie ?trac/i', $texts['Email From name'] . $texts['Text: has Ava arrived?'])) {
                $out['A white-label agency\'s Safe Arrival message says KiddieTrac'][] = "agency {$agencyId} centre {$cid}";
            }
            if (! $samples) {
                $samples = ['(centre ' . $cid . ': ' . $c->name . ')' => ''] + $texts;
            }
        }
        FamilyFacingName::flush();

        return $out;
    }

    private static function plain(string $html): string
    {
        $html = preg_replace('/<(style|head)\b.*?<\/\1>/is', '', $html) ?? $html;
        $t = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/tr|\/div)\b[^>]*>/i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n\s*\n+/", "\n", preg_replace('/[ \t]+/', ' ', $t) ?? $t) ?? $t);
    }
}
