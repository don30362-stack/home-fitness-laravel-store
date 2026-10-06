<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Banner;
use App\Models\User;
use App\Services\AdminBannerService;
use App\Services\BannerImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminBannerApiTest extends TestCase
{
    use RefreshDatabase;

    private function permissionAdmin(array $attributes = []): \App\Models\Admin
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = \App\Models\Admin::factory()->create($attributes);
        $admin->permissions()->attach(\App\Models\Permission::query()->where('code', 'home_content_manage')->value('id'));
        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        Storage::fake('public');
    }

    private function login(): void { $this->actingAs($this->permissionAdmin(), 'admin'); }
    private function file(string $ext = 'jpg', ?string $name = null): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/product-images/sample.'.$ext), $name ?? 'client-original.'.$ext, null, UPLOAD_ERR_OK, true);
    }
    private function banner(array $attributes = []): Banner
    {
        $path = 'banners/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, file_get_contents($this->file()->getPathname()));
        return Banner::create($attributes + ['title' => '輪播', 'image_path' => $path, 'sort_order' => 0, 'status' => 'active']);
    }
    private function payload(array $values = []): array { return $values + ['title' => '  新輪播  ', 'image' => $this->file()]; }

    public static function endpoints(): array
    {
        return [['get', ''], ['post', ''], ['patch', '/1'], ['patch', '/1/status'], ['patch', '/order'], ['delete', '/1']];
    }
    #[DataProvider('endpoints')]
    public function test_guest_and_member_only_cannot_use_banner_admin(string $method, string $suffix): void
    {
        $this->json($method, '/api/admin/banners'.$suffix)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        $this->json($method, '/api/admin/banners'.$suffix)->assertUnauthorized();
    }
    #[DataProvider('endpoints')]
    public function test_disabled_admin_does_not_invalidate_member(string $method, string $suffix): void
    {
        $member = User::factory()->create();
        $this->actingAs($member, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->json($method, '/api/admin/banners'.$suffix)->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($member, 'web');
    }
    public function test_list_exact_admin_contract_all_states_stable_order_and_empty(): void
    {
        $this->login();
        $this->getJson('/api/admin/banners')->assertExactJson(['data' => []]);
        $a = $this->banner(['status' => 'inactive', 'sort_order' => 2]);
        $b = $this->banner(['sort_order' => 1]); $c = $this->banner(['sort_order' => 1]);
        $result = $this->getJson('/api/admin/banners')->assertOk()->assertJsonMissingPath('meta')->assertJsonMissingPath('success');
        $this->assertSame([$b->id, $c->id, $a->id], array_column($result->json('data'), 'id'));
        $this->assertEqualsCanonicalizing(['id', 'title', 'subtitle', 'image_url', 'button_text', 'link_url', 'sort_order', 'status', 'created_at', 'updated_at'], array_keys($result->json('data.0')));
        $this->assertStringContainsString('/storage/banners/', $result->json('data.0.image_url'));
    }
    public static function formats(): array { return [['jpg'], ['png'], ['webp']]; }
    #[DataProvider('formats')]
    public function test_create_real_binary_formats_defaults_and_server_filename(string $ext): void
    {
        $this->login();
        $response = $this->postJson('/api/admin/banners', $this->payload(['image' => $this->file($ext)]))->assertCreated()
            ->assertJsonPath('data.title', '新輪播')->assertJsonPath('data.status', 'active')->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.button_text', null)->assertJsonMissingPath('data.image_path')->assertJsonMissingPath('success');
        $row = Banner::findOrFail($response->json('data.id'));
        $this->assertMatchesRegularExpression('#^banners/[a-f0-9-]{36}\.'.$ext.'$#', $row->image_path);
        Storage::disk('public')->assertExists($row->image_path);
        $this->assertSame(2, getimagesize(Storage::disk('public')->path($row->image_path))[0]);
    }
    public function test_create_inactive_and_paired_optional_fields(): void
    {
        $this->login();
        $this->postJson('/api/admin/banners', $this->payload(['status' => 'inactive', 'sort_order' => 6, 'subtitle' => '  說明 ', 'button_text' => ' 看商品 ', 'link_url' => ' /products ']))
            ->assertCreated()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.sort_order', 6)
            ->assertJsonPath('data.subtitle', '說明')->assertJsonPath('data.button_text', '看商品')->assertJsonPath('data.link_url', '/products');
    }
    public static function invalidFields(): array
    {
        return [['title', null], ['title', '   '], ['title', []], ['title', str_repeat('a', 151)],
            ['subtitle', str_repeat('a', 256)], ['button_text', str_repeat('a', 51)], ['link_url', '/'.str_repeat('a', 255)],
            ['sort_order', -1], ['sort_order', 4294967296], ['sort_order', 1.5], ['status', 'disabled'],
            ['image_path', 'banners/attack.jpg'], ['image_url', '/attack'], ['id', 3], ['created_at', 'today'], ['extra', true], ['extra.path', true]];
    }
    #[DataProvider('invalidFields')]
    public function test_create_validation_and_strict_fields(string $field, mixed $value): void
    {
        $this->login();
        $this->postJson('/api/admin/banners', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('banners', 0); $this->assertSame([], Storage::disk('public')->allFiles());
    }
    public function test_required_image_and_pair_validation_create_and_locked_partial_patch(): void
    {
        $this->login();
        $this->postJson('/api/admin/banners', ['title' => '缺圖'])->assertUnprocessable()->assertJsonValidationErrors('image');
        foreach ([['button_text' => '前往'], ['link_url' => '/products']] as $pair) {
            $this->postJson('/api/admin/banners', $this->payload($pair))->assertUnprocessable()->assertJsonValidationErrors('button_text');
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $row = $this->banner(['button_text' => '前往', 'link_url' => '/products']);
        $this->patchJson('/api/admin/banners/'.$row->id, ['subtitle' => '新說明'])->assertOk()->assertJsonPath('data.link_url', '/products');
        $this->patchJson('/api/admin/banners/'.$row->id, ['button_text' => null])->assertUnprocessable();
        $this->assertSame('前往', $row->fresh()->button_text);
        $this->patchJson('/api/admin/banners/'.$row->id, ['button_text' => null, 'link_url' => null])->assertOk()->assertJsonPath('data.link_url', null);
        $this->patchJson('/api/admin/banners/'.$row->id, ['button_text' => '查看', 'link_url' => '/'])->assertOk();
        $this->patchJson('/api/admin/banners/'.$row->id, ['image' => null])->assertUnprocessable();
    }
    public static function links(): array
    {
        $valid = ['/', '/products', '/products/12', '/products?category_id=5', '/products/12#spec', '/products?q=a%20b', '/products?q=50%25', '/products#save%25'];
        $invalid = ['http://evil.com', 'https://evil.com', '//evil.com', '\\evil.com', '/foo\\bar', 'javascript:alert(1)', 'data:image/png',
            "/products\0", "\n/products", "/products\r", '/%2fevil.com', '/%252fevil.com', '/%5cevil.com', '/%255cevil.com', '/%00', '/%250a', '/%xx'];
        return [...array_map(fn ($v) => [$v, true], $valid), ...array_map(fn ($v) => [$v, false], $invalid)];
    }
    #[DataProvider('links')]
    public function test_link_security(string $link, bool $valid): void
    {
        $this->login();
        $response = $this->postJson('/api/admin/banners', $this->payload(['button_text' => '查看', 'link_url' => $link]));
        if ($valid) $response->assertCreated(); else $response->assertUnprocessable()->assertJsonValidationErrors('link_url');
    }
    public function test_exact_five_mib_and_oversize_real_binary(): void
    {
        $this->login();
        foreach ([5 * 1024 * 1024, 5 * 1024 * 1024 + 1] as $size) {
            $path = tempnam(sys_get_temp_dir(), 'banner-size-');
            try {
                $bytes = file_get_contents($this->file()->getPathname());
                file_put_contents($path, $bytes.str_repeat("\0", $size - strlen($bytes)));
                $response = $this->postJson('/api/admin/banners', $this->payload(['image' => new UploadedFile($path, 'boundary.jpg', null, UPLOAD_ERR_OK, true)]));
                if ($size === 5 * 1024 * 1024) $response->assertCreated(); else $response->assertUnprocessable()->assertJsonValidationErrors('image');
            } finally { unlink($path); }
        }
    }
    public static function badImages(): array
    {
        return [['text', 'fake.jpg'], ['text', 'fake.png'], ['svg', 'fake.svg'], ['gif', 'fake.gif'], ['jpg', 'fake.txt'], ['svg', 'fake.jpg']];
    }
    #[DataProvider('badImages')]
    public function test_image_validation_uses_real_bytes_and_extension(string $kind, string $name): void
    {
        $this->login(); $path = tempnam(sys_get_temp_dir(), 'banner-bad-');
        try {
            $bytes = match ($kind) { 'jpg' => file_get_contents($this->file()->getPathname()), 'svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
                'gif' => base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='), default => 'not an image' };
            file_put_contents($path, $bytes);
            $this->postJson('/api/admin/banners', $this->payload(['image' => new UploadedFile($path, $name, 'image/jpeg', UPLOAD_ERR_OK, true)]))
                ->assertUnprocessable()->assertJsonValidationErrors('image');
        } finally { unlink($path); }
    }
    public function test_update_noop_status_noop_and_public_visibility(): void
    {
        $this->login(); $row = $this->banner(); $time = $row->updated_at->toISOString(); $this->travel(1)->hours();
        $this->patchJson('/api/admin/banners/'.$row->id, ['title' => $row->title])->assertOk()->assertJsonPath('data.updated_at', $time);
        $this->patchJson('/api/admin/banners/'.$row->id.'/status', ['status' => 'active'])->assertOk()->assertJsonPath('data.updated_at', $time);
        $this->patchJson('/api/admin/banners/'.$row->id, ['status' => 'inactive'])->assertUnprocessable();
        $this->patchJson('/api/admin/banners/'.$row->id.'/status', ['status' => 'inactive', 'title' => 'bad'])->assertUnprocessable();
        foreach ([[], ['status' => null], ['status' => 'disabled']] as $data) $this->patchJson('/api/admin/banners/'.$row->id.'/status', $data)->assertUnprocessable();
        $this->patchJson('/api/admin/banners/'.$row->id.'/status', ['status' => 'inactive'])->assertOk();
        $this->getJson('/api/banners')->assertExactJson(['data' => []]);
        $this->patchJson('/api/admin/banners/'.$row->id.'/status', ['status' => 'active'])->assertOk();
        $this->getJson('/api/banners')->assertJsonCount(1, 'data');
    }
    public function test_replace_spoofing_and_delete_managed_file_after_commit(): void
    {
        $this->login(); $row = $this->banner(); $old = $row->image_path;
        $this->post('/api/admin/banners/'.$row->id, ['_method' => 'PATCH', 'image' => $this->file('png'), 'title' => '替換'], ['Accept' => 'application/json'])->assertOk();
        $new = $row->fresh()->image_path;
        $this->assertNotSame($old, $new); Storage::disk('public')->assertExists($new); Storage::disk('public')->assertMissing($old);
        $this->deleteJson('/api/admin/banners/'.$row->id)->assertOk()->assertExactJson(['message' => '輪播已刪除。']);
        $this->assertDatabaseMissing('banners', ['id' => $row->id]); Storage::disk('public')->assertMissing($new);
    }
    public function test_db_create_and_replace_failure_compensate_new_file_and_keep_original_error(): void
    {
        $this->login();
        Banner::creating(fn () => throw new RuntimeException('create-db-failure'));
        $this->postJson('/api/admin/banners', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('banners', 0); $this->assertSame([], Storage::disk('public')->allFiles());
        Banner::flushEventListeners(); $row = $this->banner(); $old = $row->image_path;
        Banner::updated(fn () => throw new RuntimeException('late-update-failure'));
        $this->patchJson('/api/admin/banners/'.$row->id, ['image' => $this->file('png'), 'title' => '失敗'])->assertStatus(500);
        $this->assertSame($old, $row->fresh()->image_path); $this->assertSame('輪播', $row->fresh()->title);
        $this->assertSame([$old], Storage::disk('public')->allFiles()); Banner::flushEventListeners();
    }
    public function test_storage_failure_never_creates_row(): void
    {
        $this->login();
        $this->app->instance(BannerImageStorage::class, new class extends BannerImageStorage {
            public function store(UploadedFile $file): string { throw new RuntimeException('storage failed'); }
        });
        $this->postJson('/api/admin/banners', $this->payload())->assertStatus(500); $this->assertDatabaseCount('banners', 0);
    }
    public function test_creating_event_cancellation_fails_and_compensates_new_file(): void
    {
        $this->login();
        Banner::creating(fn () => false);
        try {
            $this->postJson('/api/admin/banners', $this->payload())->assertStatus(500);
            $this->assertDatabaseCount('banners', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        } finally { Banner::flushEventListeners(); }
    }

    public function test_updating_event_cancellation_preserves_original_and_compensates_replacement(): void
    {
        $this->login(); $row = $this->banner(); $original = $row->fresh()->getAttributes(); $old = $row->image_path;
        Banner::updating(fn () => false);
        try {
            $this->patchJson('/api/admin/banners/'.$row->id, ['image' => $this->file('png'), 'title' => '不得成功'])->assertStatus(500);
            $this->assertSame($original, $row->fresh()->getAttributes());
            Storage::disk('public')->assertExists($old);
            $this->assertSame([$old], Storage::disk('public')->allFiles());
        } finally { Banner::flushEventListeners(); }
    }

    public function test_status_event_cancellation_fails_without_changing_status(): void
    {
        $this->login(); $row = $this->banner(); $original = $row->fresh()->getAttributes();
        Banner::updating(fn () => false);
        try {
            $this->patchJson('/api/admin/banners/'.$row->id.'/status', ['status' => 'inactive'])->assertStatus(500);
            $this->assertSame($original, $row->fresh()->getAttributes());
        } finally { Banner::flushEventListeners(); }
    }

    public function test_reorder_event_cancellation_rolls_back_prior_successful_write(): void
    {
        $this->login(); $a = $this->banner(['sort_order' => 5]); $b = $this->banner(['sort_order' => 6]);
        $earlierWriteObserved = false;
        Banner::updating(function ($row) use ($a, $b, &$earlierWriteObserved) {
            if ($row->id === $a->id) {
                $earlierWriteObserved = Banner::findOrFail($b->id)->sort_order === 0;
                return false;
            }
        });
        try {
            $this->patchJson('/api/admin/banners/order', ['ids' => [$b->id, $a->id]])->assertStatus(500);
            $this->assertTrue($earlierWriteObserved);
            $this->assertSame(5, $a->fresh()->sort_order);
            $this->assertSame(6, $b->fresh()->sort_order);
        } finally { Banner::flushEventListeners(); }
    }

    public function test_deleting_event_cancellation_keeps_row_and_managed_file(): void
    {
        $this->login(); $row = $this->banner(); $original = $row->fresh()->getAttributes();
        Banner::deleting(fn () => false);
        try {
            $this->deleteJson('/api/admin/banners/'.$row->id)->assertStatus(500);
            $this->assertSame($original, $row->fresh()->getAttributes());
            Storage::disk('public')->assertExists($row->image_path);
        } finally { Banner::flushEventListeners(); }
    }
    public function test_outer_rollback_keeps_old_image_and_compensates_replacement(): void
    {
        $row = $this->banner(); $old = $row->image_path;
        DB::beginTransaction();
        app(AdminBannerService::class)->update($row->id, ['image' => $this->file('png')]);
        $new = $row->fresh()->image_path; Storage::disk('public')->assertExists($old);
        DB::rollBack();
        $this->assertSame($old, $row->fresh()->image_path); Storage::disk('public')->assertExists($old); Storage::disk('public')->assertMissing($new);
        DB::beginTransaction(); app(AdminBannerService::class)->delete($row->id); DB::rollBack();
        $this->assertDatabaseHas('banners', ['id' => $row->id]); Storage::disk('public')->assertExists($old);
    }
    public function test_delete_unmanaged_and_missing_images_are_logical_success(): void
    {
        $this->login(); $row = $this->banner(['image_path' => 'legacy/banner.jpg']); Storage::disk('public')->put('legacy/banner.jpg', 'keep');
        Log::spy(); $this->deleteJson('/api/admin/banners/'.$row->id)->assertOk();
        Storage::disk('public')->assertExists('legacy/banner.jpg');
        Log::shouldHaveReceived('warning')->with('Banner image cleanup skipped: unmanaged path', \Mockery::on(fn ($context) => isset($context['path_hash']) && ! isset($context['path'])))->once();
        $row = $this->banner(); Storage::disk('public')->delete($row->image_path);
        $this->deleteJson('/api/admin/banners/'.$row->id)->assertOk();
    }
    public static function cleanupFailures(): array { return [['false'], ['exception']]; }
    #[DataProvider('cleanupFailures')]
    public function test_cleanup_failure_is_logged_without_undoing_logical_delete(string $mode): void
    {
        $this->login(); $row = $this->banner(); $other = $this->banner(); Log::spy();
        $this->app->instance(BannerImageStorage::class, new class($mode) extends BannerImageStorage {
            public function __construct(private string $mode) {}
            protected function deleteFile(string $path): bool { if ($this->mode === 'exception') throw new RuntimeException('cleanup failed'); return false; }
        });
        $this->deleteJson('/api/admin/banners/'.$row->id)->assertOk();
        $this->assertDatabaseMissing('banners', ['id' => $row->id]); Storage::disk('public')->assertExists($other->image_path);
        Log::shouldHaveReceived('error')->with('Banner image physical cleanup failed.', \Mockery::on(fn ($c) => $c['banner_id'] === $row->id && isset($c['failure_kind'], $c['error'])))->once();
    }
    public function test_create_failure_cleanup_failure_preserves_original_exception(): void
    {
        Log::spy();
        $this->app->instance(BannerImageStorage::class, new class extends BannerImageStorage {
            protected function deleteFile(string $path): bool { return false; }
        });
        Banner::creating(fn () => throw new RuntimeException('original db error'));
        try { app(AdminBannerService::class)->create($this->payload()); $this->fail('Expected failure'); }
        catch (RuntimeException $e) { $this->assertSame('original db error', $e->getMessage()); }
        finally { Banner::flushEventListeners(); }
        Log::shouldHaveReceived('error')->atLeast()->once(); $this->assertDatabaseCount('banners', 0);
    }
    public function test_replace_cleanup_failure_keeps_new_db_path_and_logs_old_file_failure(): void
    {
        $this->login(); $row = $this->banner(); $old = $row->image_path; Log::spy();
        $this->app->instance(BannerImageStorage::class, new class extends BannerImageStorage {
            protected function deleteFile(string $path): bool { return false; }
        });
        $this->patchJson('/api/admin/banners/'.$row->id, ['image' => $this->file('png')])->assertOk();
        $new = $row->fresh()->image_path; $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new); Storage::disk('public')->assertExists($old);
        Log::shouldHaveReceived('error')->with('Banner image physical cleanup failed.', \Mockery::on(fn ($c) => $c['reason'] === 'replace_committed' && $c['path'] === $old))->once();
    }
    public function test_delete_late_db_failure_does_not_delete_file(): void
    {
        $this->login(); $row = $this->banner();
        Banner::deleted(fn () => throw new RuntimeException('late delete failure'));
        $this->deleteJson('/api/admin/banners/'.$row->id)->assertStatus(500);
        $this->assertDatabaseHas('banners', ['id' => $row->id]); Storage::disk('public')->assertExists($row->image_path);
        Banner::flushEventListeners();
    }
    public function test_reorder_full_set_read_order_and_stale_validation(): void
    {
        $this->login(); $a = $this->banner(); $b = $this->banner(); $c = $this->banner();
        $ids = [$c->id, $a->id, $b->id];
        $this->patchJson('/api/admin/banners/order', ['ids' => $ids])->assertOk();
        foreach (['/api/admin/banners', '/api/banners'] as $url) $this->assertSame($ids, array_column($this->getJson($url)->json('data'), 'id'));
        $this->assertSame([0, 1, 2], Banner::orderBy('sort_order')->pluck('sort_order')->all());
        foreach ([[], [$a->id], [$a->id, $a->id, $c->id], [$a->id, $b->id, 999], ['x'], [null]] as $bad) {
            $this->patchJson('/api/admin/banners/order', ['ids' => $bad])->assertUnprocessable();
        }
        foreach ([[], ['ids' => null], ['ids' => $ids, 'extra' => 1]] as $bad) $this->patchJson('/api/admin/banners/order', $bad)->assertUnprocessable();
        $this->patchJson('/api/admin/banners/order', ['ids' => $ids, 'ids.*' => 1])->assertUnprocessable();
        $this->banner(); $this->patchJson('/api/admin/banners/order', ['ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors('ids');
    }
    public function test_empty_reorder_and_late_failure_atomicity(): void
    {
        $this->login(); $this->patchJson('/api/admin/banners/order', ['ids' => []])->assertOk();
        $a = $this->banner(['sort_order' => 5]); $b = $this->banner(['sort_order' => 6]);
        Banner::updated(function ($row) use ($a) { if ($row->id === $a->id) throw new RuntimeException('late sort failure'); });
        $this->patchJson('/api/admin/banners/order', ['ids' => [$b->id, $a->id]])->assertStatus(500);
        $this->assertSame(5, $a->fresh()->sort_order); $this->assertSame(6, $b->fresh()->sort_order); Banner::flushEventListeners();
    }
    public function test_numeric_missing_ids_and_six_admin_banner_routes(): void
    {
        $this->login();
        foreach (['patch', 'delete'] as $method) foreach (['unknown', '999'] as $id) {
            $this->json($method, '/api/admin/banners/'.$id, ['title' => '不存在'])->assertNotFound();
        }
        $this->patchJson('/api/admin/banners/999/status', ['status' => 'active'])->assertNotFound();
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/banners'));
        $this->assertCount(6, $routes);
        foreach ($routes as $route) $this->assertSame(['api', 'auth:admin', 'admin.active', 'admin.permission:home_content_manage'], $route->gatherMiddleware());
    }
}
