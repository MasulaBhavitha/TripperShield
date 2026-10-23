<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IssuanceAttempt extends Model
{
    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'scenario',
        'provider_reference',
        'status',
        'response_payload',
    ];

    protected $casts = [
        'response_payload' => 'array',
    ];

    public function policy_request()
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
