<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A family arriving from the iLearn sync is not the same thing as a family being ready
 * to use KiddieTrac.
 *
 * The sync brings across identity: names, date of birth, address, the centre they belong
 * to. Everything that makes the portal actually work for them still has to be done by a
 * person — the welcome email that gives the parent a way in, the immunization records,
 * the consents, and sitting down with them once so they know what the app does.
 * Nothing asked anyone to do any of it, so a family could sit in the portal for weeks
 * with a guardian account that had never been used and nobody aware of it. Danielle
 * Grant arrived on 26 Aug 2026 and had received no email at all.
 *
 * So: when a family lands through the integration, tell the people who can act on it,
 * once, with the specific things outstanding for THAT family — and with the standing
 * items that are never in the data because nobody has ever been asked to record them.
 */
class NewFamilyNotice
{
    /**
     * Notify the agency's admins and the centre's director about a newly-synced family.
     *
     * Deliberately idempotent per family: the sync is a daily cron and an observer, and
     * the same family can be pushed repeatedly. A reminder that arrives every morning
     * stops being a reminder and becomes something people filter.
     *
     * $force re-sends for a family already notified — used to backfill families that
     * arrived before this existed.
     */
    public static function forSyncedFamily(int $familyId, ?int $agencyId = null, bool $force = false, ?string $overrideTo = null): bool
    {
        try {
            $family = DB::table('families')->find($familyId);
            if (! $family) {
                return false;
            }

            $centre = DB::table('centres')->find($family->centre_id);
            $agencyId = $agencyId ?: ($centre->agency_id ?? null);
            if (! $agencyId) {
                return false;
            }

            if (! $force) {
                $already = DB::table('audit_logs')
                    ->where('action', 'family.onboarding_notice_sent')
                    ->where('entity_type', 'family')
                    ->where('entity_id', $familyId)
                    ->exists();
                if ($already) {
                    return false;
                }
            }

            $outstanding = self::outstanding($familyId);
            $agency = DB::table('agencies')->find($agencyId);
            $agencyName = $agency->name ?? 'your agency';

            $html = EmailTemplate::wrap($agencyId, self::body($family, $centre, $outstanding), [
                'eyebrow' => 'NEW FAMILY',
                'title' => $family->family_name.' has arrived from iLearn',
                'subtitle' => $centre->name ?? $agencyName,
                'preheader' => $family->family_name.' is in KiddieTrac but not set up yet — '
                    .count($outstanding).' things to action.',
            ]);

            $subject = 'New family to set up: '.$family->family_name;

            if ($overrideTo) {
                AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($overrideTo, $subject) {
                    $m->to($overrideTo)->subject('[SAMPLE] '.$subject);
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                });

                return true;
            }

            $recipients = self::recipients($agencyId, (int) $family->centre_id);
            if (! $recipients->count()) {
                return false;
            }

            $sent = 0;
            foreach ($recipients as $r) {
                try {
                    AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($r, $subject) {
                        $m->to($r->email)->subject($subject);
                    });
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('New-family notice failed for '.$r->email.': '.$e->getMessage());
                }
            }

            if ($sent) {
                \App\Support\Audit::write([
                    'user_id' => null,
                    'agency_id' => $agencyId,
                    'action' => 'family.onboarding_notice_sent',
                    'entity_type' => 'family',
                    'entity_id' => $familyId,
                    'payload' => json_encode([
                        'recipients' => $recipients->pluck('email')->all(),
                        'outstanding' => $outstanding,
                    ]),
                    'created_at' => now(),
                ]);
            }

            return $sent > 0;
        } catch (\Throwable $e) {
            Log::warning('NewFamilyNotice failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * The people who can actually do something about it: the agency's admins, plus the
     * director of the centre the family joined. Not every educator — this is an
     * administrative task, and sending it wider only spreads the assumption that
     * somebody else has picked it up.
     */
    private static function recipients(int $agencyId, int $centreId)
    {
        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)
            ->where('ra.active', 1)
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->whereNotNull('u.email')
            /* Not the integration's own service account. It holds agency_admin so it can
               push records, so it matches this query perfectly — and it is a machine.
               Mailing it does nothing except make the recipient list look longer than the
               number of people who will actually read it. */
            ->where('u.email', 'not like', 'integration+%')
            ->where(function ($q) use ($centreId) {
                $q->where('ra.role', 'agency_admin')
                    ->orWhere(function ($x) use ($centreId) {
                        $x->where('ra.role', 'centre_director')->where('ra.centre_id', $centreId);
                    });
            })
            ->distinct()
            ->get(['u.id', 'u.email', 'u.first_name']);
    }

    /**
     * What is genuinely still missing for this family — checked, not assumed.
     *
     * A checklist that lists things already done trains people to skim past it, so every
     * line here is the result of an actual query. The standing items that are never in
     * the data (immunization, the walkthrough) live in the email body instead, clearly
     * separated, so this list stays trustworthy.
     */
    private static function outstanding(int $familyId): array
    {
        $todo = [];

        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->get(['u.id', 'u.email', 'u.status', 'u.last_login_at']);

        $neverIn = $guardians->filter(fn ($g) => empty($g->last_login_at));
        if ($guardians->isEmpty()) {
            $todo[] = 'Add a parent or guardian — this family has no contact account yet.';
        } elseif ($neverIn->count()) {
            $todo[] = 'Send the welcome email — '
                .($neverIn->count() === $guardians->count() ? 'no guardian' : $neverIn->count().' guardian(s)')
                .' has signed in yet, so they cannot see anything at all.';
        }

        $children = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')->get();

        $unplaced = $children->filter(fn ($c) => empty($c->primary_room_id));
        if ($unplaced->count()) {
            $todo[] = 'Assign a room for '
                .$unplaced->map(fn ($c) => trim($c->first_name.' '.$c->last_name))->implode(', ')
                .' — until then they will not appear in their educator\'s list.';
        }

        $missingDob = $children->filter(fn ($c) => empty($c->date_of_birth));
        if ($missingDob->count()) {
            $todo[] = 'Add a date of birth for '
                .$missingDob->map(fn ($c) => $c->first_name)->implode(', ').' — age drives room ratios.';
        }

        /* No paperwork on file at all. Immunization is the one that matters most and the
           one there is no field for, so the absence of ANY document is the closest honest
           signal we have that nothing has been collected yet. */
        if (Schema::hasTable('documents')) {
            $noDocs = $children->filter(function ($c) {
                return ! DB::table('documents')->where('scope_type', 'child')->where('scope_id', $c->id)->exists();
            });
            if ($noDocs->count()) {
                $todo[] = 'Collect and upload records for '
                    .$noDocs->map(fn ($c) => $c->first_name)->implode(', ')
                    .' — immunization first, plus birth certificate, custody or court orders '
                    .'if any, and the signed consents. Nothing is on file yet.';
            }
        }

        $hasEmergency = Schema::hasTable('emergency_contacts')
            && DB::table('emergency_contacts')->where('family_id', $familyId)->exists();
        if (! $hasEmergency) {
            $todo[] = 'Add at least one emergency contact, with who may collect the child.';
        }

        $noAllergyInfo = $children->filter(fn ($c) => empty($c->allergies) && empty($c->medical_notes));
        if ($noAllergyInfo->count() === $children->count() && $children->count()) {
            $todo[] = 'Confirm allergies, dietary needs and any medical notes — even a '
                .'confirmed "none" is worth recording, so an educator is never guessing.';
        }

        $noTimes = $children->filter(fn ($c) => empty($c->expected_dropoff_time) && empty($c->expected_pickup_time));
        if ($noTimes->count()) {
            $todo[] = 'Set the expected drop-off and pick-up times for '
                .$noTimes->map(fn ($c) => $c->first_name)->implode(', ').'.';
        }

        if (empty(DB::table('families')->where('id', $familyId)->value('address_line1'))) {
            $todo[] = 'Complete the family address — it flows through to billing and to iLearn.';
        }

        return $todo;
    }

    private static function body($family, $centre, array $outstanding): string
    {
        $e = fn ($v) => e((string) $v);

        $html = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
            .'<strong>'.$e($family->family_name).'</strong> has just come across from iLearn into '
            .$e($centre->name ?? 'your centre').'. Their details are here, but the family cannot '
            .'use KiddieTrac yet — the sync brings the record, not the set-up.</p>';

        if ($outstanding) {
            $html .= '<div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:12px;padding:14px 16px;margin:16px 0;">'
                .'<div style="font-weight:800;font-size:14px;color:#9A3412;margin-bottom:8px;">'
                .'Outstanding for this family ('.count($outstanding).')</div><ul style="margin:0;padding-left:18px;">';
            foreach ($outstanding as $t) {
                $html .= '<li style="font-size:13.5px;color:#7C2D12;line-height:1.6;margin-bottom:7px;">'.$e($t).'</li>';
            }
            $html .= '</ul></div>';
        } else {
            $html .= '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:12px;padding:13px 15px;margin:16px 0;'
                .'font-size:13.5px;color:#166534;line-height:1.55;">Their record looks complete. '
                .'The walk-through below is still worth doing.</div>';
        }

        /* Separate block, deliberately. These are not derived from the record — there is
           no field that knows whether somebody sat down with a parent — so mixing them
           into the checked list above would make that list unreliable. */
        $html .= '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin:16px 0;">'
            .'<div style="font-weight:800;font-size:14px;color:#0F172A;margin-bottom:8px;">'
            .'Before their first day</div>'
            .'<ul style="margin:0;padding-left:18px;">'
            .'<li style="font-size:13.5px;color:#334155;line-height:1.6;margin-bottom:7px;">'
            .'<strong>Immunization record.</strong> Ask for the up-to-date copy, upload it to the '
            .'child\'s Documents, and set the review date if any doses are still due.</li>'
            .'<li style="font-size:13.5px;color:#334155;line-height:1.6;margin-bottom:7px;">'
            .'<strong>Signed consents.</strong> Photo and media, outings and walks, sunscreen and '
            .'medication, and the parent handbook acknowledgement.</li>'
            .'<li style="font-size:13.5px;color:#334155;line-height:1.6;margin-bottom:7px;">'
            .'<strong>Walk them through KiddieTrac.</strong> Sit with the parent once and show them '
            .'how to sign in, where the daily log and photos appear, how to message their educator, '
            .'how check-in and the QR code work at the door, and how to turn on notifications so '
            .'they actually hear from you. Ten minutes here prevents most of the questions that '
            .'come later.</li>'
            .'<li style="font-size:13.5px;color:#334155;line-height:1.6;">'
            .'<strong>Agree the starting details.</strong> First day, the usual schedule, who may '
            .'collect the child, and the fee arrangement.</li>'
            .'</ul></div>';

        $html .= '<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#475569;">'
            .'The welcome email is on the family record — open the family and use '
            .'<strong>Send welcome</strong>. That is what gives the parent their sign-in.</p>'
            .'<p style="margin:18px 0 0;"><a href="https://app.kiddietrac.com/dashboard.html#families" '
            .'style="display:inline-block;background:#1F6080;color:#fff;text-decoration:none;padding:10px 20px;'
            .'border-radius:9px;font-size:14px;font-weight:700;">Open '.$e($family->family_name).'</a></p>';

        return $html;
    }
}
