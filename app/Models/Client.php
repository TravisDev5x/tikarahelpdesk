<?php

namespace App\Models;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'operator_user_id',
        'name',
        'code',
        'legal_name',
        'tax_id',
        'industry',
        'contact_name',
        'contact_email',
        'contact_phone',
        'website',
        'logo_path',
        'portal_slug',
        'portal_primary_color',
        'portal_welcome_message',
        'notes',
        'is_active',
        'plan_id',
        'subscription_expires_at',
        'billing_email',
        'cancelled_at',
        'inbound_email',
        'mode',
        'ai_classification_enabled',
        'show_agent_names',
    ];

    protected $appends = [
        'business_name',
        'support_email',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'subscription_expires_at' => 'datetime',
        'cancelled_at'            => 'datetime',
        'show_agent_names'        => 'boolean',
    ];

    protected static function booted(): void
    {
        // ticket_prefix: auto-asignado al crear, INMUTABLE después (los
        // folios emitidos lo llevan embebido — nunca se recalcula aunque el
        // cliente cambie de nombre). Ver TicketPrefixService.
        static::creating(function (self $client) {
            if (! $client->ticket_prefix && $client->name) {
                $client->ticket_prefix = app(\App\Services\TicketPrefixService::class)
                    ->uniquePrefixFor($client->name);
            }
        });

        static::updating(function (self $client) {
            $original = $client->getOriginal('ticket_prefix');
            if ($original && $client->ticket_prefix !== $original) {
                $client->ticket_prefix = $original;
            }
        });

        static::saved(function (self $client) {
            if ($client->wasChanged('is_active') || $client->wasChanged('portal_slug')) {
                if ($client->portal_slug) {
                    \App\Services\TenantContextService::clearPortalCache($client->portal_slug);
                }
                $oldSlug = $client->getOriginal('portal_slug');
                if ($oldSlug && $oldSlug !== $client->portal_slug) {
                    \App\Services\TenantContextService::clearPortalCache($oldSlug);
                }
            }
        });
    }

    public function getBusinessNameAttribute(): string
    {
        return (string) ($this->attributes['name'] ?? '');
    }

    /**
     * Dirección única para levantar tickets por correo, fuera de la
     * plataforma -- soporte@{portal_slug}.{TENANCY_BASE_DOMAIN}. Sin código
     * de resolución nuevo: InboundEmailService::resolveTenant() ya matchea
     * este subdominio contra portal_slug (fallback #2, ver
     * app/Services/Email/InboundEmailService.php). Null si el cliente aún
     * no tiene portal_slug asignado.
     */
    public function getSupportEmailAttribute(): ?string
    {
        if (! $this->portal_slug) {
            return null;
        }

        $baseDomain = config('tenancy.base_domain', 'tikara.mx');

        return "soporte@{$this->portal_slug}.{$baseDomain}";
    }

    public function operatorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'client_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'client_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'client_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'client_id');
    }

    public function scopeForOperator(Builder $query, int $userId): Builder
    {
        return $query->where('operator_user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
