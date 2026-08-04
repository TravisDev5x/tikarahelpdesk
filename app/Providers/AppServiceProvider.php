<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use App\Models\Ticket;
use App\Models\Incident;
use App\Policies\RequesterTicketPolicy;
use App\Policies\TicketPolicy;
use App\Policies\IncidentPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Support\Tenancy\PgsqlRowLevelSecurity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole() && PgsqlRowLevelSecurity::enabled()) {
            PgsqlRowLevelSecurity::setBypass(true);
        }

        $this->fixViteHotFileForNetworkAccess();

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
        RateLimiter::for('tickets', function (Request $request) {
            $key = $request->user() ? 'tickets.user.' . $request->user()->id : 'tickets.ip.' . $request->ip();
            // Dashboard hace varias llamadas (tickets, summary, analytics, filtros); 300/min evita 429 en uso normal
            return Limit::perMinute(300)->by($key)->response(function () {
                return response()->json(['message' => 'Demasiadas peticiones. Espera un momento.'], 429);
            });
        });

        if ($this->app->runningInConsole() && PgsqlRowLevelSecurity::enabled()) {
            PgsqlRowLevelSecurity::setBypass(true);
        }

        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Incident::class, IncidentPolicy::class);

        // Gates para contexto "Mis Tickets" (solicitante). No reemplazan TicketPolicy en rutas operativas.
        Gate::define('requester.viewAny.ticket', fn ($user) => app(RequesterTicketPolicy::class)->viewAny($user));
        Gate::define('requester.view.ticket', [RequesterTicketPolicy::class, 'view']);
        Gate::define('requester.create.ticket', fn ($user) => app(RequesterTicketPolicy::class)->create($user));
        Gate::define('requester.alert.ticket', [RequesterTicketPolicy::class, 'alert']);
        Gate::define('requester.comment.ticket', [RequesterTicketPolicy::class, 'comment']);
        Gate::define('requester.attach.ticket', [RequesterTicketPolicy::class, 'attach']);
        Gate::define('requester.cancel.ticket', [RequesterTicketPolicy::class, 'cancel']);

        Event::listen(\App\Events\TicketCreated::class, \App\Listeners\SendTicketNotification::class);
        Event::listen(\App\Events\TicketUpdated::class, \App\Listeners\SendTicketNotification::class);

        // Login con Outlook/Microsoft 365 (Azure AD) -- registra el driver
        // 'azure' en Socialite. Ver docs/MICROSOFT_LOGIN_SETUP.md.
        Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            \SocialiteProviders\Azure\AzureExtendSocialite::class.'@handle'
        );

        ResetPassword::createUrlUsing(function ($user, string $token) {
            $email = urlencode((string) $user->email);
            return URL::to('/') . "/reset-password?token={$token}&email={$email}";
        });
    }

    /**
     * Cuando el archivo "hot" de Vite contiene http://[::]:5173 (por host: true),
     * el navegador bloquea los scripts por CORS. Reemplazamos esa URL por el host
     * de la petición (o VITE_DEV_SERVER_HOST) para que funcione desde otro dispositivo en la red.
     */
    protected function fixViteHotFileForNetworkAccess(): void
    {
        $hotFile = public_path('hot');
        if (! is_file($hotFile)) {
            return;
        }

        $content = trim((string) file_get_contents($hotFile));
        if ($content === '') {
            return;
        }

        $host = env('VITE_DEV_SERVER_HOST');
        if ($host === null || $host === '') {
            try {
                $request = request();
            } catch (\Throwable) {
                return;
            }
            if ($request === null) {
                return;
            }
            $host = $request->getHost();
        }

        $port = (string) (parse_url($content, PHP_URL_PORT) ?? 5173);
        $newOrigin = 'http://' . $host . ':' . $port;
        if (str_starts_with($content, 'https://')) {
            $newOrigin = 'https://' . $host . ':' . $port;
        }

        $replaced = preg_replace('#^https?://[^/]+#', $newOrigin, $content);
        if ($replaced === $content) {
            return;
        }

        $resolvedPath = public_path('hot.resolved');
        file_put_contents($resolvedPath, $replaced);
        Vite::useHotFile($resolvedPath);
    }
}
