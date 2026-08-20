<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvMaintenanceOrigin extends Model
{
    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_active',
        'operator_user_id',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
