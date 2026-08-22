<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvAssetRelationship extends Model
{
    use Auditable;

    protected $fillable = [
        'parent_asset_id',
        'child_asset_id',
        'relationship_type',
        'notes',
        'client_id',
        'created_by',
    ];

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'parent_asset_id');
    }

    public function childAsset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'child_asset_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
