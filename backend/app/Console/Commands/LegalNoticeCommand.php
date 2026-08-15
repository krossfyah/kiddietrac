<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\LegalDocuments;
use App\Support\Suppression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tell everyone when the privacy policy, terms or confidentiality terms change (2026-08-14).
 *
 * A change to what we may do with a family's data is only meaningful if the family is told
 * about it, and told what actually changed — "our terms have been updated" trains people to
 * ignore the next one. The wording comes from LegalDocuments so it cannot drift from the
 * published document.
 *
 * SENDING IS OPT-IN AT THE COMMAND LINE. This is the one command here that mails every user
 * on the platform at once, so the default is to do nothing: without --confirm it reports who
 * it would write to and stops. --test sends one copy to a single address and records nothing,
 * which is how a notice should always be read before it goes out.
 *
 * Sends are recorded per user per version, so re-running after a failure picks up where it
 * stopped rather than mailing the same people twice.
 *
 *   php artisan kiddietrac:legal-notice --test=me@example.com
 *   php artisan kiddietrac:legal-notice --dry-run
 *   php artisan kiddietrac:legal-notice --confirm
 */
final class LegalNoticeCommand extends Command
{
    protected $signature = 'kiddietrac:legal-notice
        {--doc-version= : which version to announce (defaults to the current one; not --version, which Artisan reserves)}
        {--test= : send a single copy to this address and record nothing}
        {--dry-run : list who would be written to, and send nothing}
        {--confirm : actually send — without this the command only reports}
        {--audience=access : access (active + invited) | all (everyone we hold an email for)}
        {--override-suppression : include agencies that are normally suppressed}
        {--resend : ignore the record of who has already been told}';

    protected $description = 'Email every user that the privacy policy or terms have changed.';

    public function handle(): int
    {
        $version = (string) ($this->option('doc-version') ?: LegalDocuments::CURRENT_VERSION);

        try {
            $doc = LegalDocuments::version($version);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Legal notice for version <info>{$version}</info>, effective {$doc['effective']}");
        $this->line("  {$doc['headline']}");
        $this->newLine();

        // ── a single copy, to be read before anyone else gets it ────────────
        if ($test = (string) $this->option('test')) {
            $html = $this->body($doc, $version, null, 'there', true);
            $this->send($test, 'Test recipient', $doc['headline'], $html, null);
            $this->info("Test sent to {$test}. Nothing recorded, nobody else written to.");

            return self::SUCCESS;
        }

        // ── who ─────────────────────────────────────────────────────────────
        $q = DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($this->option('audience') !== 'all') {
            // People who have, or have been offered, access to the platform. Someone never
            // invited has no account to accept terms for; --audience=all covers everyone we
            // hold an address for, which is the wider reading.
            $q->whereIn('status', ['active', 'invited']);
        }

        $users = $q->select('id', 'first_name', 'last_name', 'email', 'status')->get();

        $already = $this->option('resend')
            ? collect()
            : DB::table('legal_notice_sends')->where('version', $version)->pluck('user_id')->flip();

        $plan = ['send' => [], 'suppressed' => 0, 'already' => 0];
        foreach ($users as $u) {
            if (! $this->option('resend') && $already->has((int) $u->id)) {
                $plan['already']++;
                continue;
            }
            if (! $this->option('override-suppression') && Suppression::isUser((int) $u->id)) {
                $plan['suppressed']++;
                continue;
            }
            $plan['send'][] = $u;
        }

        $this->line('  candidates      : ' . $users->count() . ' (audience=' . $this->option('audience') . ')');
        $this->line('  already told    : ' . $plan['already']);
        $this->line('  suppressed      : ' . $plan['suppressed'] . ($plan['suppressed'] ? '  (--override-suppression to include)' : ''));
        $this->line('  <info>would send      : ' . count($plan['send']) . '</info>');
        $this->newLine();

        if ($this->option('dry-run') || ! $this->option('confirm')) {
            foreach (array_slice($plan['send'], 0, 10) as $u) {
                $this->line("    {$u->email}  ({$u->status})");
            }
            if (count($plan['send']) > 10) {
                $this->line('    … and ' . (count($plan['send']) - 10) . ' more');
            }
            $this->newLine();
            $this->warn($this->option('dry-run')
                ? 'Dry run — nothing sent.'
                : 'Nothing sent. Re-run with --confirm to send, or --test=<address> to read it first.');

            return self::SUCCESS;
        }

        // ── send ────────────────────────────────────────────────────────────
        $sent = 0;
        $failed = 0;
        foreach ($plan['send'] as $u) {
            $agencyId = $this->agencyIdFor((int) $u->id);
            $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'there';
            $html = $this->body($doc, $version, $agencyId, (string) ($u->first_name ?: 'there'), false);

            try {
                $this->send((string) $u->email, $name, $doc['headline'], $html, $agencyId);
                DB::table('legal_notice_sends')->insert([
                    'user_id' => $u->id, 'version' => $version, 'sent_at' => now(),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Legal notice failed', ['user' => $u->id, 'error' => $e->getMessage()]);
                $this->line("    <fg=red>failed</> {$u->email}: " . $e->getMessage());
            }
        }

        $this->info("Sent {$sent}" . ($failed ? ", {$failed} failed" : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function send(string $to, string $name, string $subject, string $html, ?int $agencyId): void
    {
        AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($to, $name, $subject) {
            $m->to($to, $name)->subject($subject);
        });
    }

    /** Branding follows the agency the person belongs to, so it looks like their centre wrote it. */
    private function agencyIdFor(int $userId): ?int
    {
        $id = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->value('agency_id')
            ?: DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)
                ->value('c.agency_id');

        return $id ? (int) $id : null;
    }

    /**
     * @param array{effective:string,headline:string,summary:string,points:string[],anchor:string} $doc
     */
    private function body(array $doc, string $version, ?int $agencyId, string $firstName, bool $isTest): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $url = LegalDocuments::URL;
        $deep = LegalDocuments::URL . $doc['anchor'];
        $effective = Carbon::parse($doc['effective'])->format('j F Y');

        $points = '';
        foreach ($doc['points'] as $p) {
            $points .= '<tr><td style="padding:0 0 10px;vertical-align:top;width:22px;font-size:15px;color:#159FB4;">&bull;</td>'
                . '<td style="padding:0 0 10px;font-size:15px;line-height:1.6;color:#334155;">' . $e($p) . '</td></tr>';
        }

        $testBanner = $isTest
            ? '<tr><td style="padding:0 0 18px;"><div style="background:#FEF3C7;border-left:4px solid #F59E0B;'
              . 'border-radius:8px;padding:12px 14px;font-size:13px;line-height:1.55;color:#78350F;">'
              . '<strong>This is a test.</strong> Nobody else has received it, and no send has been recorded. '
              . 'The real notice is identical apart from this banner and the greeting.</div></td></tr>'
            : '';

        $body = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
  {$testBanner}
  <tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 14px;">
    Hello {$e($firstName)},
  </td></tr>
  <tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 14px;">
    We have updated the legal terms that cover your use of KiddieTrac. We are writing because
    you should know what changed rather than find out later.
  </td></tr>
  <tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 6px;">
    {$e($doc['summary'])}
  </td></tr>
  <tr><td style="padding:12px 0 6px;font-size:13px;font-weight:700;letter-spacing:.06em;
      text-transform:uppercase;color:#64748B;">What changed</td></tr>
  <tr><td style="padding:0 0 8px;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">{$points}</table>
  </td></tr>
  <tr><td style="padding:6px 0 18px;">
    <div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;font-size:14px;line-height:1.6;color:#334155;">
      <strong>Please take a few minutes to read it.</strong> These changes take effect on
      {$e($effective)}, and continuing to use KiddieTrac after that date means you accept them.
      If anything is unclear, or you would rather not accept, reply to this email or speak to
      your centre before then.
    </div>
  </td></tr>
  <tr><td style="padding:0 0 10px;">
    <a href="{$e($deep)}" style="display:inline-block;background:#159FB4;color:#ffffff;
       text-decoration:none;font-weight:700;font-size:15px;padding:12px 22px;border-radius:9px;">
      Read what changed</a>
  </td></tr>
  <tr><td style="font-size:13px;line-height:1.6;color:#64748B;padding:6px 0 0;">
    The full document — Privacy Policy, Terms of Use, and Confidentiality and Intellectual
    Property — is always at <a href="{$e($url)}" style="color:#159FB4;">{$e($url)}</a>.
    This is version {$e($version)}, effective {$e($effective)}.
  </td></tr>
  <tr><td style="font-size:13px;line-height:1.6;color:#64748B;padding:12px 0 0;">
    Questions about this, or about the information we hold about you:
    <a href="mailto:info@kiddietrac.com" style="color:#159FB4;">info@kiddietrac.com</a>.
  </td></tr>
</table>
HTML;

        return EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'IMPORTANT — PLEASE READ',
            'title' => 'Our legal terms have changed',
            'subtitle' => 'Version ' . $version . ' · effective ' . $effective,
            'preheader' => $doc['headline'],
            // Not marketing, so no unsubscribe line: this is a notice about the terms
            // covering an account somebody holds, and there is no opting out of being told.
            'footer_note' => 'You are receiving this because you have a KiddieTrac account. '
                . 'Notices about our legal terms are sent to every account holder.',
        ]);
    }
}
