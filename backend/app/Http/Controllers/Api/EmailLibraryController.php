<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\EmailCatalogue;
use App\Support\EmailOverrides;
use App\Support\EmailPreviews;
use App\Support\EmailTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Email templates: every email, previewable, and nearly all of them editable
 * (2026-10-06).
 *
 * Anthony: "the edit and preview under email templates should be a popup instead of a
 * new page - fully sweep and i don't see all email templates being able to edit and
 * preview only some?"
 *
 * The registry templates (App\Support\EmailTemplates, provider-welcome) keep their own
 * section editors on AdminController. This controller covers the rest of the catalogue:
 *
 *   GET  /admin/email-library              every email, grouped, with how it is edited
 *   GET  /admin/email-library/{key}        the wording fields for one email
 *   PUT  /admin/email-library/{key}        save (an empty set resets to the default)
 *   POST /admin/email-library/{key}/preview  build it with sample data (EmailPreviews)
 *   POST /admin/email-library/{key}/test     build it and send it to a typed address
 *
 * Scope: the ACTIVE agency, resolved exactly as AdminController::getAgencyId does, and
 * failing closed when there is none. Platform emails (EmailCatalogue::PLATFORM_KEYS) are
 * worded once for everybody: anyone with this screen can preview them, only a platform
 * admin can change them.
 */
final class EmailLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        $isPlatform = $this->isPlatformAdmin($request);

        $groups = [];
        foreach (EmailCatalogue::GROUPS as $g => $label) {
            $groups[$g] = ['key' => $g, 'label' => $label, 'emails' => []];
        }
        $counts = ['total' => 0, 'editable' => 0, 'previewable' => 0, 'customised' => 0];
        foreach (EmailCatalogue::all() as $e) {
            $key = $e['key'];
            $mode = EmailCatalogue::editMode($key);
            $platform = EmailOverrides::isPlatform($key);
            $registry = $e['registry'] ?? null;
            $customised = false;
            try {
                if ($mode === 'sections' && $registry) {
                    $customised = EmailTemplates::isCustomised($agencyId, $registry);
                } elseif ($mode === 'basic') {
                    $customised = EmailOverrides::isCustomised($agencyId, $key);
                }
            } catch (\Throwable $ex) {
            }
            $canEdit = in_array($mode, ['sections', 'basic'], true) && (! $platform || $isPlatform);
            $preview = $mode === 'sections' ? true : EmailPreviews::has($key);
            $row = [
                'key' => $key,
                'name' => $e['name'],
                'fires' => $e['fires'] ?? '',
                'to' => $e['to'] ?? '',
                'subject' => $e['subject'] ?? '',
                'audience' => $e['audience'],
                'edit' => $mode,
                'registry' => $registry,
                'platform' => $platform,
                'can_edit' => $canEdit,
                'can_preview' => $preview,
                'customised' => $customised,
                'reason' => $mode === 'none' ? (EmailCatalogue::PREVIEW_ONLY[$key] ?? null)
                    : ($platform && ! $isPlatform && $mode !== 'per-event'
                        ? 'KiddieTrac-wide email: only a platform admin can change its wording.' : null),
                'link' => EmailCatalogue::PER_EVENT[$key] ?? null,
                'variants' => EmailPreviews::VARIANTS[$key] ?? null,
                'shared_channel' => in_array($key, ['checkin-reminder', 'absence-reported', 'absence-reported-office', 'check-event', 'safe-arrival', 'safe-arrival-staff'], true),
            ];
            $groups[EmailCatalogue::groupOf($e)]['emails'][] = $row;
            $counts['total']++;
            $counts['editable'] += $canEdit ? 1 : 0;
            $counts['previewable'] += $preview ? 1 : 0;
            $counts['customised'] += $customised ? 1 : 0;
        }
        foreach ($groups as &$g) {
            usort($g['emails'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($g);

        return response()->json([
            'groups' => array_values(array_filter($groups, fn ($g) => $g['emails'])),
            'counts' => $counts,
            'is_platform_admin' => $isPlatform,
        ]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        [$agencyId, $err] = $this->scope($request, $key, false);
        if ($err) {
            return $err;
        }
        $e = EmailCatalogue::find($key);

        return response()->json([
            'key' => $key,
            'label' => $e['name'],
            'fires' => $e['fires'] ?? '',
            'to' => $e['to'] ?? '',
            'platform' => EmailOverrides::isPlatform($key),
            'fields' => EmailOverrides::fields($key),
            'blocks' => EmailOverrides::blocks($agencyId, $key),
            'defaults' => EmailOverrides::defaults($key),
            'merge_tags' => EmailOverrides::tags($key),
            'variants' => EmailPreviews::VARIANTS[$key] ?? null,
            'customised' => EmailOverrides::isCustomised($agencyId, $key),
        ]);
    }

    public function save(Request $request, string $key): JsonResponse
    {
        [$agencyId, $err] = $this->scope($request, $key, true);
        if ($err) {
            return $err;
        }
        $blocks = (array) $request->input('blocks', []);
        EmailOverrides::store($agencyId, $key, $blocks);
        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'agency_id' => EmailOverrides::isPlatform($key) ? null : $agencyId,
            'action' => 'agency.email_wording_updated',
            'entity_type' => 'email',
            'entity_id' => null,
            'payload' => json_encode(['email' => $key, 'fields' => array_keys(EmailOverrides::values($agencyId, $key)),
                'platform' => EmailOverrides::isPlatform($key)]),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => EmailOverrides::values($agencyId, $key) ? 'Saved.' : 'Reset to the default wording.',
            'customised' => EmailOverrides::isCustomised($agencyId, $key),
        ]);
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        [$agencyId, $err] = $this->scope($request, $key, false, true);
        if ($err) {
            return $err;
        }
        $draft = $request->has('blocks') && EmailCatalogue::editMode($key) === 'basic' ? (array) $request->input('blocks') : null;
        $r = EmailPreviews::render($agencyId, $key, $draft, $request->input('variant'));
        if (! ($r['ok'] ?? false)) {
            return response()->json(['message' => 'This preview could not be built: ' . ($r['error'] ?? 'unknown error')], 422);
        }
        $mailer = \App\Services\AgencyMailer::forAgency($agencyId);
        $blocks = $draft ?? EmailOverrides::values($agencyId, $key);

        return response()->json([
            'html' => $r['html'],
            'subject' => $r['subject'],
            'from' => ['name' => $mailer->fromName(), 'address' => $mailer->fromAddress()],
            'attachments' => $r['attachments'] ?? [],
            'how' => $r['how'] ?? 'built',
            'tags' => $this->tagsIn($key, $blocks),
        ]);
    }

    public function test(Request $request, string $key): JsonResponse
    {
        [$agencyId, $err] = $this->scope($request, $key, false, true);
        if ($err) {
            return $err;
        }
        /* The address is REQUIRED and goes exactly where it was typed — a test never
           falls back to anybody. */
        $to = strtolower(trim((string) $request->input('to')));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Enter the email address to send the test to.'], 422);
        }
        $draft = $request->has('blocks') && EmailCatalogue::editMode($key) === 'basic' ? (array) $request->input('blocks') : null;
        $r = EmailPreviews::render($agencyId, $key, $draft, $request->input('variant'));
        if (! ($r['ok'] ?? false)) {
            return response()->json(['message' => 'This email could not be built: ' . ($r['error'] ?? 'unknown error')], 422);
        }
        $subject = '[Test] ' . $r['subject'];
        $html = (string) $r['html'];
        $before = (int) DB::table('email_logs')->max('id');
        /* Through the agency's own mailer — the From and transport the real email uses —
           and with no suppression bypass: an agency whose email is switched off gets an
           honest "held back". */
        try {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject, $agencyId) {
                $m->to($to)->subject($subject);
                \App\Support\MailScope::agency($m, $agencyId);
            });
            $o = $this->outcome($before, $to);
        } catch (\Throwable $e) {
            $o = ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 300)];
        }
        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'agency_id' => $agencyId,
            'action' => 'agency.email_template_test',
            'entity_type' => 'email',
            'entity_id' => null,
            'payload' => json_encode(['email' => $key, 'to' => $to, 'status' => $o['status'], 'used_draft' => $draft !== null]),
            'created_at' => now(),
        ]);
        $msg = $o['status'] === 'sent' ? 'Test sent to ' . $to : 'Not sent to ' . $to . ' — ' . ($o['reason'] ?? '');

        return response()->json(['sent' => $o['status'] === 'sent', 'status' => $o['status'], 'reason' => $o['reason'] ?? null,
            'message' => $msg], $o['status'] === 'failed' ? 502 : 200);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** [agencyId, error response]. Fails closed on no agency, unknown key, or no right. */
    private function scope(Request $request, string $key, bool $writing, bool $anyMode = false): array
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) {
            return [null, response()->json(['message' => 'No agency access'], 403)];
        }
        $e = EmailCatalogue::find($key);
        if (! $e) {
            return [null, response()->json(['message' => 'Unknown email'], 404)];
        }
        $mode = EmailCatalogue::editMode($key);
        if (! $anyMode && $mode !== 'basic') {
            return [null, response()->json(['message' => $mode === 'sections'
                ? 'This email has its own section editor.' : 'This email cannot be worded here.'], 422)];
        }
        if ($anyMode && $mode === 'sections') {
            return [null, response()->json(['message' => 'This email has its own section editor.'], 422)];
        }
        if ($writing && EmailOverrides::isPlatform($key) && ! $this->isPlatformAdmin($request)) {
            return [null, response()->json(['message' => 'Only a platform admin can change KiddieTrac-wide emails.'], 403)];
        }

        return [$agencyId, null];
    }

    private function tagsIn(string $key, array $blocks): array
    {
        $known = EmailOverrides::tags($key);
        $used = [];
        foreach ($blocks as $v) {
            if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', (string) $v, $m)) {
                $used = array_merge($used, $m[1]);
            }
        }
        $used = array_values(array_unique($used));

        return ['used' => $used, 'unknown' => array_values(array_diff($used, $known))];
    }

    /** What the mail layer did with the test, read from email_logs. */
    private function outcome(int $before, string $to): array
    {
        $row = DB::table('email_logs')->where('id', '>', $before)
            ->where('to_email', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $to) . '%')
            ->orderByDesc('id')->first(['id', 'status', 'error']);
        if (! $row) {
            return ['status' => 'unknown', 'reason' => 'The mail layer recorded nothing for this message, so it cannot be confirmed as sent.'];
        }
        $status = in_array($row->status, ['sent', 'suppressed', 'failed'], true) ? $row->status : 'failed';
        $reason = $status === 'sent' ? null : (string) ($row->error ?: ($status === 'suppressed'
            ? 'Held back by this agency’s email settings.' : 'Delivery failed.'));

        return ['status' => $status, 'reason' => $reason === null ? null : mb_substr($reason, 0, 300)];
    }

    private function isPlatformAdmin(Request $request): bool
    {
        return \App\Support\UserRoles::has($request, 'platform_admin');
    }

    /** The active agency — the same rule as AdminController::getAgencyId. */
    private function agencyId(Request $request): ?int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($this->isPlatformAdmin($request)) {
            return ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) ? $activeId : null;
        }
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')->where('agency_id', $activeId)->where('active', true)->exists()) {
            return $activeId;
        }
        $id = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')->where('active', true)->value('agency_id');

        return $id ? (int) $id : null;
    }
}
