<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\HrLetters;
use App\Support\Notify;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * KiddieTrac HR Phase 3, item 12: HR letter templates (App\Support\HrLetters). Directors (own centres' people) and admins
 * send; only admins edit a template or mark it reviewed by the agency's lawyer. Nothing sends unreviewed.
 */
class HrLetterController extends Controller
{
    use HrAccess;

    private function tpl(int $aid, int $id): object
    {
        $t = DB::table('hr_letter_templates')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($t, 404, 'That template was not found.');

        return $t;
    }

    private function shape(object $t): array
    {
        return ['id' => (int) $t->id, 'kind' => $t->kind, 'title' => $t->title, 'body' => $t->body, 'is_default' => (bool) $t->is_default,
            'reviewed_on' => $t->reviewed_on ? (string) $t->reviewed_on : null, 'reviewed_by' => $t->reviewed_by_id ? ($this->hrNames([(int) $t->reviewed_by_id])[(int) $t->reviewed_by_id] ?? null) : null];
    }

    /** GET /hr/letters */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $people = $this->hrPeople($request, $aid);
        $sent = DB::table('hr_letters as l')->leftJoin('managed_form_signoffs as s', function ($j) {
            $j->on('s.managed_form_id', '=', 'l.managed_form_id')->on('s.user_id', '=', 'l.user_id');
        })->where('l.agency_id', $aid)->whereIn('l.user_id', $people ?: [0])->orderByDesc('l.id')->limit(100)
            ->get(['l.id', 'l.kind', 'l.user_id', 'l.signatory_id', 'l.managed_form_id', 'l.created_at', 's.signed_at']);
        $names = $this->hrNames(array_merge($people, $sent->pluck('signatory_id')->map(fn ($v) => (int) $v)->all()));
        asort($names);

        return response()->json(['is_admin' => $this->hrIsAdmin($request), 'kinds' => HrLetters::KINDS, 'fields' => HrLetters::FIELDS,
            'templates' => array_map(fn ($t) => $this->shape($t), HrLetters::templates($aid)),
            'signatories' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'roles' => $p['roles']], HrLetters::signatories($aid)),
            'people' => collect($names)->only($people)->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->values()->all(),
            'sent' => $sent->map(fn ($l) => ['id' => (int) $l->id, 'kind' => $l->kind, 'title' => HrLetters::KINDS[$l->kind] ?? $l->kind, 'person' => $names[(int) $l->user_id] ?? '',
                'signatory' => $names[(int) $l->signatory_id] ?? '', 'sent_at' => (string) $l->created_at, 'signed_at' => $l->signed_at ? (string) $l->signed_at : null])->all()]);
    }

    /** PUT /hr/letters/templates/{id} (admin) — changing the text clears "reviewed by our lawyer". */
    public function update(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $t = $this->tpl($aid, $id);
        $d = $request->validate(['title' => 'required|string|max:150', 'body' => 'required|string|max:20000']);
        $changed = trim($d['body']) !== trim((string) $t->body) || trim($d['title']) !== trim((string) $t->title);
        DB::table('hr_letter_templates')->where('id', $id)->update(['title' => trim($d['title']), 'body' => trim($d['body']), 'updated_by_id' => $request->user()->id, 'updated_at' => now()]
            + ($changed ? ['is_default' => 0, 'reviewed_on' => null, 'reviewed_by_id' => null] : []));
        $this->hrNote($aid, 'hr.letter_template_saved', 'hr_letter_template', $id, ['kind' => $t->kind, 'review_cleared' => $changed && (bool) $t->reviewed_on]);

        return response()->json(['template' => $this->shape($this->tpl($aid, $id))]);
    }

    /** POST /hr/letters/templates/{id}/review (admin) {reviewed_on: date|null} */
    public function review(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $t = $this->tpl($aid, $id);
        $d = $request->validate(['reviewed_on' => 'nullable|date|before_or_equal:' . \App\Support\AgencyTime::today($aid)], ['reviewed_on.before_or_equal' => 'The review date cannot be in the future.']);
        DB::table('hr_letter_templates')->where('id', $id)->update(['reviewed_on' => $d['reviewed_on'] ?? null, 'reviewed_by_id' => ! empty($d['reviewed_on']) ? $request->user()->id : null, 'updated_at' => now()]);
        $this->hrNote($aid, ! empty($d['reviewed_on']) ? 'hr.letter_template_reviewed' : 'hr.letter_template_unreviewed', 'hr_letter_template', $id, ['kind' => $t->kind, 'reviewed_on' => $d['reviewed_on'] ?? null]);

        return response()->json(['template' => $this->shape($this->tpl($aid, $id))]);
    }

    private function build(Request $request, int $aid): array
    {
        $d = $request->validate(['template_id' => 'required|integer', 'user_id' => 'required|integer', 'signatory_id' => 'required|integer',
            'effective_date' => 'nullable|date', 'details' => 'nullable|string|max:5000']);
        $t = $this->tpl($aid, (int) $d['template_id']);
        $this->hrNeedPerson($request, $aid, (int) $d['user_id']);
        abort_unless(in_array((int) $d['signatory_id'], array_column(HrLetters::signatories($aid), 'id'), true), 422, 'Choose a director or admin of this agency to sign.');
        abort_if((int) $d['signatory_id'] === (int) $d['user_id'], 422, 'Someone else signs for the agency.');
        $v = HrLetters::values($aid, (int) $d['user_id'], (int) $d['signatory_id'], $d);
        $r = HrLetters::render((string) $t->body, $v);

        return [$t, $v, $r, $d];
    }

    /** POST /hr/letters/preview — the merged letter as it will be sent (no draft note), and which fields are empty. */
    public function preview(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        [$t, $v, $r] = $this->build($request, $aid);

        return response()->json(['title' => $t->title, 'html' => $r['html'], 'missing' => array_values(array_intersect_key(HrLetters::FIELDS, array_flip($r['missing']))),
            'blocking' => array_values(array_intersect_key(HrLetters::FIELDS, array_flip($r['blocking']))),
            'person' => $v['full_name'], 'signatory' => $v['signatory_name'], 'signatory_title' => $v['signatory_title'], 'reviewed' => (bool) $t->reviewed_on]);
    }

    /** POST /hr/letters/send — a PDF assigned to the person in Managed Forms (#my-forms), countersigned by a director / admin. */
    public function send(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        [$t, $v, $r, $d] = $this->build($request, $aid);
        abort_unless($t->reviewed_on, 422, 'An agency admin has to mark this template "Reviewed by our lawyer" before it can be sent.');
        abort_if($r['blocking'], 422, 'Fill these in first, or the letter would have a gap: ' . implode(', ', array_intersect_key(HrLetters::FIELDS, array_flip($r['blocking']))) . '.');
        $agency = DB::table('agencies')->where('id', $aid)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);
        $logo = $agency->brand_logo_url ?: ($agency->logo_url ?? null);
        if ($logo && str_starts_with((string) $logo, '/')) $logo = 'https://api.kiddietrac.com' . $logo;
        $html = view('pdf.hr_letter', ['agency' => $agency->name, 'logo' => $logo, 'colour' => $agency->brand_primary_color ?: '#1F6080',
            'address' => $v['centre_address'], 'title' => $t->title, 'today' => $v['today'], 'kind' => $t->kind, 'person' => $v['full_name'],
            'body' => $r['html'], 'signatory' => $v['signatory_name'], 'signatoryTitle' => $v['signatory_title']])->render();
        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter', 'portrait');
        $pdf->render();
        $bytes = $pdf->output();
        // The Managed Forms flow reads its files from the public disk under an unguessable name (as every form there does).
        $path = 'managed-forms/' . $aid . '/hr-letter-' . Str::random(40) . '.pdf';
        Storage::disk('public')->put($path, $bytes);
        $uid = (int) $d['user_id'];
        $formTitle = $t->title . ' — ' . $v['full_name'];
        $fid = DB::transaction(function () use ($aid, $v, $formTitle, $path, $bytes, $request, $uid, $t, $d) {
            $fid = (int) DB::table('managed_forms')->insertGetId(['agency_id' => $aid, 'centre_id' => $v['_centre_id'], 'title' => mb_substr($formTitle, 0, 190),
                'description' => 'From HR: please read this letter and sign it.', 'file_url' => '/storage/' . $path, 'file_type' => 'application/pdf', 'file_size' => strlen($bytes),
                'fillable' => 0, 'reusable' => 0, 'requires_countersign' => 1, 'audiences' => json_encode([]), 'active' => 1,
                'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('managed_form_recipients')->insertOrIgnore(['managed_form_id' => $fid, 'user_id' => $uid, 'created_at' => now()]);
            DB::table('hr_letters')->insert(['agency_id' => $aid, 'template_id' => $t->id, 'kind' => $t->kind, 'user_id' => $uid, 'centre_id' => $v['_centre_id'],
                'signatory_id' => (int) $d['signatory_id'], 'managed_form_id' => $fid, 'sent_by_id' => (int) $request->user()->id, 'created_at' => now()]);

            return $fid;
        });
        $this->hrNote($aid, 'hr.letter_sent', 'managed_form', $fid, ['kind' => $t->kind, 'user_id' => $uid, 'signatory_id' => (int) $d['signatory_id']]);
        try {
            Notify::write(['user_id' => $uid, 'agency_id' => $aid, 'type' => 'hr', 'title' => 'You have a letter to sign: ' . $t->title,
                'body' => 'Open My forms to read and sign it.', 'data' => json_encode(['link' => '#my-forms', 'form_id' => $fid, 'kind' => 'own']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true, 'managed_form_id' => $fid], 201);
    }
}
