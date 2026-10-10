<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EDUCATORS REACH A CHILD'S PARENTS (2026-10-09, Anthony: "educators should be able to see
 * the emergency card for their children and to reach parents you should have a button to
 * allow for SMS and email to be sent").
 *
 * Sent THROUGH KiddieTrac, not from the educator's phone (his choice): the text goes from the
 * agency's SMS number and the email from the agency's mailer, so an educator's personal
 * number and address stay private, and every message is logged on the child and in the
 * audit log.
 *
 * Who: anyone who can open the child's record — the same room-scoped rule as the record
 * itself (ResolvesCentreContext::canAccessChildId), so an educator reaches the families in
 * their own rooms. Texts go through SmsController::sendOne, so suppression, the agency's SMS
 * switch, the parent's consent (per handset) and duplicate protection all apply unchanged; a
 * parent who has not agreed to texts can still be emailed.
 */
final class ParentContactController extends Controller
{
    use ResolvesCentreContext;

    private const SMS_MAX = 480;

    private function context(Request $request, int $childId): array
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        abort_unless($child, 404);
        abort_unless($this->canAccessChildId($request->user(), $childId), 403, 'You can only contact the families of children in your care.');
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $centre = $family ? DB::table('centres')->where('id', $family->centre_id)->first() : null;
        abort_unless($family && $centre, 404);

        return [$child, $family, $centre, (int) $centre->agency_id];
    }

    private function guardians(int $familyId)
    {
        return DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->whereNull('u.deleted_at')
            ->orderByDesc('g.is_primary')->orderBy('g.id')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'g.relationship', 'g.is_primary']);
    }

    private function smsState(int $agencyId, object $g): array
    {
        $phone = trim((string) $g->phone);
        if ($phone === '') return [false, 'No mobile number on file'];
        if (! DB::table('agencies')->where('id', $agencyId)->value('sms_enabled')) return [false, 'Texting is switched off for this agency'];
        if (\App\Support\Suppression::isUser((int) $g->id)) return [false, 'This person is not being contacted'];
        $c = \App\Support\Handset::smsConsent($agencyId, (int) $g->id, $phone);

        return $c['ok'] ? [true, null] : [false, 'Has not agreed to texts — email instead'];
    }

    /** GET /provider/children/{child}/parent-contacts */
    public function contacts(Request $request, int $childId): JsonResponse
    {
        [$child, $family, $centre, $agencyId] = $this->context($request, $childId);
        $out = [];
        foreach ($this->guardians((int) $family->id) as $g) {
            [$smsOk, $smsWhy] = $this->smsState($agencyId, $g);
            $email = trim((string) $g->email);
            $emailOk = filter_var($email, FILTER_VALIDATE_EMAIL) && ! \App\Support\ChatAudience::isServiceEmail($email);
            $out[] = ['user_id' => (int) $g->id, 'name' => trim($g->first_name . ' ' . $g->last_name), 'relationship' => $g->relationship,
                'is_primary' => (bool) $g->is_primary, 'phone' => $g->phone, 'email' => $emailOk ? $email : null,
                'sms_ok' => $smsOk, 'sms_note' => $smsWhy, 'email_ok' => (bool) $emailOk];
        }
        $recent = DB::table('staff_parent_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.child_id', $childId)->orderByDesc('m.id')->limit(8)
            ->get(['m.channel', 'm.status', 'm.body', 'm.created_at', 'm.guardian_user_id', 'u.first_name as sender']);

        return response()->json(['guardians' => $out, 'child' => ['id' => (int) $child->id, 'first_name' => $child->preferred_name ?: $child->first_name],
            'sms_max' => self::SMS_MAX, 'recent' => $recent]);
    }

    /** POST /provider/children/{child}/message-parents {guardian_ids[], channels[], body} */
    public function send(Request $request, int $childId): JsonResponse
    {
        [$child, $family, $centre, $agencyId] = $this->context($request, $childId);
        $d = $request->validate([
            'guardian_ids' => ['required', 'array', 'min:1', 'max:6'],
            'guardian_ids.*' => ['integer'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:sms,email'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);
        $body = trim($d['body']);
        if (in_array('sms', $d['channels'], true) && mb_strlen($body) > self::SMS_MAX) {
            return response()->json(['message' => 'A text can be at most ' . self::SMS_MAX . ' characters. Shorten it, or send by email only.'], 422);
        }
        $results = self::reach($agencyId, $family, $centre, $child, $request->user(), $d['guardian_ids'], $d['channels'], $body, true, (string) $request->ip());

        return response()->json(['sent' => count(array_filter($results, fn ($r) => $r['status'] === 'sent')), 'results' => $results]);
    }

    /**
     * Send one message to some of a family's parents by text and/or email — shared by the
     * child record (above) and Messenger ("also send by text / email", ChatController).
     * $child may be null (a Messenger thread not tied to one child). $bell: write the
     * parent's in-app notice (Messenger already does, through the chat message itself).
     */
    public static function reach(int $agencyId, object $family, object $centre, ?object $child, $sender, array $guardianIds, array $channels, string $body, bool $bell = true, string $ip = ''): array
    {
        $self = new self();
        $senderName = trim($sender->first_name . ' ' . $sender->last_name) ?: 'Your educator';
        $childName = $child ? ($child->preferred_name ?: $child->first_name) : null;
        $about = $childName ? ' about ' . $childName : '';
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'contact_email', 'brand_support_email']);
        $agencyName = (string) ($agency->name ?? 'Your child care agency');
        $wantSms = in_array('sms', $channels, true);
        $wantEmail = in_array('email', $channels, true);
        $guardians = $self->guardians((int) $family->id)->keyBy('id');
        $results = [];
        $sms = app(SmsController::class);
        foreach (array_unique(array_map('intval', $guardianIds)) as $gid) {
            $g = $guardians[$gid] ?? null;
            if (! $g) { $results[] = ['user_id' => $gid, 'channel' => '-', 'status' => 'refused', 'note' => 'Not a parent in this family']; continue; }
            $gname = trim($g->first_name . ' ' . $g->last_name);

            if ($wantSms) {
                [$ok, $why] = $self->smsState($agencyId, $g);
                $status = 'skipped'; $note = $why;
                if ($ok && mb_strlen($body) > self::SMS_MAX) { $ok = false; $note = 'Too long for a text (' . self::SMS_MAX . ' characters)'; }
                if ($ok) {
                    $text = $agencyName . ': ' . $senderName . $about . ': ' . $body;
                    $before = (int) DB::table('sms_messages')->max('id');
                    $sent = $sms->sendOne($agencyId, (int) $g->id, (string) $g->phone, $text, 'staff_message');
                    $row = DB::table('sms_messages')->where('id', '>', $before)->where('to_user_id', $g->id)->orderByDesc('id')->first(['status', 'error']);
                    $status = $sent ? 'sent' : ($row->status ?? 'failed');
                    $note = $sent ? null : ($row->error ?? 'Not sent');
                }
                $self->log($agencyId, $child, $family, (int) $sender->id, (int) $g->id, 'sms', (string) $g->phone, $body, $status, $note);
                $results[] = ['user_id' => (int) $g->id, 'name' => $gname, 'channel' => 'sms', 'status' => $status, 'note' => $note];
            }

            if ($wantEmail) {
                $email = trim((string) $g->email);
                $status = 'skipped'; $note = 'No email address on file';
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! \App\Support\ChatAudience::isServiceEmail($email)) {
                    try {
                        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                        $html = EmailTemplate::wrap($agencyId,
                            '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Hi ' . $e($g->first_name ?: 'there') . ',</p>'
                            . '<div style="font-size:15px;color:#0F172A;line-height:1.65">' . nl2br($e($body)) . '</div>'
                            . '<p style="margin:16px 0 0;font-size:14px;color:#334155;line-height:1.6">— ' . $e($senderName) . ', ' . $e($centre->name) . '</p>'
                            . '<p style="margin:16px 0 0;font-size:12.5px;color:#64748B;line-height:1.55">To reply, use Messenger in the KiddieTrac app, or reply to this email and it will reach '
                            . $e($agencyName) . '.</p>',
                            ['eyebrow' => $childName ? 'A MESSAGE ABOUT ' . mb_strtoupper($childName) : 'A MESSAGE FROM YOUR PROVIDER', 'title' => 'A message from ' . $senderName,
                             'subtitle' => $centre->name, 'preheader' => mb_substr($body, 0, 120)]);
                        $replyTo = collect([$agency->contact_email ?? null, $agency->brand_support_email ?? null])->first(fn ($a) => filter_var((string) $a, FILTER_VALIDATE_EMAIL));
                        $subject = $senderName . ' — ' . ($childName ? 'a message about ' . $childName : 'a message from ' . $centre->name);
                        $beforeE = (int) DB::table('email_logs')->max('id');
                        AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($email, $gname, $subject, $replyTo) {
                            $m->to($email, $gname ?: null)->subject($subject);
                            if ($replyTo) $m->replyTo($replyTo);
                        });
                        $row = DB::table('email_logs')->where('id', '>', $beforeE)->where('to_email', 'like', '%' . strtolower($email) . '%')->orderByDesc('id')->first(['status', 'error']);
                        $status = $row ? (string) $row->status : 'sent';
                        $note = $status === 'sent' ? null : ($row->error ?? null);
                    } catch (\Throwable $ex) {
                        Log::warning('educator parent email failed', ['family' => $family->id, 'error' => $ex->getMessage()]);
                        $status = 'failed'; $note = 'The email could not be sent';
                    }
                }
                $self->log($agencyId, $child, $family, (int) $sender->id, (int) $g->id, 'email', $email, $body, $status, $note);
                $results[] = ['user_id' => (int) $g->id, 'name' => $gname, 'channel' => 'email', 'status' => $status, 'note' => $note];
            }

            if ($bell) {
                try {
                    \App\Support\Notify::write(['user_id' => (int) $g->id, 'type' => 'staff_message', 'title' => '💬 ' . $senderName . $about,
                        'body' => mb_substr($body, 0, 300), 'data' => json_encode(['link' => '#messages']), 'created_at' => now()]);
                } catch (\Throwable $ex) {}
            }
        }

        $sentN = count(array_filter($results, fn ($r) => $r['status'] === 'sent'));
        try {
            \App\Support\Audit::write(['user_id' => (int) $sender->id, 'agency_id' => $agencyId, 'action' => 'child.parent_message_sent',
                'entity_type' => $child ? 'child' : 'family', 'entity_id' => (int) ($child->id ?? $family->id),
                'payload' => json_encode(['summary' => $senderName . ' messaged ' . ($family->family_name ?? 'a family') . $about . ' (' . implode('+', $channels) . '): ' . $sentN . ' sent of ' . count($results),
                    'results' => $results, 'body' => mb_substr($body, 0, 500)]), 'ip_address' => substr($ip, 0, 45), 'created_at' => now()]);
        } catch (\Throwable $ex) {}

        return $results;
    }

    private function log(int $agencyId, ?object $child, object $family, int $senderId, int $guardianId, string $channel, string $to, string $body, string $status, ?string $note): void
    {
        try {
            DB::table('staff_parent_messages')->insert(['agency_id' => $agencyId, 'child_id' => $child ? (int) $child->id : null, 'family_id' => (int) $family->id,
                'sender_id' => $senderId, 'guardian_user_id' => $guardianId, 'channel' => $channel, 'to_address' => mb_substr($to, 0, 190),
                'body' => $body, 'status' => $status, 'error' => $note ? mb_substr($note, 0, 500) : null, 'created_at' => now()]);
        } catch (\Throwable $e) { Log::warning('staff_parent_messages insert failed', ['error' => $e->getMessage()]); }
    }
}
