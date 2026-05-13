<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiDigestController
{
    public function __call($method, $args)
    {
        return response()->json([
            'message' => 'Endpoint not implemented yet',
            'controller' => 'AiDigestController',
            'method' => $method,
        ], 501);
    }
}
