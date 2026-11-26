<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WHO EACH KIND OF NOTIFICATION IS FOR (2026-11-06).
 *
 * Anthony: "for notifications the chats and messages under each role should reflect properly - i logged in as a
 * parent and I see notification types for staff and other things - full sweep", then "add guards to ensure roles
 * and responsibilities see their relevant settings, views etc".
 *
 * Every settings list a person sees (Settings → Notifications: Notify me about, Texts & calls, Sounds & vibration)
 * and every bell row Notify::write sends is one of three audiences:
 *
 *   staff   somebody working at a centre (Visibility::STAFF_ROLES, platform admins): clock, shifts, headcounts,
 *           the team chat, records and compliance reminders...
 *   family  a parent: their own child signed in, photos and moments, their invoices...
 *   all     both: chats, announcements, Safe Arrival, emergencies...
 *
 * THE LISTS take the caller's sides from the active agency (AgencyRole::names) and drop the other side's items;
 * each item carries `for`, so the screen can narrow further to the ROLE being viewed (a parent who also works there,
 * or a super admin viewing as a parent).
 *
 * THE SENDER drops a `staff` row addressed to somebody with no active staff role (2026-11-06: an educator account
 * switched to a parent kept getting "you're still clocked in" and staff team chat alerts). Fail closed: if the role
 * lookup breaks, staff-only rows are held back rather than sent to whoever.
 *
 * NEW KINDS: a type not listed here counts as `all` (never dropped), and kt:notify-audience-check fails nightly on
 * any type written in the last week that is not listed, and on any settings item without an audience.
 */
final class NotifyAudience
{
    /* sales_rep added 2026-11-07: a sales rep is staff (their own mailbox, proposals, bookings), but was missing here, so
       their lists fell through to the parent side and the sender dropped their staff rows (new-mail alerts). */
    public const STAFF_ROLES = ['agency_admin', 'auditor', 'centre_director', 'educator', 'home_visitor', 'platform_admin', 'reseller_admin', 'sales_rep'];

    /* WHICH STAFF ROLES EACH SETTING IS FOR (2026-11-07, Anthony: "notifications for example under home visitor why is
       there a head count notification showing when this role has nothing to do with that - full sweep for all roles").
       "staff" was one bucket, so a home visitor, an auditor or a sales rep was offered headcounts, sign-ins and Safe
       Arrival - things only the people working in a centre's rooms ever receive. A setting listed here shows on the
       STAFF side only to these roles (a parent still sees an "all" setting as a parent). Not listed = every staff role.
       Platform admins get everything; the screen narrows to the role they are viewing. */
    public const FLOOR = ['agency_admin', 'centre_director', 'educator', 'platform_admin'];

    public const SOUND_ROLES = [
        'email' => ['agency_admin', 'centre_director', 'educator', 'platform_admin', 'sales_rep'],
        'checkin' => self::FLOOR,
        'safety' => self::FLOOR,
        'headcount' => self::FLOOR,
        'billing' => ['agency_admin', 'centre_director', 'platform_admin', 'reseller_admin'],
        'staff' => ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'platform_admin'],
    ];

    /** Call reasons about a centre's building and children: only the people who work in one (and its parents). */
    public const VOICE_ROLES = [
        'evacuation' => self::FLOOR, 'lockdown' => self::FLOOR, 'illness' => self::FLOOR,
    ];

    /** Bell row type => audience. */
    public const TYPES = [
        // staff: the job
        'clock' => 'staff', 'clock_reminder' => 'staff', 'time_off' => 'staff', 'time_off_reminder' => 'staff',
        'award' => 'staff', 'educator_reward' => 'staff', 'bio_reminder' => 'staff', 'staff_notify_setup' => 'staff',
        'records_reminder' => 'staff', 'compliance' => 'staff', 'report_card_request' => 'staff',
        'report_card_reminder' => 'staff', 'headcount_due' => 'staff', 'headcount_strike' => 'staff',
        'ratio_breach' => 'staff', 'children_still_in' => 'staff', 'team_message' => 'staff', 'sms_reply' => 'staff',
        'voicemail' => 'staff', 'content_report' => 'staff', 'countersign_reminder' => 'staff', 'walk_quiet' => 'staff',
        'walk_auto_closed' => 'staff', 'sleep_check' => 'staff', 'immunization_record' => 'staff',
        'pfcc_copay_unpaid' => 'staff', 'family_joined' => 'staff', 'user_invited' => 'staff', 'occms_roa_due' => 'staff',
        'occms_roa_ready' => 'staff', 'drill_recorded' => 'staff', 'drill_scheduled' => 'staff', 'drill_missed' => 'staff', 'document_shared' => 'staff', 'family_file' => 'staff',
        'email' => 'staff', 'proposal_opened' => 'staff', 'booking_request' => 'staff', 'child_note' => 'staff',
        'account_deletion' => 'staff', 'sub_request' => 'staff', 'staff_message' => 'staff', 'running_late' => 'staff', 'break_overrun' => 'staff', 'photo_permission' => 'staff',
        'cold_reach' => 'staff',   // sales outreach replies (ColdReach) (2026-11-24, ticket #260)
        // family: their own children and account
        'checkin' => 'family', 'checkin_reminder' => 'family', 'pickup_alarm' => 'family', 'moment' => 'family', 'photo' => 'family',
        'observation' => 'family', 'walk_started' => 'family', 'walk_ended' => 'family',
        'immunization_reminder' => 'family', 'form_correction' => 'family', 'late_pickup' => 'family',
        'marketing' => 'family', 'menu' => 'family', 'payment_plan' => 'family', 'invoice' => 'family',
        'withdrawal' => 'family', 'report_card' => 'family',
        // all
        'absence' => 'all', 'announcement' => 'all', 'attendance' => 'all', 'birthday' => 'all', 'chat' => 'all',
        'message' => 'all', 'nudge' => 'all', 'call' => 'all', 'call_missed' => 'all', 'closure' => 'all',
        'incident' => 'all', 'payment' => 'all', 'safe_arrival' => 'all', 'schedule' => 'all',
        'support_ticket' => 'all', 'transfer' => 'all', 'portal_tip' => 'all', 'general' => 'all',
        'pickup_auth' => 'all',   // pick-up list changes go to room staff, leads AND the family (2026-11-24, ticket #260)
        'meeting' => 'all',   // invitations and replies: staff, parents, leads and guests can all be on a meeting (2026-11-13, ticket #253)
    ];

    /** Sounds & vibration category (NotificationSounds::CATEGORIES) => audience. */
    public const SOUND_CATEGORIES = [
        'chat' => 'all', 'nudge' => 'all', 'call' => 'all', 'announcement' => 'all', 'email' => 'staff',
        'checkin' => 'all', 'safety' => 'all', 'photos' => 'family', 'billing' => 'all', 'forms' => 'all',
        'staff' => 'staff', 'headcount' => 'staff', 'general' => 'all', 'emergency' => 'all',
        'reminder' => 'all',   // reminder toasts: staff, and parents for meetings they were invited to (2026-11-26)
    ];

    /** Optional text kinds (ContactCategories::SMS) => audience. Who each is sent to: the senders' recipients. */
    public const SMS = [
        'checkin' => 'family', 'checkin_reminder' => 'family', 'announcement' => 'all', 'broadcast' => 'all',
        'chat_reply' => 'family',
    ];

    /** Optional call reasons (VoiceController::CATEGORIES) => audience. */
    public const VOICE = [
        'closure' => 'all', 'evacuation' => 'all', 'lockdown' => 'all', 'illness' => 'all', 'emergency' => 'all',
        'broadcast' => 'all',
    ];

    /** "Notify me about…" events (NotificationPrefsController::EVENTS) => audience. */
    public const PREF_EVENTS = [
        'check_in_out' => 'family', 'daily_moments' => 'family', 'learning_observations' => 'family', 'photos_videos' => 'family',
        'safe_arrival' => 'staff',   // directors and admins choose their Safe Arrival channels (2026-11-24; mapped here 2026-11-25, ticket #268)
        // What admins and directors choose to hear about (StaffNotifyPrefs::TOPICS, 2026-11-26).
        'staff_clock' => 'staff', 'staff_absences' => 'staff', 'staff_countersign' => 'staff', 'staff_compliance' => 'staff',
        'staff_payments' => 'staff', 'staff_billing_copies' => 'staff', 'staff_pickup' => 'staff', 'staff_closures' => 'staff',
        'staff_schedule' => 'staff', 'staff_tips' => 'staff', 'staff_cold_reach' => 'staff', 'staff_tickets' => 'staff',
    ];

    public static function ofType(?string $type): string
    {
        return self::TYPES[(string) $type] ?? 'all';
    }

    /**
     * The caller's sides in the agency they are working in: ['staff' => bool, 'family' => bool].
     * A parent is anyone with a guardian role there, or a guardian record (some parents have no role row).
     */
    public static function sides(Request $request): array
    {
        $names = AgencyRole::names($request);
        /* EXACTLY WHAT THEY RECEIVE HERE (2026-11-26, Anthony: "as admin I can turn on and off parent notifications -
           this needs to reflect the exact roles"). A platform admin used to get BOTH sides and every staff role, so a
           super admin working as iLearn's admin was offered parent switches. Now:
             - previewing a role ("View as", X-View-As-Role, platform admins only): exactly that role's lists;
             - otherwise: their real roles in the ACTIVE agency, and the family side only if they are a parent IN IT
               (a guardian record at another agency does not count). */
        $agency = AgencyRole::activeAgency($request);
        if (in_array('platform_admin', $names, true)) {
            $view = strtolower(trim((string) $request->header('X-View-As-Role')));
            if ($view !== '' && $view !== 'platform_admin') {
                $isStaff = in_array($view, self::STAFF_ROLES, true);
                return ['staff' => $isStaff, 'family' => $view === 'guardian', 'roles' => $isStaff ? [$view] : [], 'preview' => $view];
            }
            /* AgencyRole never narrows a platform admin, so their roles here are every agency's (Anthony: parent at
               the Test Agency, admin at iLearn). Narrow to the agency on screen for these lists. */
            $header = (int) $request->header('X-Active-Agency-Id');
            if ($agency === null && $header > 0) {
                $agency = $header;
                $centres = AgencyRole::centreIds($header);
                $names = ['platform_admin'];
                foreach (UserRoles::all($request) as $r) {
                    if ((int) $r->agency_id === $header || ($r->centre_id && in_array((int) $r->centre_id, $centres, true))) {
                        $names[] = (string) $r->role;
                    }
                }
                $names = array_values(array_unique($names));
            }
        }
        $staffRoles = array_values(array_intersect($names, self::STAFF_ROLES));
        $staff = (bool) $staffRoles;
        $family = in_array('guardian', $names, true);
        if (! $family) {
            try {
                $g = DB::table('guardians as g')->where('g.user_id', (int) $request->user()->id);
                if ($agency !== null) {
                    $g->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                        ->where('c.agency_id', $agency);
                }
                $family = $g->exists();
            } catch (\Throwable $e) {
            }
        }
        if (! $staff && ! $family) {
            $family = true;   // nobody is left with an empty screen; a parent's list is the harmless one
        }

        return ['staff' => $staff, 'family' => $family, 'roles' => $staffRoles];
    }

    /** Does an item with this audience (and, on the staff side, these roles) belong on this person's lists? */
    public static function shows(string $audience, array $sides, ?array $roles = null): bool
    {
        return self::tag($audience, $sides, $roles) !== null;
    }

    /**
     * THE ONE RULE for every per-person list and its save guard (2026-11-26): which hat an item reaches this person
     * in - 'staff', 'parent', 'both' - or null when it never reaches them. A staff item with `roles` needs one of
     * those roles (platform_admin no longer passes every list: an item for it says so in its roles).
     */
    public static function tag(string $audience, array $sides, ?array $roles = null): ?string
    {
        $myRoles = (array) ($sides['roles'] ?? []);
        $staff = $audience !== 'family' && ! empty($sides['staff']) && ($roles === null || array_intersect($myRoles, $roles));
        $family = $audience !== 'staff' && ! empty($sides['family']);
        if ($staff && $family) return 'both';
        if ($staff) return 'staff';

        return $family ? 'parent' : null;
    }

    /**
     * Which of these users hold an active staff role anywhere, as [user_id => true].
     * Throws on a failed lookup: the caller holds staff rows back (fail closed).
     */
    public static function staffUsers(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        return DB::table('role_assignments')->whereIn('user_id', $userIds)->where('active', true)
            ->whereIn('role', self::STAFF_ROLES)->distinct()->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }
}
