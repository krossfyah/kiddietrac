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
            $q->where('t.agency_id', $agencyId);
        } else {
            $q->where('t.raised_by_user_id', $u->id);
        }
        return response()->json(['data' => $q->limit(200)->get()]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => 'required|string|in:billing,enrollment,maintenance,technical,policy,other',
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
            if ($data['category'] === 'technical') {
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
                . '<p style="color:#94a3b8;font-size:12px;margin:16px 0 0;">Ticket #' . $id . ' &middot; open it under <strong>Support</strong> in the portal to reply.</p>';
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
        return response()->json(['ticket' => $ticket, 'messages' => $messages]);
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
        DB::table('support_tickets')->where('id', $id)->update([
            'status' => $isStaff ? 'awaiting_user' : 'open',
            'updated_at' => now(),
        ]);
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
        return response()->json(['status' => 'replied']);
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
        ]);
        DB::table('support_tickets')->where('id', $id)->update([
            'status' => $data['status'],
            'assigned_user_id' => array_key_exists('assigned_user_id', $data) ? $data['assigned_user_id'] : DB::raw('assigned_user_id'),
            'priority' => $data['priority'] ?? DB::raw('priority'),
            'resolved_at' => in_array($data['status'], ['resolved', 'closed']) ? now() : null,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => $data['status']]);
    }
}
