<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WebDemoPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /parent/demo-pay {invoice_ids: [..], method: card|ach|interac|pad} - the website demo's simulated payment
 * (App\Support\WebDemoPayments). Only the invoice ids and the method's name are read: card or bank details are never
 * sent by the app and would be ignored if they were. Anyone who is not a website demo parent gets a 403.
 */
class DemoPayController extends Controller
{
    public function pay(Request $request): JsonResponse
    {
        $ids = (array) $request->input('invoice_ids', []);
        $method = (string) $request->input('method', '');
        try {
            return response()->json(WebDemoPayments::pay($request->user(), $ids, $method));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getMessage() === 'Online payments are not switched on for your centre.' ? 403 : 422);
        }
    }
}
