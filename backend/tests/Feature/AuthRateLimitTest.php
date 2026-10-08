<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    public static function loginRoutes(): array
    {
        return [['/api/login'], ['/api/admin/login']];
    }

    private function attempt(string $route, mixed $email = 'quota@example.test', string $ip = '192.0.2.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($route, [
            'email' => $email, 'password' => 'wrong-password',
        ]);
    }

    #[DataProvider('loginRoutes')]
    public function test_five_attempts_then_generic_429_with_retry_after_and_natural_expiry(string $route): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 5; $i++) {
            $this->attempt($route)->assertUnauthorized();
        }
        $limited = $this->attempt($route)->assertStatus(429)
            ->assertExactJson(['message' => '嘗試次數過多，請稍後再試。']);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->travel(61)->seconds();
        $this->attempt($route)->assertUnauthorized();
    }

    #[DataProvider('loginRoutes')]
    public function test_normalization_and_email_ip_isolation(string $route): void
    {
        foreach (['Quota@example.test', ' quota@example.test ', 'QUOTA@EXAMPLE.TEST', 'quota@example.test', 'Quota@Example.Test'] as $email) {
            $this->attempt($route, $email)->assertUnauthorized();
        }
        $this->attempt($route)->assertStatus(429);
        $this->attempt($route, 'other@example.test')->assertUnauthorized();
        $this->attempt($route, 'quota@example.test', '192.0.2.2')->assertUnauthorized();
    }

    public function test_member_admin_buckets_are_independent(): void
    {
        for ($i = 0; $i < 5; $i++) $this->attempt('/api/login')->assertUnauthorized();
        $this->attempt('/api/login')->assertStatus(429);
        for ($i = 0; $i < 5; $i++) $this->attempt('/api/admin/login')->assertUnauthorized();
        $this->attempt('/api/admin/login')->assertStatus(429);
    }

    public function test_register_three_attempts_ip_isolation_and_expiry(): void
    {
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3']);
        for ($i = 0; $i < 3; $i++) $this->postJson('/api/register', [])->assertUnprocessable();
        $this->postJson('/api/register', [])->assertStatus(429)
            ->assertExactJson(['message' => '嘗試次數過多，請稍後再試。'])->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->postJson('/api/register', [])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->postJson('/api/register', [])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_normalized_member_login_and_disabled_contract(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'test-password']);
        $this->postJson('/api/login', ['email' => ' MEMBER@EXAMPLE.TEST ', 'password' => 'test-password'])
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user, 'web');
        $user->forceFill(['status' => 'disabled'])->save();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-password'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_normalized_admin_login_and_disabled_contract(): void
    {
        $admin = Admin::query()->create(['name' => 'Fixture', 'email' => 'admin@example.test', 'password' => 'test-password', 'status' => 'active']);
        $this->postJson('/api/admin/login', ['email' => ' ADMIN@EXAMPLE.TEST ', 'password' => 'test-password'])
            ->assertOk()->assertJsonPath('data.id', $admin->id);
        $this->assertAuthenticatedAs($admin, 'admin');
        $admin->update(['status' => 'disabled']);
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-password'])
            ->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
    }

    public function test_success_does_not_clear_window_and_non_auth_reads_are_not_throttled(): void
    {
        $user = User::factory()->create(['email' => 'quota@example.test', 'password' => 'test-password']);
        for ($i = 0; $i < 4; $i++) $this->attempt('/api/login')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-password'])->assertOk();
        $this->attempt('/api/login')->assertStatus(429);
        for ($i = 0; $i < 6; $i++) $this->getJson('/api/categories')->assertOk();
    }

    public function test_non_string_email_is_validation_error_without_crash(): void
    {
        $this->attempt('/api/login', ['invalid'])->assertUnprocessable();
        $this->attempt('/api/admin/login', ['invalid'])->assertUnprocessable();
    }

    public function test_valid_registration_keeps_existing_contract_and_consumes_quota(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/register', [
                'name' => 'Fixture', 'email' => 'register'.$i.'@example.test', 'phone' => '0912345678',
                'password' => 'test-password', 'password_confirmation' => 'test-password',
            ])->assertCreated()->assertJsonPath('data.status', 'active')
                ->assertJsonMissingPath('data.password')->assertJsonMissingPath('success');
        }
        $this->postJson('/api/register', [])->assertStatus(429);
        $this->assertDatabaseCount('users', 3);
        $this->assertGuest('web');
    }
}
