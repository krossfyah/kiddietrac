<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Send the files a centre asked for, from a link, with no password.
 *
 * The same device as the fill-and-sign link, for the same reason: the people most often
 * asked for documents — a family being onboarded, a new educator — are exactly the ones
 * least likely to have a working password, and "log in first" is where the paperwork
 * stalls.
 *
 * What the link can and cannot do:
 *   · it opens ONE request for ONE person, both named in the signature
 *   · it expires (LINK_DAYS)
 *   · it can upload against the lines of THAT request and nothing else — no portal, no
 *     other records, no session
 *   · it can never READ anything back. A leaked link could add a file; it could not fetch
 *     one, which is the asymmetry that matters when the files are ID documents.
 */
final class SignedFileUploadController extends Controller
{
    /** Long enough to survive a fortnight of good intentions. */
    public const LINK_DAYS = 21;

    /** 15MB a file: bigger than any phone photo, smaller than a video somebody picked by mistake. */
    private const MAX_BYTES = 15728640;

    private const ALLOWED = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'heic' => 'image/heic', 'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public static function linkFor(int $requestId, int $userId): string
    {
        return URL::temporarySignedRoute(
            'files.upload',
            now()->addDays(self::LINK_DAYS),
            ['req' => $requestId, 'u' => $userId]
        );
    }

    /**
     * The request and the person, or null when the pairing is not real.
     *
     * A valid signature proves we issued the link. It does not prove the request still
     * exists or still belongs to them, so both are checked again here — cancelling a
     * request has to actually cancel it.
     */
    private function resolve(int $requestId, int $userId): ?object
    {
        $r = DB::table('file_requests')->where('id', $requestId)->where('user_id', $userId)
            ->whereIn('status', ['open', 'complete'])
            ->first();
        if (! $r) {
            return null;
        }
        $u = DB::table('users')->where('id', $userId)->whereNull('deleted_at')
            ->first(['id', 'first_name', 'last_name', 'email']);

        return $u ? (object) ['req' => $r, 'user' => $u] : null;
    }

    /** GET /files/upload/{req}/{u} — the page. Signed, no login. */
    public function page(Request $request, int $req, int $u)
    {
        $found = $this->resolve($req, $u);
        if (! $found) {
            return response($this->shell(
                'This link is no longer valid',
                'The request may have been withdrawn. Please ask your centre to send it again.'
            ), 404)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $d = FileRequestController::detail($req);
        $brand = $this->brand((int) $found->req->agency_id);

        $cfg = [
            'id'     => $req,
            'u'      => $u,
            'person' => trim(($found->user->first_name ?? '') . ' ' . ($found->user->last_name ?? ''))
                        ?: (string) $found->user->email,
            'note'   => (string) ($d['note'] ?? ''),
            'due_on' => $d['due_on'] ?? null,
            'priority' => $d['priority'] ?? 'normal',
            /* Who is asking. A page that says only "send your documents" is what a
               phishing page says; a named person at a named centre, with an address the
               reader can check, is what tells them it is their centre. */
            'requestedBy'      => (string) ($d['requested_by'] ?? ''),
            'requestedByEmail' => (string) ($d['requested_by_email'] ?? ''),
            'items'  => array_map(fn ($i) => [
                'id' => $i['id'], 'description' => $i['description'],
                'kind' => $i['kind'], 'quantity' => $i['quantity'],
                'have' => count($i['files']),
            ], $d['items'] ?? []),
            'links'  => [
                'upload' => URL::temporarySignedRoute('files.upload.put', now()->addDays(self::LINK_DAYS),
                    ['req' => $req, 'u' => $u]),
            ],
        ];

        return response($this->uploadPage($cfg, $brand))->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * POST /files/upload/{req}/{u} — one file against one line. Signed, no login.
     *
     * One file per request on purpose: a phone on a bad connection uploading four photos
     * in one body either succeeds completely or loses the lot, and this host caps a body
     * well below four photos anyway.
     */
    public function put(Request $request, int $req, int $u): JsonResponse
    {
        $found = $this->resolve($req, $u);
        if (! $found) {
            return response()->json(['message' => 'This link is no longer valid.'], 404);
        }

        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'file'    => ['required', 'file'],
        ]);

        $item = DB::table('file_request_items')
            ->where('id', $data['item_id'])->where('file_request_id', $req)
            ->first();
        if (! $item) {
            return response()->json(['message' => 'That item is not part of this request.'], 422);
        }

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['message' => 'That file did not arrive in one piece. Please try again.'], 422);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return response()->json([
                'message' => 'That file is larger than 15 MB. Please send a smaller copy — a photo rather than a scan usually does it.',
            ], 422);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (! isset(self::ALLOWED[$ext])) {
            return response()->json([
                'message' => 'That kind of file cannot be accepted. Please send a PDF, a photo (JPG/PNG/HEIC) or a Word document.',
            ], 422);
        }

        /* ALREADY ENOUGH. Without this a leaked link could fill the disk one file at a
           time against a request that was satisfied weeks ago. */
        $have = DB::table('documents')->where('source_type', FileRequestController::SOURCE)
            ->where('source_id', $item->id)->count();
        if ($have >= (int) $item->quantity) {
            return response()->json([
                'message' => 'That item already has the ' . (int) $item->quantity . ' file(s) it needed.',
            ], 409);
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $file->getClientOriginalName());
        $path = 'file-requests/' . $req . '/' . $item->id . '-' . time() . '-' . substr(md5((string) mt_rand()), 0, 6) . '.' . $ext;
        Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));

        /* FILED ON THE PERSON'S RECORD, not in a table only this feature can read. That is
           what puts it in their own Documents screen and on their record for whoever
           manages them, with no further code. See SignedFormFiler for the same pattern. */
        DB::table('documents')->insert([
            'scope_type'     => 'user',
            'scope_id'       => (int) $u,
            'category'       => FileRequestController::CATEGORY,
            'source_type'    => FileRequestController::SOURCE,
            'source_id'      => (int) $item->id,
            'title'          => mb_substr($item->description . ' — ' . $safe, 0, 200),
            'file_url'       => '/storage/' . $path,
            'file_type'      => mb_substr(self::ALLOWED[$ext], 0, 20),
            'file_size'      => $file->getSize(),
            'uploaded_by_id' => (int) $u,
            'created_at'     => now(),
        ]);

        try {
            Audit::write([
                'user_id'     => (int) $u,
                'agency_id'   => (int) $found->req->agency_id,
                'action'      => 'file_request.uploaded',
                'entity_type' => 'file_request',
                'entity_id'   => $req,
                'payload'     => json_encode([
                    'item' => $item->description,
                    'file' => $safe,
                    'size' => $file->getSize(),
                    'via'  => 'temporary link',
                ]),
                'ip_address'  => $request->ip(),
            ]);
        } catch (\Throwable $e) {
        }

        // Flips the request to complete and tells the office, once, when the last one lands.
        FileRequestController::refreshStatus($req);

        $d = FileRequestController::detail($req);

        return response()->json([
            'ok'       => true,
            'received' => $d['received'] ?? 0,
            'asked'    => $d['asked'] ?? 0,
            'complete' => ($d['status'] ?? 'open') === 'complete',
            'items'    => array_map(fn ($i) => [
                'id' => $i['id'], 'have' => count($i['files']), 'quantity' => $i['quantity'],
            ], $d['items'] ?? []),
        ]);
    }

    /* ───────────────────────── the page ───────────────────────── */

    private function portalUrl(): string
    {
        $cfg = rtrim((string) config('app.frontend_url', ''), '/');
        if ($cfg !== '' && preg_match('~^https://~i', $cfg)
            && ! preg_match('~^https://(localhost|127\.0\.0\.1|\[::1\])(:|/|$)~i', $cfg)) {
            return $cfg;
        }

        return 'https://app.kiddietrac.com';
    }

    /** @return array{name: string, logo: ?string, colour: string} */
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
                $out['logo'] = preg_match('~^https?://~i', (string) $logo)
                    ? (string) $logo
                    : $this->portalUrl() . '/' . ltrim((string) $logo, '/');
            }
            $c = trim((string) ($a->brand_primary_color ?? ''));
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) {
                $out['colour'] = $c;
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /** Two letters for the avatar disc, or one when there is only one word. */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));
        if (! $parts) {
            return '?';
        }
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';

        return $first . $last;
    }

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
     * ONE BUTTON PER THING ASKED FOR.
     *
     * Not a single "attach files" box. A person handed one box has to work out what to put
     * in it and in what order, and the centre then has to work out what they got. A row per
     * line, each with its own button and its own tick, means both sides are reading the
     * same list — and the page can say "2 of 3" without anybody counting.
     */
    private function uploadPage(array $cfg, array $brand): string
    {
        $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $mark = $brand['logo']
            ? '<img src="' . e((string) $brand['logo']) . '" alt="' . e((string) $brand['name']) . '"'
                . ' style="max-height:52px;max-width:210px;display:block;margin:0 auto 8px;">'
            : '';
        $accept = '.pdf,.jpg,.jpeg,.png,.heic,.webp,.doc,.docx';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>Send your documents · ' . e((string) $brand['name']) . '</title>'
            . '<style>'
            . 'body{margin:0;font:15px/1.55 system-ui,-apple-system,sans-serif;background:#0D1B2A;color:#fff;}'
            . '.wrap{max-width:620px;margin:0 auto;padding:26px 18px 60px;}'
            . '.card{background:#fff;color:#0F172A;border-radius:16px;padding:20px 22px;}'
            . '.row{border:1.5px solid #E2E8F0;border-radius:12px;padding:14px;margin-bottom:11px;}'
            . '.row.done{border-color:#BBF7D0;background:#F0FDF4;}'
            . '.btn{display:inline-block;background:linear-gradient(135deg,#0FA3B1,' . e((string) $brand['colour']) . ');'
            . 'color:#fff;border:0;border-radius:10px;padding:11px 18px;font-size:14px;font-weight:800;cursor:pointer;}'
            . '.btn[disabled]{opacity:.5;cursor:default;}'
            . '.tick{color:#16A34A;font-weight:800;}'
            . '.files{margin:8px 0 0;font-size:12.5px;color:#475569;}'
            . '.bar{height:8px;border-radius:99px;background:#E2E8F0;overflow:hidden;margin:10px 0 2px;}'
            . '.bar>i{display:block;height:100%;background:#16A34A;width:0;transition:width .25s;}'
            . '</style></head><body><div class="wrap">'
            . '<div style="text-align:center;margin-bottom:16px;">' . $mark
            . '<div style="font-weight:800;letter-spacing:.4px;">' . e((string) $brand['name']) . '</div></div>'
            . '<div class="card">'
            . '<div style="font-size:19px;font-weight:800;">Send your documents</div>'
            . '<div style="color:#64748B;margin-top:6px;font-size:13.5px;">Hello ' . e((string) $cfg['person'])
            . ' — please attach the items below. You can do them one at a time, and come back later if you need to.</div>'

            /* WHO ASKED, ON THE PAGE ITSELF.

               The reader arrived from an email; the page has to stand on its own once they
               are on it. Their name, the centre, and a real address to reply to — the same
               three facts the email led with, so the two corroborate each other rather
               than each asking to be trusted alone. */
            . ($cfg['requestedBy'] !== ''
                ? '<div style="display:flex;align-items:center;gap:11px;background:#F8FAFC;border:1px solid #E7EDF3;'
                    . 'border-radius:12px;padding:11px 13px;margin-top:14px;">'
                  . '<div style="flex:0 0 auto;width:36px;height:36px;border-radius:50%;background:'
                    . e((string) $brand['colour']) . ';color:#fff;display:flex;align-items:center;justify-content:center;'
                    . 'font-weight:800;font-size:14px;">' . e($this->initials((string) $cfg['requestedBy'])) . '</div>'
                  . '<div style="min-width:0;">'
                    . '<div style="font-size:11px;font-weight:800;color:#94A3B8;letter-spacing:.4px;">REQUESTED BY</div>'
                    . '<div style="font-weight:700;color:#0F172A;font-size:13.5px;">' . e((string) $cfg['requestedBy'])
                      . ' <span style="font-weight:500;color:#64748B;">· ' . e((string) $brand['name']) . '</span></div>'
                    . ($cfg['requestedByEmail'] !== ''
                        ? '<div style="font-size:12.5px;margin-top:1px;"><a href="mailto:' . e((string) $cfg['requestedByEmail'])
                          . '" style="color:#1F6FB2;text-decoration:none;">' . e((string) $cfg['requestedByEmail']) . '</a></div>'
                        : '')
                  . '</div></div>'
                : '')
            . ($cfg['note'] !== '' ? '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:11px 13px;margin-top:12px;font-size:13.5px;color:#334155;">'
                . nl2br(e((string) $cfg['note'])) . '</div>' : '')
            . ($cfg['due_on'] ? '<div style="margin-top:10px;font-size:13px;font-weight:700;color:#B45309;">Needed by '
                . e((string) $cfg['due_on']) . '</div>' : '')
            . '<div class="bar"><i id="bar"></i></div>'
            . '<div id="count" style="font-size:12.5px;color:#64748B;"></div>'
            . '<div id="items" style="margin-top:14px;"></div>'
            . '<div id="msg" style="margin-top:12px;font-size:13.5px;"></div>'
            . '</div>'
            /* Plain, and true: it names the product, says what the link can do, and does
               not ask for anything a real request would not. Reassurance that survives
               being read carefully. */
            . '<div style="text-align:center;color:#8FA3BC;font-size:11.5px;margin-top:16px;line-height:1.6;">'
            .   'This page is part of KiddieTrac, the system ' . e((string) $brand['name']) . ' uses to manage childcare records.<br>'
            .   'It only accepts the documents listed above, and the link expires in ' . self::LINK_DAYS . ' days. '
            .   'It will never ask you for a password or a payment.'
            . '</div>'
            . '</div>'
            . '<script>(function(){var CFG=' . $json . ';'
            . 'var items=document.getElementById("items"),msg=document.getElementById("msg"),'
            . 'bar=document.getElementById("bar"),count=document.getElementById("count");'
            . 'function esc(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c];});}'
            . 'function paint(){'
            .   'var got=0,ask=0;'
            .   'items.innerHTML=CFG.items.map(function(it,i){'
            .     'got+=Math.min(it.have,it.quantity);ask+=it.quantity;'
            .     'var done=it.have>=it.quantity;'
            .     'return "<div class=\\"row"+(done?" done":"")+"\\">"'
            .       '+"<div style=\\"font-weight:700;font-size:14.5px;\\">"+esc(it.description)+"</div>"'
            .       '+"<div style=\\"font-size:12.5px;color:#64748B;margin-top:2px;\\">"'
            .         '+(it.quantity>1?("Needs "+it.quantity+" files · "):"")'
            .         '+(it.kind==="pdf"?"PDF":it.kind==="image"?"A photo or scan":it.kind==="document"?"A document":"Any file")'
            .         '+" · "+it.have+" of "+it.quantity+" sent</div>"'
            .       '+(done?"<div class=\\"files\\"><span class=\\"tick\\">\\u2713 Received, thank you</span></div>"'
            .            ':"<div style=\\"margin-top:10px;\\"><button class=\\"btn\\" data-i=\\""+i+"\\">Choose a file</button></div>")'
            .     '+"</div>";'
            .   '}).join("");'
            .   'bar.style.width=(ask?Math.round(got/ask*100):0)+"%";'
            .   'count.textContent=got+" of "+ask+" sent";'
            .   'items.querySelectorAll("button[data-i]").forEach(function(b){'
            .     'b.addEventListener("click",function(){pick(CFG.items[+b.getAttribute("data-i")],b);});'
            .   '});'
            .   'if(got>=ask&&ask>0){msg.style.color="#16A34A";msg.innerHTML="<strong>All done \\u2014 thank you.</strong> '
            .     'Your centre has been told, and a copy of everything is kept in your KiddieTrac account under Documents. You can close this window.";}'
            . '}'
            /* A fresh <input type=file> per pick: reusing one means selecting the same
               file twice in a row fires no change event, which reads as a dead button. */
            . 'function pick(item,btn){'
            .   'var inp=document.createElement("input");inp.type="file";inp.accept="' . $accept . '";'
            .   'inp.style.display="none";document.body.appendChild(inp);'
            .   'inp.addEventListener("change",function(){'
            .     'var f=inp.files&&inp.files[0];document.body.removeChild(inp);if(!f)return;'
            .     'send(item,f,btn);'
            .   '});inp.click();'
            . '}'
            . 'function send(item,file,btn){'
            .   'btn.disabled=true;btn.textContent="Sending\\u2026";msg.style.color="#64748B";msg.textContent="";'
            .   'var fd=new FormData();fd.append("item_id",item.id);fd.append("file",file,file.name);'
            /* No Content-Type header: the browser sets the multipart boundary. And the
               file travels as a file, which is what gets past this host's 1MB cap on
               bodies with no file part. */
            .   'fetch(CFG.links.upload,{method:"POST",headers:{Accept:"application/json"},body:fd})'
            .     '.then(function(r){return r.json().catch(function(){return {};}).then(function(j){'
            .       'if(!r.ok){throw new Error(j.message||(r.status===413?"That file is too large to send.":"Upload failed ("+r.status+")."));}'
            .       'return j;});})'
            .     '.then(function(j){'
            .       '(j.items||[]).forEach(function(u){var m=CFG.items.filter(function(x){return x.id===u.id;})[0];if(m){m.have=u.have;}});'
            .       'paint();'
            .     '})'
            .     '.catch(function(e){btn.disabled=false;btn.textContent="Choose a file";'
            .       'msg.style.color="#B91C1C";msg.textContent=e.message||"That did not send. Please try again.";});'
            . '}'
            . 'paint();'
            . '})();</script></body></html>';
    }
}
