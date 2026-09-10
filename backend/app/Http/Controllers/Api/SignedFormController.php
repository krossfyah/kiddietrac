<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProtectedMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Fill and sign a form from a TEMPORARY LINK, without signing in.
 *
 * A package email used to say "here are four forms" and send the reader to a login screen.
 * The people least likely to have a working password are exactly the ones being onboarded,
 * so the paperwork stalled at the front door. This is the same device the portal already
 * uses for day feedback and time-off decisions: a signed, expiring URL that IS the
 * capability.
 *
 * What the link can and cannot do, deliberately:
 *   · it opens ONE form for ONE person, both named in the signature — a link cannot be
 *     edited into somebody else's form, because the signature covers the parameters
 *   · it expires (LINK_DAYS), so a forwarded email stops working
 *   · it can fill, sign and submit THAT form and nothing else — no portal, no other
 *     records, no session is created
 *   · once the form is signed it stops accepting submissions, so a link that leaks later
 *     cannot overwrite a signature already on file
 *
 * The signature is a real one: the same filler, the same signature pad and the same
 * flattened PDF as the in-portal route, so a form signed from a link is not a lesser
 * record than one signed in the app.
 */
final class SignedFormController extends Controller
{
    /** Long enough to survive a weekend and a reminder, short enough that a leak dies. */
    public const LINK_DAYS = 14;

    /** The link that goes in the email. */
    public static function linkFor(int $formId, int $userId): string
    {
        return URL::temporarySignedRoute(
            'forms.fill',
            now()->addDays(self::LINK_DAYS),
            ['form' => $formId, 'u' => $userId]
        );
    }

    /**
     * The recipient and form, or null when the pairing is not a real assignment.
     *
     * A valid signature proves the link was issued by us; it does NOT prove the person is
     * still meant to sign this form. Checked again here so revoking an assignment actually
     * revokes it.
     */
    private function resolve(int $formId, int $userId): ?array
    {
        $form = DB::table('managed_forms')->where('id', $formId)->where('active', 1)->first();
        if (! $form) {
            return null;
        }
        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')
            ->first(['id', 'first_name', 'last_name', 'email']);
        if (! $user) {
            return null;
        }

        /* The same rule the in-portal list uses — App\Support\FormAudience. Named
           recipients narrow the form to those people; only a form naming nobody is
           reachable by role, and one with neither is reachable by no one.

           It matters more here than anywhere: this is the no-login link. Whoever
           holds the URL is answered on the strength of this check alone. */
        if (! \App\Support\FormAudience::canUse($form, $userId)) {
            return null;
        }

        return ['form' => $form, 'user' => $user];
    }

    /** GET /forms/fill/{form}/{u} — the page itself. Signed, no login. */
    public function page(Request $request, int $form, int $u)
    {
        $found = $this->resolve($form, $u);
        if (! $found) {
            return response($this->shell(
                'This link is no longer valid',
                'The form may have been withdrawn, or it is no longer assigned to you. Please ask your centre to send it again.'
            ), 404)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $f = $found['form'];
        $user = $found['user'];

        $done = DB::table('managed_form_signoffs')
            ->where('managed_form_id', $form)->where('user_id', $u)
            ->whereNotNull('signed_at')->first(['signed_at']);
        if ($done) {
            return response($this->shell(
                'Already signed',
                'You signed “' . e($f->title) . '” on ' . e((string) $done->signed_at) . '. There is nothing more to do — thank you.'
            ), 200)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $draft = DB::table('managed_form_signoffs')
            ->where('managed_form_id', $form)->where('user_id', $u)
            ->whereNull('signed_at')->value('field_values');

        $cfg = [
            'id'          => (int) $f->id,
            'title'       => (string) $f->title,
            'description' => (string) ($f->description ?? ''),
            'fillable'    => (bool) $f->fillable,
            // Signed media URL: the PDF itself is behind the protected-media route.
            'fileUrl'     => ProtectedMedia::sign($f->file_url),
            'signerName'  => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $user->email,
            'draftValues' => $draft ? (json_decode((string) $draft, true) ?: null) : null,
            'links'       => [
                'submit' => URL::temporarySignedRoute('forms.fill.submit', now()->addDays(self::LINK_DAYS), ['form' => $form, 'u' => $u]),
                'draft'  => URL::temporarySignedRoute('forms.fill.draft', now()->addDays(self::LINK_DAYS), ['form' => $form, 'u' => $u]),
            ],
        ];

        return response($this->fillPage($cfg, $this->brand((int) $f->agency_id)))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** POST /forms/fill/{form}/{u}/draft — save progress. Signed, no login. */
    public function draft(Request $request, int $form, int $u): JsonResponse
    {
        if (! $this->resolve($form, $u)) {
            return response()->json(['message' => 'This link is no longer valid.'], 404);
        }
        $data = $request->validate(['field_values' => ['nullable', 'array']]);

        $existing = DB::table('managed_form_signoffs')
            ->where('managed_form_id', $form)->where('user_id', $u)->whereNull('signed_at')->first(['id']);
        $payload = [
            'field_values' => json_encode($data['field_values'] ?? []),
            'updated_at'   => now(),
        ];
        if ($existing) {
            DB::table('managed_form_signoffs')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('managed_form_signoffs')->insert($payload + [
                'managed_form_id' => $form, 'user_id' => $u, 'created_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /forms/fill/{form}/{u} — the signature. Signed, no login. */
    public function submit(Request $request, int $form, int $u): JsonResponse
    {
        $found = $this->resolve($form, $u);
        if (! $found) {
            return response()->json(['message' => 'This link is no longer valid.'], 404);
        }

        /* Already signed = done, not an error to retry. A leaked link must never be able to
           overwrite a signature that is already on file. */
        $already = DB::table('managed_form_signoffs')
            ->where('managed_form_id', $form)->where('user_id', $u)->whereNotNull('signed_at')->exists();
        if ($already) {
            return response()->json(['message' => 'This form has already been signed.'], 409);
        }

        $data = $request->validate([
            'signature'    => ['required', 'string'],
            /* Loose on purpose: the filler posts multipart (the PDF as a file, the values
               as a JSON string) and the real checks are on the bytes. See
               storeFilledPdf(). */
            'field_values' => ['nullable'],
            'filled_file'  => ['nullable'],
        ]);

        $user = $found['user'];
        /* The same guards the in-portal sign() applies, deliberately duplicated rather than
           loosened: a 20MB ceiling and a real %PDF header, so this route can never write
           whatever was posted to it. A link with no login is the LAST place to relax a
           file check. */
        $filledUrl = $this->storeFilledPdf($request, $form, $u);
        $fieldValues = $this->filledFieldValues($request);

        $row = [
            'signer_name'     => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $user->email,
            'signature'       => mb_substr((string) $data['signature'], 0, 400000),
            'field_values'    => json_encode($fieldValues),
            'filled_file_url' => $filledUrl,
            'signed_at'       => now(),
            /* The address it came from, kept for the same reason the in-portal route keeps
               it: a signature is a record, and a record says where it was made. */
            'ip_address'      => $request->ip(),
            'updated_at'      => now(),
        ];

        $draft = DB::table('managed_form_signoffs')
            ->where('managed_form_id', $form)->where('user_id', $u)->whereNull('signed_at')->first(['id']);
        if ($draft) {
            DB::table('managed_form_signoffs')->where('id', $draft->id)->update($row);
            $signoffId = (int) $draft->id;
        } else {
            $signoffId = (int) DB::table('managed_form_signoffs')->insertGetId($row + [
                'managed_form_id' => $form, 'user_id' => $u, 'created_at' => now(),
            ]);
        }

        /* Filed on the signer's record exactly as an in-portal signature is. A form
           signed from an emailed link is not a lesser record, and it must not be harder
           to find afterwards -- which it would be if only one of the two routes filed. */
        \App\Support\SignedFormFiler::file($signoffId);

        /* The same two letters as the in-portal route. A form signed from an emailed link
           is the case where the receipt matters MOST -- the signer has no account open to
           check, and until now got nothing back at all. */
        \App\Support\FormSubmissionNotice::send($signoffId);

        try {
            \App\Support\Audit::write([
                'user_id'     => (int) $u,
                'action'      => 'managed_form.signed_via_link',
                'entity_type' => 'managed_form',
                'entity_id'   => $form,
                'payload'     => json_encode(['title' => $found['form']->title, 'via' => 'temporary link']),
                'ip_address'  => $request->ip(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true, 'message' => 'Thank you — your form has been submitted.']);
    }


    /**
     * The completed PDF, however it arrived.
     *
     * Two shapes, on purpose. Multipart is what the filler sends now -- a JSON body with
     * the PDF base64'd inside it is capped at ~1MB by this host, which is why a 2.2MB form
     * came back as a bare Apache 413. The base64 field is still accepted so an older
     * cached copy of kt-form-filler.js keeps working through a deploy; drop it once no
     * client sends it.
     *
     * The guards do not move: 20MB and a real %PDF header, checked on the BYTES, whichever
     * route they came in by. A file part is not more trustworthy than a string.
     *
     * @return string|null the stored /storage path, or null when nothing usable arrived
     */
    private function storeFilledPdf(Request $request, int $formId, int $userId): ?string
    {
        $bin = null;

        $up = $request->file('filled_file');
        if ($up && $up->isValid()) {
            $bin = @file_get_contents($up->getRealPath());
        } elseif (is_string($request->input('filled_file')) && $request->input('filled_file') !== '') {
            $bin = base64_decode(
                preg_replace('#^data:application/pdf;base64,#', '', (string) $request->input('filled_file')),
                true
            );
        }

        if ($bin === false || $bin === null || $bin === '' ) {
            return null;
        }
        if (strlen($bin) > 20971520 || ! str_starts_with($bin, '%PDF')) {
            return null;
        }

        $fp = 'managed-forms/filled/' . $formId . '/' . $userId . '-' . time() . '.pdf';
        \Illuminate\Support\Facades\Storage::disk('public')->put($fp, $bin);

        return '/storage/' . $fp;
    }

    /**
     * field_values, whether it came as JSON or as a form field.
     *
     * Form data has no nesting, so the filler sends it as a JSON string. A JSON request
     * still sends a real array. Both mean the same thing and neither should reach the
     * database as the literal text of the other.
     */
    private function filledFieldValues(Request $request): array
    {
        $raw = $request->input('field_values');
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** A plain branded page for the states that are not the form itself. */
    private function shell(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($title) . ' · KiddieTrac</title></head>'
            . '<body style="margin:0;font:15px/1.6 system-ui,-apple-system,sans-serif;background:#F6F9FC;color:#0F172A;">'
            . '<div style="max-width:520px;margin:12vh auto;padding:28px 26px;background:#fff;border:1px solid #E7EDF3;border-radius:16px;">'
            . '<div style="font-size:19px;font-weight:800;margin-bottom:8px;">' . e($title) . '</div>'
            . '<div style="color:#475569;">' . $body . '</div>'
            . '</div></body></html>';
    }

    /**
     * WHERE THE PORTAL LIVES.
     *
     * This used to be config('app.frontend_url'), whose only value in this deployment is
     * the framework's dev default, http://localhost:3000. Nobody noticed, because the
     * config is read in exactly one place and the page it builds is only ever opened by
     * a parent on their own phone -- where the two <script> tags 404ed silently and the
     * button reported "Could not load the form tools. Please check your connection",
     * blaming the reader's network for a server misconfiguration.
     *
     * So: take the configured value only when it is a real https origin that is not
     * loopback, and otherwise use the portal host the rest of the codebase names
     * directly. A dev machine keeps its override; production can no longer inherit one.
     * (Anthony, 2026-09-09)
     */
    private function portalUrl(): string
    {
        $cfg = rtrim((string) config('app.frontend_url', ''), '/');
        if ($cfg !== ''
            && preg_match('~^https://~i', $cfg)
            && ! preg_match('~^https://(localhost|127\.0\.0\.1|\[::1\])(:|/|$)~i', $cfg)) {
            return $cfg;
        }

        return 'https://app.kiddietrac.com';
    }

    /**
     * Whose form is this? -- the mark and colour the page wears.
     *
     * A parent opening a link from their childcare centre should see their centre, not a
     * platform they have never heard of. The agency's stored logo is the same one its
     * emails already carry (EmailTemplate::footer), so the email and the page it leads to
     * look like one another. Falls back to the KiddieTrac wordmark when an agency has
     * uploaded nothing, which is a name rather than a broken image.
     *
     * @return array{name: string, logo: ?string, colour: string}
     */
    private function brand(int $agencyId): array
    {
        $out = ['name' => 'KiddieTrac', 'logo' => null, 'colour' => '#1F6FB2'];
        try {
            $a = DB::table('agencies')->where('id', $agencyId)
                ->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);
            if (! $a) {
                return $out;
            }
            $out['name'] = trim((string) ($a->name ?? '')) ?: 'KiddieTrac';
            $logo = $a->brand_logo_url ?: ($a->logo_url ?: null);
            if ($logo) {
                // Stored relative to the portal host; a page on the API host needs it absolute.
                $out['logo'] = preg_match('~^https?://~i', (string) $logo)
                    ? (string) $logo
                    : $this->portalUrl() . '/' . ltrim((string) $logo, '/');
            }
            $c = trim((string) ($a->brand_primary_color ?? ''));
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) {
                $out['colour'] = $c;
            }
        } catch (\Throwable $e) {
            // Branding is decoration. A form must still open when the lookup fails.
        }

        return $out;
    }

    /** The fill-and-sign page. Loads the SAME filler the portal uses. */
    private function fillPage(array $cfg, array $brand = ['name' => 'KiddieTrac', 'logo' => null, 'colour' => '#1F6FB2']): string
    {
        $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $app = $this->portalUrl();
        $v = date('YmdH');   // cache-bust hourly; the assets are versioned by the deploy too

        /* The agency's mark, at a size that survives a phone. An <img> that fails to load
           would leave a silent gap, so the name sits under it either way. */
        $mark = $brand['logo']
            ? '<img src="' . e((string) $brand['logo']) . '" alt="' . e((string) $brand['name']) . '"'
                . ' style="max-height:52px;max-width:210px;display:block;margin:0 auto 8px;">'
            : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>' . e($cfg['title']) . ' · KiddieTrac</title>'
            . '<style>body{margin:0;font:15px/1.55 system-ui,-apple-system,sans-serif;background:#0D1B2A;color:#fff;}'
            . '.wrap{max-width:560px;margin:0 auto;padding:26px 20px;}'
            . '.card{background:#fff;color:#0F172A;border-radius:16px;padding:22px 24px;}'
            . '.btn{display:block;width:100%;box-sizing:border-box;margin-top:18px;background:linear-gradient(135deg,#0FA3B1,' . e((string) $brand['colour']) . ');'
            . 'color:#fff;border:0;border-radius:12px;padding:15px;font-size:16px;font-weight:800;cursor:pointer;}</style>'
            . '</head><body><div class="wrap" id="wrap">'
            . '<div style="text-align:center;margin-bottom:16px;">' . $mark
            . '<div style="font-weight:800;letter-spacing:.4px;">' . e((string) $brand['name']) . '</div></div>'
            . '<div class="card" id="card">'
            . '<div style="font-size:18px;font-weight:800;">' . e($cfg['title']) . '</div>'
            . ($cfg['description'] !== '' ? '<div style="color:#64748B;margin-top:6px;">' . e($cfg['description']) . '</div>' : '')
            . '<div style="color:#64748B;margin-top:12px;font-size:13.5px;">Signing as <strong>' . e($cfg['signerName']) . '</strong>. '
            . 'This link is personal to you and expires in ' . self::LINK_DAYS . ' days.</div>'
            . '<button class="btn" id="go">' . ($cfg['fillable'] ? 'Fill &amp; sign' : 'Read &amp; sign') . '</button>'
            . '<div id="msg" style="margin-top:12px;font-size:13.5px;color:#B91C1C;"></div>'
            . '</div></div>'
            /* The portal's own modules, loaded from the app host so there is ONE filler,
               one signature pad and one flattening path — not a second implementation that
               drifts. */
            . '<script src="' . $app . '/js/kt-signature-pad.js?v=' . $v . '"></script>'
            . '<script src="' . $app . '/js/kt-form-filler.js?v=' . $v . '"></script>'
            . '<script>(function(){var CFG=' . $json . ';'
            . 'window.KT=window.KT||{};'
            /* THE FILLER ANNOUNCES SUCCESS THROUGH KT.toast, WHICH DOES NOT EXIST HERE.

               kt-form-filler.js is written for the portal, where a toast host is always
               loaded. On this page only the filler and the signature pad are, so the
               success message went nowhere: the overlay closed and the reader was looking
               at the "Fill & sign" button again, with no way to tell whether anything had
               been sent. Anthony hit exactly that on the first real form.

               A minimal shim so nothing the filler says is silently dropped, and — the
               part that matters — the resolved promise is handled below. */
            . 'if(!KT.toast){KT.toast=function(i,t,m){try{console.log("[form]",t,m||"");}catch(e){}};}'
            . 'function done(){'
            .   'var c=document.getElementById("card");if(!c){return;}'
            .   'c.innerHTML=' . json_encode(
                    '<div style="text-align:center;padding:6px 0 2px;">'
                    . '<div style="font-size:44px;line-height:1;">✅</div>'
                    . '<div style="font-size:19px;font-weight:800;margin-top:10px;">Signed and sent</div>'
                    . '<div style="color:#475569;margin-top:8px;line-height:1.55;">'
                    . 'Thank you, ' . e((string) $cfg['signerName']) . '. Your completed copy of '
                    . '<strong>' . e((string) $cfg['title']) . '</strong> has been filed with '
                    . e((string) $brand['name']) . '.</div>'
                    /* Told, not attempted: window.close() only works on a window a script
                       opened, so calling it here would do nothing on the phone this is
                       almost always read on — and a button that does nothing is worse
                       than a sentence that is true. */
                    . '<div style="color:#64748B;margin-top:14px;font-size:13.5px;">'
                    . 'You can close this window. This link will not open the form again.</div>'
                    . '<div style="color:#94A3B8;margin-top:12px;font-size:12.5px;">'
                    . 'A copy is kept in your KiddieTrac account under Documents.</div>'
                    . '</div>',
                    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                ) . ';'
            . '}'
            . 'document.getElementById("go").addEventListener("click",function(){'
            /* Says what actually failed. The old wording blamed the reader's connection for
               what turned out to be a bad asset host, which is the worst kind of error
               message: it sends somebody to fix something that was never broken. */
            . 'if(!(window.KT&&KT.formFiller&&KT.formFiller.open)){document.getElementById("msg").textContent="This form could not start up. Please reload the page \u2014 if it keeps happening, tell your centre and quote form ' . (int) $cfg['id'] . '.";return;}'
            /* open() resolves TRUE only once the signature has actually been accepted by
               the server, so this cannot congratulate somebody whose submission failed —
               a failure keeps the overlay open with its own error, which is right. */
            . 'KT.formFiller.open(CFG).then(function(ok){if(ok){done();}});});'
            . '})();</script>'
            . '</body></html>';
    }
}
