<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_schema_contains_only_confirmed_columns_and_defaults_to_active(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'password', 'status', 'created_at', 'updated_at'],
            Schema::getColumnListing('admins'),
        );

        $id = DB::table('admins')->insertGetId([
            'name' => '測試管理員',
            'email' => 'default@example.test',
            'password' => Hash::make('test-only'),
        ]);

        $this->assertSame('active', Admin::query()->findOrFail($id)->status);
    }

    public function test_admin_email_is_unique_in_database(): void
    {
        $admin = Admin::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Admin::factory()->create(['email' => $admin->email]);
    }

    public function test_model_hashes_password_and_hides_it_from_array_and_json(): void
    {
        $admin = Admin::factory()->create(['password' => 'test-only-secret']);
        $admin->refresh();

        $this->assertNotSame('test-only-secret', $admin->password);
        $this->assertTrue(Hash::check('test-only-secret', $admin->password));
        $this->assertArrayNotHasKey('password', $admin->toArray());
        $this->assertArrayNotHasKey('password', json_decode($admin->toJson(), true));
        $this->assertSame('active', $admin->status);
        $this->assertIsString($admin->status);
    }

    public function test_factory_creates_active_and_disabled_isolated_fixtures(): void
    {
        $active = Admin::factory()->create();
        $disabled = Admin::factory()->disabled()->create();

        $this->assertSame('active', $active->status);
        $this->assertSame('disabled', $disabled->status);
        $this->assertNotSame($active->email, $disabled->email);
        $this->assertLessThanOrEqual(50, mb_strlen($active->name));
        $this->assertFalse(Hash::needsRehash($active->password));
        $this->assertNotSame($active->password, $disabled->password);
    }

    public function test_guard_configuration_keeps_web_and_sanctum_unchanged(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame(['driver' => 'session', 'provider' => 'users'], config('auth.guards.web'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertSame(['driver' => 'session', 'provider' => 'admins'], config('auth.guards.admin'));
        $this->assertSame(Admin::class, config('auth.providers.admins.model'));
        $this->assertSame(['web'], config('sanctum.guard'));
    }

    public function test_same_id_identities_are_restored_from_distinct_providers_in_shared_session(): void
    {
        $user = User::factory()->create(['id' => 41]);
        $admin = Admin::factory()->create(['id' => 41]);
        $webKey = $this->sessionGuard('web')->getName();
        $adminKey = $this->sessionGuard('admin')->getName();

        $this->sessionGuard('web')->login($user);
        $this->sessionGuard('admin')->login($admin);
        $this->assertNotSame($webKey, $adminKey);
        $this->assertSame(41, $this->app['session.store']->get($webKey));
        $this->assertSame(41, $this->app['session.store']->get($adminKey));

        // 真正儲存並重讀 payload，清除 guard cache，不能只比較 login 時的物件。
        $this->restoreSavedSession();
        $restoredUser = $this->sessionGuard('web')->user();
        $restoredAdmin = $this->sessionGuard('admin')->user();
        $this->assertInstanceOf(User::class, $restoredUser);
        $this->assertInstanceOf(Admin::class, $restoredAdmin);
        $this->assertTrue($user->is($restoredUser));
        $this->assertTrue($admin->is($restoredAdmin));
        $this->assertSame($user->email, $restoredUser->email);
        $this->assertSame($admin->email, $restoredAdmin->email);
    }

    public function test_member_only_session_cannot_authenticate_admin_even_with_same_id(): void
    {
        $user = User::factory()->create(['id' => 41]);
        Admin::factory()->create(['id' => 41]);
        $this->sessionGuard('web')->login($user);
        $this->restoreSavedSession();

        $this->assertTrue($this->sessionGuard('web')->check());
        $this->assertNull($this->sessionGuard('admin')->user());
    }

    public function test_admin_only_session_cannot_authenticate_member_even_with_same_id(): void
    {
        User::factory()->create(['id' => 41]);
        $admin = Admin::factory()->create(['id' => 41]);
        $this->sessionGuard('admin')->login($admin);
        $this->restoreSavedSession();

        $this->assertTrue($this->sessionGuard('admin')->check());
        $this->assertNull($this->sessionGuard('web')->user());
    }

    public function test_admin_logout_and_id_only_rotation_preserve_member_payload_and_csrf(): void
    {
        $user = User::factory()->create();
        $this->sessionGuard('web')->login($user);
        $this->sessionGuard('admin')->login(Admin::factory()->create());
        $session = $this->app['session.store'];
        $session->put('member_marker', 'preserve');
        $this->restoreSavedSession();
        $oldId = $session->getId();
        $oldToken = $session->token();

        $this->sessionGuard('admin')->logout();
        $session->migrate(true);

        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame('', $session->getHandler()->read($oldId));
        $this->assertSame($oldToken, $session->token());
        $this->restoreSavedSession();
        $this->assertTrue($user->is($this->sessionGuard('web')->user()));
        $this->assertNull($this->sessionGuard('admin')->user());
        $this->assertSame('preserve', $session->get('member_marker'));
    }

    public function test_whole_session_invalidation_revokes_both_guard_identities(): void
    {
        $this->sessionGuard('web')->login(User::factory()->create());
        $this->sessionGuard('admin')->login(Admin::factory()->create());
        $session = $this->app['session.store'];
        $session->put('member_marker', 'removed');
        $this->restoreSavedSession();
        $oldId = $session->getId();

        $session->invalidate();
        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame('', $session->getHandler()->read($oldId));
        $this->assertSame([], $session->all());
        $this->restoreSavedSession();
        $this->assertNull($this->sessionGuard('web')->user());
        $this->assertNull($this->sessionGuard('admin')->user());
        $this->assertFalse($session->has('member_marker'));
    }

    public function test_regenerate_preserves_payload_but_rotates_id_and_csrf_token(): void
    {
        $session = $this->app['session.store'];
        $session->start();
        $session->put('marker', 'preserve');
        $oldId = $session->getId();
        $oldToken = $session->token();

        $session->regenerate(true);

        $this->assertNotSame($oldId, $session->getId());
        $this->assertNotSame($oldToken, $session->token());
        $this->assertSame('preserve', $session->get('marker'));
        $newId = $session->getId();
        $newToken = $session->token();
        $session->regenerateToken();
        $this->assertSame($newId, $session->getId());
        $this->assertNotSame($newToken, $session->token());
    }

    public static function allowedEnvironments(): array
    {
        return ['testing' => ['testing'], 'local' => ['local']];
    }

    #[DataProvider('allowedEnvironments')]
    public function test_bootstrap_creates_admin_without_imposing_password_complexity(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->bootstrapAdmin('開發管理員', 'dev@example.test', 'x', 'x')
            ->expectsOutput('本機開發管理員已建立。')
            ->assertSuccessful();

        $admin = Admin::query()->sole();
        $this->assertSame('active', $admin->status);
        $this->assertTrue(Hash::check('x', $admin->password));
        $this->assertNotSame('x', $admin->password);
    }

    public function test_bootstrap_duplicate_email_never_overwrites_existing_admin(): void
    {
        $this->bootstrapAdmin('原本姓名', 'dev@example.test', 'original-secret', 'original-secret')
            ->expectsOutput('本機開發管理員已建立。')
            ->assertSuccessful();
        $admin = Admin::query()->sole();
        $admin->update(['status' => 'disabled']);
        $before = $admin->fresh()->getAttributes();

        $this->bootstrapAdmin('不同姓名', $admin->email, 'different', 'different')
            ->expectsOutput('此 Email 已存在，未建立或修改管理員。')
            ->assertFailed();

        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertTrue(Hash::check('original-secret', $admin->fresh()->password));
        $this->assertDatabaseCount('admins', 1);
    }

    public static function invalidBootstrapInputs(): array
    {
        return [
            'empty name' => ['', 'dev@example.test', 'x', 'x'],
            'long name' => [str_repeat('名', 51), 'dev@example.test', 'x', 'x'],
            'invalid email' => ['姓名', 'invalid', 'x', 'x'],
            'long email' => ['姓名', str_repeat('a', 245).'@example.test', 'x', 'x'],
            'blank password' => ['姓名', 'dev@example.test', '   ', '   '],
            'empty password' => ['姓名', 'dev@example.test', '', ''],
            'mismatched passwords' => ['姓名', 'dev@example.test', 'x', 'y'],
        ];
    }

    #[DataProvider('invalidBootstrapInputs')]
    public function test_bootstrap_rejects_invalid_input_without_creating_admin(string $name, string $email, string $password, string $confirmation): void
    {
        $this->bootstrapAdmin($name, $email, $password, $confirmation)->assertFailed();

        $this->assertDatabaseCount('admins', 0);
    }

    public static function forbiddenEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('forbiddenEnvironments')]
    public function test_bootstrap_refuses_non_local_testing_environment(string $environment): void
    {
        // 使用此測試 app 的公開環境偵測方法；不修改全域 env 或開發設定。
        $this->app->detectEnvironment(fn () => $environment);
        $this->artisan('admin:bootstrap')
            ->expectsOutput('此指令僅允許在 local/testing 環境執行。')
            ->assertFailed();

        $this->assertDatabaseCount('admins', 0);
    }

    private function sessionGuard(string $name): SessionGuard
    {
        $guard = Auth::guard($name);
        $this->assertInstanceOf(SessionGuard::class, $guard);

        return $guard;
    }

    private function restoreSavedSession(): void
    {
        $session = $this->app['session.store'];
        $session->save();
        $session->flush();
        $session->start();
        Auth::forgetGuards();
    }

    private function bootstrapAdmin(string $name, string $email, string $password, string $confirmation): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('admin:bootstrap')
            ->expectsQuestion('管理員姓名', $name)
            ->expectsQuestion('管理員 Email', $email)
            ->expectsQuestion('管理員密碼', $password)
            ->expectsQuestion('再次輸入密碼', $confirmation);
    }
}
