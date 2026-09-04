<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Geocode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every property a point on the map.
 *
 * The walk auto-detection fences the provider's address, which it cannot do while
 * centres.latitude/longitude are null — and they are null for any property whose
 * address was typed rather than picked. This fills the gaps once.
 *
 * Deliberately conservative:
 *   · a centre that already has coordinates is left alone, because somebody may have
 *     set them by hand and a nightly job that overwrites a human correction is worse
 *     than a missing value;
 *   · one request per second, which is what OpenStreetMap's usage policy asks of an
 *     anonymous caller, and the reason this is a command rather than something that
 *     runs inside a web request;
 *   · --dry shows what it would do and touches nothing.
 */
class GeocodeCentres extends Command
{
    protected $signature = 'centres:geocode
                            {--agency= : only this agency}
                            {--centre= : only this centre}
                            {--force : re-do centres that already have coordinates}
                            {--dry : show what would happen, change nothing}';

    protected $description = 'Fill in latitude/longitude for centres from their address';

    public function handle(): int
    {
        $q = DB::table('centres')->whereNull('deleted_at')
            ->when($this->option('agency'), fn ($x) => $x->where('agency_id', (int) $this->option('agency')))
            ->when($this->option('centre'), fn ($x) => $x->where('id', (int) $this->option('centre')))
            ->when(! $this->option('force'), function ($x) {
                $x->where(function ($w) {
                    $w->whereNull('latitude')->orWhereNull('longitude');
                });
            });

        $centres = $q->get(['id', 'name', 'address_line1', 'city', 'postal_code', 'latitude', 'longitude']);
        if ($centres->isEmpty()) {
            $this->info('Every centre already has coordinates.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        $done = 0;
        $failed = 0;

        foreach ($centres as $c) {
            $where = trim((string) $c->address_line1);
            if ($where === '') {
                $this->warn(sprintf('  #%-3d %-24s no address on file — skipped', $c->id, mb_substr((string) $c->name, 0, 24)));
                $failed++;
                continue;
            }

            $hit = Geocode::forward($where, $c->city, $c->postal_code);
            if (! $hit) {
                $this->warn(sprintf('  #%-3d %-24s could not be placed: %s', $c->id,
                    mb_substr((string) $c->name, 0, 24), $where . ', ' . $c->city));
                $failed++;
            } else {
                $this->line(sprintf('  #%-3d %-24s %.6f, %.6f%s', $c->id,
                    mb_substr((string) $c->name, 0, 24), $hit['lat'], $hit['lon'], $dry ? '   [dry]' : ''));
                if (! $dry) {
                    DB::table('centres')->where('id', $c->id)->update([
                        'latitude' => $hit['lat'],
                        'longitude' => $hit['lon'],
                        'updated_at' => now(),
                    ]);
                    /* Written down: a coordinate that appeared on a property with no
                       explanation is its own small mystery, and this one came from a
                       third party rather than from anybody here. */
                    try {
                        \App\Support\Audit::write([
                            'user_id' => null,
                            'agency_id' => $c->agency_id ?? DB::table('centres')->where('id', $c->id)->value('agency_id'),
                            'action' => 'centre.geocoded',
                            'entity_type' => 'centre',
                            'entity_id' => (int) $c->id,
                            'payload' => json_encode([
                                'centre_name' => $c->name,
                                'from_address' => trim($where . ', ' . $c->city . ' ' . $c->postal_code),
                                'latitude' => $hit['lat'],
                                'longitude' => $hit['lon'],
                                'source' => 'OpenStreetMap Nominatim',
                            ]),
                            'created_at' => now(),
                        ]);
                    } catch (\Throwable $e) {
                        // never fail the fill because the note could not be written
                    }
                }
                $done++;
            }

            // OpenStreetMap asks for no more than one request a second.
            if ($centres->count() > 1) {
                sleep(1);
            }
        }

        $this->info(sprintf('%splaced %d, could not place %d', $dry ? '[dry] would have ' : '', $done, $failed));

        return self::SUCCESS;
    }
}
