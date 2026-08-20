<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvLabel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'is_active',
        'operator_user_id',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
