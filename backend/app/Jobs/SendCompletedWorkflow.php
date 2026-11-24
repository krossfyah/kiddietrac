<?php

namespace App\Jobs;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\KtDompdf as Dompdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A multi-step document workflow is fully signed: send everyone the finished copy
 * (2026-11-06).
 *
 * Until now the last signature only rang the starter's bell ("All signers have
 * completed"), and nobody - the starter included - was given the signed document. Every
 * signer and the person who started it now gets one email with the PDF attached: the
 * document text, then each step's signature, name and time in the agency's timezone.
 *
 * A person who asked for no email is skipped (and noted), the same rule as every other
 * notice. One try: a retried job would send the same document twice.
 */
class SendCompletedWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $workflowId) {}

    public function handle(): void
    {
        try {
            $this->send();
        } catch (\Throwable $e) {
            Log::error('SendCompletedWorkflow #' . $this->workflowId . ' failed: ' . $e->getMessage());
        }
    }

    private function send(): void
    {
        $w = DB::table('document_workflows')->where('id', $this->workflowId)->first();
        if (! $w || $w->status !== 'complete') {
            return;
        }
        $steps = DB::table('document_workflow_steps as s')
            ->leftJoin('users as u', 'u.id', '=', 's.signer_user_id')
            ->where('s.workflow_id', $w->id)->orderBy('s.step_order')
            ->get(['s.step_order', 's.signer_label', 's.signer_user_id', 's.signed_at', 's.signature_data', 's.signature_hash', 's.notes',
                   'u.first_name', 'u.last_name', 'u.email']);

        $agencyId = (int) $w->agency_id;
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $when = function ($ts) use ($tz) {
            try {
                $c = \Illuminate\Support\Carbon::parse($ts)->setTimezone($tz);
                return $c->format('D, M j, Y') . ' at ' . $c->format('g:i A') . ' (' . $c->format('T') . ')';
            } catch (\Throwable $e) {
                return '';
            }
        };
        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? 'Your agency');
        $name = fn ($s) => trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) ?: (string) $s->signer_label;

        // ── the document ──
        $html = '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:"DejaVu Sans",sans-serif;font-size:11px;color:#0F172A;line-height:1.5}'
            . 'h1{font-size:17px;margin:0 0 4px}.sub{color:#64748B;font-size:10px;margin:0 0 16px}'
            . '.doc{white-space:pre-wrap;border:1px solid #E2E8F0;padding:14px;margin-bottom:18px}'
            . '.sig{border:1px solid #E2E8F0;padding:10px 12px;margin:0 0 10px;page-break-inside:avoid}'
            . '.sig img{max-height:70px;max-width:260px}.k{color:#64748B;font-size:9.5px}'
            . '</style></head><body>'
            . '<h1>' . e((string) $w->title) . '</h1>'
            . '<p class="sub">' . e($agencyName) . ' &middot; fully signed ' . e($when($w->completed_at)) . '</p>'
            . '<div class="doc">' . e((string) $w->document_text) . '</div>'
            . '<h2 style="font-size:13px;margin:0 0 8px;">Signatures</h2>';
        foreach ($steps as $s) {
            $img = preg_match('#^data:image/(png|jpe?g);base64,#i', (string) $s->signature_data)
                ? '<img src="' . e((string) $s->signature_data) . '" alt="">' : '';
            $html .= '<div class="sig"><div class="k">Step ' . (int) $s->step_order . ' &middot; ' . e((string) $s->signer_label) . '</div>'
                . $img
                . '<div><strong>' . e($name($s)) . '</strong> &middot; ' . e($when($s->signed_at)) . '</div>'
                . ($s->notes ? '<div class="k">Note: ' . e((string) $s->notes) . '</div>' : '')
                . ($s->signature_hash ? '<div class="k">Signature record ' . e(substr((string) $s->signature_hash, 0, 16)) . '</div>' : '')
                . '</div>';
        }
        $html .= '</body></html>';

        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();
        $rel = 'document-workflows/' . $agencyId . '/' . (int) $w->id . '-signed.pdf';
        Storage::disk('local')->put($rel, $pdf->output());
        $abs = Storage::disk('local')->path($rel);
        $attachName = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $w->title), '-') . '-signed.pdf';

        // ── who gets it: every signer, and whoever started it ──
        $people = [];
        foreach ($steps as $s) {
            if ($s->signer_user_id) {
                $people[(int) $s->signer_user_id] = true;
            }
        }
        if ($w->started_by_user_id) {
            $people[(int) $w->started_by_user_id] = true;
        }
        $users = DB::table('users')->whereIn('id', array_keys($people))->whereNull('deleted_at')
            ->get(['id', 'email']);

        $list = '';
        foreach ($steps as $s) {
            $list .= '<br><strong>' . e((string) $s->signer_label) . ':</strong> ' . e($name($s)) . ', ' . e($when($s->signed_at));
        }
        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Everyone has signed <strong>' . e((string) $w->title)
            . '</strong>. The signed document is attached for your records.</p>'
            . EmailTemplate::calloutBox('<strong>Document:</strong> ' . e((string) $w->title)
                . '<br><strong>Fully signed:</strong> ' . e($when($w->completed_at)) . $list, 'info')
            . '<p style="margin:14px 0 0;font-size:13.5px;color:#64748B;line-height:1.6;">You can also open it any time in KiddieTrac under Document workflows.</p>';
        $mail = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'FULLY SIGNED',
            'title' => (string) $w->title,
            'subtitle' => $agencyName,
            'preheader' => 'Everyone has signed ' . $w->title . '.',
        ]);
        $subject = 'Fully signed: ' . $w->title . " \u{2014} " . \Illuminate\Support\Carbon::parse($w->completed_at)->setTimezone($tz)->format('D, M j, Y');

        $sent = [];
        foreach ($users as $u) {
            $to = trim((string) $u->email);
            if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (\App\Support\Suppression::isUser((int) $u->id)) {
                \App\Support\Suppression::note('email', (int) $u->id, 'document_workflow_complete');
                continue;
            }
            AgencyMailer::forAgency($agencyId)->html($mail, function ($m) use ($to, $subject, $abs, $attachName) {
                $m->to($to)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject)
                  ->attach($abs, ['as' => $attachName, 'mime' => 'application/pdf']);
            });
            $sent[] = $to;
        }

        try {
            \App\Support\Audit::write([
                'user_id' => $w->started_by_user_id ? (int) $w->started_by_user_id : null,
                'agency_id' => $agencyId,
                'action' => 'document_workflow.completed_sent',
                'entity_type' => 'document_workflow',
                'entity_id' => (int) $w->id,
                'payload' => json_encode([
                    'summary' => 'The fully signed "' . $w->title . '" was emailed'
                        . ($sent ? ' to: ' . implode(', ', $sent) . '.' : ' to nobody (no addresses, or email turned off).'),
                    'sent_to' => $sent,
                ]),
                'ip_address' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
