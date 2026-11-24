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
    protected $signature = 'kt:encrypt-sensitive {--dry : Count only} {--verify : Check nothing is left in plaintext and everything decrypts}';

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
        if ($this->option('verify')) {
            $this->info("Plaintext left: $left · undecryptable: $bad");
            return ($left || $bad) ? self::FAILURE : self::SUCCESS;
        }
        if (! $this->option('dry')) {
            $this->info("Sealed $sealed value(s).");
        }
        return self::SUCCESS;
    }
}
