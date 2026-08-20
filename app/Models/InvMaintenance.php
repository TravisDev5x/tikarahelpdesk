<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvMaintenance extends Model
{
    use Auditable;

    protected $fillable = [
        'asset_id',
        'origin_id',
        'modality_id',
        'title',
        'diagnosis',
        'solution',
        'cost',
        'supplier',
        'start_date',
        'end_date',
        'logged_by',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'asset_id');
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(InvMaintenanceOrigin::class, 'origin_id');
    }

    public function modality(): BelongsTo
    {
        return $this->belongsTo(InvMaintenanceModality::class, 'modality_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('end_date');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('end_date');
    }
}
