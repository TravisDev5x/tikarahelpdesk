<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvAsset extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'internal_tag',
        'serial',
        'name',
        'category_id',
        'status_id',
        'label_id',
        'condition',
        'site_id',
        'location_id',
        'specs',
        'cost',
        'purchase_date',
        'warranty_expiry',
        'supplier',
        'invoice_number',
        'current_user_id',
        'notes',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'cost' => 'decimal:2',
            'purchase_date' => 'date',
            'warranty_expiry' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InvCategory::class, 'category_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(InvStatus::class, 'status_id');
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(InvLabel::class, 'label_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function currentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_user_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(InvAssetImage::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InvMovement::class, 'asset_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(InvComponent::class, 'asset_id');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(InvMaintenance::class, 'asset_id');
    }
}
