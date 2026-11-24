<?php

namespace App\Support;

use App\Support\MessageTranslator;
use Illuminate\Mail\Events\MessageSending;

/**
 * Every outgoing email in its recipients' language (2026-10-22). Called from
 * SuppressAgencyMail::handle() once the gate has decided the email may go — a
 * MessageSending listener of its own would never run, because the gate returns true and
 * that stops the rest (Laravel's until()); and not in app/Listeners, where event discovery
 * would register it a second time. Runs after the white-label renaming (brand kept).
 *
 * Translated only when everyone it goes To shares one non-English language
 * (users.locale); mixed or English recipients get the English original. The subject and
 * plain-text part are translated whole; the HTML part word-by-word between the tags
 * (MessageTranslator::html), so the layout, links and images are untouched.
 * Opt a message out with the header X-KT-No-Translate.
 */
final class MailTranslation
{
    public static function apply(MessageSending $event)
    {
        try {
            $m = $event->message;
            if (! $m instanceof \Symfony\Component\Mime\Email) {
                return null;
            }
            if ($m->getHeaders()->has('X-KT-No-Translate')) {
                return null;
            }
            $to = array_map(fn ($a) => $a->getAddress(), $m->getTo());
            $loc = MessageTranslator::forEmails($to);
            if (! $loc) {
                return null;
            }
            $subject = (string) $m->getSubject();
            if ($subject !== '') {
                $m->subject(MessageTranslator::text($subject, $loc, 15));
            }
            $html = $m->getHtmlBody();
            if (is_string($html) && $html !== '') {
                $m->html(MessageTranslator::html($html, $loc, 40), $m->getHtmlCharset() ?: 'utf-8');
            }
            $text = $m->getTextBody();
            if (is_string($text) && trim($text) !== '') {
                $m->text(MessageTranslator::text($text, $loc, 25), $m->getTextCharset() ?: 'utf-8');
            }
            $m->getHeaders()->addTextHeader('Content-Language', $loc);
        } catch (\Throwable $e) {
            // The English original goes out.
        }

        return null;
    }
}
