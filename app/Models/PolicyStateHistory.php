<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyStateHistory extends Model
{
    protected $fillable = [
        'policy_request_id',
        'from_state',
        'to_state',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function policy_request()
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
