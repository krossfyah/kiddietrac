<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v22p43 — Custom forms builder.
 *
 * Agency admins + directors define forms with a JSON schema:
 *   schema_json = [
 *     { id, type: 'text'|'textarea'|'email'|'number'|'date'|'select'|'checkbox'|'radio',
 *       label, required?, placeholder?, help?, options?[] }
 *   ]
 * Parents (and optionally staff) submit responses; responses are stored as
 * { field_id: value } JSON.
 *
 * Endpoints:
 *   GET    /admin/forms                 List forms in this agency
 *   POST   /admin/forms                 Create
 *   GET    /admin/forms/{id}            Show + responses count
 *   PATCH  /admin/forms/{id}            Update title/desc/schema/audience/status
 *   DELETE /admin/forms/{id}            Soft delete
 *   GET    /admin/forms/{id}/responses  Paginated list of responses
 *   GET    /forms                       Parent-facing: published forms in audience
 *   POST   /forms/{id}/submit           Parent-facing: submit response
 */
final class FormsController extends Controller
{
    // ── Agency resolver (mirrors AdminController's lazy version) ──────────
    private function getAgencyId(Request $request): ?int
    {
        $userId = $request->user()->id;
        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatform) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) {
                return $activeId;
            }
            return (int) DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->value('id');
        }
        $byAgencyAdmin = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'agency_admin')->where('active', true)->value('agency_id');
        if ($byAgencyAdmin) return (int) $byAgencyAdmin;
        $directorCentre = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'centre_director')->where('active', true)->value('centre_id');
        if ($directorCentre) return (int) DB::table('centres')->where('id', $directorCentre)->value('agency_id');
        return null;
    }

    // ── Admin CRUD ────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['forms' => []]);

        $rows = DB::table('custom_forms')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();
        return response()->json(['forms' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['form' => $row]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'schema' => ['required', 'array', 'min:1'],
            'schema.*.id' => ['required', 'string', 'max:60'],
            'schema.*.type' => ['required', 'in:text,textarea,email,number,date,select,checkbox,radio,signature,payment'],
            'schema.*.label' => ['required', 'string', 'max:200'],
            'schema.*.required' => ['nullable', 'boolean'],
            'schema.*.placeholder' => ['nullable', 'string', 'max:200'],
            'schema.*.help' => ['nullable', 'string', 'max:500'],
            'schema.*.options' => ['nullable', 'array'],
            'schema.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'audience' => ['required', 'in:all_families,active_families,waitlist,prospects,staff'],
            'centre_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $id = DB::table('custom_forms')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'schema_json' => json_encode($data['schema']),
            'audience' => $data['audience'],
            'status' => $data['status'] ?? 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Form saved'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'schema' => ['sometimes', 'array', 'min:1'],
            'audience' => ['sometimes', 'in:all_families,active_families,waitlist,prospects,staff'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ]);
        if (isset($data['schema'])) {
            $data['schema_json'] = json_encode($data['schema']);
            unset($data['schema']);
        }
        $data['updated_at'] = now();
        DB::table('custom_forms')->where('id', $id)->update($data);
        return response()->json(['message' => 'Form updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $row = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        DB::table('custom_forms')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['message' => 'Form deleted']);
    }

    public function listResponses(Request $request, int $id)
    {
        $agencyId = $this->getAgencyId($request);
        $form = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$form) return response()->json(['message' => 'Not found'], 404);
        $rows = DB::table('custom_form_responses as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.submitted_by_user_id')
            ->where('r.form_id', $id)
            ->orderByDesc('r.submitted_at')
            ->limit(2000)
            ->get([
                'r.id', 'r.response_json', 'r.submitted_at',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email, 'unknown') as submitter_name"),
                'u.email as submitter_email',
            ]);

        // v22p44: CSV export — &format=csv streams an Excel-friendly file
        // with one column per schema field plus submitter / timestamp.
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            return $this->streamCsv($form, $rows);
        }

        return response()->json([
            'form' => $form,
            'responses' => $rows,
            'count' => $rows->count(),
        ]);
    }

    /**
     * Stream responses as RFC 4180 CSV. Field order matches the schema's
     * order so columns are consistent run-to-run.
     */
    private function streamCsv(object $form, $rows): StreamedResponse
    {
        $schema = json_decode((string) $form->schema_json, true) ?: [];
        $fieldIds = array_map(fn ($f) => $f['id'] ?? '', $schema);
        $fieldLabels = array_map(fn ($f) => $f['label'] ?? $f['id'] ?? '', $schema);

        $filename = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($form->title ?: 'form'));
        $filename = trim($filename, '-') . '-responses-' . date('Y-m-d') . '.csv';

        return new StreamedResponse(function () use ($rows, $fieldIds, $fieldLabels) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens special characters cleanly
            fwrite($out, "\xEF\xBB\xBF");
            $header = array_merge(['Submitted at', 'Submitter', 'Email'], $fieldLabels);
            fputcsv($out, $header);
            foreach ($rows as $r) {
                $resp = json_decode((string) $r->response_json, true) ?: [];
                $line = [$r->submitted_at, $r->submitter_name, $r->submitter_email];
                foreach ($fieldIds as $fid) {
                    $v = $resp[$fid] ?? '';
                    if (is_array($v)) $v = implode(', ', $v);
                    $line[] = (string) $v;
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    // ── Parent-facing ─────────────────────────────────────────────────────

    /**
     * GET /forms — published forms whose audience matches this caller.
     */
    public function publishedForUser(Request $request): JsonResponse
    {
        $user = $request->user();

        // Resolve which agency this caller belongs to (via guardian/family/centre/agency)
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        if (empty($familyIds)) return response()->json(['forms' => []]);
        $centreIds = DB::table('families')->whereIn('id', $familyIds)->whereNull('deleted_at')->pluck('centre_id')->all();
        if (empty($centreIds)) return response()->json(['forms' => []]);
        $agencyIds = DB::table('centres')->whereIn('id', $centreIds)->pluck('agency_id')->unique()->all();

        $rows = DB::table('custom_forms')
            ->whereIn('agency_id', $agencyIds)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($centreIds) {
                $q->whereNull('centre_id')->orWhereIn('centre_id', $centreIds);
            })
            ->whereIn('audience', ['all_families', 'active_families', 'waitlist', 'prospects'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        // Also indicate whether THIS user has already submitted each one
        $submittedIds = DB::table('custom_form_responses')
            ->whereIn('form_id', $rows->pluck('id'))
            ->where('submitted_by_user_id', $user->id)
            ->pluck('form_id')->all();
        $rows = $rows->map(function ($f) use ($submittedIds) {
            $f->already_submitted = in_array($f->id, $submittedIds);
            return $f;
        });

        return response()->json(['forms' => $rows]);
    }

    /**
     * POST /forms/{id}/submit — record a parent's response.
     */
    /**
     * POST /admin/forms/{id}/email — email a published form's link to the
     * parents in its audience. White-labelled (agency branding + from-address)
     * when the agency has the white-label add-on.
     */
    public function emailToParents(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $form = DB::table('custom_forms')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$form) return response()->json(['message' => 'Form not found'], 404);
        if ($form->status !== 'published') return response()->json(['message' => 'Publish the form before emailing it.'], 422);

        // Recipients depend on the form's AUDIENCE: a 'staff' form goes to educators /
        // directors / home-visitors of the agency; family audiences go to guardians.
        if ($form->audience === 'staff') {
            $staffQ = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->whereIn('ra.role', ['educator', 'centre_director', 'agency_admin', 'home_visitor'])
                ->where('ra.active', true)
                ->whereNull('u.deleted_at')->whereNotNull('u.email');
            if ($form->centre_id) {
                $staffQ->where(function ($w) use ($form, $agencyId) { $w->where('ra.centre_id', $form->centre_id)->orWhere('ra.agency_id', $agencyId); });
            } else {
                $staffQ->where('ra.agency_id', $agencyId);
            }
            $emails = $staffQ->pluck('u.email')->unique()->values()->all();
        } else {
            $familyQuery = DB::table('families')->whereNull('deleted_at');
            if ($form->centre_id) {
                $familyQuery->where('centre_id', $form->centre_id);
            } else {
                $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
                if (empty($centreIds)) return response()->json(['sent' => 0]);
                $familyQuery->whereIn('centre_id', $centreIds);
            }
            $familyIds = $familyQuery->pluck('id')->all();
            if (empty($familyIds)) return response()->json(['sent' => 0]);
            $emails = DB::table('guardians')
                ->join('users', 'users.id', '=', 'guardians.user_id')
                ->whereIn('guardians.family_id', $familyIds)
                ->whereNotNull('users.email')
                ->pluck('users.email')->unique()->values()->all();
        }
        if (empty($emails)) return response()->json(['sent' => 0]);

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $whiteLabel = $agency && !((bool) ($agency->powered_by_visible ?? 1));
        $primary = ($whiteLabel ? ($agency->brand_primary_color ?? null) : null) ?: '#1F6080';
        $orgName = $whiteLabel ? ($agency->name ?? 'KiddieTrac') : 'KiddieTrac';
        $logo = $whiteLabel ? ($agency->brand_logo_url ?? null) : null;
        $link = 'https://app.kiddietrac.com/dashboard.html#forms';
        $subject = $orgName . ' — please complete: ' . $form->title;

        $safeTitle = htmlspecialchars($form->title, ENT_QUOTES);
        $safeDesc = $form->description ? htmlspecialchars($form->description, ENT_QUOTES) : '';
        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:0 auto;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">'
            . '<div style="background:' . $primary . ';color:#fff;padding:22px 26px;">'
            . ($logo ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="" style="max-height:40px;margin-bottom:10px;background:#fff;border-radius:6px;padding:3px 6px;"><br>' : ('<div style="font-weight:800;font-size:18px;margin-bottom:6px;">' . htmlspecialchars($orgName, ENT_QUOTES) . '</div>'))
            . '<div style="font-size:20px;font-weight:800;">' . $safeTitle . '</div>'
            . ($safeDesc ? '<div style="font-size:13px;opacity:.92;margin-top:4px;">' . $safeDesc . '</div>' : '')
            . '</div>'
            . '<div style="padding:24px 26px;color:#374151;font-size:15px;line-height:1.6;">'
            . '<p>Hello,</p><p>Please take a moment to complete this form from ' . htmlspecialchars($orgName, ENT_QUOTES) . '.</p>'
            . '<p style="text-align:center;margin:26px 0;"><a href="' . $link . '" style="background:' . $primary . ';color:#fff;text-decoration:none;padding:12px 26px;border-radius:10px;font-weight:700;display:inline-block;">Open the form</a></p>'
            . '<p style="font-size:13px;color:#6B7280;">Or sign in to your parent portal and open the Forms section.</p>'
            . '</div>'
            . '<div style="padding:14px 26px;text-align:center;font-size:11px;color:#9CA3AF;">' . ($whiteLabel ? htmlspecialchars($orgName, ENT_QUOTES) . ($agency->brand_support_email ? ' · ' . htmlspecialchars($agency->brand_support_email, ENT_QUOTES) : '') : 'Powered by KiddieTrac — The Smart Childcare Management Platform') . '</div>'
            . '</div>';

        $svc = AgencyMailer::forAgency($agencyId);
        $mailer = $svc->mailer();
        $fromAddr = $svc->fromAddress();
        $fromName = $whiteLabel ? $orgName : $svc->fromName();
        $sent = 0;
        foreach ($emails as $email) {
            try {
                $mailer->html($html, function ($m) use ($email, $fromAddr, $fromName, $subject) {
                    $m->to($email)->from($fromAddr, $fromName)->subject($subject);
                });
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Form email failed', ['email' => $email, 'error' => $e->getMessage()]);
            }
        }

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'form.emailed',
            'entity_type' => 'custom_form',
            'entity_id' => $id,
            'payload' => json_encode(['sent' => $sent, 'recipients' => count($emails)]),
            'created_at' => now(),
        ]);

        return response()->json(['sent' => $sent, 'recipients' => count($emails)]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $form = DB::table('custom_forms')->where('id', $id)->whereNull('deleted_at')->where('status', 'published')->first();
        if (!$form) return response()->json(['message' => 'Form not available'], 404);

        $schema = json_decode((string) $form->schema_json, true) ?: [];
        $data = $request->validate(['response' => ['required', 'array']]);
        $response = $data['response'];

        // Coarse validation against the schema's required + type
        foreach ($schema as $field) {
            $fid = $field['id'] ?? null;
            if (!$fid) continue;
            $val = $response[$fid] ?? null;
            if (!empty($field['required']) && ($val === null || $val === '' || $val === [])) {
                return response()->json(['message' => 'Field "' . ($field['label'] ?? $fid) . '" is required.'], 422);
            }
        }

        DB::table('custom_form_responses')->insert([
            'form_id' => $id,
            'submitted_by_user_id' => $request->user()->id,
            'response_json' => json_encode($response),
            'submitted_at' => now(),
        ]);
        DB::table('custom_forms')->where('id', $id)->increment('response_count');

        // v22p85: record any "payment" field charges against the family's account
        // (status pending = owed). Only fires when the respondent authorised it.
        $familyId = DB::table('guardians')->where('user_id', $request->user()->id)->value('family_id');
        if ($familyId) {
            foreach ($schema as $field) {
                if (($field['type'] ?? '') !== 'payment') continue;
                $fid = $field['id'] ?? null;
                $authorised = $fid ? ($response[$fid] ?? null) : null;
                $amount = round((float) ($field['amount'] ?? 0), 2);
                if ($authorised && $amount > 0) {
                    DB::table('payments')->insert([
                        'family_id' => $familyId,
                        'amount' => $amount,
                        'method' => 'manual',
                        'status' => 'pending',
                        'reference_number' => 'FORM-' . $id,
                        'notes' => 'Form charge: ' . $form->title,
                        'created_at' => now(),
                    ]);
                }
            }
        }
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'form.submitted',
            'entity_type' => 'custom_form',
            'entity_id' => $id,
            'payload' => json_encode(['form_title' => $form->title]),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Response saved'], 201);
    }
}
