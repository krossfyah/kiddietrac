<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 7 (2026-11-27): HEALTH & SAFETY (Human Resources > Health & safety).
 *
 *  - RISK ASSESSMENTS (hr_risk_assessments): per centre and activity/area. Each line: hazard, who is at risk,
 *    likelihood x severity (1-5 each), controls, residual likelihood x severity. Owner, review date (reminders),
 *    director sign-off: a director of that centre or an agency admin, NEVER the person who last edited it; any edit
 *    afterwards returns it to draft. Five neutral starter templates (TEMPLATES), each marked
 *    "Example - review for your centre" until someone changes it. No legal claims anywhere.
 *  - WHMIS CHEMICAL INVENTORY (hr_chemicals): product, location, hazard classes (the WHMIS pictograms), the safety
 *    data sheet as a private file (ProtectedMedia folder safety-files), the SDS date (flagged when older than 3 years)
 *    and a review date (reminders). Staff of the centre can open the list and the SDS from My HR.
 *  - H&S DUTIES (hr_safety_duties): first aider, fire warden ... per person; the certificate the duty needs is read
 *    live from staff_certifications (valid / expiring in 30 days / expired / missing) and the managers are told once
 *    per state.
 *  - H&S TASKS: ordinary `tasks` rows with category 'hs' and evidence_required; closed only with an evidence file
 *    (TaskController refuses "done" without it too). source/source_id link a task to a risk assessment / chemical / hazard.
 *  - HAZARD PHOTOS: centre_hazards.photo_* (HazardController), same private folder.
 *
 * Files: put() stores under safety-files/<agency>/<kind>/ and nothing in a list carries a path; a signed URL is only
 * handed out by an endpoint that checked the caller may see the record.
 */
final class HrSafety
{
    public const FOLDER = 'safety-files';

    public const FILE_RULE = ['file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif,doc,docx,txt', 'max:15360'];

    public const PHOTO_RULE = ['file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:15360'];

    /** WHMIS 2015 hazard pictograms (Canada). Keys are stored; labels shown. */
    public const WHMIS = [
        'flame' => 'Flammable (flame)',
        'flame_circle' => 'Oxidizer (flame over circle)',
        'gas_cylinder' => 'Gas under pressure (gas cylinder)',
        'corrosion' => 'Corrosive (corrosion)',
        'exploding_bomb' => 'Explosive / self-reactive (exploding bomb)',
        'skull' => 'Acute toxicity (skull and crossbones)',
        'exclamation' => 'Health hazard / irritant (exclamation mark)',
        'health_hazard' => 'Serious health hazard (health hazard)',
        'environment' => 'Environmental hazard (environment)',
        'biohazard' => 'Biohazardous infectious material (biohazard)',
    ];

    /** Named duties => [label, the certificate type it needs (staff_certifications.cert_type) or null]. */
    public const DUTIES = [
        'first_aider' => ['First aider', 'First_Aid'],
        'cpr' => ['CPR responder', 'CPR'],
        'fire_warden' => ['Fire warden', null],
        'hs_rep' => ['Health & safety representative', null],
        'jhsc' => ['Joint health & safety committee member', null],
        'food_safety' => ['Food safety lead', 'Food_Handler'],
        'other' => ['Other duty', null],
    ];

    public const SDS_MAX_YEARS = 3;

    public const SOON_DAYS = 30;

    public const REVIEW_STEPS = [14, 0];

    public static function ready(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasTable('hr_risk_assessments') && Schema::hasTable('hr_chemicals') && Schema::hasTable('hr_safety_duties');
    }

    /** Store an uploaded file under the protected folder. @return array{path:string,name:string,mime:string,size:int} */
    public static function put(int $agencyId, UploadedFile $f, string $kind): array
    {
        $kind = preg_replace('/[^a-z]/', '', $kind) ?: 'file';
        $ext = strtolower($f->getClientOriginalExtension() ?: ($f->extension() ?: 'bin'));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $name = (string) Str::uuid() . '.' . $ext;
        $dir = self::FOLDER . '/' . $agencyId . '/' . $kind;
        Storage::disk('public')->putFileAs($dir, $f, $name);
        $orig = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '', (string) $f->getClientOriginalName()) ?: ('file.' . $ext), 0, 170);

        return ['path' => '/storage/' . $dir . '/' . $name, 'name' => $orig,
            'mime' => (string) ($f->getMimeType() ?: $f->getClientMimeType() ?: 'application/octet-stream'), 'size' => (int) $f->getSize()];
    }

    /** The signed link + facts for an endpoint that already checked access. */
    public static function fileOut(?string $path, ?string $name, ?string $mime): array
    {
        abort_unless($path, 404, 'There is no file on this record.');

        return ['url' => ProtectedMedia::sign($path, $name), 'name' => $name, 'mime' => $mime,
            'previewable' => (bool) preg_match('#^(image/(png|jpe?g|webp|gif)|application/pdf)$#i', (string) $mime)];
    }

    // ── risk ─────────────────────────────────────────────────────────────────────────────────

    public static function score(?int $l, ?int $s): ?int
    {
        return $l && $s ? max(1, min(5, $l)) * max(1, min(5, $s)) : null;
    }

    /** low 1-4, medium 5-9, high 10-16, very high 20-25. */
    public static function band(?int $score): ?string
    {
        if (! $score) return null;

        return $score >= 20 ? 'very_high' : ($score >= 10 ? 'high' : ($score >= 5 ? 'medium' : 'low'));
    }

    /** Clean the posted lines. @return array<int,array> */
    public static function items($raw): array
    {
        $out = [];
        foreach (array_slice(is_array($raw) ? $raw : [], 0, 60) as $it) {
            if (! is_array($it)) continue;
            $hz = trim((string) ($it['hazard'] ?? ''));
            if ($hz === '') continue;
            $n = fn ($k) => isset($it[$k]) && $it[$k] !== '' && $it[$k] !== null ? max(1, min(5, (int) $it[$k])) : null;
            $out[] = ['hazard' => mb_substr($hz, 0, 300), 'who' => mb_substr(trim((string) ($it['who'] ?? '')), 0, 300),
                'likelihood' => $n('likelihood'), 'severity' => $n('severity'), 'controls' => mb_substr(trim((string) ($it['controls'] ?? '')), 0, 2000),
                'residual_likelihood' => $n('residual_likelihood'), 'residual_severity' => $n('residual_severity')];
        }

        return $out;
    }

    /** The highest residual (else initial) score on an assessment. */
    public static function topScore(array $items): ?int
    {
        $top = null;
        foreach ($items as $it) {
            $s = self::score($it['residual_likelihood'] ?? null, $it['residual_severity'] ?? null) ?? self::score($it['likelihood'] ?? null, $it['severity'] ?? null);
            if ($s !== null && ($top === null || $s > $top)) $top = $s;
        }

        return $top;
    }

    /**
     * STARTER TEMPLATES (Anthony's brief: neutral, clearly an example, no legal claims). A centre copies one and makes it
     * its own; until it is edited the copy is marked "Example - review for your centre".
     */
    public const TEMPLATES = [
        'outdoor_play' => ['title' => 'Outdoor play', 'area' => 'Playground and yard', 'items' => [
            ['Trips and falls on uneven ground or equipment', 'Children, staff', 3, 3, 'Daily walk-round of the yard before use; report damage; soft surfacing kept topped up under climbing equipment', 2, 2],
            ['Gaps in fencing or a gate left open', 'Children', 2, 5, 'Gate check at each outdoor time; self-closing latch; head counts on the way out and back in', 1, 5],
            ['Sun and heat', 'Children, staff', 3, 3, 'Shade available; water out; sunscreen and hats as agreed with families; shorter outdoor time in heat', 2, 2],
            ['Litter, sharp objects or animal waste in the play area', 'Children', 2, 4, 'Sweep the yard before children go out; gloves and a sharps container for staff', 1, 3],
            ['Children out of sight behind equipment or structures', 'Children', 2, 4, 'Staff positioned to see the whole area; agreed zones for each adult', 1, 4],
        ]],
        'field_trips' => ['title' => 'Field trips and outings', 'area' => 'Off-site outings', 'items' => [
            ['A child becomes separated from the group', 'Children', 2, 5, 'Head counts at every move; children wear a visible identifier with the centre phone number; buddy pairs', 1, 5],
            ['Road traffic on the way', 'Children, staff', 2, 5, 'Plan the route; cross only at crossings; adults at the front and back of the group', 1, 4],
            ['A medical need or allergy away from the centre', 'Children', 2, 4, 'Take medication, the emergency contact list and a first aid kit; a staff member with first aid on each trip', 1, 3],
            ['Weather changes', 'Children, staff', 3, 2, 'Check the forecast; take spare clothing; have a plan to return early', 2, 2],
            ['Not enough adults for the group', 'Children', 2, 4, 'Confirm adult numbers before leaving; postpone if short', 1, 3],
        ]],
        'sleep_room' => ['title' => 'Sleep room', 'area' => 'Sleep / rest room', 'items' => [
            ['A child in distress or unwell while sleeping is not noticed', 'Infants, toddlers', 2, 5, 'Regular visual checks recorded; enough light to see each child; a staff member always within sight or sound', 1, 5],
            ['Loose bedding, pillows or soft toys in cots', 'Infants', 2, 5, 'Firm flat mattress; cots kept clear; follow the sleep guidance your centre has adopted', 1, 5],
            ['Cords, curtains or furniture within reach of a cot', 'Infants, toddlers', 2, 4, 'Move cots away from blinds and cords; check the room layout monthly', 1, 4],
            ['Room too warm or too cold', 'Children', 3, 2, 'Thermometer in the room; adjust clothing and bedding', 2, 2],
            ['Blocked path to the exit in an emergency', 'Children, staff', 2, 4, 'Keep a clear route; evacuation cot or plan for non-walkers', 1, 3],
        ]],
        'kitchen' => ['title' => 'Kitchen', 'area' => 'Kitchen and food preparation', 'items' => [
            ['Burns and scalds from hot surfaces, liquids or food', 'Staff, children', 3, 4, 'Children kept out of the kitchen; hot drinks away from children; pan handles turned in', 2, 3],
            ['Food allergies or intolerances', 'Children', 2, 5, 'Allergy list posted for kitchen staff; labelled plates; separate utensils; check before serving', 1, 4],
            ['Food poisoning from storage or handling', 'Children, staff', 2, 4, 'Fridge temperature checked and recorded; date labels; hand washing; food handler training for kitchen staff', 1, 3],
            ['Cleaning chemicals mixed up with food', 'Staff, children', 2, 4, 'Chemicals stored separately in labelled containers, locked or out of reach; see the chemical inventory', 1, 3],
            ['Cuts from knives or equipment', 'Staff', 3, 3, 'Knives stored safely; cut on a board; equipment guards in place', 2, 2],
        ]],
        'water_play' => ['title' => 'Water play', 'area' => 'Water tables, paddling and sprinklers', 'items' => [
            ['Drowning - even in shallow water', 'Children', 2, 5, 'Constant adult supervision within arm\'s reach for the youngest; empty containers straight after use', 1, 5],
            ['Slips on wet surfaces', 'Children, staff', 3, 3, 'Non-slip mats; mop up spills; limit running near water', 2, 2],
            ['Water that is dirty or left standing', 'Children', 2, 3, 'Fresh water each session; empty and clean equipment after use', 1, 2],
            ['Cold or sun exposure', 'Children', 2, 2, 'Towels and dry clothes ready; shade and sun protection outdoors', 1, 2],
        ]],
    ];

    /** A template as stored items. */
    public static function templateItems(string $key): array
    {
        $t = self::TEMPLATES[$key] ?? null;
        if (! $t) return [];

        return array_map(fn ($i) => ['hazard' => $i[0], 'who' => $i[1], 'likelihood' => $i[2], 'severity' => $i[3], 'controls' => $i[4],
            'residual_likelihood' => $i[5], 'residual_severity' => $i[6]], $t['items']);
    }

    // ── chemicals ────────────────────────────────────────────────────────────────────────────

    public static function classes($raw): array
    {
        $a = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_values(array_intersect(array_keys(self::WHMIS), array_map('strval', $a)));
    }

    /** sds_missing, sds_old (date older than 3 years), review_soon (30 days), review_overdue. */
    public static function chemFlags(object $c, string $today): array
    {
        $f = [];
        if (! $c->sds_path) $f[] = 'sds_missing';
        if ($c->sds_date && $c->sds_date < Carbon::parse($today)->subYears(self::SDS_MAX_YEARS)->toDateString()) $f[] = 'sds_old';
        if (! $c->sds_date && $c->sds_path) $f[] = 'sds_undated';
        if ($c->sds_review_on) {
            if ($c->sds_review_on < $today) $f[] = 'review_overdue';
            elseif ($c->sds_review_on <= Carbon::parse($today)->addDays(self::SOON_DAYS)->toDateString()) $f[] = 'review_soon';
        }

        return $f;
    }

    // ── duties ───────────────────────────────────────────────────────────────────────────────

    public static function dutyLabel(object $d): string
    {
        return $d->duty === 'other' && $d->label ? (string) $d->label : (self::DUTIES[$d->duty][0] ?? 'Duty') . ($d->label && $d->duty !== 'other' ? ' (' . $d->label . ')' : '');
    }

    /**
     * Does this person hold the certificate the duty needs today? state none (no cert needed) | ok | soon | expired | missing.
     * @return array{state:string,expires:?string,label:string}
     */
    public static function certFor(int $userId, ?string $certType, string $today): array
    {
        if (! $certType) return ['state' => 'none', 'expires' => null, 'label' => ''];
        $label = HrOpenShifts::certLabel($certType);
        $rows = DB::table('staff_certifications')->where('user_id', $userId)->where('cert_type', $certType)->where('active', 1)
            ->orderByRaw('expires_at IS NULL DESC')->orderByDesc('expires_at')->get(['expires_at']);
        if ($rows->isEmpty()) return ['state' => 'missing', 'expires' => null, 'label' => $label];
        $best = $rows->first();
        if ($best->expires_at === null) return ['state' => 'ok', 'expires' => null, 'label' => $label];
        $exp = substr((string) $best->expires_at, 0, 10);
        if ($exp < $today) return ['state' => 'expired', 'expires' => $exp, 'label' => $label];
        if ($exp <= Carbon::parse($today)->addDays(self::SOON_DAYS)->toDateString()) return ['state' => 'soon', 'expires' => $exp, 'label' => $label];

        return ['state' => 'ok', 'expires' => $exp, 'label' => $label];
    }

    /** Active = not ended and inside its dates. */
    public static function activeDuties(int $agencyId, string $today)
    {
        return DB::table('hr_safety_duties')->where('agency_id', $agencyId)->whereNull('ended_at')
            ->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }

    // ── reminders (hr:safety-remind) ────────────────────────────────────────────────────────

    /** Directors of a centre (else the agency admins), own staff only, never an outsider or a leaver. */
    public static function centreManagers(int $agencyId, ?int $centreId): array
    {
        $valid = HrTalent::managerIds($agencyId);
        $ids = [];
        if ($centreId) {
            $ids = CentreScope::managers($agencyId, [$centreId])->where('ra.role', 'centre_director')->pluck('u.id')->map(fn ($v) => (int) $v)->all();
        }
        if (! $ids) $ids = CentreScope::managers($agencyId, [])->pluck('u.id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_unique(array_intersect($ids, $valid)));
    }

    private static function step(?string $date, Carbon $today): ?string
    {
        if (! $date) return null;
        $d = (int) $today->diffInDays(Carbon::parse($date)->startOfDay(), false);
        // Windows, not exact days, so a missed morning (or a record added 10 days out) still gets its reminder once:
        // within 14 days, on the day, then once in each overdue week.
        if ($d > self::REVIEW_STEPS[0]) return null;
        if ($d > 0) return 'd' . self::REVIEW_STEPS[0] . ':' . $date;
        if ($d === 0) return 'd0:' . $date;

        return 'o' . intdiv(-$d - 1, 7) . ':' . $date;   // overdue: once a week
    }

    /**
     * Work out (and unless $dry, send) one agency's reminders. Bell rows (type hr, kind team = StaffNotifyPrefs staff_hr;
     * own = the person's own). Each record remembers the last step it sent (reminded_for / alerted_for).
     * @return array{risk:int,chem:int,duty:int,bells:int}
     */
    public static function remind(int $agencyId, bool $dry = false): array
    {
        $out = ['risk' => 0, 'chem' => 0, 'duty' => 0, 'bells' => 0];
        if (! self::ready()) return $out;
        $todayS = AgencyTime::today($agencyId);
        $today = Carbon::parse($todayS)->startOfDay();
        $open = Live::centreIds($agencyId);
        $staff = array_flip(HrLeave::staffIds($agencyId));
        $centreName = DB::table('centres')->whereIn('id', $open ?: [0])->pluck('name', 'id');
        $bell = function (array $to, string $title, string $body, string $hash, string $kindFor, int $ownId = 0) use ($agencyId, $dry, &$out) {
            foreach (array_unique($to) as $uid) {
                $out['bells']++;
                if ($dry) continue;
                Notify::write(['agency_id' => $agencyId, 'user_id' => (int) $uid, 'type' => 'hr', 'title' => mb_substr($title, 0, 180), 'body' => mb_substr($body, 0, 900),
                    'data' => json_encode(['hash' => (int) $uid === $ownId ? 'my-hr' : $hash, 'kind' => (int) $uid === $ownId ? 'own' : 'team', 'hr_safety' => $kindFor]), 'created_at' => now()]);
            }
        };

        // Risk assessments due for review.
        foreach (DB::table('hr_risk_assessments')->where('agency_id', $agencyId)->whereNull('archived_at')->whereIn('centre_id', $open ?: [0])->whereNotNull('review_on')->get() as $r) {
            $step = self::step((string) $r->review_on, $today);
            if (! $step || $step === $r->reminded_for) continue;
            $to = self::centreManagers($agencyId, (int) $r->centre_id);
            if ($r->owner_user_id && isset($staff[(int) $r->owner_user_id])) $to[] = (int) $r->owner_user_id;
            $when = $r->review_on < $todayS ? 'review overdue since ' . Carbon::parse($r->review_on)->format('M j') : ($r->review_on === $todayS ? 'review due today' : 'review due ' . Carbon::parse($r->review_on)->format('M j'));
            $bell($to, '🦺 Risk assessment ' . $when . ': ' . $r->title, ($centreName[$r->centre_id] ?? '') . '. Open Human Resources > Health & safety > Risk assessments.', 'hr-safety?tab=risk', 'risk');
            $out['risk']++;
            if (! $dry) DB::table('hr_risk_assessments')->where('id', $r->id)->update(['reminded_for' => $step]);
        }

        // Safety data sheets: review date, and the day an SDS passes 3 years old.
        $oldBefore = $today->copy()->subYears(self::SDS_MAX_YEARS)->toDateString();
        foreach (DB::table('hr_chemicals')->where('agency_id', $agencyId)->whereNull('archived_at')->whereIn('centre_id', $open ?: [0])->get() as $c) {
            $step = self::step($c->sds_review_on ? (string) $c->sds_review_on : null, $today);
            $why = null;
            if ($step) {
                $why = $c->sds_review_on < $todayS ? 'SDS review overdue' : ($c->sds_review_on === $todayS ? 'SDS review due today' : 'SDS review due ' . Carbon::parse($c->sds_review_on)->format('M j'));
            } elseif ($c->sds_date && $c->sds_date < $oldBefore) {
                $step = 'old:' . $c->sds_date;
                $why = 'safety data sheet is over ' . self::SDS_MAX_YEARS . ' years old';
            }
            if (! $step || $step === $c->reminded_for) continue;
            $bell(self::centreManagers($agencyId, (int) $c->centre_id), '🧪 ' . $c->product . ': ' . $why,
                ($centreName[$c->centre_id] ?? '') . '. Check with the supplier for a current SDS. Human Resources > Health & safety > Chemicals (WHMIS).', 'hr-safety?tab=chemicals', 'chemical');
            $out['chem']++;
            if (! $dry) DB::table('hr_chemicals')->where('id', $c->id)->update(['reminded_for' => $step]);
        }

        // Duties whose certificate is expiring, expired or missing: the person and their centre's managers, once per state.
        $names = DB::table('users')->whereIn('id', array_keys($staff) ?: [0])->pluck('first_name', 'id');
        foreach (self::activeDuties($agencyId, $todayS)->whereNotNull('cert_type')->get() as $d) {
            if (! isset($staff[(int) $d->user_id])) continue;
            $c = self::certFor((int) $d->user_id, $d->cert_type, $todayS);
            if (! in_array($c['state'], ['soon', 'expired', 'missing'], true)) continue;
            $step = $c['state'] . ':' . ($c['expires'] ?? '-');
            if ($step === $d->alerted_for) continue;
            $label = self::dutyLabel($d);
            $what = $c['state'] === 'missing' ? 'no ' . $c['label'] . ' certificate on file'
                : ($c['state'] === 'expired' ? $c['label'] . ' expired ' . Carbon::parse($c['expires'])->format('M j') : $c['label'] . ' expires ' . Carbon::parse($c['expires'])->format('M j'));
            $to = self::centreManagers($agencyId, $d->centre_id ? (int) $d->centre_id : null);
            $to[] = (int) $d->user_id;
            $bell($to, '🧯 ' . ($names[$d->user_id] ?? 'Staff') . ' (' . $label . '): ' . $what,
                'Named ' . $label . ($d->centre_id && isset($centreName[$d->centre_id]) ? ' at ' . $centreName[$d->centre_id] : '') . '. Add the renewed certificate, or name someone else.', 'hr-safety?tab=duties', 'duty', (int) $d->user_id);
            $out['duty']++;
            if (! $dry) DB::table('hr_safety_duties')->where('id', $d->id)->update(['alerted_for' => $step]);
        }

        return $out;
    }
}
