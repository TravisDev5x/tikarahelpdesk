<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'description',
        'price_monthly',
        'price_yearly',
        'max_clients',
        'max_users',
        'max_agents',
        'max_assets',
        'features',
        'is_active',
        'is_public',
        'highlighted',
        'trial_days',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'highlighted' => 'boolean',
            'trial_days' => 'integer',
        ];
    }

    public function operatorProfiles(): HasMany
    {
        return $this->hasMany(OperatorProfile::class);
    }

    public function scopeActivePublic(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('price_monthly');
    }

    public function scopeForMsp(Builder $query): Builder
    {
        return $query->whereIn('type', ['msp', 'both']);
    }

    public function scopeForInhouse(Builder $query): Builder
    {
        return $query->whereIn('type', ['inhouse', 'both']);
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        if ($type === 'msp') {
            return $query->whereIn('type', ['msp', 'both']);
        }

        if ($type === 'inhouse') {
            return $query->whereIn('type', ['inhouse', 'both']);
        }

        return $query;
    }

    public function isMsp(): bool
    {
        return in_array($this->type, ['msp', 'both'], true);
    }

    public function isInhouse(): bool
    {
        return in_array($this->type, ['inhouse', 'both'], true);
    }
}
