<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * EMAIL LINKS THAT CAN OPEN THE APP (2026-11-05).
 *
 * The Android app claims https://app.kiddietrac.com/ , /index.html and /dashboard.html (verified App Links), so
 * "Open KiddieTrac" in an email opens the installed app and the browser otherwise. Android matches on the path, and
 * a link written as the bare host - https://app.kiddietrac.com with nothing after it, how many emails write the
 * portal - has NO path, so it never matched and always opened the browser (checked on a Pixel 8 emulator). The same
 * address with its "/" is the same page in every browser. Added here, at send time, for every email at once, in the
 * HTML and in a text part the sender wrote. Never blocks or cancels a send.
 */
final class AppLinksMail
{
    private const BARE_HTML = '#(href\s*=\s*["\'])(https://app\.kiddietrac\.com)(?=["\'?\#])#i';
    private const BARE_TEXT = '#(https://app\.kiddietrac\.com)(?=$|[\s?\#)\]>"\'<,]|\.(?:\s|$))#im';

    public function handle(MessageSending $event): void
    {
        try {
            $msg = $event->message;
            if (method_exists($msg, 'getHtmlBody')) {
                $html = $msg->getHtmlBody();
                if (is_string($html) && stripos($html, 'app.kiddietrac.com') !== false) {
                    $new = (string) preg_replace(self::BARE_HTML, '$1$2/', $html);
                    if ($new !== $html) $msg->html($new);
                }
            }
            if (method_exists($msg, 'getTextBody')) {
                $text = $msg->getTextBody();
                if (is_string($text) && stripos($text, 'app.kiddietrac.com') !== false) {
                    $new = (string) preg_replace(self::BARE_TEXT, '$1/', $text);
                    if ($new !== $text) $msg->text($new, 'utf-8');
                }
            }
        } catch (\Throwable $e) {
            // A link left as it was still opens the browser.
        }
    }
}
