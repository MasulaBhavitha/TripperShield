<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_request_produces_expected_premium_and_status(): void
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.premium_amount', '225.00')
            ->assertJsonPath('data.state', 'VALIDATED');

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'premium_amount' => '225.00',
            'state' => 'VALIDATED',
        ]);
    }

    public function test_invalid_dates_and_unsupported_plan_are_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-2026-0002',
            'agent_id' => 'AGENT-99',
            'customer_name' => 'Bad Customer',
            'date_of_birth' => '1990-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-10',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);

        $response->assertStatus(422);

        $invalidPlan = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-2026-0003',
            'agent_id' => 'AGENT-99',
            'customer_name' => 'Bad Customer',
            'date_of_birth' => '1990-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'GOLD',
            'sum_insured' => 300000,
        ]);

        $invalidPlan->assertStatus(422);
        $this->assertDatabaseCount('policy_requests', 0);
    }

    public function test_repeated_agent_id_and_client_request_id_does_not_create_duplicate_record(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0004',
            'agent_id' => 'AGENT-22',
            'customer_name' => 'Repeat Customer',
            'date_of_birth' => '1985-06-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-04',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $first = $this->postJson('/api/policy-requests', $payload);
        $second = $this->postJson('/api/policy-requests', $payload);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_successful_provider_response_creates_exactly_one_policy_number(): void
    {
        $create = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-2026-0005',
            'agent_id' => 'AGENT-30',
            'customer_name' => 'Issued Customer',
            'date_of_birth' => '1996-08-02',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2027-02-01',
            'trip_end' => '2027-02-03',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
        ]);

        $create->assertStatus(201);
        $created = $create->json('data');

        $response = $this->postJson('/api/policy-requests/'.$created['id'].'/submit', ['scenario' => 'SUCCESS']);

        $response->assertStatus(200)
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('data.policy_number', 'POL-1');

        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertDatabaseHas('policy_requests', ['state' => 'ISSUED', 'policy_number' => 'POL-1']);
    }

    public function test_duplicate_callback_is_ignored_and_confirmed_issued_state_is_not_overwritten(): void
    {
        $create = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-2026-0006',
            'agent_id' => 'AGENT-40',
            'customer_name' => 'Callback Customer',
            'date_of_birth' => '1988-02-14',
            'destination' => 'DOMESTIC',
            'trip_start' => '2027-05-10',
            'trip_end' => '2027-05-11',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);

        $policyId = $create->json('data.id');
        $payload = [
            'policy_request_id' => $policyId,
            'provider_reference' => 'PROV-DUP-1',
            'status' => 'ISSUED',
            'event_id' => 'evt-duplicate-1',
            'policy_number' => 'POL-2',
        ];

        $firstCallback = $this->postJson('/api/mock-insurer/callback', $payload);
        $firstCallback->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $secondCallback = $this->postJson('/api/mock-insurer/callback', $payload);
        $secondCallback->assertStatus(200)
            ->assertJsonPath('data.duplicate', true);

        $this->assertDatabaseCount('provider_events', 1);
        $this->assertDatabaseHas('policy_requests', ['id' => $policyId, 'state' => 'ISSUED', 'policy_number' => 'POL-2']);
    }

    public function test_timeout_after_issue_is_reconciled_without_duplicate_policy_number(): void
    {
        $create = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-2026-0007',
            'agent_id' => 'AGENT-50',
            'customer_name' => 'Timeout Customer',
            'date_of_birth' => '1980-07-03',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2027-09-01',
            'trip_end' => '2027-09-04',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        $policyId = $create->json('data.id');
        $first = $this->postJson('/api/policy-requests/'.$policyId.'/submit', ['scenario' => 'TIMEOUT_AFTER_ISSUE']);
        $second = $this->postJson('/api/policy-requests/'.$policyId.'/submit', ['scenario' => 'SUCCESS']);

        $first->assertStatus(504);
        $second->assertStatus(200);

        $policy = PolicyRequest::findOrFail($policyId);
        $this->assertSame('ISSUED', $policy->state);
        $this->assertSame('POL-'. $policyId, $policy->policy_number);
        $this->assertSame(2, $policy->issuance_attempts()->count());
    }
}
