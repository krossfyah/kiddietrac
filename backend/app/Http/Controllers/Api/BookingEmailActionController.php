<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingMail;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DECIDE A MEETING REQUEST FROM THE EMAIL (2026-10-23, Anthony: "I want to be able to
 * approve, change or decline meeting without logging into the portal").
 *
 * The sales notice carries Accept / Change time / Decline links to
 * app.kiddietrac.com/booking-action.html. Each link is signed for ONE booking and ONE
 * action, and expires when the meeting starts. Opening it changes nothing - the page shows
 * the request and the person presses a button (mail scanners pre-open links). The decision
 * runs the very same SalesBookingController code as the Bookings screen, as the email
 * approver (PlatformSettings sales.booking.email_actor, default Anthony, user 1), and is
 * audited as made by email link.
 */
class BookingEmailActionController extends Controller
{
    private const ACTIONS = ['accept', 'modify', 'decline'];

    public static function actor(): int
    {
        return (int) (PlatformSettings::get('sales.booking.email_actor', 1) ?: 1);
    }

    private static function sig(int $id, string $a, int $exp): string
    {
        return substr(hash_hmac('sha256', 'bkact|' . $id . '|' . $a . '|' . $exp . '|' . self::actor(), (string) config('app.key')), 0, 32);
    }

    public static function link(object $b, string $action): string
    {
        $exp = \Carbon\Carbon::parse($b->starts_at, 'UTC')->timestamp;
        return 'https://app.kiddietrac.com/booking-action.html?' . http_build_query(['b' => (int) $b->id, 'a' => $action, 'e' => $exp, 's' => self::sig((int) $b->id, $action, $exp)]);
    }

    private function check(Request $request, int $id): array
    {
        $a = (string) $request->query('a', $request->input('a', ''));
        $exp = (int) $request->query('e', $request->input('e', 0));
        $s = (string) $request->query('s', $request->input('s', ''));
        abort_unless(in_array($a, self::ACTIONS, true) && hash_equals(self::sig($id, $a, $exp), $s), 404, 'This link is not valid.');
        $b = DB::table('marketing_bookings')->where('id', $id)->first();
        abort_unless($b, 404, 'This link is not valid.');
        return [$b, $a, $exp];
    }

    /** GET /marketing-site/booking-action/{id}?a=&e=&s= — what the page shows. Changes nothing. */
    public function show(Request $request, int $id): JsonResponse
    {
        [$b, $a, $exp] = $this->check($request, $id);
        $decider = $b->decided_by ? DB::table('users')->where('id', $b->decided_by)->value('first_name') : null;
        return response()->json([
            'action' => $a, 'expired' => time() > $exp, 'status' => $b->status, 'decided_by' => $decider, 'decided_at' => $b->decided_at,
            'booking' => [
                'name' => $b->name, 'email' => $b->email, 'phone' => $b->phone, 'agency' => $b->agency, 'children' => $b->children, 'notes' => $b->notes,
                'type' => $b->type, 'type_name' => BookingMail::typeName($b), 'duration_min' => (int) $b->duration_min, 'meet_via' => $b->meet_via,
                'when_et' => BookingMail::whenEt($b->starts_at), 'when_local' => BookingMail::whenLocal($b),
                'local_date' => \Carbon\Carbon::parse($b->starts_at, 'UTC')->tz(BookingMail::TZ)->toDateString(),
                'local_time' => \Carbon\Carbon::parse($b->starts_at, 'UTC')->tz(BookingMail::TZ)->format('H:i'), 'teams_url' => $b->teams_url, 'invitees' => \App\Support\BookingTeams::invitees($b),
            ],
            'teams_auto' => \App\Support\BookingTeams::available(), 'default_link' => (string) PlatformSettings::get('sales.booking.teams_link', ''),
            'types' => collect(BookingMail::TYPES)->map(fn ($t, $k) => ['id' => $k, 'name' => $t['name'], 'min' => $t['min']])->values(),
            'actor' => DB::table('users')->where('id', self::actor())->value('first_name'),
            // The other two decisions, so a person who opened "Accept" can still change their mind.
            'links' => collect(self::ACTIONS)->mapWithKeys(fn ($x) => [$x => self::link($b, $x)]),
        ]);
    }

    /** POST /marketing-site/booking-action/{id}?a=&e=&s= — make the decision, as the email approver. */
    public function submit(Request $request, int $id): JsonResponse
    {
        [$b, $a, $exp] = $this->check($request, $id);
        if (time() > $exp) return response()->json(['message' => 'This meeting time has passed, so the link has expired. Open Sales → Bookings in the portal.'], 410);
        $user = \App\Models\User::find(self::actor());
        abort_unless($user, 500, 'No approver is set up for email decisions.');
        $inner = Request::create('/api/v1/sales/bookings/' . $id . '/' . $a, 'POST', $request->except(['a', 'e', 's', 'b']));
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $user);
        try {
            $res = app(SalesBookingController::class)->{$a}($inner, $id);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage() ?: 'That could not be done.'], $e->getStatusCode());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first() ?: 'Please check the form.'], 422);
        }
        \App\Support\Audit::write(['user_id' => $user->id, 'agency_id' => null, 'action' => 'booking.email_decision', 'entity_type' => 'marketing_booking', 'entity_id' => $id,
            'payload' => json_encode(['summary' => ['accept' => 'Accepted', 'modify' => 'Changed', 'decline' => ($b->status === 'booked' ? 'Cancelled' : 'Declined')][$a] . ' from the email link (not signed in): ' . $b->name . ' — ' . BookingMail::whenEt($b->starts_at)]),
            'ip_address' => $request->ip(), 'created_at' => now()]);
        return $res;
    }
}
