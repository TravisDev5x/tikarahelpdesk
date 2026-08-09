<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Security\OauthAutoLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Cubre el fix de seguridad del auto-vínculo de Google en login: antes,
 * cualquier cuenta de Google con el mismo correo que un usuario existente se
 * vinculaba en silencio con solo un "if (! $user->google_id)". Ahora exige
 * email_verified=true (siempre presente en /userinfo de Google) y audita +
 * notifica al dueño de la cuenta cuando el vínculo ocurre por esta vía.
 */
class GoogleOauthLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'fake-client-id',
            'services.google.client_secret' => 'fake-client-secret',
        ]);
    }

    private function fakeGoogleUser(string $id, string $email, bool $emailVerified): SocialiteUser
    {
        return (new SocialiteUser())->setRaw([
            'sub' => $id,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => 'Fake Google User',
        ])->map([
            'id' => $id,
            'email' => $email,
            'name' => 'Fake Google User',
        ]);
    }

    private function mockGoogleDriver(SocialiteUser $user): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
    }

    public function test_login_rejects_auto_link_when_google_email_is_not_verified(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@testco.test',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
            'google_id' => null,
        ]);

        $this->mockGoogleDriver($this->fakeGoogleUser('google-999', 'existing@testco.test', emailVerified: false));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_login_auto_links_and_notifies_when_google_email_is_verified(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'existing2@testco.test',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
            'google_id' => null,
        ]);

        $this->mockGoogleDriver($this->fakeGoogleUser('google-1000', 'existing2@testco.test', emailVerified: true));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-1000', $user->fresh()->google_id);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'oauth_link',
            'auditable_id' => $user->id,
            'action' => 'google',
        ]);

        Notification::assertSentTo($user->fresh(), OauthAutoLinkNotification::class);
    }

    public function test_login_does_not_relink_or_renotify_when_already_linked_by_google_id(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'existing3@testco.test',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
            'google_id' => 'google-2000',
        ]);

        // email_verified=false a propósito: si el fix intentara re-vincular igual
        // fallaría aquí, probando que la rama de re-login (ya vinculado) no
        // pasa por el chequeo de verificación en absoluto.
        $this->mockGoogleDriver($this->fakeGoogleUser('google-2000', 'existing3@testco.test', emailVerified: false));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user->fresh());

        Notification::assertNotSentTo($user->fresh(), OauthAutoLinkNotification::class);
    }
}
