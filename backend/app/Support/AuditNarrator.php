<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns audit rows into sentences a person can read (2026-08-24).
 *
 * The portal-wide AuditActivity middleware records raw HTTP: action is
 * "post:api/v1/provider/chat-archive" and the only other identifier is entity_id. The
 * audit screen had nothing else to show, which is why it read as "id: 17". Earlier work
 * on this only improved the handful of hand-instrumented actions (chat.broadcast,
 * staff.contractor_flag and friends) -- the generic middleware rows are the large
 * majority and were never touched.
 *
 * Narration happens at READ time, deliberately. Doing it at write time would only fix
 * rows created from now on; doing it here makes the ~10,600 rows already in the table
 * readable too, and lets the wording be corrected later without a migration.
 *
 * Entity names are resolved in ONE batched query per type for the whole page, never per
 * row -- a hundred rows must not become four hundred queries.
 */
final class AuditNarrator
{
    /**
     * Exact action -> verb phrase. Anything not listed falls back to the path
     * humaniser below, so an unmapped route still reads as English.
     */
    private const VERBS = [
        'post:api/v1/provider/chat-archive'        => 'Archived a conversation',
        'post:api/v1/provider/team-threads/start'  => 'Started a conversation',
        'post:api/v1/auth/agreement/sign'          => 'Signed the user agreement',
        'email.sent'                               => 'Sent an email',
        'email.suppressed'                         => 'Suppressed an email',
        'email.failed'                             => 'An email failed to send',
        /* Zum Rails. Without these the narrator falls back to "Changed zum.settled",
           which is the one line in an audit log a bookkeeper actually needs to read. */
        'agency.updated'                           => 'Updated agency details',
        'schedule.autofill_enabled'                => 'Turned on nightly schedule filling',
        'schedule.autofill_disabled'               => 'Turned off nightly schedule filling',
        'zum.settled'                              => 'A payment settled',
        // Written by the webhook once refunds were audited (2026-09-02). The wording
        // makes the direction plain: a settled refund means money has left us.
        'zum.refund_settled'                       => 'A refund was paid back',
        'zum.refund_failed'                        => 'A refund failed',
        'zum.refund_cancelled'                     => 'A refund was cancelled',
        'zum.refund_submitted'                     => 'A refund was sent to the bank',
        'zum.refund_in_review'                     => 'A refund is being reviewed',
        'zum.failed'                               => 'A payment failed',
        'zum.cancelled'                            => 'A payment was cancelled',
        'zum.in_review'                            => 'A payment is being reviewed',
        'holidays.synced'                          => 'Added statutory holidays to the calendar',
        'shift.autofilled'                         => 'Filled the staff schedule',
        'push.sent'                                => 'Sent a push notification',
        'push.failed'                              => 'A push notification failed',
        'push.no_device'                           => 'No device to notify',
        'push.suppressed'                          => 'A push notification was suppressed',
        'zum.failed'                               => 'A payment failed',
        'zum.cancelled'                            => 'A payment was cancelled',
        'zum.cancelling'                           => 'A payment is being cancelled',
        'zum.submitted'                            => 'A payment reached the bank',
        'zum.in_review'                            => 'A payment was held for review',
        'zum.requested'                            => 'Requested a payment from a family',
        'zum.sent'                                 => 'Sent money out',
        'zum.bank_account_saved'                   => 'Saved a bank account for payments',
        /* Attendance corrections. Named plainly because these are the rows somebody
           reads back months later when a ratio or an invoice is questioned. */
        'attendance.backdated'                     => 'Corrected attendance for a child',
        'attendance.entry_removed'                 => 'Removed an attendance entry',
        'post:api/v1/parent/zum/pay'               => 'A parent paid an invoice (Zum)',
        'post:api/v1/parent/zum/bank-account'      => 'A parent saved a bank account (Zum)',
        'post:api/v1/director/zum/send'            => 'Sent money out (Zum)',
        'post:api/v1/director/zum/request'         => 'Requested money from a family (Zum)',
        'post:api/v1/admin/payment-providers/zumrails' => 'Changed Zum Rails settings',
        'post:api/v1/admin/payment-providers/stripe'   => 'Changed Stripe settings',
        'post:api/v1/parent/billing/setup-intent'  => 'Started adding a card (Stripe)',
        'post:api/v1/parent/billing/save-card'     => 'Saved a card (Stripe)',
        'post:api/v1/parent/billing/autopay'       => 'Changed autopay',
        'post:api/v1/parent/wallet/setup-intent'   => 'Started adding a payment method',
        'chat.broadcast'                           => 'Sent a broadcast',
        'staff.contractor_flag'                    => 'Changed a contractor flag',
        'login'                                    => 'Signed in',
        'login_failed'                             => 'Failed sign-in attempt',
        'post:api/v1/auth/logout'                  => 'Signed out',
        'password_set_via_invite'                  => 'Set a password from an invite',
        'user.welcome_resent'                      => 'Resent a welcome email',
        'digest.daily_sent'                        => 'Sent the daily digest',
        'post:api/v1/notifications/mark-read'      => 'Marked notifications as read',
        'post:api/v1/push/subscribe'               => 'Enabled push notifications',
        'post:api/v1/push/device'                  => 'Registered a device for push',
        'post:api/v1/care/logs'                    => 'Logged a care moment',
        'post:api/v1/provider/check-in'            => 'Checked a child in',
        'post:api/v1/provider/check-out'           => 'Checked a child out',
        'child.check_in_by_staff'                  => 'Checked a child in',
        'child.check_out_by_staff'                 => 'Checked a child out',
        'staff.clock_in'                           => 'Clocked in',
        'staff.clock_out'                          => 'Clocked out',
        /* Written by the nightly job with user_id = NULL, so the actor already
           reads as "System". The verb says what happened and why. */
        'staff.auto_clock_out'                     => 'Clocked out automatically (no clock-out recorded)',
        'child.auto_check_out'                     => 'Checked out automatically (nobody signed them out)',
        'post:api/v1/parent/messages'              => 'Sent a message',
        'logout'                                   => 'Signed out',
    ];

    /** entity_type -> [table, columns to build a name from]. */
    private const ENTITIES = [
        'user'         => ['users', ['first_name', 'last_name']],
        'child'        => ['children', ['first_name', 'last_name']],
        'family'       => ['families', ['family_name']],
        'centre'       => ['centres', ['name']],
        'conversation' => ['conversations', []],
    ];

    /**
     * Narrate a page of rows in place. Each row gains:
     *   description  the sentence
     *   actor_name   who did it
     *   action_raw   the original action, kept for filtering and support
     *
     * @param  Collection<int,object>  $rows
     */
    public static function narrate(Collection $rows): Collection
    {
        $names = self::resolveNames($rows);
        $payloadNames = self::resolvePayloadNames($rows);

        return $rows->map(function ($row) use ($names, $payloadNames) {
            try {
                $action = (string) ($row->action ?? '');
                /* A refused attempt is recorded as the action with " [fail]" appended,
                   which the exact-match lookup below could never hit — so every failed
                   action in the whole log fell through to the path humaniser and read
                   as "Created zum pay [fail]" instead of the sentence its successful
                   twin gets. Stripped for the lookup, said plainly afterwards. */
                $failed = (bool) preg_match('/ \[fail\]$/i', $action);
                $lookup = (string) preg_replace('/ \[fail\]$/i', '', $action);

                /* Rows written before 2026-09-02 carry a "generated::xxxx" placeholder
                   instead of an action, because a cached route has a name even when
                   nobody gave it one. The method and path were recorded correctly in the
                   payload all along, so rebuild the lookup from those rather than
                   rewriting history -- an audit row should be read differently, never
                   edited quietly. */
                if (str_starts_with($lookup, 'generated::')) {
                    $rebuilt = self::actionFromPayload($row);
                    if ($rebuilt !== '') {
                        $lookup = $rebuilt;
                    }
                }

                $verb = self::VERBS[$lookup] ?? self::patternVerb($lookup) ?? self::humanisePath($lookup);
                if ($failed) {
                    $verb .= ' — failed';
                }

                /* Some endpoints resolve the actor in SQL and hand us a ready
                   actor_name without the split name columns. Take theirs when it
                   exists -- rebuilding it from fields that were never selected is
                   how every row briefly became "Someone". */
                $actor = trim((string) ($row->actor_name ?? ''));
                /* A scheduled job is not an unknown person. Keep the distinction:
                   an auditor needs to tell a cron run apart from an unidentified
                   human action. */
                if ($actor === 'system') {
                    $actor = 'System';
                }
                if ($actor === '') {
                    $actor = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
                }
                if ($actor === '') {
                    $actor = (string) ($row->actor_email ?? $row->email ?? '');
                }
                if ($actor === '') {
                    /* No user on the row does not mean an unknown person. Most of these
                       are the platform acting on its own — a nightly job, a mailer, a
                       payment provider calling back — and "Someone sent an email" reads
                       as though a person did it. Worse for the auto sign-outs: the
                       compliance report exists to separate "the system closed this" from
                       "a person closed this", and this flattened the two.

                       Matched by action family rather than blanket, because login_failed
                       with no user genuinely IS an unknown person typing an address we do
                       not recognise, and calling that "System" would be a lie exactly
                       where it matters most. */
                    $actor = self::systemActorFor($lookup) ?: 'Someone';
                }

                $subject = self::subjectFor($row, $names, $payloadNames);

                $row->action_raw = $action;
                $row->actor_name = $actor;
                $row->description = $actor . ' — ' . lcfirst($verb) . ($subject ? ' (' . $subject . ')' : '');
                // The screen renders `action`; overwriting it means the sentence shows
                // without the front end having to change. action_raw keeps the original.
                $row->action = $verb . ($subject ? ': ' . $subject : '');

                /* Blank the entity fields when they name nothing. AuditActivity derives
                   entity_type from a path segment, so hundreds of rows carry the literal
                   string "id" -- which is exactly what the screen was printing as
                   "id: 123". If we could not resolve a name, there is nothing here worth
                   showing. */
                if ($subject === '' || ! isset(self::ENTITIES[(string) ($row->entity_type ?? '')])) {
                    $row->entity_type = null;
                    $row->entity_id = null;
                }
            } catch (Throwable $e) {
                // A row that will not narrate must still be listed.
            }

            return $row;
        });
    }

    /**
     * Names for ids that live in the PAYLOAD rather than the entity columns.
     *
     * Most rows are written by the generic activity middleware, which records the
     * request body but cannot know which field identifies the subject. So
     * "checked a child out" carried child_id=91 and displayed nothing.
     *
     * One query per type for the entire page.
     *
     * @return array{child: array<int,string>, family: array<int,string>, thread: array<int,string>}
     */
    private static function resolvePayloadNames(Collection $rows): array
    {
        $childIds = [];
        $familyIds = [];
        $threadIds = [];

        foreach ($rows as $row) {
            try {
                $p = json_decode((string) ($row->payload ?? ''), true) ?: [];
                $in = $p['input'] ?? [];
                if (! empty($in['child_id'])) {
                    $childIds[(int) $in['child_id']] = true;
                }
                if (! empty($in['family_id'])) {
                    $familyIds[(int) $in['family_id']] = true;
                }
                /* chat-archive records what was archived as kind + id. */
                if (($in['kind'] ?? null) === 'family' && ! empty($in['id'])) {
                    $familyIds[(int) $in['id']] = true;
                }
                if (($in['kind'] ?? null) === 'staff' && ! empty($in['id'])) {
                    $threadIds[(int) $in['id']] = true;
                }
                if ((string) ($row->entity_type ?? '') === 'thread' && ! empty($row->entity_id)) {
                    $threadIds[(int) $row->entity_id] = true;
                }
            } catch (Throwable $e) {
            }
        }

        $out = ['child' => [], 'family' => [], 'thread' => [], 'thread_ids' => []];

        try {
            if ($childIds) {
                foreach (DB::table('children')->whereIn('id', array_keys($childIds))
                    ->get(['id', 'first_name', 'last_name', 'preferred_name']) as $c) {
                    $name = trim(($c->preferred_name ?: $c->first_name) . ' ' . ($c->last_name ?? ''));
                    if ($name !== '') {
                        $out['child'][(int) $c->id] = $name;
                    }
                }
            }
            if ($familyIds) {
                foreach (DB::table('families')->whereIn('id', array_keys($familyIds))
                    ->get(['id', 'family_name']) as $f) {
                    if ($f->family_name) {
                        $out['family'][(int) $f->id] = (string) $f->family_name;
                    }
                }
            }
            /* A staff thread has no name of its own — it IS the people in it. Naming
               every participant lets a reader see who was actually messaged. */
            if ($threadIds) {
                $parts = DB::table('staff_thread_participants as p')
                    ->join('users as u', 'u.id', '=', 'p.user_id')
                    ->whereIn('p.thread_id', array_keys($threadIds))
                    ->get(['p.thread_id', 'p.user_id', 'u.first_name', 'u.last_name']);
                $byThread = [];
                foreach ($parts as $pt) {
                    $out['thread_ids'][(int) $pt->thread_id][(int) $pt->user_id] =
                        trim(($pt->first_name ?? '') . ' ' . ($pt->last_name ?? ''));
                    $n = trim(($pt->first_name ?? '') . ' ' . ($pt->last_name ?? ''));
                    if ($n !== '') {
                        $byThread[(int) $pt->thread_id][] = $n;
                    }
                }
                /* Keyed by user id, so the actor can be dropped when the row is
                   narrated -- a message you sent should name who received it, not
                   read your own name back to you. */
                foreach ($byThread as $tid => $names) {
                    $out['thread'][$tid] = implode(' and ', array_unique($names));
                }
            }
        } catch (Throwable $e) {
            // A failed lookup must not take the audit page down.
        }

        return $out;
    }

    /**
     * Verbs for routes that carry an id in the middle, which exact matching cannot reach.
     *
     * "post:api/v1/provider/team-threads/5/send" was falling through to the path
     * humaniser and reading as "Created team threads send".
     */
    private static function patternVerb(string $action): ?string
    {
        $patterns = [
            '#team-threads/\d+/send$#' => 'Sent a staff message',
            '#chats/\d+/send$#' => 'Sent a message',
            '#conversations/\d+/send$#' => 'Sent a message',
            '#children/\d+/check-?in$#' => 'Checked a child in',
            '#children/\d+/check-?out$#' => 'Checked a child out',
            '#managed-forms/\d+/draft$#' => 'Saved a form draft',
            '#managed-forms/\d+/submit$#' => 'Submitted a form',
            '#invoices/\d+/#' => 'Updated an invoice',
            '#users/\d+/resend-welcome$#' => 'Resent a welcome email',
            /* Now visible for the first time: these rows used to carry a generated::
               placeholder, so the path humaniser never saw them and they read as
               "Created walks start". */
            '#walks/start$#' => 'Started a walk',
            '#walks/\d+/end$#' => 'Ended a walk',
            '#walks/\d+/checkpoint$#' => 'Logged a walk checkpoint',
            '#provider/check-?in$#' => 'Checked a child in',
            '#provider/check-?out$#' => 'Checked a child out',
            '#care/logs$#' => 'Logged a care moment',
        ];
        foreach ($patterns as $re => $verb) {
            if (preg_match($re, $action)) {
                return $verb;
            }
        }

        return null;
    }

    /**
     * Everyone in a thread except the person the row is about.
     *
     * Listing every participant means a row about you messaging a colleague reads your
     * own name back at you. What an auditor wants is the other end of the conversation.
     */
    private static function otherParticipants($row, int $threadId, array $payloadNames): string
    {
        $all = $payloadNames['thread_ids'][$threadId] ?? [];
        if (! $all) {
            return '';
        }
        $actorId = (int) ($row->user_id ?? 0);
        $names = [];
        foreach ($all as $uid => $name) {
            if ($uid !== $actorId && trim((string) $name) !== '') {
                $names[] = trim((string) $name);
            }
        }

        return $names ? implode(' and ', array_unique($names)) : '';
    }

    /** Name of the thing the row is about, if we can resolve one. */
    private static function subjectFor($row, array $names, array $payloadNames = []): string
    {
        $type = (string) ($row->entity_type ?? '');
        $id = (int) ($row->entity_id ?? 0);
        if ($type !== '' && $id > 0 && isset($names[$type][$id])) {
            return (string) $names[$type][$id];
        }

        /* A staff thread names the people in it — "sent a staff message" is not much
           use without knowing who received it. */
        if ($type === 'thread' && $id > 0) {
            $others = self::otherParticipants($row, $id, $payloadNames);
            if ($others !== '') {
                return 'to ' . $others;
            }
            if (isset($payloadNames['thread'][$id])) {
                return 'with ' . $payloadNames['thread'][$id];
            }
        }

        // Fall back to something meaningful out of the payload rather than an id.
        try {
            $payload = json_decode((string) ($row->payload ?? ''), true) ?: [];
            $in = $payload['input'] ?? [];
            /* The ids the generic middleware captured. Checked before the generic
               string keys below so "Checked a child out" names the child. */
            if (! empty($in['child_id']) && isset($payloadNames['child'][(int) $in['child_id']])) {
                return $payloadNames['child'][(int) $in['child_id']];
            }
            if (! empty($in['family_id']) && isset($payloadNames['family'][(int) $in['family_id']])) {
                return $payloadNames['family'][(int) $in['family_id']];
            }
            if (($in['kind'] ?? null) === 'family' && isset($payloadNames['family'][(int) ($in['id'] ?? 0)])) {
                return $payloadNames['family'][(int) $in['id']];
            }
            if (($in['kind'] ?? null) === 'staff' && isset($payloadNames['thread'][(int) ($in['id'] ?? 0)])) {
                return 'with ' . $payloadNames['thread'][(int) $in['id']];
            }
            foreach (['subject', 'family_name', 'name', 'title'] as $k) {
                if (! empty($payload[$k]) && is_string($payload[$k])) {
                    return $payload[$k];
                }
            }
            if (! empty($payload['to']) && is_array($payload['to'])) {
                return (string) reset($payload['to']);
            }
        } catch (Throwable $e) {
        }

        return '';
    }

    /**
     * One query per entity type for the whole page.
     *
     * @return array<string,array<int,string>>
     */
    private static function resolveNames(Collection $rows): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $type = (string) ($row->entity_type ?? '');
            $id = (int) ($row->entity_id ?? 0);
            if ($type !== '' && $id > 0 && isset(self::ENTITIES[$type])) {
                $byType[$type][$id] = true;
            }
        }

        $out = [];
        foreach ($byType as $type => $ids) {
            [$table, $cols] = self::ENTITIES[$type];
            try {
                if (empty($cols)) {
                    // No natural name (a conversation); say what it is, not its id.
                    foreach (array_keys($ids) as $id) {
                        $out[$type][$id] = 'conversation';
                    }
                    continue;
                }
                foreach (DB::table($table)->whereIn('id', array_keys($ids))
                    ->get(array_merge(['id'], $cols)) as $rec) {
                    $parts = [];
                    foreach ($cols as $c) {
                        $parts[] = (string) ($rec->$c ?? '');
                    }
                    $name = trim(implode(' ', array_filter($parts)));
                    if ($name !== '') {
                        $out[$type][(int) $rec->id] = $name;
                    }
                }
            } catch (Throwable $e) {
                // A missing table must not break the whole audit page.
            }
        }

        return $out;
    }

    /**
     * "post:api/v1/provider/team-threads/start" -> "Started team threads".
     *
     * Not as good as an explicit entry in VERBS, but far better than showing the route.
     * Anything appearing here often enough to matter should get a real verb above.
     */
    /**
     * "post:care/logs", rebuilt from a row whose action is a framework placeholder.
     *
     * Returns '' when the payload cannot supply both halves, so the caller keeps whatever
     * it had rather than inventing a sentence out of nothing.
     */
    private static function actionFromPayload($row): string
    {
        $raw = $row->payload ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return '';
        }

        $method = strtolower(trim((string) ($raw['method'] ?? '')));
        $path = trim((string) ($raw['path'] ?? ''));
        if ($method === '' || $path === '') {
            return '';
        }

        return $method . ':' . ltrim($path, '/');
    }

    /**
     * Who to name when an audit row has no user.
     *
     * Returns '' for anything not recognised, so an unattributed action stays "Someone"
     * rather than being confidently mislabelled.
     */
    private static function systemActorFor(string $action): string
    {
        // The payment provider calling us back is not "the system" in general — naming it
        // is the difference between "a payment settled" and "Zum Rails settled a payment".
        if (str_starts_with($action, 'zum.')) {
            return 'Zum Rails';
        }

        foreach ([
            'email.', 'push.', 'digest.', 'chat.email_notified',
            'child.auto_check_out', 'staff.auto_clock_out',
            'shift.autofilled', 'holidays.synced',
            // Geocoding and address tidying run unattended from the provider map.
            'centre.geocoded', 'centre.address_corrected',
            // A queued notice, not a person clicking send.
            'family.onboarding_notice_sent',
            'family.departed', 'family.auto', 'invoice.auto', 'retention.',
        ] as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return 'System';
            }
        }

        return '';
    }

    private static function humanisePath(string $action): string
    {
        // Framework-generated names (generated::xxxx) say nothing to a reader.
        if ($action === '' || str_starts_with($action, 'generated::')) {
            return 'Activity';
        }

        $method = 'changed';
        if (str_contains($action, ':')) {
            [$verb, $action] = explode(':', $action, 2);
            $method = match (strtolower($verb)) {
                'post' => 'created',
                'put', 'patch' => 'updated',
                'delete' => 'deleted',
                default => 'changed',
            };
        }

        $path = preg_replace('#^api/v\d+/#', '', $action) ?? $action;
        // Drop ids and the role prefix; they add nothing to the sentence.
        $parts = array_values(array_filter(
            explode('/', (string) $path),
            fn ($p) => $p !== '' && ! ctype_digit($p)
                && ! in_array($p, ['provider', 'parent', 'admin', 'api'], true)
        ));
        if (empty($parts)) {
            return ucfirst($method) . ' a record';
        }

        $what = str_replace(['-', '_'], ' ', implode(' ', $parts));

        return ucfirst($method) . ' ' . $what;
    }
}
