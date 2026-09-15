<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgCommissionDepartment extends Model
{
    protected $table = 'org_commission_departments';

    protected $fillable = ['name', 'description', 'is_active', 'sort_order', 'created_by'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function members()
    {
        return $this->hasMany(OrgCommissionDepartmentMember::class, 'department_id')->orderBy('sort_order');
    }

    public function activeMembers()
    {
        return $this->members()->where('is_active', true);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
