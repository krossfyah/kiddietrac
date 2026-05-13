<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicationController
{
    public function __call($method, $args)
    {
        return response()->json([
            'message' => 'Endpoint not implemented yet',
            'controller' => 'MedicationController',
            'method' => $method,
        ], 501);
    }
}
