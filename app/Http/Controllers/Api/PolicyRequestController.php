<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use App\Services\PolicyWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PolicyRequestController extends Controller
{
    public function __construct(protected PolicyWorkflowService $workflowService) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $perPage = max(1, (int) $request->query('per_page', 15));
        $results = $this->workflowService->listRequests($status, $perPage);

        return response()->json([
            'data' => $results->items(),
            'pagination' => [
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $payload = $this->workflowService->validateRequestPayload($request->all());

            $result = DB::transaction(function () use ($payload) {
                return $this->workflowService->createRequest($payload);
            });

            $policy = $result['policy'];
            $statusCode = $result['created'] ? 201 : 200;

            return response()->json([
                'success' => true,
                'message' => $statusCode === 201 ? 'Policy request created.' : 'Existing policy request returned safely.',
                'data' => $this->serializePolicy($policy),
            ], $statusCode);
        } catch (InvalidArgumentException $exception) {
            $errors = json_decode($exception->getMessage(), true);
            if (! is_array($errors)) {
                $errors = ['message' => $exception->getMessage()];
            }

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
    }

    public function show(PolicyRequest $policyRequest): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->serializePolicy($policyRequest->load(['state_history', 'issuance_attempts', 'provider_events'])),
        ]);
    }

    public function submit(Request $request, PolicyRequest $policyRequest): JsonResponse
    {
        $scenario = $request->input('scenario', 'SUCCESS');

        try {
            $result = $this->workflowService->submitRequest($policyRequest->id, $scenario);

            return response()->json([
                'success' => true,
                'data' => $this->serializePolicy($result['policy']->fresh()->load(['state_history', 'issuance_attempts', 'provider_events'])),
                'provider' => $result['provider'] ?? null,
                'message' => $result['provider']['message'] ?? 'Submission processed.',
            ], $result['status'] ?? 200);
        } catch (\Throwable $exception) {
            Log::error('Submission failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Submission failed.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    protected function serializePolicy(PolicyRequest $policy): array
    {
        return [
            'id' => $policy->id,
            'client_request_id' => $policy->client_request_id,
            'agent_id' => $policy->agent_id,
            'customer_name' => $policy->customer_name,
            'date_of_birth' => $policy->date_of_birth,
            'destination' => $policy->destination,
            'trip_start' => $policy->trip_start,
            'trip_end' => $policy->trip_end,
            'plan_code' => $policy->plan_code,
            'sum_insured' => (int) $policy->sum_insured,
            'premium_amount' => $policy->premium_amount,
            'premium_snapshot' => $policy->premium_snapshot,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'state' => $policy->state,
            'provider_status' => $policy->provider_status,
            'attempt_count' => (int) $policy->attempt_count,
            'last_response' => $policy->last_response,
            'audit_trail' => $policy->state_history()->orderBy('created_at')->get()->map(function ($history) {
                return [
                    'from_state' => $history->from_state,
                    'to_state' => $history->to_state,
                    'meta' => $history->meta,
                    'created_at' => $history->created_at,
                ];
            })->all(),
        ];
    }
}
