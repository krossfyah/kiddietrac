<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Sensitive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seal any plaintext left in the protected columns (App\Support\Sensitive), and verify.
 *
 *   php artisan kt:encrypt-sensitive --dry      count what would be sealed, change nothing
 *   php artisan kt:encrypt-sensitive            seal it (idempotent; scheduled every 10 min
 *                                               as the safety net for any write the
 *                                               connection could not parse)
 *   php artisan kt:encrypt-sensitive --verify   plaintext left + every sealed value decrypts
 */
class EncryptSensitiveCommand extends Command
{
    protected $signature = 'kt:encrypt-sensitive {--dry : Count only} {--verify : Check nothing is left in plaintext and everything decrypts}'
        . ' {--chat-history : One-off (2026-11-27): seal old emails that quoted chat messages and take message text out of old push audit rows}';

    protected $description = 'Encrypt children\'s health fields (medical notes, allergies, …) still stored in plaintext';

    public function handle(): int
    {
        if (! Sensitive::enabled()) {
            $this->error('KT_DATA_KEY is missing or not 32 bytes: nothing was changed.');
            return self::FAILURE;
        }
        $bad = 0; $left = 0; $sealed = 0;
        foreach (Sensitive::COLUMNS as $table => $cols) {
            foreach ($cols as $col => $kind) {
                // Plaintext = non-blank and not carrying the prefix. Decided in SQL, because
                // a SELECT through this connection would hand back the decrypted value.
                $q = DB::table($table)->whereNotNull($col)->whereRaw("TRIM(`$col`) <> ''")
                    ->where($col, 'not like', Sensitive::PREFIX . '%')
                    ->where($col, 'not like', '"' . Sensitive::PREFIX . '%');
                if ($kind === 'json') {
                    $q->whereRaw("LOWER(TRIM(`$col`)) NOT IN ('[]','{}','null','\"\"')");
                }
                $plain = (clone $q)->count();
                $enc = DB::table($table)->where(fn ($w) => $w->where($col, 'like', Sensitive::PREFIX . '%')->orWhere($col, 'like', '"' . Sensitive::PREFIX . '%'))->count();

                if ($this->option('verify')) {
                    $fail = 0;
                    Sensitive::raw(function () use ($table, $col, &$fail) {
                        DB::table($table)->where(fn ($w) => $w->where($col, 'like', Sensitive::PREFIX . '%')->orWhere($col, 'like', '"' . Sensitive::PREFIX . '%'))
                            ->orderBy('id')->select('id', $col)->chunk(200, function ($rows) use ($col, &$fail) {
                                foreach ($rows as $r) {
                                    $v = Sensitive::open($r->{$col});
                                    if (str_starts_with((string) $v, '⚠') || str_contains((string) $v, 'could not be decrypted')) { $fail++; }
                                }
                            });
                    });
                    $bad += $fail;
                    $left += $plain;
                    $this->line(sprintf('%-32s sealed=%-5d plaintext=%-5d undecryptable=%d', "$table.$col", $enc, $plain, $fail));
                    continue;
                }

                $this->line(sprintf('%-32s sealed=%-5d plaintext=%d', "$table.$col", $enc, $plain));
                if ($this->option('dry') || ! $plain) {
                    continue;
                }
                $q->orderBy('id')->select('id', $col)->chunkById(100, function ($rows) use ($table, $col, $kind, &$sealed) {
                    foreach ($rows as $r) {
                        $v = Sensitive::seal($r->{$col}, $kind);
                        if ($v !== $r->{$col}) {
                            // A plain query-builder update: the connection would seal it anyway;
                            // sealing here too is a no-op (already sealed values are skipped).
                            DB::table($table)->where('id', $r->id)->update([$col => $v]);
                            $sealed++;
                        }
                    }
                });
            }
        }
        /* BELL ROWS THAT COPY A CHAT MESSAGE (2026-11-27, App\Support\ChatPrivacy). Sealed per row rather than by
           column: notifications.body is searched with LIKE by other features (expiry dedupe, Safe Arrival). */
        $bell = DB::table('notifications')->whereIn('type', \App\Support\ChatPrivacy::BELL_TYPES)
            ->whereNotNull('body')->whereRaw("TRIM(`body`) <> ''")->where('body', 'not like', Sensitive::PREFIX . '%');
        $bellPlain = (clone $bell)->count();
        $bellSealed = DB::table('notifications')->whereIn('type', \App\Support\ChatPrivacy::BELL_TYPES)->where('body', 'like', Sensitive::PREFIX . '%')->count();
        $this->line(sprintf('%-32s sealed=%-5d plaintext=%d', 'notifications.body (chat rows)', $bellSealed, $bellPlain));
        if ($this->option('verify')) {
            $left += $bellPlain;
        } elseif (! $this->option('dry') && $bellPlain) {
            $bell->orderBy('id')->select('id', 'body')->chunkById(200, function ($rows) use (&$sealed) {
                foreach ($rows as $r) {
                    $v = Sensitive::seal($r->body, 'text');
                    if ($v !== $r->body) {
                        DB::table('notifications')->where('id', $r->id)->update(['body' => $v]);
                        $sealed++;
                    }
                }
            });
        }

        if ($this->option('chat-history')) {
            $this->chatHistory((bool) $this->option('dry'));
        }

        if ($this->option('verify')) {
            $this->info("Plaintext left: $left · undecryptable: $bad");
            return ($left || $bad) ? self::FAILURE : self::SUCCESS;
        }
        if (! $this->option('dry')) {
            $this->info("Sealed $sealed value(s).");
        }
        return self::SUCCESS;
    }
    /**
     * ONE-OFF (2026-11-27): copies of chat text written before ChatPrivacy.
     *  - email_logs: the unread-message emails, the old "New KiddieTrac message" email and daily summaries that
     *    quoted the day's messages. The body is SEALED per row, not erased: the Email log still shows exactly
     *    what was sent (the connection decrypts it), a copy of the table does not.
     *  - audit_logs push.*: a push's body was the message itself. That copy is replaced by a note; the title,
     *    who, when and the outcome stay.
     */
    private function chatHistory(bool $dry): void
    {
        $mail = DB::table('email_logs')->whereNotNull('body_html')->where('body_html', 'not like', Sensitive::PREFIX . '%')
            ->where(fn ($w) => $w->where('subject', 'like', '%New message from%')->orWhere('subject', 'like', '%new messages%')
                ->orWhere('subject', 'like', '%New KiddieTrac message%')->orWhere('body_html', 'like', '%Messages today%'));
        $n = (clone $mail)->count();
        $this->line("email_logs quoting chat: $n" . ($dry ? ' [dry]' : ''));
        $done = 0;
        if (! $dry && $n) {
            $mail->orderBy('id')->select('id')->chunkById(50, function ($rows) use (&$done) {
                foreach ($rows as $r) {
                    $html = Sensitive::raw(fn () => DB::table('email_logs')->where('id', $r->id)->value('body_html'));
                    $v = Sensitive::seal((string) $html, 'text');
                    if ($v !== $html) {
                        DB::table('email_logs')->where('id', $r->id)->update(['body_html' => $v]);
                        $done++;
                    }
                }
            });
            $this->info("Sealed $done email bodies.");
        }

        $note = '(message text not kept - ChatPrivacy 2026-11-27)';
        $push = DB::table('audit_logs')->where('action', 'like', 'push.%')
            ->where(fn ($w) => $w->where('payload', 'like', '%"link":"#chat%')->orWhere('payload', 'like', '%"link":"#messages%')
                ->orWhere('payload', 'like', '%EMERGENCY%')
                ->orWhere('payload', 'like', '%"title":"Message from%')->orWhere('payload', 'like', '%"title":"New message from your centre%'));
        $p = (clone $push)->count();
        $this->line("push audit rows for chat: $p" . ($dry ? ' [dry]' : ''));
        if (! $dry && $p) {
            $red = 0;
            $push->orderBy('id')->select('id', 'payload')->chunkById(200, function ($rows) use (&$red, $note) {
                foreach ($rows as $r) {
                    $j = json_decode((string) $r->payload, true);
                    if (! is_array($j) || ! isset($j['body']) || $j['body'] === $note || $j['body'] === '') {
                        continue;
                    }
                    $j['body'] = $note;
                    DB::table('audit_logs')->where('id', $r->id)->update(['payload' => json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    $red++;
                }
            });
            $this->info("Took message text out of $red push audit rows.");
        }
    }
}
