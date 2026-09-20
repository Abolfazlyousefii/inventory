<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgCommissionMemberShare extends Model
{
    protected $table = 'org_commission_member_shares';

    protected $fillable = ['allocation_id', 'member_id', 'share_amount', 'notes'];

    protected $casts = ['share_amount' => 'integer'];

    public function allocation()
    {
        return $this->belongsTo(OrgCommissionAllocation::class, 'allocation_id');
    }

    public function member()
    {
        return $this->belongsTo(OrgCommissionDepartmentMember::class, 'member_id');
    }
}
