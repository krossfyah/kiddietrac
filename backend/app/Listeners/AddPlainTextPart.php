<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * EVERY EMAIL CARRIES A PLAIN-TEXT PART (2026-10-30). Anthony: "make sure these emails do not get flagged as
 * junk/spam otherwise this is pointless". Sales campaigns, cold reach, and most notices were sent with Mail::html()
 * - HTML only. Spam filters (Outlook's most of all) score an HTML-only message as machine-made; a matching text
 * alternative is one of the cheapest, most reliable fixes. Authentication was already fine (SPF, DKIM through
 * Microsoft 365, DMARC), and GraphTransport sends the whole MIME message, so the part we add here goes out.
 *
 * Registered BEFORE SuppressAgencyMail (that gate returns true and stops the listeners after it), so the text is
 * also translated by MailTranslation with the HTML. Returns nothing: never cancels or changes a send otherwise.
 */
class AddPlainTextPart
{
    public function handle(MessageSending $event): void
    {
        try {
            $m = $event->message;
            if (! method_exists($m, 'getHtmlBody')) return;
            $html = $m->getHtmlBody();
            if (! is_string($html) || trim($html) === '') return;
            $text = $m->getTextBody();
            if (is_string($text) && trim($text) !== '') return;   // the sender wrote its own
            $m->text(\App\Support\MailPlainText::fromHtml($html), 'utf-8');
        } catch (\Throwable $e) {
            // never in the way of a send
        }
    }
}
