<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\FileRequestController;
use App\Http\Controllers\Api\SignedFileUploadController;
use App\Services\EmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The three letters a file request produces.
 *
 *   ask()        -> the person being asked, with a no-login link and the list
 *   completed()  -> the office, when everything has arrived
 *   receipt()    -> the person, confirming what they sent and when
 *
 * Same shape as FormSubmissionNotice, deliberately: a request for files and a request for
 * a signature are the same conversation from the reader's side, and they should not arrive
 * looking like they came from two different products.
 *
 * Every send stamps X-KT-Agency-Id. Without it SuppressAgencyMail judges every account on
 * the address -- and one address can hold accounts in two agencies, so a Test Agency
 * switch would silence a live agency's mail. That has bitten twice.
 */
final class FileRequestNotice
{
    /** Ask, or ask again. Returns whether anything was handed to the mailer. */
    public static function ask(int $requestId, bool $isReminder = false): bool
    {
        try {
            $d = FileRequestController::detail($requestId);
            if (! $d || empty($d['email'])) {
                return false;
            }

            $agencyId = (int) $d['agency_id'];
            $agencyName = self::agencyName($agencyId);
            $link = SignedFileUploadController::linkFor($requestId, (int) $d['user_id']);

            $outstanding = array_values(array_filter($d['items'], fn ($i) => ! $i['done']));
            $list = self::itemsHtml($isReminder ? $outstanding : $d['items']);

            $due = $d['due_on']
                ? '<br><strong>Needed by:</strong> ' . e(self::prettyDate((string) $d['due_on'], $agencyId))
                : '';
            $pri = in_array($d['priority'], ['high', 'urgent'], true)
                ? '<br><strong>Priority:</strong> ' . e(ucfirst((string) $d['priority']))
                : '';

            /* NAMED, NOT ANONYMOUS.

               "Your childcare agency has asked you for 3 items" is exactly what a phishing
               email says. A person's name, the agency they work for, and a real address to
               reply to are what tell a parent this is their centre and not somebody
               fishing for a copy of their ID. So the requester leads the sentence, and
               their address is in the box underneath where it can be checked.
               (Anthony, 2026-09-10) */
            $requester = trim((string) ($d['requested_by'] ?? ''));
            $requesterEmail = trim((string) ($d['requested_by_email'] ?? ''));
            $who = $requester !== ''
                ? '<strong>' . e($requester) . '</strong> at <strong>' . e($agencyName) . '</strong>'
                : '<strong>' . e($agencyName) . '</strong>';

            $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                . ($isReminder
                    ? 'A quick reminder from ' . $who . ' — ' . count($outstanding)
                        . ' item(s) are still outstanding.'
                    : $who . ' has asked you for ' . count($d['items']) . ' item(s).')
                . '</p>'
                . EmailTemplate::calloutBox(
                    '<strong>Requested by:</strong> ' . e($requester !== '' ? $requester : $agencyName)
                    . ($requesterEmail !== ''
                        ? ' &middot; <a href="mailto:' . e($requesterEmail) . '" style="color:#1F6FB2;text-decoration:none;">'
                            . e($requesterEmail) . '</a>'
                        : '')
                    . '<br><strong>Centre:</strong> ' . e($agencyName)
                    . $due . $pri,
                    in_array($d['priority'], ['high', 'urgent'], true) ? 'warning' : 'info'
                )
                . ($d['note'] ? '<p style="margin:0 0 14px;font-size:14px;color:#334155;line-height:1.6;">'
                    . nl2br(e((string) $d['note'])) . '</p>' : '')
                . '<p style="margin:16px 0 4px;font-size:13.5px;font-weight:700;color:#334155;">What is needed</p>'
                . $list
                . EmailTemplate::button($isReminder ? 'Finish uploading' : 'Upload your files', $link)
                . '<p style="margin:8px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
                . 'No password needed — the link opens straight onto the upload page and is personal to you. '
                . 'It expires in ' . SignedFileUploadController::LINK_DAYS . ' days.</p>';

            $byLine = $requester !== '' ? $requester . ' · ' . $agencyName : $agencyName;

            $html = EmailTemplate::wrap($agencyId, $body, [
                'eyebrow'   => $isReminder ? 'STILL NEEDED' : 'FILES REQUESTED',
                'title'     => $isReminder ? 'A reminder about your documents' : 'Please send us a few documents',
                'subtitle'  => $byLine,
                'preheader' => ($requester !== '' ? $requester . ' at ' : '') . $agencyName
                    . ' has asked you for ' . count($d['items']) . ' document(s).',
            ]);

            /* The subject names the person too. It is the only line a phone shows before
               the reader decides whether to open it. */
            $subject = ($isReminder ? 'Reminder: ' : '')
                . ($requester !== '' ? $requester . ' at ' . $agencyName : $agencyName)
                . ' needs ' . count($isReminder ? $outstanding : $d['items']) . ' document(s)';

            self::queue((string) $d['email'], $subject, $html, $agencyId);

            DB::table('file_requests')->where('id', $requestId)->update(['notified_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Everything has arrived: tell the office, and send the person a receipt. */
    public static function completed(int $requestId): void
    {
        try {
            $d = FileRequestController::detail($requestId);
            if (! $d) {
                return;
            }
            $agencyId = (int) $d['agency_id'];
            $agencyName = self::agencyName($agencyId);
            $when = AgencyTime::fmt(now(), AgencyTime::tz($agencyId));

            $family = DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->where('g.user_id', $d['user_id'])->whereNull('f.deleted_at')
                ->first(['f.family_name', 'f.centre_id']);

            // ── the office: THE PERSON WHO ASKED, not everyone who could have asked ──
            $recipients = self::officeEmails($d);
            if ($recipients) {
                $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                    . '<strong>' . e((string) $d['person']) . '</strong>'
                    . ($family ? ' (' . e((string) $family->family_name) . ')' : '')
                    . ' has sent everything that was requested.</p>'
                    . EmailTemplate::calloutBox(
                        '<strong>Agency:</strong> ' . e($agencyName)
                        . ($family ? '<br><strong>Family:</strong> ' . e((string) $family->family_name) : '')
                        . '<br><strong>Sent by:</strong> ' . e((string) $d['person'])
                        . ((string) ($d['email'] ?? '') !== '' ? ' (' . e((string) $d['email']) . ')' : '')
                        . '<br><strong>Requested by:</strong> ' . e((string) ($d['requested_by'] ?? '—'))
                        . '<br><strong>Completed:</strong> ' . e($when)
                        . '<br><strong>Files received:</strong> ' . (int) $d['received'] . ' of ' . (int) $d['asked'],
                        'success'
                    )
                    . '<p style="margin:16px 0 4px;font-size:13.5px;font-weight:700;color:#334155;">What was sent</p>'
                    . self::itemsHtml($d['items'], true)
                    . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
                    . 'Every file is filed on their record and in <strong>Forms Manager → Request files</strong>.</p>';

                $html = EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow'   => 'FILES RECEIVED',
                    'title'     => 'All requested files are in',
                    'subtitle'  => (string) $d['person'] . ($family ? ' · ' . $family->family_name : ''),
                    'preheader' => $d['person'] . ' sent all requested files.',
                ]);

                $subject = 'Files received: ' . $d['person']
                    . ($family ? ' — ' . $family->family_name : '')
                    . ' — ' . self::prettyDate(now()->toDateString(), $agencyId);
                foreach ($recipients as $to) {
                    self::queue($to, $subject, $html, $agencyId);
                }
            }

            // ── the person ──
            self::receipt($d, $agencyId, $agencyName, $when);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** "We have your files" — so the sender is not left wondering. */
    private static function receipt(array $d, int $agencyId, string $agencyName, string $when): void
    {
        if (empty($d['email'])) {
            return;
        }
        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
            . 'Thank you. Everything <strong>' . e($agencyName) . '</strong> asked for has been received.</p>'
            . EmailTemplate::calloutBox(
                '<strong>Sent:</strong> ' . e($when)
                . '<br><strong>Files:</strong> ' . (int) $d['received'] . ' of ' . (int) $d['asked']
                . '<br><strong>Agency:</strong> ' . e($agencyName),
                'success'
            )
            . '<p style="margin:16px 0 4px;font-size:13.5px;font-weight:700;color:#334155;">What you sent</p>'
            . self::itemsHtml($d['items'], true)
            . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
            . 'Your copies are kept in your KiddieTrac account under <strong>Documents</strong>, '
            . 'where you can open or save them at any time. You do not need to do anything else.</p>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'FILES RECEIVED',
            'title'     => 'We have your documents',
            'subtitle'  => 'Sent to ' . $agencyName,
            'preheader' => $agencyName . ' has received all of your documents.',
        ]);

        self::queue((string) $d['email'], 'We have received your documents — ' . $agencyName, $html, $agencyId);
    }

    /** The list, with a tick against anything already in. */
    private static function itemsHtml(array $items, bool $showFiles = false): string
    {
        if (! $items) {
            return '<span style="color:#94A3B8;">Nothing listed</span>';
        }
        $li = [];
        foreach ($items as $i) {
            $line = '<strong>' . e((string) $i['description']) . '</strong>'
                . ((int) $i['quantity'] > 1 ? ' <span style="color:#64748B;">× ' . (int) $i['quantity'] . '</span>' : '');
            if ($showFiles) {
                $line .= ' <span style="color:#16A34A;font-weight:700;">✓ ' . count($i['files']) . ' sent</span>';
            } elseif ($i['done']) {
                $line .= ' <span style="color:#16A34A;font-weight:700;">✓ received</span>';
            }
            $li[] = '<li style="margin:3px 0;">' . $line . '</li>';
        }

        return '<ul style="margin:6px 0 0;padding-left:20px;">' . implode('', $li) . '</ul>';
    }

    private static function agencyName(int $agencyId): string
    {
        $n = DB::table('agencies')->where('id', $agencyId)->value('name');

        return trim((string) $n) ?: 'your childcare agency';
    }

    private static function prettyDate(string $date, int $agencyId): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($date, AgencyTime::tz($agencyId))->format('D, M j, Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    /**
     * Who hears that the documents arrived: THE PERSON WHO REQUESTED THEM.
     *
     * BUG (found 2026-09-10, alongside the same mistake in FormSubmissionNotice): this
     * derived its own audience — every agency_admin plus the centre's directors — for a
     * message that has one obvious, already-recorded addressee. `file_requests` stores
     * `requested_by_id`: a named person chose these items, wrote to this family and is
     * waiting on the reply. Telling the whole office instead both buries the answer for
     * the one person who needed it and copies a family's identity documents to people who
     * were never part of the exchange.
     *
     * The requester, then. FAILS CLOSED for the same reason as the form notice: if the
     * request has no recorded requester, nobody is told rather than everybody. The upload
     * still lands on the record, and the Documents screen still shows it.
     *
     * @return string[]
     */
    private static function officeEmails(array $d): array
    {
        $to = trim((string) ($d['requested_by_email'] ?? ''));

        return filter_var($to, FILTER_VALIDATE_EMAIL) ? [$to] : [];
    }

    private static function queue(string $to, string $subject, string $html, int $agencyId): void
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        dispatch(function () use ($to, $subject, $html, $agencyId) {
            try {
                Mail::html($html, function ($m) use ($to, $subject, $agencyId) {
                    $m->to($to)
                      ->from('noreply@kiddietrac.com', 'KiddieTrac')
                      ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                      ->subject($subject);
                    // Whose switches govern this message. See the class comment.
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                });
            } catch (\Throwable $e) {
                Log::warning('File request notice failed', ['to' => $to, 'error' => $e->getMessage()]);
            }
        })->onQueue('mail');
    }
}
