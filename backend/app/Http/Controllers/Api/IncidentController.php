<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Stub controller for routes referenced in routes/api.php.
 * Returns 501 for any method until the real implementation lands.
 */
final class IncidentController extends Controller
{
    public function __call($method, $args): JsonResponse
    {
        return response()->json([
            'message' => 'Endpoint not implemented yet',
            'controller' => 'IncidentController',
            'method' => $method,
        ], 501);
    }
}
