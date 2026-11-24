<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * THE DEMO PHONE'S MAIL APP (2026-10-31, Anthony: "the second phone should have a mail app ... to show the emails that
 * are being received as well by the parent"). Mail to a public-demo person is never delivered (SuppressAgencyMail cancels
 * it: the addresses are on domains we own with no mail server). Just before it is cancelled, the finished message - the
 * HTML and text exactly as it would have gone out, its subject, sender and attachments - is kept here for that demo
 * person, so the website's demo phone can show it in a Mail app.
 *
 * Only a person who belongs ONLY to public demo agencies (PublicDemo::isUser) ever gets a row; real agencies' suppressed
 * mail is never captured. Rows and files are removed every night by kt:reset-web-demo.
 */
final class WebDemoMail
{
    public const DIR = 'web-demo-mail';
    private const MAX_ATT = 8;
    private const MAX_ATT_BYTES = 12 * 1048576;

    public static function capture($message, array $addresses): void
    {
        try {
            if (! $message instanceof \Symfony\Component\Mime\Email || ! PublicDemo::agencyIds()) return;
            $seen = [];
            foreach ($addresses as $addr) {
                $e = mb_strtolower(trim((string) $addr));
                if ($e === '' || isset($seen[$e])) continue;
                $seen[$e] = 1;
                $uid = (int) DB::table('users')->whereRaw('LOWER(email) = ?', [$e])->value('id');
                if (! $uid || ! PublicDemo::isUser($uid)) continue;
                $agency = PublicDemo::agencyOf($uid);
                if (! PublicDemo::isAgency($agency)) continue;
                self::store($message, $uid, $agency);
            }
        } catch (\Throwable $e) {
            Log::warning('web demo mail capture failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    private static function store(\Symfony\Component\Mime\Email $m, int $uid, int $agency): void
    {
        $from = ($m->getFrom() ?: [])[0] ?? null;
        $html = $m->getHtmlBody();
        $text = $m->getTextBody();
        if (is_resource($html)) $html = stream_get_contents($html);
        if (is_resource($text)) $text = stream_get_contents($text);
        $id = DB::table('web_demo_mail')->insertGetId([
            'user_id' => $uid, 'agency_id' => $agency,
            'from_name' => $from ? mb_substr((string) $from->getName(), 0, 190) : null,
            'from_email' => $from ? mb_substr((string) $from->getAddress(), 0, 190) : null,
            'subject' => mb_substr((string) $m->getSubject(), 0, 300),
            'html' => $html !== null ? mb_substr((string) $html, 0, 3000000) : null,
            'text' => $text !== null ? mb_substr((string) $text, 0, 500000) : null,
            'created_at' => now(),
        ]);
        $atts = [];
        $n = 0;
        foreach ($m->getAttachments() as $part) {
            if ($n >= self::MAX_ATT) break;
            try {
                $body = $part->getBody();
                if (! is_string($body) || strlen($body) > self::MAX_ATT_BYTES) continue;
                $name = (string) ($part->getFilename() ?: ('attachment-' . ($n + 1)));
                $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'file';
                $path = self::DIR . '/' . $id . '/' . $n . '-' . $safe;
                Storage::disk('local')->put($path, $body);
                $atts[] = ['n' => $n, 'name' => $name, 'mime' => $part->getMediaType() . '/' . $part->getMediaSubtype(), 'size' => strlen($body), 'path' => $path];
                $n++;
            } catch (\Throwable $e) {}
        }
        if ($atts) DB::table('web_demo_mail')->where('id', $id)->update(['attachments' => json_encode($atts)]);
    }

    /** Nightly: every captured message and its files. */
    public static function purge(bool $dry = false): int
    {
        $n = DB::table('web_demo_mail')->count();
        if (! $dry) {
            DB::table('web_demo_mail')->delete();
            try { Storage::disk('local')->deleteDirectory(self::DIR); } catch (\Throwable $e) {}
        }
        return $n;
    }
}
