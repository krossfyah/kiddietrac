<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place an invoice number is minted (2026-09-17).
 *
 * Anthony: "invoice numbering naming convention should be definable for each agency on
 * how they want invoice numbering to start and be formatted."
 *
 * THREE GENERATORS WROTE THE SAME STRING THREE TIMES. Monthly batches
 * (InvoiceController), payment-schedule instalments (PaymentPlanController) and fee-plan
 * invoices (FeePlanController) each built 'INV-' . $d->format('Ym') . ... by hand, with
 * three subtly different shapes — one carried no sequence at all. Any per-agency format
 * bolted onto one of them would have left the other two disagreeing, so they now all
 * come through here.
 *
 * THE DEFAULT REPRODUCES TODAY'S FORMAT EXACTLY. An agency that never opens the setting
 * sees no change whatsoever: INV-202610-0081-01 is what it was and what it stays. This
 * is deliberate — renumbering invoices nobody asked to renumber would break every
 * reference a family or a bookkeeper already holds.
 *
 * TOKENS
 *   {YYYY} {YY} {MM} {DD}  the billed date (an invoice for December is a December
 *                          invoice however early it goes out)
 *   {FAMILY}               family id, zero-padded to 4
 *   {N}                    instalment index within its schedule/plan, padded to 2
 *   {SEQ}                  a RUNNING COUNTER for the agency — this is the one that
 *                          honours "where numbering starts"
 * Anything else in the string is literal, so 'ILH-{YYYY}-{SEQ}' is a valid format.
 *
 * WHY {SEQ} NEEDS A TABLE. A running counter cannot live in the agencies.settings JSON:
 * two invoices raised in the same second would read the same value, increment it and
 * both write back the same number. invoice_number_sequences is one row per agency taken
 * with lockForUpdate inside a transaction, so the second caller waits for the first.
 */
final class InvoiceNumber
{
    /** Exactly what the three hand-rolled generators produced before this existed. */
    public const DEFAULT_FORMAT = 'INV-{YYYY}{MM}-{FAMILY}-{N}';

    /** Offered in the UI. The key is the format; the value is what to call it. */
    public static function presets(): array
    {
        return [
            self::DEFAULT_FORMAT      => 'INV-202610-0081-01  (current KiddieTrac default)',
            'INV-{YYYY}-{SEQ}'        => 'INV-2026-1001  (running number, resets never)',
            '{YYYY}{MM}-{SEQ}'        => '202610-1001  (date then running number)',
            'INV-{SEQ}'               => 'INV-1001  (plain running number)',
            'INV-{YYYY}{MM}-{FAMILY}' => 'INV-202610-0081  (month and family, no sequence)',
            '{YY}{MM}-{FAMILY}-{N}'   => '2610-0081-01  (short year)',
        ];
    }

    /**
     * Mint the next number for an agency.
     *
     * @param  array{date?:Carbon|string|null,family_id?:int|null,n?:int|null}  $ctx
     */
    public static function next(?int $agencyId, array $ctx = []): string
    {
        $cfg = self::config($agencyId);
        $date = isset($ctx['date']) && $ctx['date']
            ? ($ctx['date'] instanceof Carbon ? $ctx['date'] : Carbon::parse((string) $ctx['date']))
            : Carbon::now();

        $number = self::render($cfg['format'], $date, $ctx, function () use ($agencyId, $cfg) {
            return self::takeSequence($agencyId, $cfg['seq_start'], $cfg['seq_pad']);
        });

        return self::deduplicate($number);
    }

    /**
     * What a format WOULD produce, for the settings screen's live preview. Touches no
     * counter and writes nothing — a preview that consumed a number would burn one
     * every time somebody typed a character.
     */
    public static function preview(?int $agencyId, string $format, ?int $seqStart = null): string
    {
        $cfg = self::config($agencyId);
        $seq = $seqStart ?? $cfg['seq_start'];
        $pad = max(strlen((string) $seq), $cfg['seq_pad']);

        return self::render(
            $format !== '' ? $format : self::DEFAULT_FORMAT,
            Carbon::now(),
            ['family_id' => 81, 'n' => 1],
            function () use ($seq, $pad) { return str_pad((string) $seq, $pad, '0', STR_PAD_LEFT); }
        );
    }

    /** The agency's stored choice, with every field defaulted. */
    public static function config(?int $agencyId): array
    {
        $out = ['format' => self::DEFAULT_FORMAT, 'seq_start' => 1001, 'seq_pad' => 4];
        if (! $agencyId) { return $out; }

        try {
            $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $s = $raw ? json_decode((string) $raw, true) : null;
            if (! is_array($s)) { return $out; }

            $fmt = trim((string) ($s['invoice_number_format'] ?? ''));
            if ($fmt !== '') { $out['format'] = $fmt; }
            if (isset($s['invoice_number_start']) && (int) $s['invoice_number_start'] > 0) {
                $out['seq_start'] = (int) $s['invoice_number_start'];
                // The width of the number they chose IS the padding they meant: somebody
                // who starts at 1001 wants 1002, not 0000001002.
                $out['seq_pad'] = max(1, strlen((string) $out['seq_start']));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $out;
    }

    private static function render(string $format, Carbon $date, array $ctx, callable $seq): string
    {
        $familyId = (int) ($ctx['family_id'] ?? 0);
        $n = (int) ($ctx['n'] ?? 0);

        $map = [
            '{YYYY}'   => $date->format('Y'),
            '{YY}'     => $date->format('y'),
            '{MM}'     => $date->format('m'),
            '{DD}'     => $date->format('d'),
            '{FAMILY}' => str_pad((string) $familyId, 4, '0', STR_PAD_LEFT),
            '{N}'      => str_pad((string) max(1, $n), 2, '0', STR_PAD_LEFT),
        ];

        /* The counter is only taken when the format actually asks for one — an agency
           on a family/month format must not silently burn sequence numbers. */
        if (str_contains($format, '{SEQ}')) {
            $map['{SEQ}'] = $seq();
        }

        $out = strtr($format, $map);

        /* A format with no {N} is fine for a monthly batch and ambiguous for a schedule,
           where several invoices share a family and a month. Rather than let two rows
           collide, deduplicate() below settles it — but strip any token we did not
           recognise first, so a typo like {MONTH} does not end up printed on a parent's
           invoice. */
        return trim(preg_replace('/\{[A-Za-z_]+\}/', '', $out)) ?: ('INV-' . $date->format('Ym'));
    }

    /**
     * Read and advance the agency's counter under a row lock.
     *
     * Seeded from the agency's configured start the first time it is used, so "start my
     * numbering at 5000" means the first invoice is 5000 and not 5001.
     */
    private static function takeSequence(?int $agencyId, int $start, int $pad): string
    {
        $key = 'agency:' . (int) $agencyId;

        try {
            $value = DB::transaction(function () use ($agencyId, $key, $start) {
                $row = DB::table('invoice_number_sequences')
                    ->where('agency_id', $agencyId)->where('scope_key', $key)
                    ->lockForUpdate()->first();

                if (! $row) {
                    DB::table('invoice_number_sequences')->insert([
                        'agency_id' => $agencyId, 'scope_key' => $key,
                        'next_value' => $start + 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);

                    return $start;
                }

                /* Raising the configured start above the counter moves it forward; it is
                   never moved BACK, because a lower start would reissue numbers that
                   already exist on real invoices. */
                $current = max((int) $row->next_value, $start);
                DB::table('invoice_number_sequences')->where('id', $row->id)
                    ->update(['next_value' => $current + 1, 'updated_at' => now()]);

                return $current;
            });
        } catch (Throwable $e) {
            report($e);
            // Never block an invoice over its own counter; a timestamp is unique enough
            // to get the row written, and deduplicate() guarantees the rest.
            $value = (int) (now()->format('ymdHis'));
        }

        return str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * An invoice number is the handle a family and a bookkeeper use; two invoices
     * sharing one is worse than an ugly suffix. Formats without {SEQ} genuinely can
     * collide (two batch invoices for the same family in the same month), which the
     * hand-rolled generators simply did not consider.
     */
    private static function deduplicate(string $number): string
    {
        try {
            if (! DB::table('invoices')->where('invoice_number', $number)->exists()) {
                return $number;
            }
            for ($i = 2; $i <= 99; $i++) {
                $try = $number . '-' . $i;
                if (! DB::table('invoices')->where('invoice_number', $try)->exists()) {
                    return $try;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $number . '-' . now()->format('His');
    }
}
