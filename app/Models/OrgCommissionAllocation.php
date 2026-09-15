<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgCommissionAllocation extends Model
{
    protected $table = 'org_commission_allocations';

    protected $fillable = ['document_id', 'department_id', 'percentage', 'allocated_amount'];

    protected $casts = [
        'percentage' => 'decimal:4',
        'allocated_amount' => 'integer',
    ];

    public function document()
    {
        return $this->belongsTo(OrgCommissionDocument::class, 'document_id');
    }

    public function department()
    {
        return $this->belongsTo(OrgCommissionDepartment::class, 'department_id');
    }

    public function memberShares()
    {
        return $this->hasMany(OrgCommissionMemberShare::class, 'allocation_id');
    }
}
