<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * WHOSE FILE IS THIS (2026-11-28, data-leakage rule).
 *
 * SignProtectedMedia signs every protected `/storage/<folder>/...` path in every API reply, whoever's it is. So any
 * endpoint that stores a path the browser sent - a photo_url, a document_url, an image in a menu, a link pasted into
 * a note - could be handed another agency's file path and would then serve that file to everyone who reads the row.
 * This class answers "which agency does this stored file belong to" from three kinds of evidence:
 *
 *   1. the PATH itself, where the folder carries its owner: hr-documents/<agency>/, managed-forms/<agency>/,
 *      child-documents/<child>/, file-requests/<request>/, agreements/<user>/, photos/<Y>/<m>/<user>-..., private:<folder>/<agency>/;
 *   2. the UPLOAD ledger: a write request that created the file, in the agency it was made in (file_owners "upload");
 *   3. the ROWS that reference it (file_owners "row", rebuilt nightly by kt:leak-integrity; looked up live on a miss).
 *
 * Used by RefuseForeignFileRefs (refuse a save naming another agency's file, 422) and by kt:leak-integrity (report
 * rows already pointing at another agency's file - reported, never changed).
 */
final class FileRefs
{
    /** Stock demo media used by every demo agency (no real people): allowed anywhere. */
    public const SHARED_PREFIXES = ['photos/web-demo/', 'videos/web-demo/'];

    /** Folders whose second segment IS the agency id. */
    private const AGENCY_FOLDERS = ['hr-documents', 'safety-files', 'drill-maps', 'ai-extractions', 'managed-forms'];

    /** Folders whose second segment is the person the file is about / was made by. */
    private const USER_FOLDERS = ['agreements', 'sms-consent', 'user-documents'];

    /** Folders named <Y>/<m>/<uploader id>-<time>-... */
    private const UPLOADER_FOLDERS = ['photos', 'messages', 'voice-messages', 'videos'];

    /** Tables never scanned: logs and caches, and the tables whose text is a copy of what was sent elsewhere. */
    public const SKIP_TABLES = ['email_logs', 'audit_logs', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches',
        'migrations', 'personal_access_tokens', 'permission_shadow_log', 'file_owners', 'web_demo_mail', 'ai_content_log', 'ai_usage'];

    /** Seed list of reference-bearing columns (2026-11-28 discovery); kt:leak-integrity caches the live list. */
    private const SEED_COLUMNS = ['photos.url', 'photos.thumbnail_url', 'menu_items.image_url', 'documents.file_url', 'documents.signature_url',
        'managed_form_signoffs.filled_file_url', 'managed_form_signoffs.countersigned_file_url', 'managed_form_signoffs.countersign_reviewed_url',
        'signed_documents.signature_data', 'immunizations.proof_document_url', 'users.photo_url', 'managed_forms.file_url', 'videos.url',
        'videos.thumbnail_url', 'children.photo_url', 'hr_documents.file_path', 'lesson_plans.plan_data', 'lesson_plans.image_url',
        'hr_chemicals.sds_path', 'tasks.evidence_path', 'inspection_checklist_items.evidence_url', 'menu_templates.items', 'hr_risk_assessments.items'];

    public const COLUMNS_CACHE = 'kt.filerefs.columns';

    // ── finding references ────────────────────────────────────────────────────────────────────

    /** A cheap "could this string name one of our files" test, before any parsing. */
    public static function mayHold(string $s): bool
    {
        return $s !== '' && ! str_starts_with($s, 'data:')
            && (str_contains($s, 'storage/') || str_contains($s, 'private:') || str_contains($s, 'media/f') || str_contains($s, 'media\\/f')
                || str_contains($s, 'media/e/') || str_contains($s, 'media\\/e\\/'));
    }

    /**
     * Every PROTECTED or PRIVATE file a string names, as a storage-relative path ("photos/2026/11/x.jpg") or a private
     * reference ("private:contact-cards/6/..."). Public files (logos, the food and lesson libraries) are not returned:
     * they are public by intent and naming one shares nothing.
     *
     * @return string[]
     */
    public static function extract(string $s): array
    {
        if (! self::mayHold($s)) {
            return [];
        }
        $s = str_replace(['\\/', '&amp;'], ['/', '&'], $s);
        $out = [];
        if (preg_match_all('#(?:^|[^A-Za-z0-9_])storage/([A-Za-z0-9._\-/%]+)#', $s, $m)) {
            foreach ($m[1] as $p) {
                $rel = ProtectedMedia::relative('/storage/' . rawurldecode(rtrim($p, '/.')));
                if ($rel !== null) $out[$rel] = true;
            }
        }
        if (preg_match_all('#private:([A-Za-z0-9._\-/]+)#', $s, $m)) {
            foreach ($m[1] as $p) {
                $folder = explode('/', $p)[0];
                if (in_array($folder, PrivateFiles::KINDS, true) && ! str_contains($p, '..')) $out['private:' . $p] = true;
            }
        }
        if (preg_match_all('#media/f\?[^\s"\'<>]*?(?:^|[?&])p=([A-Za-z0-9_-]+)#', $s, $m)) {
            foreach ($m[1] as $p) {
                $rel = ProtectedMedia::decode($p);
                if ($rel !== null) $out[$rel] = true;
            }
        }
        if (preg_match_all('#media/e/([A-Za-z0-9_-]+)\.#', $s, $m)) {
            foreach ($m[1] as $payload) {
                // Expired or not, the token names a file: decode what it claims (the signature is not what matters here).
                $j = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
                $rel = is_array($j) && ! empty($j['p']) ? ProtectedMedia::relative((string) $j['p']) : null;
                if ($rel !== null) $out[$rel] = true;
            }
        }

        return array_keys($out);
    }

    /** Every reference in a nested input array: [dot.key => [rel, ...]]. Uploaded files and data: URLs are skipped. */
    public static function inInput(array $input, string $prefix = '', int $depth = 0): array
    {
        $found = [];
        if ($depth > 12) return $found;
        foreach ($input as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $found += self::inInput($v, $key, $depth + 1);
            } elseif (is_string($v) && ($refs = self::extract($v))) {
                $found[$key] = $refs;
            }
        }
        return $found;
    }

    public static function isShared(string $rel): bool
    {
        foreach (self::SHARED_PREFIXES as $p) {
            if (str_starts_with($rel, $p)) return true;
        }
        return false;
    }

    // ── evidence ─────────────────────────────────────────────────────────────────────────────

    /**
     * What the PATH says: ['agencies' => int[], 'users' => int[]]. Empty both = the path names no owner (avatars,
     * child-photos, chat attachments, media/...): the ledger and the rows have to say.
     */
    public static function fromPath(string $rel): array
    {
        $agencies = []; $users = [];
        if (str_starts_with($rel, 'private:')) {
            $seg = explode('/', substr($rel, 8));
            if (isset($seg[1]) && ctype_digit($seg[1])) $agencies[] = (int) $seg[1];
            return ['agencies' => $agencies, 'users' => $users];
        }
        $seg = explode('/', $rel);
        $f = $seg[0]; $s1 = $seg[1] ?? ''; $s2 = $seg[2] ?? '';
        if ($f === 'photos' && $s1 === 'menu' && ctype_digit($s2)) {
            $agencies[] = (int) $s2;
        } elseif ($f === 'managed-forms' && $s1 === 'filled' && ctype_digit($s2)) {
            $a = self::formAgency((int) $s2);
            if ($a) $agencies[] = $a;
            if (isset($seg[3]) && preg_match('/^(\d+)-/', $seg[3], $m)) $users[] = (int) $m[1];
        } elseif (in_array($f, self::AGENCY_FOLDERS, true) && ctype_digit($s1)) {
            $agencies[] = (int) $s1;
        } elseif ($f === 'child-documents' && ctype_digit($s1)) {
            $a = self::childAgency((int) $s1);
            if ($a) $agencies[] = $a;
        } elseif ($f === 'file-requests' && ctype_digit($s1)) {
            $a = (int) DB::table('file_requests')->where('id', (int) $s1)->value('agency_id');
            if ($a) $agencies[] = $a;
        } elseif (in_array($f, self::USER_FOLDERS, true) && ctype_digit($s1)) {
            $users[] = (int) $s1;
        } elseif (in_array($f, self::UPLOADER_FOLDERS, true) && ctype_digit($s1) && isset($seg[3]) && preg_match('/^(\d+)-\d+/', $seg[3], $m)) {
            $users[] = (int) $m[1];
        }

        return ['agencies' => $agencies, 'users' => $users];
    }

    /** The agencies a person has ever legitimately belonged to (a role there, a role at one of its centres, a family there). */
    public static function memberships(int $userId): array
    {
        static $memo = [];
        if (isset($memo[$userId])) return $memo[$userId];
        $a = DB::table('role_assignments')->where('user_id', $userId)->whereNotNull('agency_id')->pluck('agency_id')
            ->merge(DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')->where('ra.user_id', $userId)->pluck('c.agency_id'))
            ->merge(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)->pluck('c.agency_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (count($memo) > 500) $memo = [];
        return $memo[$userId] = $a;
    }

    /** Agencies the ledger and the row index record for these files: [rel => int[]]. */
    public static function indexed(array $rels): array
    {
        $out = [];
        if (! $rels || ! Schema::hasTable('file_owners')) return $out;
        $byHash = [];
        foreach ($rels as $r) $byHash[sha1($r)] = $r;
        foreach (array_chunk(array_keys($byHash), 500) as $chunk) {
            foreach (DB::table('file_owners')->whereIn('rel_hash', $chunk)->whereNotNull('agency_id')->get(['rel_hash', 'agency_id']) as $row) {
                $out[$byHash[$row->rel_hash]][] = (int) $row->agency_id;
            }
        }
        return $out;
    }

    /** Remember who owns a file. */
    public static function record(string $rel, ?int $agencyId, ?int $userId, string $source): void
    {
        if (! Schema::hasTable('file_owners')) return;
        try {
            DB::table('file_owners')->insertOrIgnore([
                'rel_hash' => sha1($rel), 'rel' => mb_substr($rel, 0, 500), 'agency_id' => $agencyId ?: null,
                'user_id' => $userId ?: null, 'source' => $source, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * May a save made in $agencyId by $userId name this file? True for shared demo media, a file whose path names this
     * agency, the caller's own files, a file a member of this agency made or is about, and a file the ledger or this
     * agency's rows already hold. Falls back to looking the file up in every reference-bearing column before refusing.
     */
    public static function allows(string $rel, int $agencyId, int $userId, ?array $indexed = null): bool
    {
        if (self::isShared($rel)) return true;
        if ($agencyId <= 0) return false;
        $p = self::fromPath($rel);
        if ($p['agencies']) {
            return in_array($agencyId, $p['agencies'], true);
        }
        if (in_array($userId, $p['users'], true)) return true;
        foreach ($p['users'] as $u) {
            if (in_array($agencyId, self::memberships($u), true)) return true;
        }
        $known = $indexed ?? (self::indexed([$rel])[$rel] ?? []);
        if (in_array($agencyId, $known, true)) return true;
        // A miss: the index is nightly, so ask the rows themselves before refusing.
        $live = self::rowAgencies($rel);
        foreach ($live as $a) self::record($rel, $a, null, 'row');
        return in_array($agencyId, $live, true);
    }

    // ── rows ─────────────────────────────────────────────────────────────────────────────────

    /** The reference-bearing columns ("table.column"): the list kt:leak-integrity found last, else the seed list. */
    public static function columns(): array
    {
        $c = Cache::get(self::COLUMNS_CACHE);
        return is_array($c) && $c ? $c : self::SEED_COLUMNS;
    }

    /** The agencies of every row that references this file, looked up live. */
    public static function rowAgencies(string $rel): array
    {
        $needle = str_starts_with($rel, 'private:') ? $rel : 'storage/' . $rel;
        $agencies = [];
        $t = new RowTenant();
        foreach (self::columns() as $tc) {
            [$table, $col] = explode('.', $tc, 2);
            if (! Schema::hasColumn($table, $col)) continue;
            try {
                $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
                $rows = DB::table($table)->where(function ($w) use ($col, $esc) {
                    $w->where($col, 'like', '%' . $esc . '%')->orWhere($col, 'like', '%' . str_replace('/', '\\\\/', $esc) . '%');
                })->limit(50)->get();
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($rows as $row) {
                foreach ($t->agencies($table, $row) as $a) $agencies[$a] = true;
            }
        }
        return array_keys($agencies);
    }

    public static function childAgency(int $childId): ?int
    {
        $a = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.id', $childId)->value('c.agency_id');
        return $a ? (int) $a : null;
    }

    public static function formAgency(int $formId): ?int
    {
        $f = DB::table('managed_forms')->where('id', $formId)->first(['agency_id', 'centre_id']);
        if (! $f) return null;
        if ($f->agency_id) return (int) $f->agency_id;
        $a = $f->centre_id ? DB::table('centres')->where('id', $f->centre_id)->value('agency_id') : null;
        return $a ? (int) $a : null;
    }

    /** Does the file exist and was it written in the last $minutes? (What a write request has just created.) */
    public static function isFresh(string $rel, int $minutes = 15): bool
    {
        try {
            if (str_starts_with($rel, 'private:')) {
                $disk = PrivateFiles::disk(); $p = substr($rel, 8);
            } else {
                $disk = Storage::disk('public'); $p = $rel;
            }
            return $disk->exists($p) && $disk->lastModified($p) >= time() - $minutes * 60;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
