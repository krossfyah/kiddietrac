<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * WHAT AN AUDIT ROW SAYS, IN WORDS (2026-10-20).
 *
 * Anthony: "all audit log entries shouldn't be raw and should detail - full sweep to ensure
 * this does not repeat". The write-auditing middleware records a request as
 * "post:api/v1/attendance/pattern/48", and the reader turned that into URL pieces
 * ("Created · provider › check in") for 381 of the 517 kinds of change seen in 90 days.
 *
 *   - Every API write route has a sentence in resources/audit-labels.txt (861 of them),
 *     matched by method + route shape, ids and all.
 *   - A route that is not in the file still gets a readable sentence built from its words,
 *     never the URL - and `php artisan kt:audit-labels` lists such routes so the file is
 *     kept complete as routes are added.
 *   - A read is only audited when it FAILS, so a GET reads "Couldn't load …".
 *   - A failed write says why: not allowed, not found, rejected, server error.
 *   - entityType() turns the route-parameter names the middleware stored ("childId", "u",
 *     "ec") into the record kind ("child", "user", "emergency_contact"), so a row lands in
 *     that record's history.
 */
final class AuditLabels
{
    private static ?array $byMethod = null;

    /** @return array<string, array<int, array{0:string,1:string,2:string}>> method => [[regex, label, uri]] */
    private static function catalogue(): array
    {
        if (self::$byMethod !== null) {
            return self::$byMethod;
        }
        self::$byMethod = [];
        $file = resource_path('audit-labels.txt');
        if (! is_file($file)) {
            return self::$byMethod;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, ' | ')) {
                continue;
            }
            [$key, $label] = array_map('trim', explode(' | ', $line, 2));
            [$method, $uri] = array_pad(explode(' ', $key, 2), 2, '');
            $rx = '#^' . preg_replace('#\\\\\{[^}]+\\\\\}#', '[^/]+', preg_quote(trim($uri, '/'), '#')) . '$#i';
            self::$byMethod[strtolower($method)][] = [$rx, $label, $uri];
        }
        // Literal paths before patterned ones: "invoices/generate" must beat "invoices/{id}".
        foreach (self::$byMethod as $m => $list) {
            usort($list, fn ($a, $b) => substr_count($a[2], '{') <=> substr_count($b[2], '{'));
            self::$byMethod[$m] = $list;
        }

        return self::$byMethod;
    }

    /** The catalogue sentence for method + path (path as recorded, with or without api/v1/). */
    public static function lookup(string $method, string $path): ?string
    {
        $path = trim(preg_replace('#^/?api/v1/#', '', $path), '/');
        foreach (self::catalogue()[strtolower($method)] ?? [] as [$rx, $label]) {
            if (preg_match($rx, $path)) {
                return $label;
            }
        }

        return null;
    }

    /** Is method + uri (route form) in the catalogue? For kt:audit-labels. */
    public static function has(string $method, string $uri): bool
    {
        $uri = trim(preg_replace('#^api/v1/#', '', $uri), '/');
        foreach (self::catalogue()[strtolower($method)] ?? [] as [, , $u]) {
            if (trim($u, '/') === $uri) {
                return true;
            }
        }

        return false;
    }

    private const WHY = [
        400 => 'bad request', 401 => 'signed out', 403 => 'not allowed', 404 => 'not found',
        405 => 'not allowed', 409 => 'conflict with existing data', 410 => 'no longer available',
        413 => 'file too large', 419 => 'session expired', 422 => 'not accepted - check the details',
        423 => 'locked', 429 => 'too many attempts',
    ];

    private static function why(?int $status): string
    {
        if (! $status) {
            return '';
        }
        if ($status >= 500) {
            return 'server error';
        }

        return self::WHY[$status] ?? ('error ' . $status);
    }

    /** "attendance/pattern/48" -> "attendance pattern"; role prefixes and ids dropped. */
    private static function words(string $path): string
    {
        $skip = ['api', 'v1', 'admin', 'provider', 'parent', 'director', 'platform', 'agency', 'me', 'auth'];
        $segs = array_values(array_filter(explode('/', trim($path, '/')), function ($s) use ($skip) {
            return $s !== '' && ! ctype_digit($s) && ! preg_match('/^[0-9a-f-]{20,}$/i', $s)
                && ! str_starts_with($s, '{') && ! in_array(strtolower($s), $skip, true);
        }));
        $w = strtolower(str_replace(['-', '_'], ' ', implode(' ', $segs)));

        return trim(preg_replace('/\s+/', ' ', $w));
    }

    /**
     * The sentence for an audit row's action, or null for a named event this class does
     * not handle (the caller keeps its own wording for those).
     */
    public static function label(string $action, ?array $payload = null): ?string
    {
        $action = trim($action);
        $failed = str_contains($action, '[fail]');
        $action = trim(str_replace('[fail]', '', $action));
        $status = isset($payload['status']) ? (int) $payload['status'] : null;
        $why = $failed ? self::why($status) : '';

        $method = null;
        $path = null;
        if (preg_match('#^(get|post|put|patch|delete):(.+)$#i', $action, $m)) {
            $method = strtolower($m[1]);
            $path = $m[2];
        } elseif (preg_match('/^[a-z0-9_.-]+$/i', $action) && ($route = Route::getRoutes()->getByName($action))) {
            // A NAMED route: the middleware records the route name instead of the path.
            $method = strtolower(collect($route->methods())->first(fn ($x) => $x !== 'HEAD') ?? 'post');
            $path = $route->uri();
        }
        if ($method === null) {
            return null;
        }

        if ($method === 'get') {
            $w = self::words($path);

            return "Couldn't load " . ($w !== '' ? $w : 'a page') . ($why ? ' (' . $why . ')' : '');
        }

        $label = self::lookup($method, $path) ?? self::generated($method, $path);

        return $label . ($failed ? ' - failed' . ($why ? ' (' . $why . ')' : '') : '');
    }

    /** A readable sentence for a route the catalogue does not know yet. Never the URL. */
    public static function generated(string $method, string $path): string
    {
        $segs = array_values(array_filter(explode('/', trim(preg_replace('#^/?api/v1/#', '', $path), '/')),
            fn ($s) => $s !== '' && ! ctype_digit($s) && ! str_starts_with($s, '{')));
        $tailVerbs = [
            'sign' => 'Signed', 'draft' => 'Saved a draft of', 'resend' => 'Re-sent', 'send' => 'Sent',
            'promote' => 'Promoted', 'decline' => 'Declined', 'remind' => 'Sent a reminder about',
            'approve' => 'Approved', 'reject' => 'Rejected', 'restore' => 'Restored', 'archive' => 'Archived',
            'suspend' => 'Suspended', 'resume' => 'Resumed', 'cancel' => 'Cancelled', 'submit' => 'Submitted',
            'void' => 'Voided', 'email' => 'Emailed', 'test' => 'Tested', 'preview' => 'Previewed',
            'upload' => 'Uploaded a file to', 'status' => 'Changed the status of', 'settings' => 'Changed the settings for',
        ];
        $tail = end($segs);
        $rest = $segs;
        $verb = ['post' => 'Created', 'put' => 'Updated', 'patch' => 'Updated', 'delete' => 'Deleted'][$method] ?? 'Changed';
        if ($tail !== false && isset($tailVerbs[strtolower($tail)])) {
            $verb = $tailVerbs[strtolower($tail)];
            array_pop($rest);
        }
        $w = self::words(implode('/', $rest));

        return $verb . ' ' . ($w !== '' ? $w : 'a record');
    }

    private const ENTITY = [
        'u' => 'user', 'users' => 'user', 'user' => 'user', 'ec' => 'emergency_contact',
        'po' => 'purchase_order', 'bill' => 'expense_invoice', 'req' => 'file_request',
        'txn' => 'grant_transaction', 'rid' => 'reminder', 'doc' => 'document',
        'children' => 'child', 'families' => 'family', 'centres' => 'centre', 'rooms' => 'room',
        'agencies' => 'agency', 'invoices' => 'invoice', 'guardians' => 'guardian',
        'managed-forms' => 'managed_form', 'managed_forms' => 'managed_form',
    ];

    /** "childId" / "child_id" / "children" -> "child"; "u" -> "user". Null stays null. */
    public static function entityType(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return $type;
        }
        $t = trim($type);
        $t = preg_replace('/(?<=[a-z])Id$/', '', $t);                 // childId -> child
        $t = strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '_$1', $t)); // familyId handled above; camel -> snake
        $t = preg_replace('/_id$/', '', $t);

        return self::ENTITY[$t] ?? $t;
    }

    /** Every stored spelling that means this kind, for filtering old rows. */
    public static function entityVariants(string $type): array
    {
        $want = self::entityType($type);
        $out = [$type, $want];
        foreach (self::ENTITY as $k => $v) {
            if ($v === $want) {
                $out[] = $k;
            }
        }
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $want))));
        array_push($out, $want . '_id', $camel . 'Id');

        return array_values(array_unique(array_filter($out)));
    }
}
