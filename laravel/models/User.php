<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\PasswordReset;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'avatar'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
    ];

    public static int $adminId = 1;

    public function getIsDemoAttribute(): bool
    {
        foreach ($this->roles as $role) {
            if ($role['code'] === 'demo') {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole($role): bool
    {
        if ($this->roles->contains('code', $role)) {
            return true;
        }

        return false;
    }

    public function payments(): HasMany
    {
        return $this->hasMany('App\Models\Payment');
    }

    public function processes(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'user_post_processes', 'user_id', 'post_id')
            ->withPivot('process');
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new PasswordReset($token));
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'recipient_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany('App\Models\Notification');
    }
}
