<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelAttachment extends Model
{
    protected $guarded = ['id'];

    public function entry()
    {
        return $this->belongsTo(PersonnelEntry::class, 'personnel_entry_id');
    }
}
