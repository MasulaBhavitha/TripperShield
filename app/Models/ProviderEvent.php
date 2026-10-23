<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderEvent extends Model
{
    protected $fillable = [
        'policy_request_id',
        'event_id',
        'provider_reference',
        'status',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function policy_request()
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
