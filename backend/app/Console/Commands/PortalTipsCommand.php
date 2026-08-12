<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FcmService;
use App\Support\Suppression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly portal tips — a friendly "did you know?" nudge pushed to parents and
 * educators on the APK (and shown in their in-app bell). Rotates through a set of
 * tips (keyed on the ISO week number) so the same one isn't repeated week to week.
 *
 * Push-first: only reaches users who actually have the app installed (a registered
 * android/ios device_token), so we don't nag email-only users. Do-not-contact
 * suppression for live agencies is enforced by Suppression + FcmService.
 */
final class PortalTipsCommand extends Command
{
    protected $signature = 'kiddietrac:portal-tips {--dry-run : list who would be reminded without sending} {--audience=all : parent | staff | all}';
    protected $description = 'Push a weekly portal tip to parents and educators (APK notification + in-app bell).';

    /** @var array<int,array{title:string,body:string,link:string}> */
    private array $parentTips = [
        ['title' => "\u{1F4A1} Tip: See your child's day live", 'body' => "Open Today to watch check-ins, meals, naps and photos as they happen — no need to wait for pickup.", 'link' => '#today'],
        ['title' => "\u{1F4A1} Tip: Message your educator", 'body' => "Have a quick question? Use Messages to reach your child's room directly — replies land right in the app.", 'link' => '#messages'],
        ['title' => "\u{1F4A1} Tip: Turn on notifications", 'body' => "Enable notifications so you get an instant alert at check-in, check-out and for any incident.", 'link' => '#settings'],
        ['title' => "\u{1F4A1} Tip: Invoices & payments in-app", 'body' => "Your invoices and payment history live under Billing — review balances any time.", 'link' => '#billing'],
        ['title' => "\u{1F4A1} Tip: Keep contacts up to date", 'body' => "Add or update your emergency and pickup contacts in Settings so your centre always has the right info.", 'link' => '#settings'],
        ['title' => "\u{1F4A1} Tip: Photos & memories", 'body' => "Your child's photos are saved in the app — scroll Today to relive the highlights of their week.", 'link' => '#today'],
        ['title' => "\u{1F4A1} Tip: Going away?", 'body' => "Ask your centre about placing a vacation hold so your billing pauses while your family is out.", 'link' => '#billing'],
    ];

    /** @var array<int,array{title:string,body:string,link:string}> */
    private array $staffTips = [
        ['title' => "\u{1F4A1} Tip: Clock in and out every shift", 'body' => "Accurate punches keep ratios compliant and your pay correct — tap Clock in/out at the start and end of your day.", 'link' => '#dashboard'],
        ['title' => "\u{1F4A1} Tip: Log observations on the go", 'body' => "Capture a quick learning moment or photo from your phone — it goes straight to the child's portfolio and parents.", 'link' => '#observations'],
        ['title' => "\u{1F4A1} Tip: Watch your room ratio", 'body' => "The dashboard shows live present counts — keep an eye on it so your room stays within ratio all day.", 'link' => '#dashboard'],
        ['title' => "\u{1F4A1} Tip: Check today's roster", 'body' => "See who's expected, who's here and any allergy alerts before the day starts — it's all on your home screen.", 'link' => '#dashboard'],
        ['title' => "\u{1F4A1} Tip: Message families instantly", 'body' => "Share a quick update or reply to a parent from Messages — they'll get it on their phone right away.", 'link' => '#messages'],
        ['title' => "\u{1F4A1} Tip: Record meals & naps in real time", 'body' => "Logging meals, naps and diapers as they happen keeps parents in the loop and your daily records complete.", 'link' => '#dashboard'],
        ['title' => "\u{1F4A1} Tip: Keep certifications current", 'body' => "Check the renewal calendar for any certifications or first-aid expiring soon so nothing lapses.", 'link' => '#certifications'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $audience = (string) $this->option('audience');
        $week = (int) Carbon::now()->format('W'); // ISO week 1..53 — rotates the tip weekly

        $sent = 0;
        if ($audience === 'parent' || $audience === 'all') {
            $tip = $this->parentTips[$week % count($this->parentTips)];
            $sent += $this->pushTip($tip, true, [], $dry);
        }
        if ($audience === 'staff' || $audience === 'all') {
            $tip = $this->staffTips[$week % count($this->staffTips)];
            $sent += $this->pushTip($tip, false, ['educator', 'centre_director'], $dry);
        }

        $this->info("Portal tips pushed: {$sent}");
        return self::SUCCESS;
    }

    /**
     * @param array{title:string,body:string,link:string} $tip
     * @param string[] $roles
     */
    private function pushTip(array $tip, bool $guardianPath, array $roles, bool $dry): int
    {
        // Push-first: only users with a registered APK/iOS device token.
        $appUsers = DB::table('device_tokens')
            ->whereIn('platform', ['android', 'ios'])
            ->pluck('user_id')->unique();

        if ($appUsers->isEmpty()) {
            return 0;
        }

        if ($guardianPath) {
            $target = DB::table('guardians')->whereIn('user_id', $appUsers)->pluck('user_id');
        } else {
            $target = DB::table('role_assignments')
                ->whereIn('user_id', $appUsers)
                ->whereIn('role', $roles)->where('active', true)
                ->pluck('user_id');
        }
        $target = $target->map(fn ($i) => (int) $i)->unique()->values();

        $svc = app(FcmService::class);
        $n = 0;
        foreach ($target as $uid) {
            // Do-not-contact: suppressed (live) agencies get nothing — not even an
            // in-app bell row. FcmService also re-checks, but skipping here avoids
            // writing a notification we'd never deliver.
            if (Suppression::isUser($uid)) {
                continue;
            }
            if ($dry) {
                $this->line("  [dry-run] tip \u{2192} user {$uid}: {$tip['title']}");
                $n++;
                continue;
            }
            try {
                DB::table('notifications')->insert([
                    'user_id' => $uid, 'type' => 'portal_tip',
                    'title' => $tip['title'], 'body' => $tip['body'],
                    'data' => json_encode(['link' => $tip['link']]),
                    'created_at' => now(),
                ]);
                $res = $svc->sendToUser($uid, $tip['title'], $tip['body'], $tip['link']);
                if (! empty($res['sent'])) {
                    $n++;
                }
            } catch (\Throwable $e) {
                Log::warning('Portal tip push failed', ['user_id' => $uid, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
