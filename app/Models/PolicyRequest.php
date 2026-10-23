<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyRequest extends Model
{

    protected $fillable = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium_amount',
        'premium_snapshot',
        'provider_reference',
        'policy_number',
        'state',
        'provider_status',
        'last_response',
        'attempt_count',
    ];

    protected $casts = [
        'premium_snapshot' => 'array',
        'last_response' => 'array',
    ];

    public function issuance_attempts()
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function state_history()
    {
        return $this->hasMany(PolicyStateHistory::class);
    }

    public function provider_events()
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function transitionTo(string $newState, array $meta = []): void
    {
        $oldState = $this->state;

        if ($oldState === $newState) {
            return;
        }

        $allowed = [
            'RECEIVED' => ['VALIDATED', 'SUBMITTED', 'FAILED'],
            'VALIDATED' => ['SUBMITTED', 'FAILED'],
            'SUBMITTED' => ['PENDING_CONFIRMATION', 'FAILED', 'ISSUED'],
            'PENDING_CONFIRMATION' => ['ISSUED', 'NEEDS_RECONCILIATION', 'FAILED'],
            'FAILED' => ['SUBMITTED', 'PENDING_CONFIRMATION', 'RETRYING'],
            'ISSUED' => [],
            'NEEDS_RECONCILIATION' => ['ISSUED', 'FAILED'],
            'RETRYING' => ['SUBMITTED', 'FAILED', 'ISSUED'],
        ];

        if ($oldState && isset($allowed[$oldState]) && ! in_array($newState, $allowed[$oldState], true)) {
            throw new \RuntimeException("Invalid state transition from {$oldState} to {$newState}.");
        }

        $this->state = $newState;
        $this->save();

        $this->state_history()->create([
            'from_state' => $oldState,
            'to_state' => $newState,
            'meta' => $meta,
        ]);
    }
}
