<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasRoles;

    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * IMPORTANT: Use *_id because these are foreign keys.
     * name no es fillable: se obtiene por accessor desde first_name + apellidos.
     */
    protected $fillable = [
        'first_name',
        'paternal_last_name',
        'maternal_last_name',
        'email',
        'google_id',
        'microsoft_id',
        'password',
        'phone',
        'campaign_id',
        'area_id',
        'position_id',
        'site_id',
        'client_id',
        'is_operator',
        'onboarding_completed',
        'privacy_notice_accepted_at',
        'privacy_notice_version',
        'privacy_notice_ip',
        'location_id',
        'avatar_path',
        'status',
        'theme',
        'theme_color',
        'ui_density',
        'sidebar_state',
        'sidebar_hover_preview',
        'sidebar_position',
        'locale',
        'availability',
        'is_blacklisted',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Accessors que se incluyen en la serialización JSON (ej. para el frontend).
     */
    protected $appends = [
        'avatar_url',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'privacy_notice_accepted_at' => 'datetime',
            'password' => 'hashed',
            'is_operator' => 'boolean',
            'onboarding_completed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->syncNameColumn();
        });
    }

    /**
     * Sincroniza la columna `name` con first_name + apellidos para consultas raw (ej. catálogos).
     */
    public function syncNameColumn(): void
    {
        $first = trim((string) ($this->first_name ?? ''));
        $paternal = trim((string) ($this->paternal_last_name ?? ''));
        $maternal = trim((string) ($this->maternal_last_name ?? ''));
        $this->attributes['name'] = trim($first . ' ' . $paternal . ' ' . $maternal) ?: null;
    }

    /**
     * URL pública del avatar (para que el frontend cargue la imagen desde el servidor correcto).
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $path = $this->attributes['avatar_path'] ?? null;
                if (! is_string($path) || trim($path) === '') {
                    return null;
                }
                $path = ltrim($path, '/');
                $base = rtrim(config('app.url'), '/');

                return $base.'/storage/'.$path;
            },
        );
    }

    /**
     * Nombre completo: primero + apellido paterno + apellido materno.
     * Si los nuevos campos están vacíos, se usa la columna legacy `name` (tras migración de datos).
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function () {
                $first = trim((string) ($this->attributes['first_name'] ?? ''));
                $paternal = trim((string) ($this->attributes['paternal_last_name'] ?? ''));
                $maternal = trim((string) ($this->attributes['maternal_last_name'] ?? ''));
                $computed = trim($first . ' ' . $paternal . ' ' . $maternal);
                if ($computed !== '') {
                    return $computed;
                }
                return (string) ($this->attributes['name'] ?? '');
            },
        );
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** Sites a los que este usuario tiene acceso vía site_user (Fase 4) -- para supervisor/agente, distinto de site() (su site "hogar"). */
    public function sites(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_user');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Sobrescribe el comportamiento default de Laravel (notificación con link
     * siempre al dominio principal) para usar App\Mail\ResetPasswordLink, que
     * arma el link contra el subdominio del tenant del usuario si tiene uno.
     */
    public function sendPasswordResetNotification($token): void
    {
        \Illuminate\Support\Facades\Mail::to($this->email)->queue(
            new \App\Mail\ResetPasswordLink($token, $this->email, $this->client)
        );
    }

    public function operatorProfile(): HasOne
    {
        return $this->hasOne(OperatorProfile::class);
    }

    /** Customers del operador (filas de clients que le pertenecen). */
    public function clients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Client::class, 'operator_user_id');
    }

    public function isOnboarded(): bool
    {
        return (bool) $this->onboarding_completed;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function getCachedRoleNames(): Collection
    {
        return cache()->remember(
            "user.{$this->id}.roles",
            now()->addMinutes(10),
            fn () => $this->getRoleNames()
        );
    }

    public function getCachedPermissions(): Collection
    {
        return cache()->remember(
            "user.{$this->id}.permissions",
            now()->addMinutes(10),
            fn () => $this->getAllPermissions()->pluck('name')
        );
    }

    public static function forgetPermissionCache(int|self $user): void
    {
        $id = $user instanceof self ? $user->id : $user;
        cache()->forget("user.{$id}.roles");
        cache()->forget("user.{$id}.permissions");
    }

    public static function forgetPermissionCacheForRole(string $roleName): void
    {
        static::role($roleName)->pluck('id')->each(
            fn (int $userId) => static::forgetPermissionCache($userId)
        );
    }

}
