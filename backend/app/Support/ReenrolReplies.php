<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RE-ENROLMENT REPLIES (2026-11-28).
 *
 * A campaign (EngagementController::createCampaign) asks every enrolled child's family whether the child is coming back
 * next term. Families answer per child - Yes, coming back / No, not returning - with an optional note:
 *   - in the portal (#reenrollment, signed in as a guardian), or
 *   - from the email's button (reenrol-reply.html, a signed link: one campaign + one family + one guardian, only the
 *     SHA-256 kept, good until the end of the deadline day in the agency's timezone). Opening the link changes nothing.
 * Answers can be changed until the deadline. The campaign's Responded / Renewed counts are recounted on every answer.
 *
 * Families who have not answered are reminded ONCE, by the hourly engagement:reenrol-reminders, on the agency's
 * re-enrolment reminder day (Billing setup reenrol_reminder_days before the deadline, default 7; 0 = never), from 9 am
 * agency time. The reminder covers only the children still waiting for an answer.
 *
 * Emails are house-frame family mail (BillingNoticeMail::reenrolment) through the agency's mailer with X-KT-Agency-Id,
 * so every gate applies: test agencies send nothing, do-not-contact, and guardians who have not accepted their invite
 * are not emailed (they still get the bell when they sign in).
 */
final class ReenrolReplies
{
    public const PAGE = 'https://app.kiddietrac.com/reenrol-reply.html?t=';
    public const ANSWERS = ['yes', 'no', 'unsure'];
    public const SEND_FROM_HOUR = 9;

    public static function campaign(int $id): ?object
    {
        return DB::table('reenrollment_campaigns')->where('id', $id)->first();
    }

    public static function isOpen(object $c): bool
    {
        return $c->status === 'sent' && AgencyTime::today((int) $c->agency_id) <= substr((string) $c->deadline, 0, 10);
    }

    /** The family's invited children for a campaign, with their answers. */
    public static function children(int $campaignId, int $familyId): array
    {
        return DB::table('reenrollment_responses as r')->join('children as ch', 'ch.id', '=', 'r.child_id')
            ->where('r.campaign_id', $campaignId)->where('r.family_id', $familyId)
            ->orderBy('ch.first_name')
            ->get(['r.child_id', 'ch.first_name', 'ch.last_name', 'r.response', 'r.notes', 'r.responded_at', 'r.responded_via'])
            ->map(fn ($r) => [
                'child_id' => (int) $r->child_id, 'first_name' => (string) $r->first_name, 'name' => trim($r->first_name . ' ' . $r->last_name),
                'response' => (string) $r->response, 'notes' => $r->notes, 'responded_at' => $r->responded_at, 'via' => $r->responded_via,
            ])->all();
    }

    /** Live counts by campaign, optionally only for families at these centres (a director's own). */
    public static function counts(array $campaignIds, ?array $centreIds = null): array
    {
        $campaignIds = array_values(array_filter(array_map('intval', $campaignIds)));
        if (! $campaignIds) return [];
        $q = DB::table('reenrollment_responses as r')->whereIn('r.campaign_id', $campaignIds);
        if ($centreIds !== null) {
            $q->join('families as f', 'f.id', '=', 'r.family_id')->whereIn('f.centre_id', $centreIds ?: [0]);
        }
        $out = [];
        foreach ($q->groupBy('r.campaign_id')->selectRaw("r.campaign_id, COUNT(*) invited, SUM(r.response <> 'pending') responded, SUM(r.response = 'yes') renewed, SUM(r.response = 'no') declined, SUM(r.response = 'unsure') unsure")->get() as $r) {
            $out[(int) $r->campaign_id] = ['invited' => (int) $r->invited, 'responded' => (int) $r->responded, 'renewed' => (int) $r->renewed, 'declined' => (int) $r->declined, 'unsure' => (int) $r->unsure];
        }

        return $out;
    }

    public static function recount(int $campaignId): void
    {
        $c = self::counts([$campaignId])[$campaignId] ?? ['invited' => 0, 'responded' => 0, 'renewed' => 0];
        DB::table('reenrollment_campaigns')->where('id', $campaignId)->update([
            'total_invited' => $c['invited'], 'total_responded' => $c['responded'], 'total_renewed' => $c['renewed'], 'updated_at' => now(),
        ]);
    }

    /**
     * Record a family's answers. $answers: [[child_id, response, note?], ...]. Only children of THIS family invited to
     * THIS campaign; nothing after the deadline. @return array{0:bool,1:string}
     */
    public static function answer(object $c, int $familyId, array $answers, ?int $userId, string $via): array
    {
        if (! self::isOpen($c)) {
            return [false, 'Answers for this re-enrolment closed on ' . BillingRules::day((string) $c->deadline) . '. Please contact the centre.'];
        }
        $mine = collect(self::children((int) $c->id, $familyId))->keyBy('child_id');
        $rows = [];
        foreach ($answers as $a) {
            $cid = (int) ($a['child_id'] ?? 0);
            $resp = (string) ($a['response'] ?? '');
            if (! $mine->has($cid)) return [false, 'That child is not part of this re-enrolment.'];
            if (! in_array($resp, self::ANSWERS, true)) return [false, 'Choose Yes or No for each child.'];
            $note = trim((string) ($a['note'] ?? ''));
            $rows[$cid] = [$resp, $note === '' ? null : mb_substr($note, 0, 500)];
        }
        if (! $rows) return [false, 'Choose Yes or No for at least one child.'];
        DB::transaction(function () use ($c, $familyId, $rows, $userId, $via) {
            foreach ($rows as $cid => [$resp, $note]) {
                DB::table('reenrollment_responses')->where('campaign_id', $c->id)->where('family_id', $familyId)->where('child_id', $cid)->update([
                    'response' => $resp, 'notes' => $note, 'responded_at' => now(), 'responded_by_user_id' => $userId, 'responded_via' => $via,
                ]);
            }
            self::recount((int) $c->id);
        });
        $names = $mine->only(array_keys($rows))->map(fn ($k) => $k['first_name'] . ': ' . $rows[$k['child_id']][0])->implode(', ');
        Audit::write(['user_id' => $userId, 'agency_id' => (int) $c->agency_id, 'action' => 'reenrolment.answered', 'entity_type' => 'reenrollment_campaign', 'entity_id' => (int) $c->id,
            'payload' => json_encode(['summary' => 'Re-enrolment for ' . $c->target_term . ' answered (' . $via . '): ' . $names, 'family_id' => $familyId], JSON_UNESCAPED_UNICODE),
            'created_at' => now()]);

        return [true, count($rows) === 1 ? 'Thank you - your answer is saved.' : 'Thank you - your answers are saved.'];
    }

    // ---- the signed link ----------------------------------------------------------------------------------------

    public static function issue(object $c, int $familyId, int $guardianId): ?string
    {
        try {
            $tz = AgencyTime::tz((int) $c->agency_id) ?: 'America/Toronto';
            $token = Str::random(40);
            DB::table('reenrolment_reply_links')->insert([
                'token_hash' => hash('sha256', $token), 'agency_id' => (int) $c->agency_id, 'campaign_id' => (int) $c->id,
                'family_id' => $familyId, 'guardian_user_id' => $guardianId,
                'expires_at' => Carbon::parse(substr((string) $c->deadline, 0, 10), $tz)->endOfDay()->utc()->format('Y-m-d H:i:s'),
                'created_at' => now(),
            ]);

            return self::PAGE . $token;
        } catch (\Throwable $e) {
            Log::warning('Re-enrolment reply link not issued', ['campaign' => $c->id ?? null, 'family' => $familyId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array{0:?object,1:?object,2:string} [link, campaign, ok|expired|closed|unknown] */
    public static function find(string $token): array
    {
        $link = DB::table('reenrolment_reply_links')->where('token_hash', hash('sha256', $token))->first();
        if (! $link) return [null, null, 'unknown'];
        $c = self::campaign((int) $link->campaign_id);
        if (! $c || (int) $c->agency_id !== (int) $link->agency_id) return [$link, null, 'unknown'];
        $live = DB::table('families')->where('id', $link->family_id)->whereNull('deleted_at')->exists()
            && DB::table('guardians')->where('family_id', $link->family_id)->where('user_id', $link->guardian_user_id)->exists();
        if (! $live) return [$link, $c, 'unknown'];
        if ((string) $link->expires_at < now()->utc()->format('Y-m-d H:i:s') || ! self::isOpen($c)) return [$link, $c, $c->status === 'sent' ? 'expired' : 'closed'];

        return [$link, $c, 'ok'];
    }

    public static function stateMessage(string $state): string
    {
        return match ($state) {
            'expired' => 'The deadline for this re-enrolment has passed. Please contact the centre if your plans have changed.',
            'closed' => 'This re-enrolment is closed. Please contact the centre.',
            default => 'This link is not valid any more. Please sign in to the app to answer.',
        };
    }

    // ---- telling families ---------------------------------------------------------------------------------------

    /**
     * Bell + email to each guardian of these families. $kind: invite | reminder (reminder: only children still waiting).
     * Returns the number of families told.
     */
    public static function notifyFamilies(object $c, array $familyIds, string $kind): int
    {
        $agencyId = (int) $c->agency_id;
        $when = BillingRules::day((string) $c->deadline);
        $told = 0;
        foreach (array_unique(array_map('intval', $familyIds)) as $fid) {
            $kids = array_values(array_filter(self::children((int) $c->id, $fid), fn ($k) => $kind === 'invite' || $k['response'] === 'pending'));
            if (! $kids) continue;
            $names = array_column($kids, 'first_name');
            $guardians = BillingRules::guardians($fid);
            if (! $guardians) continue;
            foreach ($guardians as $uid => $g) {
                Notify::write([
                    'user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'reenrollment',
                    'title' => ($kind === 'reminder' ? 'Reminder: re-enrol for ' : 'Re-enrol for ') . $c->target_term,
                    'body' => (count($names) > 1 ? 'Are ' : 'Is ') . self::list($names) . ' coming back? Please answer by ' . Carbon::parse(substr((string) $c->deadline, 0, 10))->format('M j') . '.',
                    'data' => json_encode(['link' => '#reenrollment', 'campaign_id' => (int) $c->id]),
                    'created_at' => now(),
                ]);
                if (! $g['email']) continue;
                $url = self::issue($c, $fid, (int) $uid);
                if (! $url) continue;
                $m = BillingNoticeMail::reenrolment($agencyId, (string) $g['first'], $kind, (string) $c->target_term, $when, $names, $url);
                BillingRules::mailFamily($agencyId, $g['email'], $m['subject'], $m['html'], false);
            }
            if ($kind === 'reminder') {
                DB::table('reenrollment_responses')->where('campaign_id', $c->id)->where('family_id', $fid)->where('response', 'pending')->update(['reminded_at' => now()]);
            }
            $told++;
        }

        return $told;
    }

    /** "Ava", "Ava and Liam", "Ava, Liam and Noah" */
    public static function list(array $names): string
    {
        $names = array_values($names);
        if (count($names) <= 1) return (string) ($names[0] ?? 'your child');
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * The reminder pass for one agency (engagement:reenrol-reminders). Once per campaign, on or after the reminder day
     * (deadline - reenrol_reminder_days), from 9 am agency time, until the deadline. @return array<int,array>
     */
    public static function runReminders(int $agencyId, bool $dry = false): array
    {
        $days = BillingRules::reenrolReminderDays($agencyId);
        if ($days <= 0) return [];
        $now = AgencyTime::now($agencyId);
        if ((int) $now->format('G') < self::SEND_FROM_HOUR) return [];
        $today = $now->toDateString();
        $out = [];
        foreach (DB::table('reenrollment_campaigns')->where('agency_id', $agencyId)->where('status', 'sent')->whereNull('reminder_sent_at')
            ->whereDate('deadline', '>=', $today)->get() as $c) {
            $remindOn = Carbon::parse(substr((string) $c->deadline, 0, 10))->subDays($days)->toDateString();
            if ($today < $remindOn) continue;
            $q = DB::table('reenrollment_responses as r')->join('families as f', 'f.id', '=', 'r.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('r.campaign_id', $c->id)->where('r.response', 'pending')->where('ce.agency_id', $agencyId);
            Live::families($q, 'f');
            Live::centres($q, 'ce');
            $fams = $q->distinct()->pluck('r.family_id')->map(fn ($v) => (int) $v)->all();
            if ($dry) { $out[] = ['campaign' => (int) $c->id, 'families' => count($fams)]; continue; }
            // Claim first: a second run (or a slow one) never reminds twice.
            if (! DB::table('reenrollment_campaigns')->where('id', $c->id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now(), 'updated_at' => now()])) continue;
            $n = self::notifyFamilies($c, $fams, 'reminder');
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'reenrolment.reminded', 'entity_type' => 'reenrollment_campaign', 'entity_id' => (int) $c->id,
                'payload' => json_encode(['summary' => 'Re-enrolment reminder for ' . $c->target_term . ' sent to ' . $n . ' families who had not answered'], JSON_UNESCAPED_UNICODE),
                'created_at' => now()]);
            $out[] = ['campaign' => (int) $c->id, 'families' => $n];
        }

        return $out;
    }
}
