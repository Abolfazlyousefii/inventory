<?php

namespace App\Models\CRM;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Invoice;
use App\Models\MessageGroup;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{

    protected $connection = 'CRM';
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, hasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'password',
        'manager_id', // 👈 این خیلی مهمه
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
    public function reports()
    {
        return $this->hasMany(Report::class);
    }


public function userProducts()
{
    return $this->hasMany(\App\Models\UserProduct::class);
}
protected $casts = [
    'email_verified_at' => 'datetime',
    'blocked_until' => 'datetime',
];

public function isBlocked(): bool
{
    return $this->blocked_until && $this->blocked_until->isFuture();
}

public function blockRemaining(): ?string
{
    return $this->isBlocked() ? $this->blocked_until->diffForHumans(null, true) : null;
}

public function manager()
{
    return $this->belongsTo(User::class, 'manager_id');
}

public function employees()
{
    return $this->hasMany(User::class, 'manager_id');
}
public function isRole($role)
{
    return $this->role === $role;
}

public function notes() { return $this->hasMany(CustomerNote::class, 'user_id'); }

public function messageGroups()
{
    return $this->belongsToMany(MessageGroup::class, 'message_group_user')->withTimestamps();
}

}
