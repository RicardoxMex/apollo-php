<?php

namespace Apollo\Core\Auth;

use Apollo\Core\Database\Model;

class UserSession extends Model
{
    protected $table = 'user_sessions';
    
    protected $fillable = [
        'user_id',
        'token_id',
        'device_name',
        'ip_address',
        'user_agent',
        'expires_at',
        'last_used_at',
        'is_revoked'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_revoked' => 'boolean'
    ];

    /**
     * User relationship
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if session is active
     */
    public function isActive(): bool
    {
        return !$this->is_revoked && $this->expires_at > now();
    }

    /**
     * Revoke session
     */
    public function revoke(): bool
    {
        return $this->update(['is_revoked' => true]);
    }

    /**
     * Update last used timestamp
     */
    public function updateLastUsed(): bool
    {
        return $this->update(['last_used_at' => now()]);
    }

    /**
     * Scope for active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('is_revoked', false)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope for expired sessions
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope for revoked sessions
     */
    public function scopeRevoked($query)
    {
        return $query->where('is_revoked', true);
    }
}