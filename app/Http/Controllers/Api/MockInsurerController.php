<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use App\Services\MockInsurerGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockInsurerController extends Controller
{
    public function __construct(protected MockInsurerGateway $gateway) {}

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->all();
        $result = $this->gateway->processCallback($payload);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $policy = PolicyRequest::findOrFail((int) $request->input('policy_request_id'));
        $result = $this->gateway->reconcile($policy);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
