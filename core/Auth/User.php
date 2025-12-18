<?php

namespace Apollo\Core\Auth;

use Apollo\Core\Database\Model;
use Apollo\Core\Auth\Traits\HasRoles;

class User extends Model
{
    use HasRoles;

    protected $table = 'users';
    
    protected $fillable = [
        'username',
        'email',
        'password',
        'first_name',
        'last_name',
        'phone',
        'status',
        'avatar',
        'metadata'
    ];

    protected $hidden = [
        'password'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'metadata' => 'array'
    ];

    protected $dates = [
        'email_verified_at',
        'last_login_at',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Hash password when setting
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if email is verified
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Mark email as verified
     */
    public function markEmailAsVerified(): bool
    {
        return $this->update(['email_verified_at' => now()]);
    }

    /**
     * Update last login
     */
    public function updateLastLogin(): bool
    {
        return $this->update(['last_login_at' => now()]);
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get display name (full name or username)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->full_name ?: $this->username;
    }

    /**
     * Sessions relationship
     */
    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Active sessions
     */
    public function activeSessions()
    {
        return UserSession::where('user_id', $this->id)
            ->where('is_revoked', false)
            ->where('expires_at', '>', now())
            ->get();
    }

    /**
     * Revoke all sessions
     */
    public function revokeAllSessions(): bool
    {
        $sessions = UserSession::where('user_id', $this->id)->get();
        foreach ($sessions as $session) {
            $session->update(['is_revoked' => true]);
        }
        return true;
    }

    /**
     * Scope for active users
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for verified users
     */
    public function scopeVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }
}