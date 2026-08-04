<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $table = 'sites';

    protected $fillable = [
        'client_id',
        'customer_id',
        'name',
        'code',
        'type',      // physical | virtual
        'address',
        'city',
        'latitude',
        'longitude',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

    protected static function booted(): void
    {
        // Consistencia site.customer.client_id === site.client_id: un
        // CHECK constraint con subquery entre tablas no es portable entre
        // Postgres/SQLite, así que se valida aquí (mismo patrón que
        // TicketCreationService::assertSiteBelongsToClient).
        static::saving(function (self $site) {
            if (! $site->customer_id) {
                return;
            }

            $customerClientId = Customer::where('id', $site->customer_id)->value('client_id');

            if ($customerClientId !== null && (int) $customerClientId !== (int) $site->client_id) {
                throw new \InvalidArgumentException(
                    "Customer {$site->customer_id} pertenece al client {$customerClientId}, no al client {$site->client_id} de la sede."
                );
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Staff (supervisor/agente) con acceso a este site vía el pivote site_user -- distinto de users() (site "hogar"). */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'site_user');
    }
}
