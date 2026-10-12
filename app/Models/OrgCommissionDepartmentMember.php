<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgCommissionDepartmentMember extends Model
{
    protected $table = 'org_commission_department_members';

    protected $fillable = ['department_id', 'name', 'role', 'is_active', 'sort_order', 'percentage'];

    protected $casts = [
        'is_active' => 'boolean',
        'percentage' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(OrgCommissionDepartment::class, 'department_id');
    }
}
