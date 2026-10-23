# PolicyFlow Assessment

PolicyFlow is a Laravel 11 API for issuing and reconciling travel insurance policies. It accepts client policy requests, validates pricing inputs, prevents duplicate intake, submits to a mock insurer, and tracks lifecycle state transitions with an audit trail.

## Features

- API intake for policy requests
- Premium calculation per assignment rules
- Duplicate prevention on agent + client request ID
- Mock insurer submission with success, timeout, retry, and callback scenarios
- Idempotent callback handling and state reconciliation
- State-tracking history for every lifecycle transition

## Assignment-aligned rules

The service follows the required pricing model:

- Base rate: BASIC 12, PLUS 20, PREMIUM 30
- Age multipliers: 0-17 => 1.10, 18-60 => 1.00, 61-70 => 1.50, 71+ => 2.00
- Destination multipliers: DOMESTIC 1.00, INTERNATIONAL 1.80
- Coverage multipliers: 100000 => 1.00, 300000 => 1.25, 500000 => 1.50
- Travel days use inclusive counting with a minimum of 1 day
- Premiums are rounded with half-up arithmetic to two decimal places

## Local setup

1. Copy `.env.example` to `.env` if needed.
2. Install dependencies:
   - `composer install`
3. Generate an app key:
   - `php artisan key:generate`
4. Run the database migrations and seedless setup:
   - `php artisan migrate`
5. Start the app:
   - `php artisan serve`

## API quick reference

### Create a policy request

POST `/api/policy-requests`

Example body:

```json
{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

### Submit to mock insurer

POST `/api/policy-requests/{id}/submit`

Optional body:

```json
{
  "scenario": "SUCCESS"
}
```

Supported scenarios include `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, and `DELAYED_CALLBACK`.

### Provider callback endpoint

POST `/api/mock-insurer/callback`

Example body:

```json
{
  "policy_request_id": 1,
  "provider_reference": "PROV-ABC123",
  "status": "ISSUED",
  "event_id": "evt-123",
  "policy_number": "POL-1"
}
```

## Duplicate handling and reconciliation

The app prevents duplicate policy ingestion by requiring a unique pair of `agent_id` and `client_request_id` values. Provider callbacks are also deduplicated by `policy_request_id + event_id`, which prevents repeated callback processing from creating repeat policy numbers or state resets.

## Testing

Run the feature suite with:

```bash
php artisan test
```

The core acceptance tests cover valid issuance, invalid payload rejection, duplicate prevention, successful policy issuance, duplicate callbacks, and timeout-after-issue reconciliation.

## AI usage

This project was implemented with Laravel scaffolding and iterative issue-fix work guided by the assignment brief. The codebase follows the requirements in the brief and includes the final business logic and tests necessary to validate the workflow.

## License

This project is provided for assessment use and follows the same licensing model as the underlying Laravel application.
