<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;
use Apps\ApolloAuth\Models\User;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_id',
        'entity_type',
        'entity_id',
        'action',
        'before_data',
        'after_data',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
    ];

    protected $dates = [
        'created_at',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}