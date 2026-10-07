<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Made-up people and records for email previews (2026-10-06).
 *
 * Only ever used inside EmailPreviews::capture(), which opens a database transaction
 * before the first row is written here and rolls it back afterwards — none of this
 * survives the preview, and nothing else can see it while it exists (the rows are
 * uncommitted). Every address is @example.com, and every send in a preview is cancelled
 * before it reaches a transport.
 *
 * The centre is the agency's own (read, never changed), so a preview carries the
 * agency's real branding and centre name; the family, child and staff are invented.
 */
final class EmailSampleData
{
    public const PARENT_EMAIL = 'alex.sample@example.com';

    private array $made = [];

    private static array $cols = [];

    public function __construct(public readonly int $agencyId)
    {
    }

    /** Insert $row keeping only the columns the table really has. */
    public static function insert(string $table, array $row): int
    {
        if (! isset(self::$cols[$table])) {
            self::$cols[$table] = array_flip(Schema::getColumnListing($table));
        }
        $row = array_intersect_key($row, self::$cols[$table]);
        if (isset(self::$cols[$table]['created_at']) && ! isset($row['created_at'])) {
            $row['created_at'] = now();
        }
        if (isset(self::$cols[$table]['updated_at']) && ! isset($row['updated_at'])) {
            $row['updated_at'] = now();
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    private function once(string $k, callable $make)
    {
        if (! array_key_exists($k, $this->made)) {
            $this->made[$k] = $make();
        }

        return $this->made[$k];
    }

    public function agency(): object
    {
        return $this->once('agency', fn () => DB::table('agencies')->where('id', $this->agencyId)->first());
    }

    public function agencyName(): string
    {
        return (string) ($this->agency()->name ?? 'Your agency');
    }

    /**
     * Use a made-up centre instead of the agency's own, for senders that report on a
     * whole centre (an "incidents waiting" digest would otherwise list the real ones).
     */
    public function ownCentre(): object
    {
        return $this->once('centre', function () {
            $real = DB::table('centres')->where('agency_id', $this->agencyId)->whereNull('deleted_at')->orderBy('id')->first();
            $id = self::insert('centres', ['agency_id' => $this->agencyId, 'name' => 'Sunny Days (sample)', 'status' => 'active',
                'slug' => 'sample-' . bin2hex(random_bytes(5)),
                'open_time' => '07:00:00', 'close_time' => '18:00:00', 'logo_url' => $real->logo_url ?? null]);

            return DB::table('centres')->where('id', $id)->first();
        });
    }

    /** The agency's first centre, or a made-up one if it has none. */
    public function centre(): object
    {
        return $this->once('centre', function () {
            $c = DB::table('centres')->where('agency_id', $this->agencyId)->whereNull('deleted_at')->orderBy('id')->first();
            if ($c) {
                return $c;
            }
            $id = self::insert('centres', ['agency_id' => $this->agencyId, 'name' => 'Sunny Days', 'status' => 'active',
                'slug' => 'sample-' . bin2hex(random_bytes(5)),
                'open_time' => '07:00:00', 'close_time' => '18:00:00']);

            return DB::table('centres')->where('id', $id)->first();
        });
    }

    public function room(): object
    {
        return $this->once('room', function () {
            $r = DB::table('rooms')->where('centre_id', $this->centre()->id)->orderBy('id')->first();
            if ($r) {
                return $r;
            }
            $id = self::insert('rooms', ['centre_id' => $this->centre()->id, 'name' => 'Sunflowers', 'age_group' => 'preschool',
                'age_min_months' => 30, 'age_max_months' => 72, 'capacity' => 16, 'max_concurrent_children' => 16,
                'ratio_educators' => 1, 'ratio_children' => 8, 'active' => 1]);

            return DB::table('rooms')->where('id', $id)->first();
        });
    }

    private function user(string $k, string $first, string $last, string $email, ?string $role, ?int $centreId): object
    {
        return $this->once('user:' . $k, function () use ($first, $last, $email, $role, $centreId) {
            $id = self::insert('users', [
                'email' => $email, 'username' => 'sample.' . bin2hex(random_bytes(4)),
                'first_name' => $first, 'last_name' => $last, 'status' => 'active',
                'password' => bcrypt(bin2hex(random_bytes(12))), 'password_changed_at' => now()->subDays(80),
                'onboarded_at' => now()->subMonths(3), 'phone' => null,
            ]);
            if ($role) {
                self::insert('role_assignments', ['user_id' => $id, 'role' => $role, 'agency_id' => $this->agencyId,
                    'centre_id' => $centreId, 'active' => 1]);
            }

            return DB::table('users')->where('id', $id)->first();
        });
    }

    public function parent(): object
    {
        return $this->user('parent', 'Alex', 'Sample', self::PARENT_EMAIL, 'guardian', $this->centre()->id);
    }

    public function educator(): object
    {
        return $this->user('educator', 'Jordan', 'Lee', 'jordan.lee.sample@example.com', 'educator', $this->centre()->id);
    }

    public function director(): object
    {
        return $this->user('director', 'Sam', 'Rivera', 'sam.rivera.sample@example.com', 'centre_director', $this->centre()->id);
    }

    public function family(): object
    {
        return $this->once('family', function () {
            $id = self::insert('families', ['centre_id' => $this->centre()->id, 'family_name' => 'Sample',
                'primary_email' => self::PARENT_EMAIL, 'primary_phone' => null]);
            self::insert('guardians', ['family_id' => $id, 'user_id' => $this->parent()->id, 'relationship' => 'guardian',
                'is_primary' => 1, 'can_pickup' => 1, 'can_receive_billing' => 1]);

            return DB::table('families')->where('id', $id)->first();
        });
    }

    public function child(): object
    {
        return $this->once('child', function () {
            $id = self::insert('children', ['family_id' => $this->family()->id, 'first_name' => 'Ava', 'last_name' => 'Sample',
                'date_of_birth' => now()->subYears(4)->subMonths(3)->toDateString(), 'enrollment_status' => 'enrolled',
                'enrolled_at' => now()->subMonths(8), 'primary_room_id' => $this->room()->id]);
            self::insert('enrollments', ['child_id' => $id, 'room_id' => $this->room()->id,
                'start_date' => now()->subMonths(8)->toDateString()]);

            return DB::table('children')->where('id', $id)->first();
        });
    }

    /** The child row joined the way the absence and attendance senders read it. */
    public function childWithPlace(): object
    {
        $c = $this->child();

        return (object) array_merge((array) $c, [
            'centre_id' => $this->centre()->id, 'centre_name' => $this->centre()->name,
            'agency_id' => $this->agencyId, 'room_name' => $this->room()->name, 'family_name' => 'Sample',
        ]);
    }

    public function invoice(): object
    {
        return $this->once('invoice', function () {
            $id = self::insert('invoices', ['family_id' => $this->family()->id, 'centre_id' => $this->centre()->id,
                'invoice_number' => 'INV-1042', 'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(), 'subtotal' => 142.50, 'total' => 142.50,
                'amount_paid' => 0, 'balance_due' => 142.50, 'issued_at' => now()->subDays(3),
                'due_at' => now()->addDays(7)->toDateString(), 'status' => 'sent']);

            return DB::table('invoices')->where('id', $id)->first();
        });
    }

    public function payment(): object
    {
        return $this->once('payment', function () {
            $inv = $this->invoice();
            $id = self::insert('payments', ['invoice_id' => $inv->id, 'family_id' => $this->family()->id,
                'amount' => 142.50, 'method' => 'card', 'status' => 'succeeded', 'reference_number' => 'SAMPLE-1042',
                'paid_at' => now(), 'notes' => null]);

            return DB::table('payments')->where('id', $id)->first();
        });
    }

    /** The person looking at the preview, as the "actor" a sender names. */
    public function actor(): object
    {
        $u = request()->user();

        return $u ? (object) ['id' => $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'email' => $u->email]
            : (object) ['id' => 0, 'first_name' => 'Sam', 'last_name' => 'Rivera', 'email' => 'sam.rivera.sample@example.com'];
    }
}
