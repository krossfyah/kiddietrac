<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObservationController
{
    public function __call($method, $args)
    {
        return response()->json([
            'message' => 'Endpoint not implemented yet',
            'controller' => 'ObservationController',
            'method' => $method,
        ], 501);
    }
}
