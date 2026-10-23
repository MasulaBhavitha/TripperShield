<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use InvalidArgumentException;

class PolicyPricingService
{
    public const PLAN_RATES = [
        'BASIC' => '12',
        'PLUS' => '20',
        'PREMIUM' => '30',
    ];

    public const AGE_MULTIPLIERS = [
        '0-17' => '1.10',
        '18-60' => '1.00',
        '61-70' => '1.50',
        '71+' => '2.00',
    ];

    public const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    public const COVERAGE_MULTIPLIERS = [
        100000 => '1.00',
        300000 => '1.25',
        500000 => '1.50',
    ];

    public function calculate(array $data): array
    {
        $plan = strtoupper((string) ($data['plan_code'] ?? ''));
        $destination = strtoupper((string) ($data['destination'] ?? ''));
        $sumInsured = (int) ($data['sum_insured'] ?? 0);

        if (! isset(self::PLAN_RATES[$plan])) {
            throw new InvalidArgumentException('Unsupported plan_code value.');
        }

        if (! isset(self::DESTINATION_MULTIPLIERS[$destination])) {
            throw new InvalidArgumentException('Unsupported destination value.');
        }

        if (! isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            throw new InvalidArgumentException('Unsupported sum_insured value.');
        }

        $tripStart = Carbon::parse($data['trip_start']);
        $tripEnd = Carbon::parse($data['trip_end']);
        $travelDays = max(1, (int) $tripStart->diffInDays($tripEnd) + 1);

        $dob = Carbon::parse($data['date_of_birth']);
        $ageOnTripStart = $dob->diffInYears($tripStart);
        $ageMultiplier = $this->resolveAgeMultiplier((int) $ageOnTripStart);

        $baseRate = BigDecimal::of((string) self::PLAN_RATES[$plan]);
        $days = BigDecimal::of((string) $travelDays);
        $ageFactor = BigDecimal::of($ageMultiplier);
        $destinationFactor = BigDecimal::of((string) self::DESTINATION_MULTIPLIERS[$destination]);
        $coverageFactor = BigDecimal::of((string) self::COVERAGE_MULTIPLIERS[$sumInsured]);

        $premium = $baseRate
            ->multipliedBy($days)
            ->multipliedBy($ageFactor)
            ->multipliedBy($destinationFactor)
            ->multipliedBy($coverageFactor);

        return [
            'premium_amount' => $premium->toScale(2, RoundingMode::HalfUp)->toString(),
            'travel_days' => $travelDays,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => self::DESTINATION_MULTIPLIERS[$destination],
            'coverage_multiplier' => (string) self::COVERAGE_MULTIPLIERS[$sumInsured],
            'plan_rate' => self::PLAN_RATES[$plan],
        ];
    }

    private function resolveAgeMultiplier(int $age): string
    {
        if ($age <= 17) {
            return self::AGE_MULTIPLIERS['0-17'];
        }

        if ($age <= 60) {
            return self::AGE_MULTIPLIERS['18-60'];
        }

        if ($age <= 70) {
            return self::AGE_MULTIPLIERS['61-70'];
        }

        return self::AGE_MULTIPLIERS['71+'];
    }
}
