<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgCommissionDepartmentMember extends Model
{
    protected $table = 'org_commission_department_members';

    protected $fillable = ['department_id', 'name', 'role', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(OrgCommissionDepartment::class, 'department_id');
    }
}
