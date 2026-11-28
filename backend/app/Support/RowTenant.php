<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The agency (or agencies) a database row belongs to, for FileRefs (2026-11-28).
 *
 * agency_id when the row has one; else through centre_id / room_id / child_id / family_id; documents through their
 * scope; a form sign-off through its form; a menu item through its week; a row keyed only by a PERSON (users,
 * user-scoped documents) answers every agency that person belongs to. Rows that belong to no agency (platform and
 * sales tables, shared libraries) answer [] and are not judged.
 */
final class RowTenant
{
    private array $memo = [];

    /** @return int[] */
    public function agencies(string $table, object $row): array
    {
        $r = (array) $row;
        if ($table === 'users') {
            return isset($r['id']) ? FileRefs::memberships((int) $r['id']) : [];
        }
        if ($table === 'documents') {
            $id = (int) ($r['scope_id'] ?? 0);
            return match ((string) ($r['scope_type'] ?? '')) {
                'child' => array_filter([$this->child($id)]),
                'user' => FileRefs::memberships($id),
                'family' => array_filter([$this->family($id)]),
                'centre' => array_filter([$this->centre($id)]),
                'agency' => $id ? [$id] : [],
                default => [],
            };
        }
        if (! empty($r['agency_id'])) return [(int) $r['agency_id']];
        if (! empty($r['centre_id'])) return array_filter([$this->centre((int) $r['centre_id'])]);
        if (! empty($r['room_id'])) return array_filter([$this->room((int) $r['room_id'])]);
        if (! empty($r['child_id'])) return array_filter([$this->child((int) $r['child_id'])]);
        if (! empty($r['family_id'])) return array_filter([$this->family((int) $r['family_id'])]);
        if (! empty($r['managed_form_id'])) return array_filter([FileRefs::formAgency((int) $r['managed_form_id'])]);
        if (! empty($r['menu_week_id'])) {
            $c = $this->memo['mw'][(int) $r['menu_week_id']] ??= (int) DB::table('menu_weeks')->where('id', (int) $r['menu_week_id'])->value('centre_id');
            return $c ? array_filter([$this->centre($c)]) : [];
        }
        return [];
    }

    private function centre(int $id): ?int
    {
        if (! isset($this->memo['c'])) {
            $this->memo['c'] = DB::table('centres')->pluck('agency_id', 'id')->map(fn ($v) => (int) $v)->all();
        }
        return $this->memo['c'][$id] ?? null;
    }

    private function room(int $id): ?int
    {
        $c = $this->memo['r'][$id] ??= (int) DB::table('rooms')->where('id', $id)->value('centre_id');
        return $c ? $this->centre($c) : null;
    }

    private function family(int $id): ?int
    {
        $c = $this->memo['f'][$id] ??= (int) DB::table('families')->where('id', $id)->value('centre_id');
        return $c ? $this->centre($c) : null;
    }

    private function child(int $id): ?int
    {
        $f = $this->memo['ch'][$id] ??= (int) DB::table('children')->where('id', $id)->value('family_id');
        return $f ? $this->family($f) : null;
    }
}
