<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A SIGNED FORM BELONGS ON THE SIGNER'S RECORD.
 *
 * Until now a completed form lived in exactly one place: the Forms Manager's Completed
 * tab, which only an admin ever opens. The parent who signed it could not see it again;
 * the educator who signed it could not see it again; opening the family's record showed
 * nothing, because that screen reads `documents` and nothing ever wrote one.
 *
 * So filing is now part of signing. One `documents` row per sign-off, scoped to the
 * person who signed, which is enough to make it appear everywhere at once:
 *
 *   · /auth/me/documents        -- their own copy, portal and APK, WHATEVER their role
 *   · /admin/users/{id}/documents -- on their record, for whoever manages them
 *   · the family record         -- resolved through `guardians`, see familyDocuments()
 *
 * ONE row, not three. Scope duplication looks convenient and then drifts: delete one
 * copy and the others linger, rename a form and only some catch up. The family view
 * follows the guardian link instead, so there is a single fact about a single signature.
 *
 * Idempotent on (source_type, source_id), so signing, re-signing a reusable form and
 * re-running the backfill all converge on the same row.
 */
final class SignedFormFiler
{
    public const CATEGORY = 'signed_form';
    public const SOURCE   = 'managed_form_signoff';

    /**
     * File (or refresh) the document for one sign-off.
     *
     * Best-effort by design: the signature is already saved by the time this runs, and a
     * filing problem must never turn a successful submission into an error the signer
     * sees. It reports instead, so the failure is still visible to us.
     */
    public static function file(int $signoffId): void
    {
        try {
            $s = DB::table('managed_form_signoffs as s')
                ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
                ->where('s.id', $signoffId)
                ->whereNotNull('s.signed_at')
                ->first([
                    's.id', 's.user_id', 's.signed_at', 's.filled_file_url',
                    'f.title', 'f.file_url', 'f.file_type', 'f.file_size',
                ]);

            // A draft is not a document. Nothing to file until it is signed.
            if (! $s || ! $s->user_id) {
                return;
            }

            /* The COMPLETED copy when the form was filled in, otherwise the blank the
               person read and signed. Either way the row points at what they actually
               put their name to, which is the whole point of keeping it. */
            $url = $s->filled_file_url ?: $s->file_url;
            if (! $url) {
                return;
            }
            $isFilled = (bool) $s->filled_file_url;

            DB::table('documents')->updateOrInsert(
                ['source_type' => self::SOURCE, 'source_id' => (int) $s->id],
                [
                    'scope_type'     => 'user',
                    'scope_id'       => (int) $s->user_id,
                    'category'       => self::CATEGORY,
                    'title'          => mb_substr((string) $s->title, 0, 200),
                    'file_url'       => mb_substr((string) $url, 0, 500),
                    // file_type is varchar(20); the stored value is already short.
                    'file_type'      => mb_substr((string) ($s->file_type ?: 'application/pdf'), 0, 20),
                    // The filled copy is a different file, so the library's byte count
                    // would be a lie about it. Better absent than wrong.
                    'file_size'      => $isFilled ? null : $s->file_size,
                    'signed_at'      => $s->signed_at,
                    'signed_by_id'   => (int) $s->user_id,
                    'uploaded_by_id' => (int) $s->user_id,
                    // Filed when it was signed, not when this code happened to run --
                    // otherwise a backfill would date forty forms to the same afternoon.
                    'created_at'     => $s->signed_at,
                ]
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Every sign-off that has no document yet, filed.
     *
     * Used by the one-off backfill and safe to run again: file() keys on the source pair,
     * so a second pass rewrites the same rows rather than adding more.
     *
     * @return int how many sign-offs were processed
     */
    public static function backfill(?int $agencyId = null): int
    {
        $q = DB::table('managed_form_signoffs as s')
            ->join('managed_forms as f', 'f.id', '=', 's.managed_form_id')
            ->whereNotNull('s.signed_at')
            ->when($agencyId, fn ($qq) => $qq->where('f.agency_id', $agencyId))
            ->orderBy('s.id');

        $n = 0;
        foreach ($q->pluck('s.id') as $id) {
            self::file((int) $id);
            $n++;
        }

        return $n;
    }
}
