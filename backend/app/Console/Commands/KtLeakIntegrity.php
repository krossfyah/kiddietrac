<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LeakGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * kt:leak-integrity - leak guard layer 3 (2026-11-27): rows whose tenant disagrees with their parent's.
 *
 * A row filed under the wrong agency is a leak waiting for a reader: every screen scoped "correctly" by agency_id
 * shows it to the wrong agency. Two kinds of check, reported as counts + up to 10 ids, NEVER fixed automatically:
 *
 *  AUTO (discovered from the schema, so a new table is covered the day it ships):
 *    every table with agency_id AND centre_id / child_id / family_id: the parent's agency must be the row's agency;
 *    every table with agency_id AND user_id (HR, payroll, certificates, bell rows...): the person must have SOME
 *    membership in that agency (any role row, ever, or a guardian of a family there) - platform admins excepted.
 *  NAMED (the parent is two hops away): chat messages vs conversation, team chat senders/participants vs thread,
 *    daily events / incidents / check-ins child vs room, open enrolments and primary rooms, educator rooms,
 *    invoices, announcements and their recipients, email log attribution vs recipient, campaign sends vs campaign.
 *
 *   kt:leak-integrity [--no-alert] [--quiet-ok] [--days=30]
 */
final class KtLeakIntegrity extends Command
{
    protected $signature = 'kt:leak-integrity {--no-alert} {--quiet-ok} {--days=30 : window for high-volume tables}';

    protected $description = 'SQL sweep: rows whose agency does not match their parent (centre, child, family, room, thread, recipient).';

    /** agency_id+user_id tables where the user is the ACTOR or a visitor, not the subject - cross-agency is normal. */
    private const USER_NOT_SUBJECT = [
        'audit_logs', 'ai_usage', 'client_net_samples', 'help_article_feedback', 'help_article_views', 'permission_shadow_log',
        'web_demo_mail', 'chatbot_messages', 'user_blocks', 'sign_in_lock_exceptions', 'qbo_sync_log', 'sage_sync_log',
        'xero_sync_log', 'sage50_exports', 'account_deletion_requests', 'calendar_links', 'email_accounts', 'saved_reports',
        'user_refs', 'play_spark_runs', 'play_spark_ideas',
    ];

    /** High-volume tables: only rows from the last --days. */
    private const RECENT = ['notifications', 'marketing_campaign_sends', 'roa_events', 'report_card_events', 'drill_schedule_sends', 'outbound_calls'];

    private array $found = [];
    private int $checks = 0;

    public function handle(): int
    {
        $t0 = microtime(true);
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days)->toDateTimeString();
        $runId = DB::table('leak_guard_runs')->insertGetId(['kind' => 'integrity', 'mode' => 'all', 'started_at' => now()]);
        $pa = DB::table('role_assignments')->where('role', 'platform_admin')->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all() ?: [0];
        $paList = implode(',', $pa);

        // ---- membership: every (user, agency) pair a person has ever legitimately had --------------------------------
        DB::statement('DROP TEMPORARY TABLE IF EXISTS lg_member');
        DB::statement('CREATE TEMPORARY TABLE lg_member (user_id BIGINT UNSIGNED, agency_id BIGINT UNSIGNED, PRIMARY KEY (user_id, agency_id)) ENGINE=MEMORY');
        DB::statement('INSERT IGNORE INTO lg_member SELECT user_id, agency_id FROM role_assignments WHERE agency_id IS NOT NULL');
        DB::statement('INSERT IGNORE INTO lg_member SELECT ra.user_id, c.agency_id FROM role_assignments ra JOIN centres c ON c.id = ra.centre_id');
        DB::statement('INSERT IGNORE INTO lg_member SELECT g.user_id, c.agency_id FROM guardians g JOIN families f ON f.id = g.family_id JOIN centres c ON c.id = f.centre_id');
        $notMember = fn (string $u, string $a) => "NOT EXISTS (SELECT 1 FROM lg_member m WHERE m.user_id = {$u} AND m.agency_id = {$a}) AND {$u} NOT IN ({$paList})";

        // ---- AUTO --------------------------------------------------------------------------------------------------
        $cols = [];
        foreach (DB::select("SELECT table_name t, column_name c FROM information_schema.columns WHERE table_schema = DATABASE()
                AND column_name IN ('id','agency_id','centre_id','child_id','family_id','user_id','created_at')") as $r) {
            $cols[$r->t][$r->c] = true;
        }
        $views = array_flip(array_map(fn ($r) => $r->t, DB::select("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type <> 'BASE TABLE'")));
        ksort($cols);
        foreach ($cols as $t => $c) {
            if (empty($c['agency_id']) || empty($c['id']) || isset($views[$t]) || str_starts_with($t, 'leak_')) {
                continue;
            }
            $recent = in_array($t, self::RECENT, true) && ! empty($c['created_at']) ? " AND x.created_at >= '{$since}'" : '';
            if (! empty($c['centre_id'])) {
                $this->check("{$t}: centre is in another agency", "SELECT x.id FROM `{$t}` x JOIN centres p ON p.id = x.centre_id
                    WHERE x.agency_id IS NOT NULL AND p.agency_id <> x.agency_id{$recent}");
            }
            if (! empty($c['family_id'])) {
                $this->check("{$t}: family is in another agency", "SELECT x.id FROM `{$t}` x JOIN families f ON f.id = x.family_id JOIN centres p ON p.id = f.centre_id
                    WHERE x.agency_id IS NOT NULL AND p.agency_id <> x.agency_id{$recent}");
            }
            if (! empty($c['child_id'])) {
                $this->check("{$t}: child is in another agency", "SELECT x.id FROM `{$t}` x JOIN children ch ON ch.id = x.child_id JOIN families f ON f.id = ch.family_id JOIN centres p ON p.id = f.centre_id
                    WHERE x.agency_id IS NOT NULL AND p.agency_id <> x.agency_id{$recent}");
            }
            if (! empty($c['user_id']) && ! in_array($t, self::USER_NOT_SUBJECT, true) && $t !== 'role_assignments') {
                $extra = $t === 'notifications' ? " AND x.type <> 'support_ticket'" : '';
                $this->check("{$t}: person has no membership in the row's agency", "SELECT x.id FROM `{$t}` x
                    WHERE x.agency_id IS NOT NULL AND x.user_id IS NOT NULL AND " . $notMember('x.user_id', 'x.agency_id') . $recent . $extra);
            }
        }

        // ---- NAMED -------------------------------------------------------------------------------------------------
        $agencyOfChild = 'SELECT c.agency_id FROM children ch JOIN families f ON f.id = ch.family_id JOIN centres c ON c.id = f.centre_id WHERE ch.id = %s';
        $agencyOfRoom = 'SELECT c.agency_id FROM rooms r JOIN centres c ON c.id = r.centre_id WHERE r.id = %s';
        $this->check('conversations: family is in another agency than the centre', 'SELECT x.id FROM conversations x JOIN centres c ON c.id = x.centre_id
            JOIN families f ON f.id = x.family_id JOIN centres fc ON fc.id = f.centre_id WHERE fc.agency_id <> c.agency_id');
        $this->check('conversations: child is not in the conversation\'s family', 'SELECT x.id FROM conversations x JOIN children ch ON ch.id = x.child_id WHERE ch.family_id <> x.family_id');
        $this->check('messages: sender has no membership in the conversation\'s agency', "SELECT x.id FROM messages x JOIN conversations cv ON cv.id = x.conversation_id
            JOIN centres c ON c.id = cv.centre_id WHERE x.created_at >= '{$since}' AND (x.is_system IS NULL OR x.is_system = 0) AND " . $notMember('x.sender_id', 'c.agency_id'));
        $this->check('team chat: sender has no membership in the thread\'s agency', "SELECT x.id FROM staff_messages x JOIN staff_threads t ON t.id = x.thread_id
            WHERE x.created_at >= '{$since}' AND (x.is_system IS NULL OR x.is_system = 0) AND " . $notMember('x.sender_id', 't.agency_id'));
        $this->check('team chat: participant has no membership in the thread\'s agency', 'SELECT x.id FROM staff_thread_participants x JOIN staff_threads t ON t.id = x.thread_id
            WHERE ' . $notMember('x.user_id', 't.agency_id'));
        foreach (['daily_events' => " AND x.created_at >= '{$since}'", 'incidents' => '', 'check_events' => " AND x.created_at >= '{$since}'"] as $t => $w) {
            $this->check("{$t}: child and room are in different agencies", "SELECT x.id FROM `{$t}` x WHERE x.room_id IS NOT NULL{$w}
                AND (" . sprintf($agencyOfChild, 'x.child_id') . ') <> (' . sprintf($agencyOfRoom, 'x.room_id') . ')');
        }
        $this->check('enrollments (open): room is in another agency than the child', 'SELECT x.id FROM enrollments x WHERE x.end_date IS NULL
            AND (' . sprintf($agencyOfChild, 'x.child_id') . ') <> (' . sprintf($agencyOfRoom, 'x.room_id') . ')');
        $this->check('children: primary room is in another agency', 'SELECT x.id FROM children x WHERE x.primary_room_id IS NOT NULL
            AND (' . sprintf($agencyOfChild, 'x.id') . ') <> (' . sprintf($agencyOfRoom, 'x.primary_room_id') . ')');
        $this->check('educator_rooms: educator has no membership in the room\'s agency', 'SELECT x.id FROM educator_rooms x JOIN rooms r ON r.id = x.room_id JOIN centres c ON c.id = r.centre_id
            WHERE ' . $notMember('x.user_id', 'c.agency_id'));
        $this->check('role_assignments: centre is in another agency than the role', 'SELECT x.id FROM role_assignments x JOIN centres c ON c.id = x.centre_id
            WHERE x.agency_id IS NOT NULL AND c.agency_id <> x.agency_id');
        $this->check('invoices: family is in another agency than the centre', 'SELECT x.id FROM invoices x JOIN centres c ON c.id = x.centre_id
            JOIN families f ON f.id = x.family_id JOIN centres fc ON fc.id = f.centre_id WHERE fc.agency_id <> c.agency_id');
        foreach (['centre' => 'SELECT agency_id FROM centres WHERE id = x.scope_id', 'room' => sprintf($agencyOfRoom, 'x.scope_id'),
            'family' => 'SELECT c.agency_id FROM families f JOIN centres c ON c.id = f.centre_id WHERE f.id = x.scope_id'] as $scope => $sql) {
            $this->check("announcements: {$scope} scope is in another agency", "SELECT x.id FROM announcements x WHERE x.scope_type = '{$scope}' AND x.agency_id IS NOT NULL
                AND ({$sql}) <> x.agency_id");
        }
        $this->check('announcement recipients: person has no membership in the announcement\'s agency', "SELECT x.announcement_id AS id FROM announcement_recipients x
            JOIN announcements a ON a.id = x.announcement_id WHERE a.agency_id IS NOT NULL AND a.created_at >= '{$since}' AND " . $notMember('x.user_id', 'a.agency_id'));
        $this->check('campaign sends: filed under another agency than the campaign', "SELECT x.id FROM marketing_campaign_sends x JOIN marketing_campaigns c ON c.id = x.campaign_id
            WHERE x.agency_id IS NOT NULL AND c.agency_id IS NOT NULL AND x.agency_id <> c.agency_id");
        /* Email attribution: a log row filed under agency X, sent to an address whose every account has nothing to do
           with X. That is the daily-summary-in-iLearn's-log shape. Addresses with no account (contacts, prospects) are skipped. */
        $this->check('email_logs: filed under an agency the recipient has no membership in', "SELECT x.id FROM email_logs x JOIN users u ON u.email COLLATE utf8mb4_unicode_ci = x.to_email COLLATE utf8mb4_unicode_ci
            WHERE x.agency_id IS NOT NULL AND x.created_at >= '" . now()->subDays(min($days, 7))->toDateTimeString() . "'
            AND u.id NOT IN ({$paList})
            AND NOT EXISTS (SELECT 1 FROM users u2 JOIN lg_member m ON m.user_id = u2.id WHERE u2.email COLLATE utf8mb4_unicode_ci = x.to_email COLLATE utf8mb4_unicode_ci AND m.agency_id = x.agency_id)
            AND NOT EXISTS (SELECT 1 FROM users u3 WHERE u3.email COLLATE utf8mb4_unicode_ci = x.to_email COLLATE utf8mb4_unicode_ci AND u3.id IN ({$paList}))");

        // ---- report ------------------------------------------------------------------------------------------------
        $lines = [];
        foreach ($this->found as $title => [$n, $ids]) {
            $lines[] = "{$title}: {$n} row(s) - ids " . implode(', ', $ids) . ($n > count($ids) ? ' ...' : '');
        }
        $secs = (int) round(microtime(true) - $t0);
        DB::table('leak_guard_runs')->where('id', $runId)->update([
            'finished_at' => now(), 'routes_checked' => $this->checks, 'calls' => 0, 'findings' => count($lines),
            'summary' => json_encode(['secs' => $secs, 'lines' => $lines, 'coverage' => ['checks' => $this->checks]], JSON_UNESCAPED_SLASHES),
        ]);
        $this->line("Leak integrity: {$this->checks} checks in {$secs}s");
        if ($lines) {
            $this->error(count($lines) . ' check(s) found rows filed under the wrong agency:');
            foreach ($lines as $l) {
                $this->line('   ' . $l);
            }
            if (! $this->option('no-alert')) {
                LeakGuard::alert($runId);
            }
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $this->info('Clean: every checked row sits in the same agency as its parent.');
        }
        return self::SUCCESS;
    }

    private function check(string $title, string $sql): void
    {
        $this->checks++;
        try {
            $ids = array_map(fn ($r) => (int) $r->id, DB::select("SELECT id FROM ({$sql}) q LIMIT 10"));
            if (! $ids) {
                return;
            }
            $n = (int) DB::selectOne("SELECT COUNT(*) n FROM ({$sql}) q")->n;
            $this->found[$title] = [$n, $ids];
        } catch (Throwable $e) {
            $this->found[$title . ' (CHECK FAILED TO RUN)'] = [0, []];
            $this->warn("  check failed: {$title}: " . mb_substr($e->getMessage(), 0, 160));
        }
    }
}
