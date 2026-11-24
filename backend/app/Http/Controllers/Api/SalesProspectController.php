<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ColdReach;
use App\Support\SalesCampaigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales → Leads → Cold reach (2026-10-30). See App\Support\ColdReach for the rules.
 * Sales reps and platform admins (the /sales group's gate).
 */
class SalesProspectController extends Controller
{
    private const FIELDS = ['name', 'organization', 'title', 'email', 'phone', 'website', 'address', 'city', 'province', 'postal_code', 'country', 'source', 'list_name', 'consent_basis', 'owner_id', 'notes'];

    private function rules(): array
    {
        return [
            'name' => 'nullable|string|max:160', 'organization' => 'nullable|string|max:200', 'title' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:40', 'website' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255', 'postal_code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:120', 'province' => 'nullable|string|max:60', 'country' => 'nullable|string|size:2',
            'source' => 'nullable|string|max:60', 'list_name' => 'nullable|string|max:120',
            'consent_basis' => 'nullable|in:' . implode(',', array_keys(SalesCampaigns::CONSENT)),
            'owner_id' => 'nullable|integer|exists:users,id', 'notes' => 'nullable|string|max:5000',
        ];
    }

    private ?array $cfgCache = null;
    private array $ownerNames = [];

    private function out(object $p): array
    {
        $o = (array) $p;
        $o['status_label'] = ColdReach::STATUSES[$p->status] ?? $p->status;
        $mi = isset($p->match_info) && $p->match_info ? json_decode($p->match_info, true) : null;
        $o['matches'] = $mi ?: [];
        $o['flag'] = $mi ? \App\Support\ProspectMatcher::summary($mi) : null;
        $o['flag_kind'] = $mi[0]['kind'] ?? null;
        unset($o['match_info']);
        $o['suppressed'] = $p->email ? SalesCampaigns::suppressed($p->email) : false;
        $o['due'] = $p->next_follow_up_at && Carbon::parse($p->next_follow_up_at)->lte(now()) && in_array($p->status, ColdReach::ACTIVE, true);
        $cfg = $this->cfgCache ??= ColdReach::settings();
        $st = $cfg['steps'][(int) $p->follow_up_step] ?? null;
        $o['next_step'] = $st && $p->next_follow_up_at ? ['n' => (int) $p->follow_up_step + 1, 'of' => count($cfg['steps']), 'channel' => $st['channel']] : null;
        if ($p->owner_id && ! array_key_exists($p->owner_id, $this->ownerNames)) $this->ownerNames[$p->owner_id] = trim((string) DB::table('users')->where('id', $p->owner_id)->value('first_name'));
        $o['owner_name'] = $p->owner_id ? $this->ownerNames[$p->owner_id] : null;
        return $o;
    }

    /** The filtered prospects: batch (id, or "none" = added by hand), status chip, search. */
    private function filtered(Request $r)
    {
        $q = DB::table('sales_prospects')->whereNull('deleted_at');
        $b = $r->input('batch');
        if ($b === 'none') $q->whereNull('batch_id');
        elseif ($b !== null && $b !== '' && is_numeric($b)) $q->where('batch_id', (int) $b);
        if ($s = $r->input('status')) {
            if ($s === 'due') $q->whereIn('status', ColdReach::ACTIVE)->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now());
            elseif ($s === 'open') $q->whereIn('status', ['new', 'contacted', 'engaged']);
            elseif ($s === 'with_email') $q->whereNotNull('email')->where('email', '!=', '');
            elseif ($s === 'no_email') $q->where(fn ($w) => $w->whereNull('email')->orWhere('email', ''));
            elseif ($s === 'flagged') $q->whereNotNull('match_info');
            elseif (isset(ColdReach::STATUSES[$s])) $q->where('status', $s);
        }
        if ($l = $r->input('list')) $q->where('list_name', $l);
        if ($t = trim((string) $r->input('q'))) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $t) . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('organization', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('city', 'like', $like));
        }
        return $q;
    }

    /** GET /sales/prospects?batch=&status=&q=&page=&per_page= - one page of a batch (or of everything). */
    public function index(Request $r): JsonResponse
    {
        $per = max(25, min(200, (int) $r->query('per_page', 100)));
        $page = max(1, (int) $r->query('page', 1));
        $q = $this->filtered($r);
        $total = (clone $q)->count();
        $rows = $q->orderByRaw("CASE WHEN next_follow_up_at IS NOT NULL AND next_follow_up_at <= ? AND status IN ('contacted','engaged') THEN 0 ELSE 1 END", [now()])
            ->orderByRaw("CASE WHEN email IS NULL OR email = '' THEN 1 ELSE 0 END")->orderBy('organization')->orderBy('id')
            ->forPage($page, $per)->get();
        $lists = DB::table('sales_prospects')->whereNull('deleted_at')->whereNotNull('list_name')->distinct()->orderBy('list_name')->pluck('list_name');
        $owners = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->whereIn('ra.role', ['platform_admin', 'sales_rep'])->where('ra.active', 1)->distinct()->orderBy('u.first_name')->get(['u.id', 'u.first_name']);
        return response()->json([
            'prospects' => $rows->map(fn ($p) => $this->out($p))->values(),
            'total' => $total, 'page' => $page, 'per_page' => $per,
            'lists' => $lists, 'owners' => $owners,
            'statuses' => ColdReach::STATUSES, 'call_outcomes' => ColdReach::CALL_OUTCOMES,
            'consent' => collect(SalesCampaigns::CONSENT)->map(fn ($c) => $c[0]),
        ]);
    }

    /** GET /sales/prospect-batches - every import as one row, with its numbers; "Added by hand" for the rest. */
    public function batches(): JsonResponse
    {
        $agg = DB::table('sales_prospects')->whereNull('deleted_at')->selectRaw("batch_id, COUNT(*) total,
                SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) with_email,
                SUM(CASE WHEN emails_sent > 0 OR calls > 0 THEN 1 ELSE 0 END) contacted,
                SUM(CASE WHEN opens > 0 THEN 1 ELSE 0 END) opened,
                SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) replied,
                SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) interested,
                SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) converted,
                SUM(CASE WHEN queued_at IS NOT NULL THEN 1 ELSE 0 END) queued,
                SUM(CASE WHEN status IN ('contacted','engaged') AND next_follow_up_at IS NOT NULL AND next_follow_up_at <= ? THEN 1 ELSE 0 END) due,
                SUM(CASE WHEN status = 'new' AND email IS NOT NULL AND email != '' AND queued_at IS NULL THEN 1 ELSE 0 END) ready,
                SUM(CASE WHEN match_info IS NOT NULL THEN 1 ELSE 0 END) flagged", [now()])
            ->groupBy('batch_id')->get()->keyBy(fn ($x) => $x->batch_id ?? 'none');
        $out = [];
        foreach (DB::table('sales_prospect_batches as b')->leftJoin('users as u', 'u.id', '=', 'b.created_by')->whereNull('b.deleted_at')->orderByDesc('b.created_at')
            ->get(['b.id', 'b.name', 'b.source_file', 'b.created_at', 'u.first_name as by']) as $b) {
            $a = $agg[$b->id] ?? null;
            if (! $a) continue;
            $out[] = array_merge((array) $b, array_map('intval', array_diff_key((array) $a, ['batch_id' => 1])));
        }
        if (isset($agg['none'])) {
            $out[] = array_merge(['id' => 'none', 'name' => 'Added by hand', 'source_file' => null, 'created_at' => null, 'by' => null],
                array_map('intval', array_diff_key((array) $agg['none'], ['batch_id' => 1])));
        }
        return response()->json(['batches' => $out]);
    }

    /** POST /sales/prospect-batches/{id}/recheck - flag what this batch shares with everyone else we know, now. */
    public function recheckBatch(int $id): JsonResponse
    {
        @set_time_limit(300);
        $mt = new \App\Support\ProspectMatcher($id);
        $flagged = 0; $cleared = 0;
        DB::table('sales_prospects')->whereNull('deleted_at')->where('batch_id', $id)->orderBy('id')
            ->chunkById(500, function ($rows) use ($mt, &$flagged, &$cleared) {
                foreach ($rows as $p) {
                    $hits = $mt->match((array) $p);
                    $new = $hits ? json_encode($hits) : null;
                    if ($new !== $p->match_info) DB::table('sales_prospects')->where('id', $p->id)->update(['match_info' => $new]);
                    $hits ? $flagged++ : $cleared++;
                }
            });
        return response()->json(['flagged' => $flagged, 'clear' => $cleared]);
    }

    public function renameBatch(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['name' => 'required|string|max:160']);
        DB::table('sales_prospect_batches')->where('id', $id)->update(['name' => trim($d['name']), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** DELETE /sales/prospect-batches/{id} - the batch and its prospects that were never contacted or converted. */
    public function deleteBatch(int $id): JsonResponse
    {
        $q = DB::table('sales_prospects')->whereNull('deleted_at')->where('batch_id', $id);
        $kept = (clone $q)->where(fn ($w) => $w->where('emails_sent', '>', 0)->orWhere('calls', '>', 0)->orWhere('status', 'converted'))->count();
        $gone = (clone $q)->where('emails_sent', 0)->where('calls', 0)->where('status', '!=', 'converted')->update(['deleted_at' => now(), 'next_follow_up_at' => null, 'queued_at' => null]);
        if ($kept === 0) DB::table('sales_prospect_batches')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['deleted' => $gone, 'kept' => $kept]);
    }

    /** POST /sales/prospects/email-preview {ids[] | batch+all} - who would get a first email, and why not the rest. */
    public function emailPreview(Request $r): JsonResponse
    {
        $q = $this->scopeFor($r);
        $base = fn () => clone $q;
        $total = $base()->count();
        $noEmail = $base()->where(fn ($w) => $w->whereNull('email')->orWhere('email', ''))->count();
        $notNew = $base()->whereNotNull('email')->where('email', '!=', '')->where('status', '!=', 'new')->count();
        $queued = $base()->whereNotNull('queued_at')->count();
        $cand = $base()->where('status', 'new')->whereNotNull('email')->where('email', '!=', '')->whereNull('queued_at')->pluck('email');
        $supp = $cand->isEmpty() ? 0 : DB::table('sales_suppressions')->whereIn('email', $cand->map(fn ($e) => mb_strtolower(trim($e)))->all())->count();
        return response()->json(['total' => $total, 'eligible' => max(0, $cand->count() - $supp), 'no_email' => $noEmail, 'already_contacted' => $notNew, 'queued' => $queued, 'unsubscribed' => $supp]);
    }

    /** ids[] (ticked rows) or "all": everything the current filter matches (batch, status, search). */
    private function scopeFor(Request $r)
    {
        if ($r->boolean('all')) return $this->filtered($r);
        $ids = array_values(array_filter(array_map('intval', (array) $r->input('ids', []))));
        return DB::table('sales_prospects')->whereNull('deleted_at')->whereIn('id', $ids ?: [0]);
    }

    public function show(int $id): JsonResponse
    {
        $p = DB::table('sales_prospects')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($p, 404, 'Not found.');
        $touches = DB::table('sales_prospect_touches as t')->leftJoin('users as u', 'u.id', '=', 't.user_id')->where('t.prospect_id', $id)
            ->orderByDesc('t.id')->limit(200)->get(['t.id', 't.kind', 't.outcome', 't.step', 't.automatic', 't.subject', 't.body', 't.opened_at', 't.open_count', 't.error', 't.created_at', 'u.first_name as by']);
        return response()->json(['prospect' => $this->out($p), 'touches' => $touches]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate($this->rules());
        abort_if(empty($d['name']) && empty($d['organization']), 422, 'Add a name or an organization.');
        $id = DB::table('sales_prospects')->insertGetId(array_merge(array_intersect_key($d, array_flip(self::FIELDS)), [
            'email' => isset($d['email']) ? mb_strtolower(trim($d['email'])) : null,
            'consent_basis' => $d['consent_basis'] ?? 'implied_published',
            'owner_id' => $d['owner_id'] ?? auth()->id(), 'source' => $d['source'] ?? 'manual',
            'status' => 'new', 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['prospect' => $this->out(DB::table('sales_prospects')->find($id))], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $p = DB::table('sales_prospects')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($p, 404, 'Not found.');
        $d = $r->validate($this->rules() + ['status' => 'nullable|in:' . implode(',', array_keys(ColdReach::STATUSES)), 'follow_ups_paused' => 'nullable|boolean']);
        $upd = array_intersect_key($d, array_flip(array_merge(self::FIELDS, ['follow_ups_paused'])));
        if (array_key_exists('email', $upd) && $upd['email']) $upd['email'] = mb_strtolower(trim($upd['email']));
        $upd['updated_at'] = now();
        DB::table('sales_prospects')->where('id', $id)->update($upd);
        if (! empty($d['status']) && $d['status'] !== $p->status) {
            if (in_array($d['status'], ['interested', 'not_interested', 'do_not_contact', 'replied', 'no_response', 'converted'], true)) ColdReach::stop($id, $d['status']);
            else DB::table('sales_prospects')->where('id', $id)->update(['status' => $d['status']]);
            DB::table('sales_prospect_touches')->insert(['prospect_id' => $id, 'kind' => 'note', 'outcome' => 'status',
                'body' => 'Marked ' . (ColdReach::STATUSES[$d['status']] ?? $d['status']) . '.', 'user_id' => auth()->id(), 'created_at' => now()]);
        }
        return response()->json(['prospect' => $this->out(DB::table('sales_prospects')->find($id))]);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('sales_prospects')->where('id', $id)->update(['deleted_at' => now(), 'next_follow_up_at' => null]);
        return response()->json(['ok' => true]);
    }

    /** POST /sales/prospects/import {rows:[{name,organization,email,phone,city,province,...}], list_name, consent_basis} */
    public function import(Request $r): JsonResponse
    {
        $d = $r->validate([
            // The page sends a big list in batches; no limit on the list itself.
            'rows' => 'required|array|max:1000', 'list_name' => 'nullable|string|max:120',
            'batch_id' => 'nullable|integer', 'batch_name' => 'nullable|string|max:160', 'source_file' => 'nullable|string|max:255',
            'consent_basis' => 'nullable|in:' . implode(',', array_keys(SalesCampaigns::CONSENT)),
            'owner_id' => 'nullable|integer|exists:users,id',
        ]);
        $batchId = ! empty($d['batch_id']) && DB::table('sales_prospect_batches')->where('id', $d['batch_id'])->exists() ? (int) $d['batch_id'] : null;
        if (! $batchId) {
            $name = trim((string) ($d['batch_name'] ?? '')) ?: (trim((string) ($d['list_name'] ?? '')) ?: (! empty($d['source_file']) ? pathinfo($d['source_file'], PATHINFO_FILENAME) : 'Import ' . now()->setTimezone(ColdReach::TZ)->format('M j, Y g:i A')));
            $batchId = DB::table('sales_prospect_batches')->insertGetId(['name' => mb_substr($name, 0, 160), 'source_file' => $d['source_file'] ?? null,
                'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $added = 0; $dupes = 0; $bad = 0;
        $mt = new \App\Support\ProspectMatcher($batchId);
        $have = DB::table('sales_prospects')->whereNull('deleted_at')->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip();
        $leads = DB::table('sales_leads')->whereNull('deleted_at')->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip();
        foreach ($d['rows'] as $row) {
            $row = is_array($row) ? $row : [];
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 160);
            $org = mb_substr(trim((string) ($row['organization'] ?? ($row['company'] ?? ''))), 0, 200);
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) { $bad++; continue; }
            if ($name === '' && $org === '') { $bad++; continue; }
            if ($email !== '' && (isset($have[$email]) || isset($leads[$email]))) { $dupes++; continue; }
            DB::table('sales_prospects')->insert([
                'name' => $name ?: null, 'organization' => $org ?: null, 'email' => $email ?: null,
                'title' => mb_substr(trim((string) ($row['title'] ?? '')), 0, 120) ?: null,
                'phone' => mb_substr(trim((string) ($row['phone'] ?? '')), 0, 40) ?: null,
                'website' => mb_substr(trim((string) ($row['website'] ?? '')), 0, 255) ?: null,
                'address' => mb_substr(trim((string) ($row['address'] ?? '')), 0, 255) ?: null,
                'postal_code' => mb_substr(trim((string) ($row['postal_code'] ?? '')), 0, 20) ?: null,
                'city' => mb_substr(trim((string) ($row['city'] ?? '')), 0, 120) ?: null,
                'province' => mb_substr(trim((string) ($row['province'] ?? '')), 0, 60) ?: null,
                'country' => preg_match('/^[A-Za-z]{2}$/', (string) ($row['country'] ?? '')) ? strtoupper($row['country']) : null,
                'notes' => mb_substr(trim((string) ($row['notes'] ?? '')), 0, 2000) ?: null,
                'match_info' => ($hits = $mt->match(['email' => $email, 'phone' => $row['phone'] ?? null, 'organization' => $org, 'city' => $row['city'] ?? null, 'website' => $row['website'] ?? null])) ? json_encode($hits) : null,
                'batch_id' => $batchId, 'list_name' => $d['list_name'] ?? null, 'source' => 'import', 'consent_basis' => $d['consent_basis'] ?? 'implied_published',
                'owner_id' => $d['owner_id'] ?? auth()->id(), 'status' => 'new', 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($email !== '') $have[$email] = 1;
            $added++;
        }
        return response()->json(['added' => $added, 'duplicates' => $dupes, 'skipped' => $bad, 'batch_id' => $batchId]);
    }

    /** POST /sales/prospects/parse {text} - pull prospects out of any pasted / uploaded text for a preview (nothing saved). */
    public function parse(Request $r): JsonResponse
    {
        @set_time_limit(300);
        $d = $r->validate(['text' => 'required|string|max:30000000']);
        $res = \App\Support\ProspectExtractor::extract($d['text']);
        return $this->parsed($res);
    }

    /** POST /sales/prospects/parse-file (multipart "file": xlsx / xls / ods / csv / tsv / txt) - read as a spreadsheet. */
    public function parseFile(Request $r): JsonResponse
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');
        $r->validate(['file' => 'required|file|max:61440|mimes:xlsx,xls,ods,csv,tsv,txt']);
        $f = $r->file('file');
        try {
            $res = \App\Support\ProspectExtractor::fromFile($f->getRealPath(), (string) $f->getClientOriginalName());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('cold reach file read failed', ['name' => $f->getClientOriginalName(), 'error' => $e->getMessage()]);
            abort(422, 'That file could not be read as a spreadsheet. Save it as .xlsx or .csv and try again.');
        }
        return $this->parsed($res);
    }

    private function parsed(array $res): JsonResponse
    {
        $mt = new \App\Support\ProspectMatcher();
        $counts = ['contacted' => 0, 'exists' => 0, 'lead' => 0, 'customer' => 0, 'unsubscribed' => 0];
        $seen = [];
        foreach ($res['rows'] as &$x) {
            $hits = $mt->match($x);
            // The same contact twice in this one file: the second is flagged too.
            $k = \App\Support\ProspectMatcher::normEmail($x['email'] ?? null) ?: (\App\Support\ProspectMatcher::normPhone($x['phone'] ?? null) ?: null);
            if ($k && isset($seen[$k])) $hits[] = ['kind' => 'exists', 'id' => 0, 'label' => $seen[$k], 'status' => 'Also earlier in this file', 'how' => str_contains($k, '@') ? 'email' : 'phone'];
            if ($k && ! isset($seen[$k])) $seen[$k] = trim((string) (($x['organization'] ?? '') ?: ($x['name'] ?? '')));
            $x['matches'] = $hits;
            $x['flag'] = \App\Support\ProspectMatcher::summary($hits);
            $x['flag_kind'] = $hits[0]['kind'] ?? null;
            $x['exists'] = ! empty($hits);
            if ($hits) $counts[$hits[0]['kind']] = ($counts[$hits[0]['kind']] ?? 0) + 1;
        }
        unset($x);
        $res['flag_counts'] = $counts;
        return response()->json($res);
    }

    /** POST /sales/prospects/{id}/touches {kind: call|note, outcome, body} - log a call or a note. */
    public function touch(Request $r, int $id): JsonResponse
    {
        $p = DB::table('sales_prospects')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($p, 404, 'Not found.');
        $d = $r->validate(['kind' => 'required|in:call,note', 'outcome' => 'nullable|in:' . implode(',', array_keys(ColdReach::CALL_OUTCOMES)), 'body' => 'nullable|string|max:5000']);
        DB::table('sales_prospect_touches')->insert(['prospect_id' => $id, 'kind' => $d['kind'], 'outcome' => $d['outcome'] ?? null,
            'step' => (int) $p->follow_up_step, 'body' => $d['body'] ?? null, 'user_id' => auth()->id(), 'created_at' => now()]);
        if ($d['kind'] === 'call') {
            DB::table('sales_prospects')->where('id', $id)->update(['calls' => DB::raw('calls + 1')]);
            $o = $d['outcome'] ?? null;
            if ($o === 'interested') ColdReach::stop($id, 'interested');
            elseif ($o === 'not_interested') ColdReach::stop($id, 'not_interested');
            elseif ($o === 'wrong_number') DB::table('sales_prospects')->where('id', $id)->update(['phone' => null]);
            else ColdReach::afterOutbound(DB::table('sales_prospects')->find($id));
            if ($o === 'call_back' || $o === 'connected') {
                // They asked for a call back / we spoke: give it one business day before the next step is due.
                DB::table('sales_prospects')->where('id', $id)->whereIn('status', ColdReach::ACTIVE)
                    ->update(['next_follow_up_at' => ColdReach::addDays(now(), 1, true)]);
            }
        }
        return $this->show($id);
    }

    /** POST /sales/prospects/{id}/email {subject, body} - send now (the first outreach or the due follow-up). */
    public function email(Request $r, int $id): JsonResponse
    {
        $p = DB::table('sales_prospects')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($p, 404, 'Not found.');
        $d = $r->validate(['campaign_id' => 'nullable|integer', 'subject' => 'required_without:campaign_id|nullable|string|max:200', 'body' => 'required_without:campaign_id|nullable|string|max:10000']);
        abort_unless($p->email, 422, 'This prospect has no email address.');
        $step = $p->status === 'new' ? 0 : (int) $p->follow_up_step + 1;
        [$ok, $err] = ! empty($d['campaign_id'])
            ? ColdReach::sendCampaign($p, (int) $d['campaign_id'], (int) auth()->id(), $step, false)
            : ColdReach::sendEmail($p, (string) $d['subject'], (string) $d['body'], (int) auth()->id(), $step, false);
        abort_unless($ok, 422, $err ?: 'The email could not be sent.');
        return $this->show($id);
    }

    /**
     * POST /sales/prospects/email-bulk {ids:[], campaign_id | subject+body} - the first email to many (status new only).
     * QUEUED, NOT SENT AT ONCE (deliverability, 2026-10-30): ColdReach::run sends them a few at a time through the
     * sending window, under the daily cap. A burst of first-contact mail is what gets a domain flagged as spam.
     */
    public function emailBulk(Request $r): JsonResponse
    {
        $d = $r->validate(['ids' => 'required_without:all|array|max:5000', 'ids.*' => 'integer', 'all' => 'nullable|boolean', 'campaign_id' => 'nullable|integer', 'subject' => 'required_without:campaign_id|nullable|string|max:200', 'body' => 'required_without:campaign_id|nullable|string|max:10000']);
        $tpl = ! empty($d['campaign_id']) ? ['campaign_id' => (int) $d['campaign_id']] : ['subject' => (string) $d['subject'], 'body' => (string) $d['body']];
        $queued = 0; $skipped = 0;
        foreach ($this->scopeFor($r)->get(['id', 'email', 'status', 'queued_at']) as $p) {
            if (! $p->email || $p->status !== 'new' || $p->queued_at || ColdReach::suppressed($p->email)) { $skipped++; continue; }
            DB::table('sales_prospects')->where('id', $p->id)->update(['queued_at' => now(), 'queued_email' => json_encode($tpl), 'queued_by' => auth()->id(), 'updated_at' => now()]);
            $queued++;
        }
        $cfg = ColdReach::settings();
        $waiting = DB::table('sales_prospects')->whereNull('deleted_at')->whereNotNull('queued_at')->count();
        return response()->json(['queued' => $queued, 'skipped' => $skipped, 'waiting' => $waiting, 'daily_cap' => (int) $cfg['daily_cap'],
            'window' => ColdReach::windowLabel($cfg)]);
    }

    /** POST /sales/prospects/{id}/convert - a real lead, linked back. */
    public function convert(int $id): JsonResponse
    {
        $p = DB::table('sales_prospects')->whereNull('deleted_at')->where('id', $id)->first();
        abort_unless($p, 404, 'Not found.');
        if ($p->lead_id && DB::table('sales_leads')->where('id', $p->lead_id)->whereNull('deleted_at')->exists()) {
            return response()->json(['lead_id' => (int) $p->lead_id, 'existing' => true]);
        }
        $notes = trim('Converted from Cold reach' . ($p->list_name ? ' (list: ' . $p->list_name . ')' : '') . ".\n" . (string) $p->notes);
        $leadId = DB::table('sales_leads')->insertGetId([
            'name' => $p->name, 'company' => $p->organization, 'title' => $p->title, 'email' => $p->email, 'phone' => $p->phone,
            'website' => $p->website, 'city' => $p->city, 'province' => $p->province, 'country' => $p->country,
            'source' => 'cold_reach', 'stage' => 'contacted', 'status' => 'open', 'owner_id' => $p->owner_id ?: auth()->id(),
            'notes' => $notes, 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $hist = DB::table('sales_prospect_touches')->where('prospect_id', $id)->orderBy('id')->get();
        $lines = $hist->map(fn ($t) => Carbon::parse($t->created_at)->setTimezone(ColdReach::TZ)->format('M j') . ' · ' . ucfirst($t->kind)
            . ($t->outcome ? ' (' . str_replace('_', ' ', $t->outcome) . ')' : '') . ($t->subject ? ': ' . $t->subject : '') . ($t->opened_at ? ' · opened' : ''))->implode("\n");
        DB::table('sales_activities')->insert(['lead_id' => $leadId, 'user_id' => auth()->id(), 'type' => 'note',
            'body' => "Converted from Cold reach.\n" . ($lines ?: 'No touches recorded.'), 'done' => 1, 'created_at' => now(), 'updated_at' => now()]);
        ColdReach::stop($id, 'converted', ['lead_id' => $leadId]);
        DB::table('sales_prospect_touches')->insert(['prospect_id' => $id, 'kind' => 'note', 'outcome' => 'converted',
            'body' => 'Converted to lead #' . $leadId . '.', 'user_id' => auth()->id(), 'created_at' => now()]);
        return response()->json(['lead_id' => $leadId]);
    }

    /** GET /sales/prospects-stats - tiles, a 30-day series and the funnel. */
    public function stats(): JsonResponse
    {
        $base = fn () => DB::table('sales_prospects')->whereNull('deleted_at');
        $total = $base()->count();
        $emailed = $base()->where('emails_sent', '>', 0)->count();
        $opened = $base()->where('opens', '>', 0)->count();
        $called = $base()->where('calls', '>', 0)->count();
        $contacted = $base()->where(fn ($q) => $q->where('emails_sent', '>', 0)->orWhere('calls', '>', 0))->count();
        $replied = $base()->whereNotNull('replied_at')->count();
        $interested = $base()->where('status', 'interested')->count();
        $converted = $base()->where('status', 'converted')->count();
        $due = $base()->whereIn('status', ColdReach::ACTIVE)->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now())->count();
        $byStatus = $base()->select('status', DB::raw('COUNT(*) n'))->groupBy('status')->pluck('n', 'status');

        $tz = ColdReach::TZ;
        $from = now()->setTimezone($tz)->subDays(29)->startOfDay();
        $days = [];
        for ($i = 0; $i < 30; $i++) { $k = $from->copy()->addDays($i)->format('Y-m-d'); $days[$k] = ['date' => $k, 'emails' => 0, 'opens' => 0, 'calls' => 0, 'replies' => 0, 'converted' => 0]; }
        $bump = function ($rows, string $field) use (&$days, $tz) {
            foreach ($rows as $t) { $k = Carbon::parse($t)->setTimezone($tz)->format('Y-m-d'); if (isset($days[$k])) $days[$k][$field]++; }
        };
        $since = $from->copy()->utc();
        $bump(DB::table('sales_prospect_touches')->where('kind', 'email')->where('outcome', 'sent')->where('created_at', '>=', $since)->pluck('created_at'), 'emails');
        $bump(DB::table('sales_prospect_touches')->whereNotNull('opened_at')->where('opened_at', '>=', $since)->pluck('opened_at'), 'opens');
        $bump(DB::table('sales_prospect_touches')->where('kind', 'call')->where('created_at', '>=', $since)->pluck('created_at'), 'calls');
        $bump($base()->whereNotNull('replied_at')->where('replied_at', '>=', $since)->pluck('replied_at'), 'replies');
        $bump(DB::table('sales_prospect_touches')->where('outcome', 'converted')->where('created_at', '>=', $since)->pluck('created_at'), 'converted');
        $pct = fn ($a, $b) => $b > 0 ? round($a / $b * 100, 1) : 0;
        $recentOpens = DB::table('sales_prospect_touches as t')->join('sales_prospects as p', 'p.id', '=', 't.prospect_id')
            ->whereNull('p.deleted_at')->whereNotNull('t.opened_at')->orderByDesc('t.opened_at')->limit(8)
            ->get(['p.id', 'p.name', 'p.organization', 't.subject', 't.opened_at', 't.open_count']);
        return response()->json([
            'tiles' => compact('total', 'contacted', 'emailed', 'opened', 'called', 'replied', 'interested', 'converted', 'due') + [
                'open_rate' => $pct($opened, $emailed), 'reply_rate' => $pct($replied, $emailed), 'conversion_rate' => $pct($converted, $contacted),
            ],
            'by_status' => $byStatus, 'series' => array_values($days), 'recent_opens' => $recentOpens,
            'funnel' => [['Prospects', $total], ['Contacted', $contacted], ['Opened', $opened], ['Replied', $replied], ['Interested', $interested + $converted], ['Converted', $converted]],
        ]);
    }

    public function settings(): JsonResponse
    {
        // The designed emails that can be used: Sales campaigns built in the campaign editor (not the subscriber templates).
        $campaigns = DB::table('sales_campaigns')->whereNull('deleted_at')->whereNull('template')->orderByDesc('id')->get(['id', 'name', 'subject']);
        return response()->json(['settings' => ColdReach::settings(), 'defaults' => ColdReach::defaults(), 'campaigns' => $campaigns,
            'mailbox' => (bool) \App\Support\SalesMailbox::account()]);
    }

    /**
     * POST /sales/prospects-settings/test {to, which: "first" | step number (1..)} - one "[TEST]" copy of that email to the
     * address typed in (never a default recipient). Untracked, nothing recorded against any prospect.
     */
    public function testEmail(Request $r): JsonResponse
    {
        $d = $r->validate(['to' => 'required|email', 'which' => 'required|string|max:10']);
        $cfg = ColdReach::settings();
        $tpl = $d['which'] === 'first' ? $cfg['first'] : ($cfg['steps'][(int) $d['which'] - 1] ?? null);
        abort_unless($tpl && (($tpl['channel'] ?? 'email') === 'email'), 422, 'That step is not an email.');
        if (! empty($tpl['campaign_id'])) {
            $c = DB::table('sales_campaigns')->find((int) $tpl['campaign_id']);
            abort_unless($c, 422, 'That campaign email is no longer available.');
            [$ok, $err] = SalesCampaigns::sendOne($c, null, $d['to'], (int) auth()->id());
        } else {
            $sample = (object) ['id' => 0, 'name' => 'Alex Sample', 'organization' => 'Sunshine Kids Daycare', 'city' => 'Barrie', 'consent_basis' => 'implied_published'];
            $html = ColdReach::html($sample, ColdReach::fill((string) $tpl['body'], $sample, (int) auth()->id()), (int) auth()->id(), null);
            $subject = '[TEST] ' . ColdReach::fill((string) $tpl['subject'], $sample, (int) auth()->id());
            try {
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($d, $subject) {
                    \App\Support\MailScope::platform($m);
                    $m->to($d['to'])->subject($subject)->replyTo(SalesCampaigns::FROM, 'KiddieTrac Sales');
                    $m->getHeaders()->addTextHeader('X-KT-Send-As', SalesCampaigns::FROM);
                });
                $ok = true; $err = null;
            } catch (\Throwable $e) { $ok = false; $err = $e->getMessage(); }
        }
        abort_unless($ok, 422, $err ?: 'The test could not be sent.');
        return response()->json(['ok' => true]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $s = ColdReach::saveSettings($r->all());
        // Prospects already waiting keep their due date; a sequence switched off clears nothing (it simply stops firing).
        return response()->json(['settings' => $s]);
    }

    /* ── public: the open pixel and unsubscribe (no sign-in; a random 40-character token per email) ── */
    private function touchByToken(string $token): ?object
    {
        return preg_match('/^[a-z0-9]{40}$/', $token) ? DB::table('sales_prospect_touches')->where('token', $token)->first() : null;
    }

    public function pixel(Request $r, string $token)
    {
        $t = $this->touchByToken($token);
        if ($t) { try { ColdReach::opened($t, $r); } catch (\Throwable $e) {} }
        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function unsubscribe(Request $r, string $token)
    {
        $t = $this->touchByToken($token);
        if (! $t) return response(SalesCampaigns::page('Unsubscribe', '<h1>This link has expired</h1><p>To stop KiddieTrac sales emails, reply to any of them with “unsubscribe” or write to ' . SalesCampaigns::FROM . '.</p>'), 404);
        $email = (string) DB::table('sales_prospects')->where('id', $t->prospect_id)->value('email');
        if ($r->isMethod('post')) {
            ColdReach::unsubscribe($t);
            \App\Support\Audit::write(['user_id' => null, 'action' => 'sales.prospect_unsubscribed', 'entity_type' => 'sales_prospect', 'entity_id' => (int) $t->prospect_id,
                'payload' => json_encode(['summary' => $email . ' unsubscribed from KiddieTrac sales emails']), 'created_at' => now()]);
            return response(SalesCampaigns::page('Unsubscribed', '<h1>You’re unsubscribed</h1><p><b>' . e($email) . '</b> will not receive KiddieTrac sales emails again.</p><p>If that was a mistake, just reply to our email and we will put it right.</p>'));
        }
        return response(SalesCampaigns::page('Unsubscribe', '<h1>Stop KiddieTrac sales emails?</h1><p>We will not send sales emails to <b>' . e($email) . '</b> again.</p>'
            . '<form method="post" style="margin-top:22px"><button class="btn" type="submit">Unsubscribe</button></form>'));
    }
}
