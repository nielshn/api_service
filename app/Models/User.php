<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasRoles, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'avatar',
        'password',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function gudangs()
    {
        return $this->belongsToMany(Gudang::class, 'user_id');
    }

    public function routeNotificationForMail($notification)
    {
        $pendingEmail = Cache::get('pending_email_' . $this->id);
        return $pendingEmail ?: $this->email;
    }

//     public function hasRole(string $roleName): bool
// {
//     return strtolower($this->role->name ?? '') === strtolower($roleName);
// }
}
