<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /media/p/{kind}/{id} - the door for App\Support\PrivateFiles (2026-11-28).
 *
 * The `signed` middleware has proved the link is ours and under TTL_MINUTES old. That is not
 * enough on its own: the link names a person and an agency, and both are checked again HERE,
 * against the record, with the same scope code the screen's list uses. Someone who has left the
 * agency since, a director whose centres changed, or a record that is gone, all get 404 (not 403:
 * no oracle for whether the record exists).
 */
final class PrivateFileController extends Controller
{
    public function show(Request $request, string $kind, int $id): Response
    {
        abort_unless(isset(PrivateFiles::KINDS[$kind]), 404);
        $agencyId = (int) $request->query('a');
        $as = PrivateFiles::actingRequest((int) $request->query('u'), $agencyId);
        abort_unless($as !== null, 404);

        $path = match ($kind) {
            'bgc' => app(BackgroundCheckController::class)->documentPathFor($as, $id, $agencyId),
            'card' => app(ContactController::class)->cardPathFor($as, $id, $agencyId),
        };
        abort_unless($path !== null, 404);

        return PrivateFiles::stream($path, $request->query('n'));
    }
}
