<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvMovement extends Model
{
    use Auditable;

    protected $fillable = [
        'asset_id',
        'type',
        'user_id',
        'previous_user_id',
        'admin_id',
        'responsiva_path',
        'notes',
        'reason',
        'batch_uuid',
        'metadata',
        'client_id',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'date' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'asset_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function previousUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
