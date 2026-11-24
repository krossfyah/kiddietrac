<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Brand;
use App\Support\SocialLinks;
use Illuminate\Mail\Events\MessageSending;

/**
 * KIDDIETRAC'S LINKEDIN / YOUTUBE / FACEBOOK ON EVERY OUTBOUND EMAIL (2026-11-02).
 *
 * Email HTML is built in many places. EmailTemplate puts the row in its footer (inside <!--kt-social-->
 * markers); every other HTML email gets a small navy strip (SocialLinks::strip) just before </body>. Decided
 * once, here, at send time:
 *
 *   - whose mail it is: the X-KT-Agency-Id header, else WhiteLabelMail::agencyFor (the white-label render
 *     marker, then recipients that belong to exactly one agency);
 *   - an agency that does not mail under KiddieTrac's own brand (Brand::forAgency source !== 'kiddietrac':
 *     a reseller or white-label agency) gets NO links - an existing row is removed;
 *   - no agency found: a row EmailTemplate already placed stays (it was built for KiddieTrac's brand); a
 *     strip is only added when the send is stamped as KiddieTrac's own (MailScope::platform, X-KT-Platform).
 *     Anything else is left alone - fail closed.
 *
 * Registered after WhiteLabelMail and before AddPlainTextPart, so the generated text part carries the links.
 * Never blocks or cancels a send.
 */
final class SocialLinksMail
{
    public function handle(MessageSending $event): void
    {
        try {
            $msg = $event->message;
            if (! method_exists($msg, 'getHtmlBody')) return;
            $html = $msg->getHtmlBody();
            if (! is_string($html) || trim($html) === '') return;

            $has = str_contains($html, SocialLinks::START);
            $h = $msg->getHeaders();
            $agency = null;
            if ($h->has('X-KT-Agency-Id')) {
                $agency = (int) trim((string) $h->get('X-KT-Agency-Id')->getBodyAsString()) ?: null;
            }
            if (! $agency) {
                $agency = WhiteLabelMail::agencyFor($msg);
            }

            if ($agency) {
                $ok = SocialLinks::allowedFor($agency);
            } else {
                $ok = $has || $h->has('X-KT-Platform');
            }

            if (! $ok) {
                if ($has) {
                    $q = preg_quote(SocialLinks::START, '#') . '.*?' . preg_quote(SocialLinks::END, '#');
                    $msg->html((string) preg_replace('#' . $q . '#s', '', $html));
                }
                return;
            }

            if (! $has && ! str_contains($html, SocialLinks::LINKEDIN)) {
                $strip = SocialLinks::strip();
                $html = preg_match('#</body>#i', $html)
                    ? (string) preg_replace('#</body>#i', $strip . '</body>', $html, 1)
                    : $html . $strip;
                $css = SocialLinks::stripCss();
                $html = preg_match('#</head>#i', $html)
                    ? (string) preg_replace('#</head>#i', $css . '</head>', $html, 1)
                    : $css . $html;
                $msg->html($html);
            }

            // A text part the sender wrote itself (AddPlainTextPart only fills an empty one).
            $text = $msg->getTextBody();
            if (is_string($text) && trim($text) !== '' && ! str_contains($text, SocialLinks::LINKEDIN)) {
                $msg->text(rtrim($text) . "\n\n" . SocialLinks::text() . "\n", 'utf-8');
            }
        } catch (\Throwable $e) {
            // Social links must never be the reason an email does not go.
        }
    }
}
