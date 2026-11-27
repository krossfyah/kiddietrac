<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 4 (2026-11-27): THE EMPLOYEE DOCUMENT VAULT.
 *
 * Two kinds of document, one table (hr_documents):
 *  - ON A PERSON'S FILE (user_id): ID, contract, certificate, sick note... visibility `employee` (the person and their
 *    managers) or `managers` (managers only). Managers = agency admins + directors of the person's centres + the file
 *    manager, EXCEPT sick notes: own + directors + admins only (the injuries rule), never the file manager alone.
 *  - COMPANY DOCUMENTS (folder_id): the folder decides who sees them - `admins` (agency admins; every new folder starts
 *    here), `everyone` (all the agency's staff) or `people` (chosen people + admins). Read receipts for everyone who
 *    can see one; `requires_accept` adds an "I have read and accept" button.
 *
 * FILES: ProtectedMedia folder `hr-documents/<agency>/` - never served without a signature, and a signed URL is only
 * put in a response the caller may see (HrDocsController::open). Nothing in a list carries a path.
 * SICK NOTES carry no free text: the picture/PDF the person chose, the dates it covers and the request it belongs to.
 *
 * REMINDERS (hr:file-tasks, daily stamp per step in hr_document_alerts, the CertExpiryAlerts pattern): an expiry date
 * at 30 and 7 days before and on the day; a follow-up date 7 days before and on the day, bell + email. Person docs go
 * to the person (when they can see it, expiry only) and their managers (HrTalent::managersOf); company docs to the
 * agency admins. Test agencies send nothing.
 */
final class HrDocs
{
    public const FOLDER = 'hr-documents';

    public const TYPES = [
        'id' => 'Identification', 'contract' => 'Contract', 'certificate' => 'Certificate', 'work_permit' => 'Work permit',
        'tax' => 'Tax form', 'training' => 'Training record', 'letter' => 'Letter', 'sick_note' => 'Sick note',
        'medical' => 'Medical / fitness note', 'policy' => 'Policy', 'handbook' => 'Handbook', 'leave_note' => 'Time-off note', 'other' => 'Other',
    ];

    public const FILE_RULE = 'file|mimes:pdf,jpg,jpeg,png,webp,heic,heif,doc,docx,xls,xlsx,txt|max:15360';

    public const EXPIRY_STEPS = [30, 7, 0];

    public const FOLLOW_STEPS = [7, 0];

    /** Store an uploaded file under the protected folder. @return array{file_path:string,file_name:string,file_mime:string,file_size:int} */
    public static function put(int $agencyId, UploadedFile $f): array
    {
        $ext = strtolower($f->getClientOriginalExtension() ?: ($f->extension() ?: 'bin'));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $name = (string) Str::uuid() . '.' . $ext;
        Storage::disk('public')->putFileAs(self::FOLDER . '/' . $agencyId, $f, $name);
        $orig = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '', (string) $f->getClientOriginalName()) ?: ('document.' . $ext), 0, 170);

        return ['file_path' => '/storage/' . self::FOLDER . '/' . $agencyId . '/' . $name, 'file_name' => $orig,
            'file_mime' => (string) ($f->getMimeType() ?: $f->getClientMimeType() ?: 'application/octet-stream'), 'file_size' => (int) $f->getSize()];
    }

    public static function titleFrom(string $fileName): string
    {
        $t = trim((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $fileName));

        return mb_substr($t !== '' ? str_replace(['_', '-'], ' ', $t) : 'Document', 0, 200);
    }

    /** The plain facts of a document for a list (never a path or URL). */
    public static function shape(object $d, array $names = []): array
    {
        return ['id' => (int) $d->id, 'user_id' => $d->user_id ? (int) $d->user_id : null, 'folder_id' => $d->folder_id ? (int) $d->folder_id : null,
            'doc_type' => $d->doc_type, 'type_label' => self::TYPES[$d->doc_type] ?? 'Other', 'title' => $d->title, 'file_name' => $d->file_name,
            'file_mime' => $d->file_mime, 'file_size' => (int) $d->file_size, 'visibility' => $d->visibility, 'requires_accept' => (bool) $d->requires_accept,
            'expires_on' => $d->expires_on, 'follow_up_on' => $d->follow_up_on, 'follow_up_note' => $d->follow_up_note,
            'time_off_request_id' => $d->time_off_request_id ? (int) $d->time_off_request_id : null, 'covers_from' => $d->covers_from, 'covers_to' => $d->covers_to,
            'uploaded_by' => $d->uploaded_by_id ? ($names[(int) $d->uploaded_by_id] ?? null) : null, 'created_at' => $d->created_at,
            'previewable' => (bool) preg_match('#^(image/(png|jpe?g|webp|gif)|application/pdf)$#i', (string) $d->file_mime)];
    }

    /** Is this company folder open to this person? Admins see every folder. */
    public static function folderOpenTo(object $folder, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) return true;
        if ($folder->visibility === 'everyone') return true;
        if ($folder->visibility === 'people') {
            return in_array($userId, array_map('intval', (array) (json_decode((string) $folder->people_ids, true) ?: [])), true);
        }

        return false;
    }

    /** Everyone a folder is shown to (for read receipts): the agency's staff it reaches, never an outsider. @return int[] */
    public static function audience(int $agencyId, object $folder): array
    {
        $staff = HrLeave::staffIds($agencyId);
        $admins = DB::table('role_assignments')->whereIn('user_id', $staff ?: [0])->where('active', 1)->where('role', 'agency_admin')
            ->where('agency_id', $agencyId)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        if ($folder->visibility === 'everyone') return $staff;
        if ($folder->visibility === 'people') {
            $chosen = array_map('intval', (array) (json_decode((string) $folder->people_ids, true) ?: []));

            return array_values(array_unique(array_merge(array_intersect($staff, $chosen), $admins)));
        }

        return array_values(array_unique($admins));
    }

    /** Company folders this person can open, with their documents and this person's receipts. */
    public static function companyFor(int $agencyId, int $userId, bool $isAdmin): array
    {
        $folders = DB::table('hr_doc_folders')->where('agency_id', $agencyId)->whereNull('archived_at')->orderBy('name')->get()
            ->filter(fn ($f) => self::folderOpenTo($f, $userId, $isAdmin))->values();
        $docs = DB::table('hr_documents')->where('agency_id', $agencyId)->whereNull('user_id')->whereNull('archived_at')
            ->whereIn('folder_id', $folders->pluck('id')->all() ?: [0])->orderBy('title')->get();
        $rc = DB::table('hr_document_receipts')->where('user_id', $userId)->whereIn('document_id', $docs->pluck('id')->all() ?: [0])->get()->keyBy('document_id');

        return $folders->map(fn ($f) => ['id' => (int) $f->id, 'name' => $f->name, 'visibility' => $f->visibility,
            'documents' => $docs->where('folder_id', $f->id)->values()->map(fn ($d) => self::shape($d) + [
                'read_at' => $rc[$d->id]->read_at ?? null, 'accepted_at' => $rc[$d->id]->accepted_at ?? null])->all()])->all();
    }

    public static function markRead(int $docId, int $userId): void
    {
        DB::table('hr_document_receipts')->insertOrIgnore(['document_id' => $docId, 'user_id' => $userId, 'read_at' => now()]);
        DB::table('hr_document_receipts')->where('document_id', $docId)->where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    // ── reminders ─────────────────────────────────────────────────────────────────────

    /** The step a date is at today: 'x30','x7','x0' / 'f7','f0', or null. Missed days still get the step they are in. */
    public static function stepFor(string $prefix, array $steps, int $daysLeft): ?string
    {
        if ($daysLeft < 0) return null;
        sort($steps);
        foreach ($steps as $s) {
            if ($daysLeft <= $s) return $prefix . $s;
        }

        return null;
    }

    /** What would go out today for one agency: [{doc, kind expiry|follow, step, days, to_ids}]. */
    public static function plan(int $agencyId): array
    {
        $today = AgencyTime::todayDate($agencyId)->startOfDay();
        $docs = DB::table('hr_documents')->where('agency_id', $agencyId)->whereNull('archived_at')
            ->where(fn ($w) => $w->whereNotNull('expires_on')->orWhereNotNull('follow_up_on'))->get();
        if ($docs->isEmpty()) return [];
        $sent = DB::table('hr_document_alerts')->whereIn('document_id', $docs->pluck('id')->all())->get(['document_id', 'step_key'])
            ->map(fn ($r) => $r->document_id . '|' . $r->step_key)->flip()->all();
        $staff = array_flip(HrLeave::staffIds($agencyId));
        $admins = null;
        $out = [];
        foreach ($docs as $d) {
            foreach ([['expiry', $d->expires_on, 'x', self::EXPIRY_STEPS], ['follow', $d->follow_up_on, 'f', self::FOLLOW_STEPS]] as [$kind, $date, $px, $steps]) {
                if (! $date) continue;
                $days = (int) $today->diffInDays(Carbon::parse((string) $date)->startOfDay(), false);
                $step = self::stepFor($px, $steps, $days);
                if ($step === null || isset($sent[$d->id . '|' . $step])) continue;
                if ($d->user_id) {
                    if (! isset($staff[(int) $d->user_id])) continue;   // left: nobody chases a leaver's paperwork
                    $to = HrTalent::managersOf($agencyId, (int) $d->user_id);
                    if ($kind === 'expiry' && $d->visibility === 'employee' && $d->doc_type !== 'sick_note') $to[] = (int) $d->user_id;
                } else {
                    $admins ??= CentreScope::managers($agencyId, [])->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
                    $to = $admins;
                }
                $to = array_values(array_unique(array_intersect(array_map('intval', $to), array_keys($staff))));
                if ($to) $out[] = ['doc' => $d, 'kind' => $kind, 'step' => $step, 'days' => $days, 'to_ids' => $to];
            }
        }

        return $out;
    }

    /** Send (or, $dry, only count) one agency's reminders. Returns [bells, emails]. */
    public static function remind(int $agencyId, bool $dry = false): array
    {
        $items = self::plan($agencyId);
        if (! $items) return [0, 0];
        $agency = (string) DB::table('agencies')->where('id', $agencyId)->value('name');
        $people = DB::table('users')->whereIn('id', array_merge(...array_map(fn ($i) => array_merge($i['to_ids'], [(int) $i['doc']->user_id]), $items)) ?: [0])
            ->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');
        $bells = 0; $mails = 0;
        foreach ($items as $it) {
            $d = $it['doc'];
            $owner = $d->user_id ? ($people[(int) $d->user_id] ?? null) : null;
            $when = $it['days'] === 0 ? 'today' : 'in ' . $it['days'] . ' day' . ($it['days'] === 1 ? '' : 's');
            foreach ($it['to_ids'] as $uid) {
                $u = $people[$uid] ?? null;
                if (! $u) continue;
                $mine = $owner && (int) $owner->id === $uid;
                $whose = $mine ? 'Your ' : ($owner ? trim($owner->first_name . ' ' . $owner->last_name) . ': ' : 'Company document: ');
                $title = $it['kind'] === 'expiry' ? '📄 ' . $whose . $d->title . ($it['days'] === 0 ? ' expires today' : ' expires ' . $when)
                    : '📄 Follow up ' . $when . ': ' . ($owner ? trim($owner->first_name . ' ' . $owner->last_name) . ' - ' : '') . $d->title;
                $hash = $mine ? 'my-hr' : ($d->user_id ? 'hr?tab=people&person=' . (int) $d->user_id : 'hr?tab=docs');
                if ($dry) { $bells++; continue; }
                Notify::write(['agency_id' => $agencyId, 'user_id' => $uid, 'type' => 'hr',
                    'title' => mb_substr($title, 0, 180), 'body' => $it['kind'] === 'follow' && $d->follow_up_note ? (string) $d->follow_up_note : 'Open it in KiddieTrac HR.',
                    'data' => json_encode(['hash' => $hash, 'kind' => $mine ? 'own' : 'team', 'hr_document_id' => (int) $d->id]), 'created_at' => now()]);
                $bells++;
                $wants = $mine || StaffNotifyPrefs::wants($uid, 'staff_hr', 'email', $agencyId);
                if ($wants && filter_var((string) $u->email, FILTER_VALIDATE_EMAIL) && ! TestAgency::blocks($agencyId, $uid)) {
                    $mails += self::mail($agencyId, $agency, $u, $title, $it, $d, $owner);
                }
            }
            if (! $dry) DB::table('hr_document_alerts')->insertOrIgnore(['document_id' => (int) $d->id, 'step_key' => $it['step'], 'sent_at' => now()]);
        }

        return [$bells, $mails];
    }

    private static function mail(int $aid, string $agency, object $u, string $title, array $it, object $d, ?object $owner): int
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $date = Carbon::parse((string) ($it['kind'] === 'expiry' ? $d->expires_on : $d->follow_up_on))->format('l, F j, Y');
        $who = $owner ? ((int) $owner->id === (int) $u->id ? 'your file' : $e($owner->first_name) . '\'s file') : 'the company documents';
        $body = '<p style="margin:0 0 12px;font-size:15px;">Hi ' . $e($u->first_name ?: 'there') . ',</p>'
            . '<p style="margin:0 0 12px;font-size:15px;">' . ($it['kind'] === 'expiry'
                ? 'The document <b>' . $e($d->title) . '</b> on ' . $who . ' expires on <b>' . $e($date) . '</b>.'
                : 'There is a follow-up due on <b>' . $e($date) . '</b> for <b>' . $e($d->title) . '</b> on ' . $who . '.') . '</p>'
            . ($it['kind'] === 'follow' && $d->follow_up_note ? '<p style="margin:0 0 12px;font-size:14px;">Note: ' . $e($d->follow_up_note) . '</p>' : '')
            . '<p style="margin:0;font-size:14px;">Open KiddieTrac &rarr; ' . ($owner && (int) $owner->id === (int) $u->id ? 'My HR' : 'HR') . ' to see it.</p>';
        $html = \App\Services\EmailTemplate::wrap($aid, $body, ['eyebrow' => 'HR DOCUMENTS', 'title' => $it['kind'] === 'expiry' ? 'A document is expiring' : 'A follow-up is due', 'subtitle' => $agency]);
        try {
            \App\Services\AgencyMailer::forAgency($aid)->html($html, fn ($m) => $m->to((string) $u->email)->subject(trim(preg_replace('/^\S+\s/u', '', $title))));

            return 1;
        } catch (\Throwable $ex) {
            report($ex);

            return 0;
        }
    }
}
