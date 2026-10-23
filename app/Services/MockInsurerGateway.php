<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Carbon\Carbon;
use Illuminate\Support\Str;

class MockInsurerGateway
{
    public function submit(PolicyRequest $policy, string $scenario = 'SUCCESS'): array
    {
        $scenario = strtoupper($scenario);
        $attemptNumber = (int) $policy->issuance_attempts()->count() + 1;

        if ($policy->state === 'ISSUED' && $policy->policy_number) {
            return [
                'status' => 'ISSUED',
                'provider_reference' => $policy->provider_reference,
                'policy_number' => $policy->policy_number,
                'message' => 'Policy already issued; no duplicate issuance performed.',
            ];
        }

        $providerReference = $policy->provider_reference ?: 'PROV-'.strtoupper(Str::random(12));
        $policy->provider_reference = $providerReference;

        $attempt = $policy->issuance_attempts()->create([
            'attempt_number' => $attemptNumber,
            'scenario' => $scenario,
            'provider_reference' => $providerReference,
            'status' => 'CREATED',
            'response_payload' => [],
        ]);

        if ($scenario === 'TEMPORARY_FAILURE') {
            $attempt->update([
                'status' => 'TEMPORARY_FAILURE',
                'response_payload' => ['retryable' => true, 'message' => 'Temporary insurer outage.'],
            ]);
            $policy->last_response = ['retryable' => true, 'message' => 'Temporary insurer outage.'];
            $policy->provider_status = 'FAILED';
            $policy->state = 'FAILED';
            $policy->save();

            return [
                'status' => 'TEMPORARY_FAILURE',
                'provider_reference' => $providerReference,
                'retryable' => true,
                'message' => 'Temporary insurer outage.',
            ];
        }

        if ($scenario === 'TIMEOUT_AFTER_ISSUE') {
            $policyNumber = $this->buildPolicyNumber($policy);
            $policy->provider_status = 'ISSUED';
            $policy->policy_number = $policyNumber;
            $policy->state = 'PENDING_CONFIRMATION';
            $policy->last_response = ['status' => 'TIMEOUT', 'provider_reference' => $providerReference, 'message' => 'The client request timed out after issuance.'];
            $policy->save();

            $attempt->update([
                'status' => 'TIMEOUT',
                'response_payload' => ['status' => 'TIMEOUT', 'provider_reference' => $providerReference, 'message' => 'The client request timed out after issuance.'],
            ]);

            return [
                'status' => 'TIMEOUT',
                'provider_reference' => $providerReference,
                'policy_number' => $policyNumber,
                'message' => 'The provider accepted the application but the client request timed out.',
            ];
        }

        if ($scenario === 'DELAYED_CALLBACK') {
            $policy->provider_status = 'PENDING';
            $policy->state = 'PENDING_CONFIRMATION';
            $policy->save();

            $attempt->update([
                'status' => 'PENDING',
                'response_payload' => ['status' => 'PENDING', 'provider_reference' => $providerReference, 'message' => 'Application accepted; callback pending.'],
            ]);

            return [
                'status' => 'PENDING',
                'provider_reference' => $providerReference,
                'message' => 'Application accepted; callback pending.',
            ];
        }

        $policyNumber = $this->buildPolicyNumber($policy);
        $policy->policy_number = $policyNumber;
        $policy->provider_status = 'ISSUED';
        $policy->state = 'ISSUED';
        $policy->last_response = ['status' => 'ISSUED', 'provider_reference' => $providerReference, 'policy_number' => $policyNumber];
        $policy->save();

        $attempt->update([
            'status' => 'ISSUED',
            'response_payload' => ['status' => 'ISSUED', 'provider_reference' => $providerReference, 'policy_number' => $policyNumber],
        ]);

        return [
            'status' => 'ISSUED',
            'provider_reference' => $providerReference,
            'policy_number' => $policyNumber,
            'message' => 'Policy issued successfully.',
        ];
    }

    public function reconcile(PolicyRequest $policy): array
    {
        if (! $policy->provider_reference) {
            return ['status' => 'UNKNOWN', 'message' => 'No provider reference exists.'];
        }

        $providerStatus = $policy->state === 'ISSUED' ? 'ISSUED' : 'PENDING';
        $policyNumber = $policy->policy_number ?: $this->buildPolicyNumber($policy);

        if ($providerStatus === 'PENDING') {
            return [
                'status' => 'PENDING',
                'provider_reference' => $policy->provider_reference,
                'policy_number' => $policyNumber,
                'message' => 'No confirmed policy found yet.',
            ];
        }

        $policy->provider_status = 'ISSUED';
        $policy->policy_number = $policyNumber;
        $policy->state = 'ISSUED';
        $policy->last_response = ['status' => 'ISSUED', 'provider_reference' => $policy->provider_reference, 'policy_number' => $policyNumber];
        $policy->save();

        return [
            'status' => 'ISSUED',
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policyNumber,
            'message' => 'Policy confirmed after reconciliation.',
        ];
    }

    public function processCallback(array $payload): array
    {
        $requestId = (int) ($payload['policy_request_id'] ?? 0);
        $policy = PolicyRequest::findOrFail($requestId);
        $eventId = $payload['event_id'] ?? $payload['provider_event_id'] ?? $payload['callback_id'] ?? hash('sha256', json_encode($payload));

        $existing = ProviderEvent::where('policy_request_id', $policy->id)
            ->where('event_id', $eventId)
            ->first();

        if ($existing) {
            return [
                'status' => $policy->state,
                'policy_number' => $policy->policy_number,
                'message' => 'Duplicate callback ignored.',
                'duplicate' => true,
            ];
        }

        $providerReference = $payload['provider_reference'] ?? $policy->provider_reference;
        $event = ProviderEvent::create([
            'policy_request_id' => $policy->id,
            'event_id' => $eventId,
            'provider_reference' => $providerReference,
            'status' => strtoupper((string) ($payload['status'] ?? 'ISSUED')),
            'payload' => $payload,
            'processed_at' => Carbon::now(),
        ]);

        if ($policy->state === 'ISSUED' && $policy->policy_number) {
            return [
                'status' => $policy->state,
                'policy_number' => $policy->policy_number,
                'message' => 'Policy already confirmed; callback ignored.',
                'duplicate' => false,
            ];
        }

        $policy->provider_reference = $providerReference;
        $policy->policy_number = $payload['policy_number'] ?? $policy->policy_number ?? $this->buildPolicyNumber($policy);
        $policy->provider_status = 'ISSUED';
        $policy->state = 'ISSUED';
        $policy->last_response = $payload;
        $policy->save();

        $event->update(['processed_at' => Carbon::now()]);

        return [
            'status' => 'ISSUED',
            'policy_number' => $policy->policy_number,
            'message' => 'Policy issued after provider callback.',
            'duplicate' => false,
        ];
    }

    private function buildPolicyNumber(PolicyRequest $policy): string
    {
        return 'POL-'.$policy->id;
    }
}
