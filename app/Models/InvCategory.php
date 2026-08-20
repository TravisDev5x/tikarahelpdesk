<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'prefix',
        'require_specs',
        'is_active',
        'operator_user_id',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'require_specs' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
