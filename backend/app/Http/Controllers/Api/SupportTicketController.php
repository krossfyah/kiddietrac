<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Help-desk tickets (separate from chat).
 * Any user can raise a ticket; admins triage + reply.
 */
final class SupportTicketController extends Controller
{
    use ResolvesCentreContext;

    /**
     * SECURITY (v22p96): a staff member may only act on a ticket that belongs to
     * their OWN agency; a platform_admin only on tickets of the agency they've
     * switched into (X-Active-Agency-Id). The prior global `$isStaff` check let
     * any agency's staff — and a switched super-admin — read/reply/triage every
     * agency's tickets (which carry billing/PII free-text). The raiser always
     * keeps access to their own ticket.
     */
    private function staffMayAccessTicket(Request $request, object $ticket): bool
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        if (! $isStaff) return false;
        if ($this->isPlatformAdminUser($u)) {
            return (int) $ticket->agency_id === (int) $this->resolveAgencyId($request);
        }
        return $this->userBelongsToAgency($u->id, (int) $ticket->agency_id);
    }
    public function listMine(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        $q = DB::table('support_tickets as t')
            ->leftJoin('users as raised', 'raised.id', '=', 't.raised_by_user_id')
            ->leftJoin('users as assigned', 'assigned.id', '=', 't.assigned_user_id')
            ->orderByDesc('t.created_at')
            ->select('t.*',
                DB::raw("CONCAT(raised.first_name,' ',raised.last_name) as raised_by_name"),
                DB::raw("CONCAT(assigned.first_name,' ',assigned.last_name) as assigned_name"));
        if ($isStaff) {
            // SECURITY (v22p96): resolve the active agency securely (honours the
            // header only for members/platform_admin) — never trust it raw.
            $agencyId = (int) $this->resolveAgencyId($request);
            /* A ticket with NO agency is a PLATFORM-level problem — an unhandled server
               error on a public endpoint, or one raised before anybody signed in, so
               there was no agency to attribute it to. Filtered strictly on agency_id
               those are invisible to every agency at once, which is the same trap that
               hid the auto sign-off audit rows: written faithfully, readable by nobody.
               A platform admin sees them; a tenant admin still sees only their own.
               (2026-08-31) */
            if ($this->isPlatformAdminUser($u)) {
                $q->where(function ($w) use ($agencyId) {
                    $w->where('t.agency_id', $agencyId)->orWhereNull('t.agency_id');
                });
            } else {
                $q->where('t.agency_id', $agencyId);
            }
        } else {
            $q->where('t.raised_by_user_id', $u->id);
        }
        return response()->json(['data' => $q->limit(200)->get()]);
    }


    /**
     * May this user see this ticket at all?
     *
     * The person who raised it, or staff of the agency it belongs to. Everything to do
     * with files goes through here, so a file can never be reachable by somebody who
     * could not open the ticket it hangs off.
     */
    private function maySeeTicket(Request $request, $ticket): bool
    {
        if (! $ticket) {
            return false;
        }
        $uid = (int) $request->user()->id;
        if ((int) ($ticket->raised_by_user_id ?? 0) === $uid) {
            return true;
        }

        return $this->staffMayAccessTicket($request, $ticket);
    }

    /** POST /tickets/{id}/files — attach a screenshot, an export or a log. */
    public function uploadFile(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($this->maySeeTicket($request, $ticket), 403, 'Not your ticket.');

        $request->validate([
            // Images for screenshots, plus the text and archive formats a log or an
            // export actually arrives as. Deliberately no executables.
            'file' => ['required', 'file', 'max:10240',
                      'mimes:jpg,jpeg,png,gif,webp,heic,pdf,txt,log,csv,json,xml,zip'],
        ]);

        $file = $request->file('file');
        $name = (string) \Illuminate\Support\Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');
        // Stored on the PRIVATE disk. A ticket attachment can hold a child's name, an
        // invoice or a screenshot of somebody's record, so it is never served from the
        // public root where a guessed filename would reach it.
        $path = $file->storeAs('ticket-files/'.$id, $name);

        $fileId = DB::table('support_ticket_files')->insertGetId([
            'ticket_id' => $id,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getClientMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('support_tickets')->where('id', $id)->update(['updated_at' => now()]);

        return response()->json([
            'id' => $fileId,
            'original_name' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
        ]);
    }

    /** GET /tickets/{id}/files/{fileId} — stream one back. */
    public function downloadFile(Request $request, int $id, int $fileId)
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($this->maySeeTicket($request, $ticket), 403, 'Not your ticket.');

        $row = DB::table('support_ticket_files')->where('id', $fileId)->where('ticket_id', $id)->first();
        abort_unless($row, 404);

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        abort_unless($disk->exists($row->path), 404);

        return response()->stream(function () use ($disk, $row) {
            echo $disk->get($row->path);
        }, 200, [
            'Content-Type' => $row->mime ?: 'application/octet-stream',
            // inline so a screenshot opens in the browser rather than downloading.
            'Content-Disposition' => 'inline; filename="'.addslashes($row->original_name).'"',
        ]);
    }
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            /* `documentation` is raised by the help screen when somebody marks a guide
               unhelpful and says why. Not offered in the ticket form — nobody files one
               by hand — same as `crash`. */
            'category' => 'required|string|in:billing,enrollment,maintenance,technical,policy,documentation,other',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'subject' => 'required|string|max:200',
            'body' => 'nullable|string|max:10000',
            'centre_id' => 'nullable|integer',
        ]);
        $u = $request->user();
        $agencyId = (int) DB::table('role_assignments')->where('user_id', $u->id)
            ->where('active', 1)->value('agency_id');
        $id = DB::table('support_tickets')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'raised_by_user_id' => $u->id,
            'category' => $data['category'],
            'priority' => $data['priority'] ?? 'normal',
            'subject' => $data['subject'],
            'body' => $data['body'] ?? null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Notify admins
        $adminIds = DB::table('role_assignments')->where('agency_id', $agencyId)
            ->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', 1)->pluck('user_id')->unique();
        foreach ($adminIds as $aid) {
            DB::table('notifications')->insert([
                'user_id' => $aid, 'type' => 'support_ticket',
                'title' => "[{$data['category']}] {$data['subject']}",
                'body' => substr((string) ($data['body'] ?? ''), 0, 200),
                'data' => json_encode(['link' => '#tickets', 'ticket_id' => $id]),
                'created_at' => now(),
            ]);
        }
        // v22p97: EMAIL the ticket so it never sits unseen. In-app notifications
        // alone miss admins who don't log in, and KiddieTrac HQ heard nothing at
        // all. We email every agency admin/director + a platform support inbox.
        // Wrapped so a mail failure can never break ticket creation.
        try {
            $raiser = DB::table('users')->where('id', $u->id)->first();
            $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'KiddieTrac');
            $adminEmails = DB::table('users')->whereIn('id', $adminIds->all())
                ->whereNotNull('email')->pluck('email')->filter()->unique()->values()->all();
            // Routing rule: an app issue / bug (category "technical") is a PLATFORM
            // problem, so it goes to KiddieTrac HQ. Every other category is an
            // agency matter, so it goes to that agency's admins/directors.
            $hqInbox = env('SUPPORT_INBOX', 'info@kiddietrac.com');
            /* A guide is written and owned by KiddieTrac, not by the agency reading it,
               so feedback on one goes to HQ for the same reason an app bug does. Sending
               it to the agency's own admins would ask them to fix documentation they
               cannot edit. */
            if (in_array($data['category'], ['technical', 'documentation'], true)) {
                $recipients = [$hqInbox];
            } else {
                $recipients = $adminEmails;
            }
            $recipients = array_values(array_unique(array_filter($recipients)));
            $raiserName = trim(((string) ($raiser->first_name ?? '')) . ' ' . ((string) ($raiser->last_name ?? ''))) ?: 'A user';
            $raiserEmail = (string) ($raiser->email ?? '');
            $pri = strtoupper((string) ($data['priority'] ?? 'normal'));
            $subjectLine = "[Support · {$agencyName}] ({$pri}/{$data['category']}) " . $data['subject'];
            $inner = '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>' . e($raiserName) . '</strong>'
                . ($raiserEmail ? ' (<a href="mailto:' . e($raiserEmail) . '" style="color:#1F6080;">' . e($raiserEmail) . '</a>)' : '')
                . ' raised a support ticket.</p>'
                . '<table style="border-collapse:collapse;font-size:14px;margin:0 0 14px;">'
                . '<tr><td style="padding:2px 14px 2px 0;color:#64748b;">Agency</td><td style="color:#0f172a;">' . e($agencyName) . '</td></tr>'
                . '<tr><td style="padding:2px 14px 2px 0;color:#64748b;">Category</td><td style="color:#0f172a;">' . e($data['category']) . '</td></tr>'
                . '<tr><td style="padding:2px 14px 2px 0;color:#64748b;">Priority</td><td style="color:#0f172a;">' . e($pri) . '</td></tr>'
                . '<tr><td style="padding:2px 14px 2px 0;color:#64748b;">Subject</td><td style="color:#0f172a;">' . e($data['subject']) . '</td></tr>'
                . '</table>'
                . '<div style="white-space:pre-wrap;border-left:3px solid #e2e8f0;padding:4px 0 4px 14px;color:#334155;font-size:14px;line-height:1.55;">'
                . nl2br(e((string) ($data['body'] ?? '(no message)'))) . '</div>'
                . '<p class="kt-muted" style="color:#94a3b8;font-size:12px;margin:16px 0 0;">Ticket #' . $id . ' &middot; open it under <strong>Support</strong> in the portal to reply.</p>';
            // Brand the email with header/footer. An app-issue/bug goes to KiddieTrac
            // HQ so it always carries KiddieTrac branding; an agency-bound ticket
            // uses the agency's own logo/colours when that agency is white-labelled
            // (EmailTemplate::wrap auto-detects it from the agency), else KiddieTrac.
            $isTech = ($data['category'] === 'technical');
            $html = \App\Services\EmailTemplate::wrap(
                $isTech ? null : (int) $agencyId,
                $inner,
                [
                    'force_brand' => $isTech ? 'kt' : null,
                    'eyebrow'     => 'SUPPORT TICKET',
                    'title'       => 'New support ticket',
                    'subtitle'    => $agencyName . ' · ' . $pri . ' priority',
                    'preheader'   => '[' . $data['category'] . '] ' . $data['subject'],
                ]
            );
            foreach ($recipients as $to) {
                try {
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $subjectLine) {
                        $m->to($to)->subject($subjectLine);
                        // A support ticket is an operational, action-needed alert —
                        // it must reach the recipient even when the agency has bulk
                        // notifications toggled off, so bypass the suppression gate.
                        $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                    });
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('support ticket mail to ' . $to . ' failed: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('support ticket notify failed: ' . $e->getMessage());
        }
        return response()->json(['id' => $id], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        $u = $request->user();
        abort_unless($ticket->raised_by_user_id === $u->id || $this->staffMayAccessTicket($request, $ticket), 403);
        $messages = DB::table('support_ticket_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.ticket_id', $id)
            ->orderBy('m.created_at')
            ->select('m.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as author_name"))
            ->get();

        // Who is staff decides which side of the conversation a reply sits on. Worked
        // out once here rather than guessed at in the browser.
        $authorIds = $messages->pluck('user_id')->filter()->unique()->values()->all();
        $staffIds = $authorIds
            ? DB::table('role_assignments')->whereIn('user_id', $authorIds)
                ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
                ->where('active', 1)->pluck('user_id')->unique()->all()
            : [];
        foreach ($messages as $m) {
            $m->is_staff = in_array((int) $m->user_id, array_map('intval', $staffIds), true);
        }

        // People by name. The screen cannot turn an id into a person and should not try.
        $name = function ($uid) {
            if (! $uid) {
                return null;
            }
            $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);

            return $u ? trim($u->first_name.' '.$u->last_name) : null;
        };
        $ticket->raised_by_name = $name($ticket->raised_by_user_id);
        $ticket->assigned_name = $name($ticket->assigned_user_id ?? null);
        $ticket->resolved_by_name = $name($ticket->resolved_by_user_id ?? null);
        $ticket->centre_name = $ticket->centre_id
            ? DB::table('centres')->where('id', $ticket->centre_id)->value('name')
            : null;

        $files = DB::table('support_ticket_files')->where('ticket_id', $id)
            ->orderBy('id')
            ->get(['id', 'original_name', 'mime', 'size_bytes', 'created_at']);

        // The history. Without it a ticket can only show where it ended up, never how it
        // got there — which was most of what made the screen unreadable.
        $events = DB::table('support_ticket_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.ticket_id', $id)
            ->orderBy('e.created_at')->orderBy('e.id')
            ->select('e.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as actor_name"))
            ->get();

        return response()->json([
            'ticket' => $ticket,
            'messages' => $messages,
            'files' => $files,
            'events' => $events,
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:10000']);
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        // v22p96: scoped — global staff role alone is not enough; must own the agency.
        abort_unless($ticket->raised_by_user_id === $u->id || $this->staffMayAccessTicket($request, $ticket), 403);
        DB::table('support_ticket_messages')->insert([
            'ticket_id' => $id, 'user_id' => $u->id, 'body' => $data['body'],
            'created_at' => now(),
        ]);
        // Replying moves the ticket automatically. That is a real status change and
        // belongs in the history like any other, otherwise the timeline shows a ticket
        // changing state with nothing to explain it.
        $newStatus = $isStaff ? 'awaiting_user' : 'open';
        DB::table('support_tickets')->where('id', $id)->update([
            'status' => $newStatus,
            'updated_at' => now(),
        ]);
        if ((string) $ticket->status !== $newStatus) {
            $this->logEvent($id, 'status', (string) $ticket->status, $newStatus, null, $u->id);
        }
        // Notify the other side
        $otherId = $isStaff ? $ticket->raised_by_user_id : ($ticket->assigned_user_id ?? null);
        if ($otherId) {
            DB::table('notifications')->insert([
                'user_id' => $otherId, 'type' => 'support_ticket',
                'title' => "Reply on ticket: {$ticket->subject}",
                'body' => substr($data['body'], 0, 200),
                'data' => json_encode(['link' => '#tickets', 'ticket_id' => $id]),
                'created_at' => now(),
            ]);
        }

        /* And EMAIL them. The in-app bell alone only reaches people who happen to open
           the portal — which is exactly the audience a support reply is least likely to
           reach, and why tickets sat in `awaiting_user` with the user never told. */
        $this->sendReplyNotice($ticket, $u, $data['body'], $isStaff, $otherId);

        return response()->json(['status' => 'replied']);
    }

    /**
     * Tell the other side, by email, that their ticket has a reply.
     *
     * Mirrors the resolution notice in updateStatus(): same branded shell, same mailer,
     * same BCC to HQ so support has the thread. Best-effort throughout — a reply that
     * was written and stored must never fail because mail did.
     */
    private function sendReplyNotice($ticket, $author, string $body, bool $authorIsStaff, $otherId): void
    {
        try {
            $agencyId = $ticket->agency_id ? (int) $ticket->agency_id : null;

            /* Who hears about it. Staff replied → the person who raised it. The raiser
               replied → whoever is assigned; and with nobody assigned, the agency's
               admins and directors, so a customer's reply is not filed into silence. */
            $recipients = [];
            if ($otherId) {
                $e = DB::table('users')->where('id', $otherId)->whereNull('deleted_at')->value('email');
                if ($e) { $recipients[] = $e; }
            }
            if (! $authorIsStaff && ! $recipients && $agencyId) {
                $recipients = DB::table('users as u')
                    ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                    ->where('ra.agency_id', $agencyId)->where('ra.active', true)
                    ->whereIn('ra.role', ['agency_admin', 'centre_director'])
                    ->whereNull('u.deleted_at')->whereNotNull('u.email')
                    ->distinct()->pluck('u.email')->all();
            }
            $recipients = array_values(array_unique(array_filter($recipients)));
            if (! $recipients) {
                return;
            }

            $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'KiddieTrac');
            $tz = \App\Support\AgencyTime::tz($agencyId);
            $when = now()->setTimezone($tz);
            $who = trim(((string) ($author->first_name ?? '')) . ' ' . ((string) ($author->last_name ?? '')))
                ?: ($authorIsStaff ? 'The team' : 'The person who raised it');

            $html = \App\Services\EmailTemplate::wrap($agencyId,
                '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                . e($who) . ' replied to your support request'
                . ($authorIsStaff ? ' at <strong>' . e($agencyName) . '</strong>' : '') . '.</p>'

                . '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;'
                . 'border-left:4px solid #1F6FB2;border-radius:10px;padding:14px 16px;margin:0 0 14px;">'
                .   '<div class="kt-muted" style="font-size:11px;font-weight:800;letter-spacing:1px;'
                .     'color:#64748B;text-transform:uppercase;margin-bottom:4px;">Ticket #'
                .     (int) $ticket->id . '</div>'
                .   '<div style="font-size:15px;font-weight:800;color:#0F172A;">'
                .     e((string) $ticket->subject) . '</div>'
                . '</div>'

                /* The reply itself, not just a nudge to come and read it. Trimmed, because
                   an email is a notice — the full thread lives in the portal. */
                . '<div style="white-space:pre-wrap;border-left:3px solid #E2E8F0;padding:4px 0 4px 14px;'
                . 'color:#334155;font-size:14.5px;line-height:1.6;margin:0 0 16px;">'
                . nl2br(e(mb_strimwidth($body, 0, 1200, '…'))) . '</div>'

                . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#475569;">'
                . 'Sent ' . e($when->format('D, M j, Y')) . ' at ' . e($when->format('g:i A'))
                . ' (' . e($when->format('T')) . ').</p>'
                . '<p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">'
                . 'Open <strong>Support</strong> in the portal to reply.</p>',
                [
                    'eyebrow' => 'SUPPORT',
                    'title' => 'New reply on your ticket',
                    'preheader' => $who . ' replied — ticket #' . (int) $ticket->id
                        . ' · ' . (string) $ticket->subject,
                ]
            );

            \App\Services\AgencyMailer::forAgency($agencyId)->mailer()
                ->html($html, function ($m) use ($recipients, $ticket) {
                    $m->to($recipients[0])->subject('New reply on your ticket #' . (int) $ticket->id
                        . ' — ' . mb_strimwidth((string) $ticket->subject, 0, 60, '…'));
                    if (count($recipients) > 1) { $m->bcc(array_slice($recipients, 1, 20)); }
                    $m->bcc('info@kiddietrac.com');
                });
        } catch (\Throwable $e) {
            // The reply is already saved. Losing the notice must not lose the reply.
            \Illuminate\Support\Facades\Log::error('Ticket reply notice failed', [
                'ticket' => $ticket->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record one change against a ticket.
     *
     * Best-effort on purpose: losing a history line must never cost the change itself,
     * or a missing table would make the portal unable to resolve a ticket at all.
     */
    private function logEvent(int $ticketId, string $type, ?string $from, ?string $to, ?string $note, $userId): void
    {
        try {
            DB::table('support_ticket_events')->insert([
                'ticket_id' => $ticketId,
                'type' => $type,
                'from_value' => $from !== null ? mb_substr($from, 0, 60) : null,
                'to_value' => $to !== null ? mb_substr($to, 0, 60) : null,
                'note' => $note,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // History is worth having, not worth failing over.
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        // v22p96: must be staff of THIS ticket's agency (or platform_admin scoped
        // to it) — not just any staff anywhere.
        abort_unless($this->staffMayAccessTicket($request, $ticket), 403);
        $data = $request->validate([
            'status' => 'required|in:open,awaiting_user,resolved,closed',
            'assigned_user_id' => 'nullable|integer',
            'priority' => 'nullable|in:low,normal,high,urgent',
            // How it was fixed. Written down against the ticket, because otherwise the
            // answer exists only in the head of whoever closed it.
            'resolution' => 'nullable|string|max:4000',
        ]);

        $finishing = in_array($data['status'], ['resolved', 'closed'], true);
        $wasFinished = in_array((string) $ticket->status, ['resolved', 'closed'], true);

        $update = [
            'status' => $data['status'],
            'assigned_user_id' => array_key_exists('assigned_user_id', $data) ? $data['assigned_user_id'] : DB::raw('assigned_user_id'),
            'priority' => $data['priority'] ?? DB::raw('priority'),
            'resolved_at' => $finishing ? now() : null,
            'updated_at' => now(),
        ];
        if ($finishing) {
            $update['resolved_by_user_id'] = $request->user()->id;
        }
        // A blank note must not wipe a resolution that was already written.
        if (! empty($data['resolution'])) {
            $update['resolution'] = $data['resolution'];
        } elseif (! $finishing) {
            $update['resolution'] = null;   // reopened — the old answer no longer holds
            $update['resolved_by_user_id'] = null;
        }

        DB::table('support_tickets')->where('id', $id)->update($update);

        if ((string) $ticket->status !== $data['status']) {
            $this->logEvent($id, 'status', (string) $ticket->status, $data['status'],
                $data['resolution'] ?? null, $request->user()->id);
        }
        if (! empty($data['priority']) && $data['priority'] !== (string) $ticket->priority) {
            $this->logEvent($id, 'priority', (string) $ticket->priority, $data['priority'],
                null, $request->user()->id);
        }
        if (array_key_exists('assigned_user_id', $data)
            && (int) $data['assigned_user_id'] !== (int) ($ticket->assigned_user_id ?? 0)) {
            $to = $data['assigned_user_id']
                ? DB::table('users')->where('id', $data['assigned_user_id'])
                    ->selectRaw("CONCAT(first_name,' ',last_name) as n")->value('n')
                : null;
            $this->logEvent($id, 'assigned', null, $to, null, $request->user()->id);
        }
        // Tell the person who raised it — but only on a real transition INTO a
        // finished state, so re-saving a resolved ticket or changing its priority
        // does not mail them again.
        if ($finishing && ! $wasFinished) {
            $this->notifyReporterResolved($ticket, $data['status'], $request->user());
        }

        return response()->json(['status' => $data['status']]);
    }

    /**
     * Email whoever raised the ticket that it is done, BCC'ing the agency inbox so
     * there is a record of what was said.
     *
     * Auto-filed CRASH tickets are deliberately excluded. Nobody wrote in: the
     * reporter is whichever user's browser happened to throw, often a parent reading
     * their child's page. Telling them "your support ticket has been resolved" would
     * be inventing a conversation they never had.
     */
    private function notifyReporterResolved($ticket, string $status, $actor): void
    {
        try {
            if (str_starts_with((string) $ticket->body, 'Automatically filed from a crash report')) {
                return;   // machine-filed; there is no correspondent
            }
            $uid = (int) ($ticket->raised_by_user_id ?? 0);
            if (! $uid) return;
            $user = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first();
            if (! $user || empty($user->email)) return;

            $agency = $ticket->agency_id ? DB::table('agencies')->where('id', $ticket->agency_id)->first() : null;
            $agencyName = $agency->name ?? 'KiddieTrac';
            $tz = \App\Support\AgencyTime::tz($ticket->agency_id ? (int) $ticket->agency_id : null);
            $when = now()->setTimezone($tz);
            $first = trim((string) ($user->first_name ?? '')) ?: 'Hello';
            $word = $status === 'closed' ? 'closed' : 'resolved';

            $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($first) . ',</p>'
                . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                . 'The request you raised with <strong>' . e($agencyName) . '</strong> has been ' . e($word) . '.</p>'
                . '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;border-left:4px solid #1F6FB2;border-radius:10px;padding:14px 16px;margin:0 0 14px;">'
                .   '<div class="kt-muted" style="font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin-bottom:4px;">Ticket #' . (int) $ticket->id . '</div>'
                .   '<div style="font-size:15px;font-weight:800;color:#0F172A;">' . e((string) $ticket->subject) . '</div>'
                .   '<div class="kt-muted" style="font-size:12.5px;color:#64748B;margin-top:6px;">Raised ' . e(\App\Support\AgencyTime::fmt($ticket->created_at, $tz, 'D, M j, Y') ?? '') . '</div>'
                . '</div>'
                . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#475569;">'
                . 'Marked ' . e($word) . ' on ' . e($when->format('D, M j, Y')) . ' at ' . e($when->format('g:i A'))
                . ' (' . e($when->format('T')) . ').</p>'
                . '<p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">'
                . 'If this is not sorted, reply to this email and it will be reopened.</p>';

            $html = \App\Services\EmailTemplate::wrap(
                $ticket->agency_id ? (int) $ticket->agency_id : null,
                $body,
                ['eyebrow' => 'SUPPORT', 'title' => 'Your request has been ' . $word,
                 'preheader' => 'Ticket #' . (int) $ticket->id . ' — ' . (string) $ticket->subject]
            );

            \App\Services\AgencyMailer::forAgency($ticket->agency_id ? (int) $ticket->agency_id : null)
                ->mailer()->html($html, function ($m) use ($user, $ticket, $word) {
                    $m->to($user->email)
                      ->bcc('info@kiddietrac.com')
                      ->subject('Your request has been ' . $word . ' — ticket #' . (int) $ticket->id);
                });
        } catch (\Throwable $e) {
            // Never let the notification stop the ticket being resolved.
            \Illuminate\Support\Facades\Log::error('Ticket resolution notice failed', [
                'ticket' => $ticket->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }
}
