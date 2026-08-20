<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvStatus extends Model
{
    protected $fillable = [
        'name',
        'badge_class',
        'assignable',
        'is_active',
        'operator_user_id',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'assignable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
