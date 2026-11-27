<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\FcmService;
use Illuminate\Support\Facades\DB;

/**
 * 📻 WALKIE-TALKIE WAKE-UP (2026-11-27, App\Support\Walkie).
 *
 *  - A phone running the walkie-talkie listener (walkie_devices, Android app 3.13+) gets a DATA-ONLY, high-priority
 *    FCM message {kt_walkie, tx, from, ch, em}: the app's own code runs, fetches the key (walkie/device/wake) and
 *    plays the talk out loud. No notification text from Google's side, no audio, no key in the push.
 *  - Every other phone of the person gets an ordinary notification: "📻 Walkie-talkie from Amna" / "Tap to listen."
 *  - Sent in parallel (curl_multi) after the response, so a talk to the whole agency starts at once.
 *
 * Gates, before anything leaves: agency outsiders (super admins, integration, resellers) never; test agencies send
 * nothing (TestAgency::blocks); do-not-contact (Suppression); sign-in locks. Tests set WalkiePush::$capture = [] and
 * get the messages there instead of sending.
 */
final class WalkiePush
{
    /** @var array<int,array>|null tests: captured instead of sent */
    public static ?array $capture = null;

    /** @param int[] $uids */
    public static function wake(int $aid, array $uids, int $txId, int $speaker, string $label, bool $emergency): array
    {
        $from = Walkie::firstName($speaker);
        $title = ($emergency ? '🚨 ' : '📻 ') . 'Walkie-talkie from ' . $from;
        $body = $emergency ? 'Urgent. Tap to listen now.' : 'Tap to listen.';
        $ok = [];
        foreach (array_unique(array_map('intval', $uids)) as $u) {
            if ($u <= 0 || AgencyOutsiders::isOutsider($u)) continue;
            if (TestAgency::blocks($aid, $u) || TestAgency::isAgency($aid)) { TestAgency::note('walkie push', ['user' => $u]); continue; }
            if (Suppression::isUser($u)) continue;
            if (rescue(fn () => SignInLocks::pushPaused($u), false, false)) continue;
            $ok[] = $u;
        }
        $msgs = [];
        if ($ok) {
            $walkie = DB::table('walkie_devices')->whereIn('user_id', $ok)->where('platform', 'android')->whereNotNull('push_token')
                ->get(['user_id', 'push_token']);
            $wTokens = [];
            foreach ($walkie as $d) {
                $wTokens[(string) $d->push_token] = true;
                $msgs[] = ['user' => (int) $d->user_id, 'kind' => 'walkie', 'message' => ['token' => (string) $d->push_token,
                    'android' => ['priority' => 'HIGH', 'ttl' => '30s'],
                    'data' => ['kt_walkie' => '1', 'tx' => (string) $txId, 'from' => $from, 'ch' => $label, 'em' => $emergency ? '1' : '0']]];
            }
            $fcm = app(FcmService::class);
            $others = DB::table('device_tokens')->whereIn('user_id', $ok)->whereIn('platform', ['android', 'ios'])->get(['user_id', 'token']);
            foreach ($others as $t) {
                if (isset($wTokens[(string) $t->token])) continue;
                $p = $fcm->buildPayload((string) $t->token, $title, $body, ['link' => '#walkie?play=' . $txId], $emergency);
                $msgs[] = ['user' => (int) $t->user_id, 'kind' => 'alert', 'message' => $p['message'] ?? $p];
            }
        }
        if (self::$capture !== null) {
            foreach ($msgs as $m) self::$capture[] = $m;

            return ['sent' => 0, 'captured' => count($msgs)];
        }
        $sent = self::send($msgs);
        if ($ok) rescue(fn () => PushAudit::record('fcm', $ok, $title, $body, $sent > 0 ? 'sent' : 'no_device'), null, false);

        return ['sent' => $sent, 'people' => count($ok)];
    }

    /** POST each message to FCM v1 at once. @return int how many FCM accepted */
    private static function send(array $msgs): int
    {
        if (! $msgs || Outbound::off()) return 0;
        $target = app(FcmService::class)->walkieTarget();
        if (! $target) return 0;
        [$url, $bearer] = $target;
        $mh = curl_multi_init();
        $hs = [];
        foreach ($msgs as $i => $m) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['message' => $m['message']])]);
            curl_multi_add_handle($mh, $ch);
            $hs[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running && $st === CURLM_OK);
        $ok = 0;
        foreach ($hs as $i => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 200 && $code < 300) $ok++;
            elseif ($code === 404) {
                $tok = (string) ($msgs[$i]['message']['token'] ?? '');
                if ($tok !== '') {
                    DB::table('device_tokens')->where('token', $tok)->delete();
                    DB::table('walkie_devices')->where('push_token', $tok)->update(['push_token' => null]);
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $ok;
    }
}
