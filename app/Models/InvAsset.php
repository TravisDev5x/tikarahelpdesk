<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'manufacturer_id',
        'model',
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

    /** Fabricante (fase 2.3) -- model queda como texto libre, sin catálogo aparte. */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(InvManufacturer::class, 'manufacturer_id');
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

    /** Ficha técnica estructurada (fase 2.1) -- reemplaza specs.notes, que se deja de escribir pero no se borra. */
    public function specs(): HasMany
    {
        return $this->hasMany(InvAssetSpec::class, 'asset_id');
    }

    /** Documentos y evidencias (fase 2.2) -- facturas, actas, evidencia de baja, además de las fotos. */
    public function documents(): MorphMany
    {
        return $this->morphMany(InvDocument::class, 'documentable');
    }

    /** Baja estructurada más reciente (fase 2.2), si el activo se dio de baja. */
    public function disposal(): HasOne
    {
        return $this->hasOne(InvDisposal::class, 'asset_id')->latestOfMany();
    }

    /** Garantías (fase 2.3) -- opcional, no reemplaza warranty_expiry. */
    public function warranties(): HasMany
    {
        return $this->hasMany(InvWarranty::class, 'asset_id');
    }
}
