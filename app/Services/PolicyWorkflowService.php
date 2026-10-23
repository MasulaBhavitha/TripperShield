<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PolicyWorkflowService
{
    public function __construct(
        protected PolicyPricingService $pricingService,
        protected MockInsurerGateway $mockInsurerGateway,
    ) {}

    public function createRequest(array $data): array
    {
        $payload = $this->normalizeRequestPayload($data);

        return DB::transaction(function () use ($payload) {
            $existing = PolicyRequest::where('agent_id', $payload['agent_id'])
                ->where('client_request_id', $payload['client_request_id'])
                ->first();

            if ($existing) {
                return ['policy' => $existing, 'created' => false];
            }

            try {
                $policy = PolicyRequest::create($payload);
                $policy->state = 'VALIDATED';
                $policy->save();
                $policy->state_history()->create([
                    'from_state' => 'RECEIVED',
                    'to_state' => 'VALIDATED',
                    'meta' => ['validated' => true],
                ]);

                return ['policy' => $policy, 'created' => true];
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
                    $existing = PolicyRequest::where('agent_id', $payload['agent_id'])
                        ->where('client_request_id', $payload['client_request_id'])
                        ->firstOrFail();

                    return ['policy' => $existing, 'created' => false];
                }

                throw $exception;
            }
        }, 3);
    }

    public function submitRequest(int $id, string $scenario = 'SUCCESS'): array
    {
        $policy = PolicyRequest::findOrFail($id);

        if ($policy->state === 'ISSUED' && $policy->policy_number) {
            return [
                'status' => 200,
                'policy' => $policy,
                'message' => 'Request already issued; duplicate submission ignored.',
            ];
        }

        if ($policy->state === 'PENDING_CONFIRMATION' || $policy->state === 'SUBMITTED') {
            $reconciliation = $this->mockInsurerGateway->reconcile($policy);

            if ($reconciliation['status'] === 'ISSUED') {
                return ['status' => 200, 'policy' => $policy->fresh(), 'message' => 'Recovered confirmed state.'];
            }
        }

        if ($policy->state === 'FAILED' && $policy->provider_reference) {
            $policy->transitionTo('SUBMITTED');
        }

        if ($policy->state === 'RECEIVED' || $policy->state === 'VALIDATED') {
            $policy->transitionTo('SUBMITTED');
        }

        $policy->attempt_count = (int) $policy->attempt_count + 1;
        $policy->save();

        $result = $this->mockInsurerGateway->submit($policy, $scenario);

        if (($result['status'] ?? null) === 'TIMEOUT') {
            $policy->transitionTo('PENDING_CONFIRMATION', ['message' => 'Request timed out after provider issued policy.']);

            return ['status' => 504, 'policy' => $policy->fresh(), 'provider' => $result];
        }

        if (($result['status'] ?? null) === 'TEMPORARY_FAILURE') {
            $policy->transitionTo('FAILED', ['message' => 'Temporary failure, retry supported.']);

            return ['status' => 503, 'policy' => $policy->fresh(), 'provider' => $result];
        }

        if (($result['status'] ?? null) === 'PENDING') {
            $policy->transitionTo('PENDING_CONFIRMATION', ['message' => 'Provider accepted request; callback pending.']);

            return ['status' => 200, 'policy' => $policy->fresh(), 'provider' => $result];
        }

        $policy->transitionTo('ISSUED', ['message' => 'Provider confirmed issuance.']);

        return ['status' => 200, 'policy' => $policy->fresh(), 'provider' => $result];
    }

    public function listRequests(?string $status = null, int $perPage = 15)
    {
        $query = PolicyRequest::query();

        if ($status) {
            $query->where('state', strtoupper($status));
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    private function normalizeRequestPayload(array $data): array
    {
        $normalized = [
            'client_request_id' => trim((string) ($data['client_request_id'] ?? '')),
            'agent_id' => trim((string) ($data['agent_id'] ?? '')),
            'customer_name' => trim((string) ($data['customer_name'] ?? '')),
            'date_of_birth' => trim((string) ($data['date_of_birth'] ?? '')),
            'destination' => strtoupper(trim((string) ($data['destination'] ?? ''))),
            'trip_start' => trim((string) ($data['trip_start'] ?? '')),
            'trip_end' => trim((string) ($data['trip_end'] ?? '')),
            'plan_code' => strtoupper(trim((string) ($data['plan_code'] ?? ''))),
            'sum_insured' => (int) ($data['sum_insured'] ?? 0),
        ];

        if ($normalized['client_request_id'] === '') {
            throw new InvalidArgumentException('The client_request_id field is required.');
        }

        if ($normalized['agent_id'] === '') {
            throw new InvalidArgumentException('The agent_id field is required.');
        }

        if ($normalized['customer_name'] === '') {
            throw new InvalidArgumentException('The customer_name field is required.');
        }

        if ($normalized['date_of_birth'] === '') {
            throw new InvalidArgumentException('The date_of_birth field is required.');
        }

        if ($normalized['trip_start'] === '' || $normalized['trip_end'] === '') {
            throw new InvalidArgumentException('Both trip_start and trip_end are required.');
        }

        $pricing = $this->pricingService->calculate($normalized);

        $normalized['premium_amount'] = $pricing['premium_amount'];
        $normalized['premium_snapshot'] = $pricing;
        $normalized['state'] = 'RECEIVED';

        return $normalized;
    }

    public function validateRequestPayload(array $data): array
    {
        $rules = [
            'client_request_id' => ['required', 'string', 'min:3'],
            'agent_id' => ['required', 'string', 'min:2'],
            'customer_name' => ['required', 'string', 'min:2'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'destination' => ['required', 'string', 'in:DOMESTIC,INTERNATIONAL'],
            'trip_start' => ['required', 'date'],
            'trip_end' => ['required', 'date', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', 'in:BASIC,PLUS,PREMIUM'],
            'sum_insured' => ['required', 'integer', 'in:100000,300000,500000'],
        ];

        $validator = \Illuminate\Support\Facades\Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->toJson());
        }

        return $this->normalizeRequestPayload($data);
    }
}
