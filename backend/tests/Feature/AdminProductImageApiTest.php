<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminProductImageApiTest extends TestCase
{
    use RefreshDatabase;

    private function permissionAdmin(array $attributes = []): \App\Models\Admin
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = \App\Models\Admin::factory()->create($attributes);
        $admin->permissions()->attach(\App\Models\Permission::query()->where('code', 'product_manage')->value('id'));
        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        Storage::fake('public');
    }

    private function product(): Product
    {
        $root = Category::create(['name' => '圖片主分類', 'status' => 'active']);
        return Product::factory()->create(['category_id' => Category::query()->create(['parent_id' => $root->id, 'name' => '圖片測試分類'])->id]);
    }

    private function login(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
    }

    private function file(string $extension = 'jpg', ?string $name = null): UploadedFile
    {
        // 真正2x2 JPEG/PNG/WebP bytes；不依賴未安裝的PHP GD或fake MIME覆寫。
        return new UploadedFile(base_path('tests/Fixtures/product-images/sample.'.$extension), $name ?? 'client-original.'.$extension, null, UPLOAD_ERR_OK, true);
    }

    private function upload(Product $product, array $metadata = []): array
    {
        return $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $this->file()] + $metadata)
            ->assertCreated()->assertJsonMissingPath('success')->json('data');
    }

    private function image(Product $product, array $data = []): ProductImage
    {
        $path = 'products/'.$product->id.'/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, file_get_contents(base_path('tests/Fixtures/product-images/sample.jpg')));

        return $product->images()->create($data + ['image_path' => $path, 'image_type' => 'gallery', 'sort_order' => 0, 'is_primary' => false]);
    }

    public static function endpoints(): array
    {
        return [['post', '/products/1/images'], ['patch', '/product-images/1'], ['delete', '/product-images/1']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_and_member_only_are_rejected(string $method, string $path): void
    {
        $this->json($method, '/api/admin'.$path, [])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        $this->json($method, '/api/admin'.$path, [])->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_disabled_admin_does_not_clear_member(string $method, string $path): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->json($method, '/api/admin'.$path, [])->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public static function formats(): array
    {
        return [['jpg'], ['png'], ['webp']];
    }

    #[DataProvider('formats')]
    public function test_real_image_formats_first_primary_server_filename_and_storage_contract(string $extension): void
    {
        $this->login();
        $product = $this->product();
        $file = $this->file($extension);
        $this->assertSame(2, getimagesize($file->getPathname())[0]);
        $data = $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $file, 'is_primary' => '0'])
            ->assertCreated()->assertJsonPath('data.is_primary', true)->assertJsonPath('data.image_type', 'gallery')->assertJsonPath('data.sort_order', 0)->json('data');
        $this->assertMatchesRegularExpression('#^products/'.$product->id.'/[a-f0-9-]{36}\.'.$extension.'$#', $data['image_path']);
        $this->assertStringNotContainsString('client-original', $data['image_path']);
        $this->assertStringEndsWith('/storage/'.$data['image_path'], $data['image_url']);
        Storage::disk('public')->assertExists($data['image_path']);
    }

    public static function badFiles(): array
    {
        return [['fake.jpg', 'not an image'], ['file.txt', 'text'], ['file.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')], ['file.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>']];
    }

    #[DataProvider('badFiles')]
    public function test_non_image_forged_mime_and_unsupported_formats_are_rejected(string $name, string $content): void
    {
        $this->login();
        $product = $this->product();
        $path = tempnam(sys_get_temp_dir(), 'hfs-image-');
        file_put_contents($path, $content);
        try {
            $file = new UploadedFile($path, $name, 'image/jpeg', UPLOAD_ERR_OK, true);
            $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $file])->assertUnprocessable()->assertJsonValidationErrors('image');
            $this->assertDatabaseCount('product_images', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        } finally {
            unlink($path);
        }
    }

    public function test_valid_image_with_disallowed_extension_is_rejected(): void
    {
        $this->login();
        $product = $this->product();
        $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $this->file('jpg', 'renamed.txt')])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_jpeg_extension_alias_and_missing_image(): void
    {
        $this->login();
        $product = $this->product();
        foreach (['original.jpeg', 'original.JPG'] as $name) {
            $data = $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $this->file('jpg', $name)])->assertCreated()->json('data');
            $this->assertStringEndsWith('.jpg', $data['image_path']);
        }
        $this->postJson('/api/admin/products/'.$product->id.'/images', [])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public static function sizeBoundary(): array
    {
        return [[5120 * 1024, 201], [5120 * 1024 + 1, 422]];
    }

    #[DataProvider('sizeBoundary')]
    public function test_actual_five_mib_boundary(int $size, int $status): void
    {
        $this->login();
        $product = $this->product();
        $path = tempnam(sys_get_temp_dir(), 'hfs-size-');
        $bytes = file_get_contents(base_path('tests/Fixtures/product-images/sample.jpg'));
        file_put_contents($path, $bytes.str_repeat("\0", $size - strlen($bytes)));
        try {
            $file = new UploadedFile($path, 'large.jpg', null, UPLOAD_ERR_OK, true);
            $this->assertSame($size, $file->getSize());
            $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $file])->assertStatus($status);
            $this->assertDatabaseCount('product_images', $status === 201 ? 1 : 0);
        } finally {
            unlink($path);
        }
    }

    public static function invalidMetadata(): array
    {
        return [[['image_type' => 'other'], 'image_type'], [['sort_order' => -1], 'sort_order'], [['is_primary' => 'invalid'], 'is_primary'],
            [['image_path' => '../unsafe.jpg'], 'image_path'], [['product_id' => 2], 'product_id'], [['image_url' => 'https://example.test'], 'image_url']];
    }

    #[DataProvider('invalidMetadata')]
    public function test_metadata_validation_for_both_endpoints(array $payload, string $field): void
    {
        $this->login();
        $product = $this->product();
        $image = $this->image($product, ['is_primary' => true]);
        $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $this->file()] + $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/admin/product-images/'.$image->id, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_second_default_does_not_steal_primary_but_explicit_true_can_select_detail(): void
    {
        $this->login();
        $product = $this->product();
        $first = $this->upload($product);
        $second = $this->upload($product, ['sort_order' => 3]);
        $this->assertFalse($second['is_primary']);
        $third = $this->upload($product, ['image_type' => 'detail', 'is_primary' => '1']);
        $this->assertTrue($third['is_primary']);
        $this->assertDatabaseHas('product_images', ['id' => $first['id'], 'is_primary' => false]);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_metadata_omitted_fields_switch_and_primary_false_rules_are_explicit(): void
    {
        $this->login();
        $product = $this->product();
        $first = $this->image($product, ['is_primary' => true]);
        $second = $this->image($product);
        $other = $this->image($this->product(), ['is_primary' => true]);
        $this->patchJson('/api/admin/product-images/'.$second->id, ['image_type' => 'detail', 'sort_order' => 4])->assertOk()->assertJsonPath('data.is_primary', false);
        $this->patchJson('/api/admin/product-images/'.$second->id, ['is_primary' => true])->assertOk()->assertJsonPath('data.image_type', 'detail')->assertJsonPath('data.sort_order', 4);
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($other->fresh()->is_primary);
        $this->patchJson('/api/admin/product-images/'.$second->id, ['is_primary' => false])->assertUnprocessable()->assertJsonValidationErrors('is_primary');
        $this->patchJson('/api/admin/product-images/'.$first->id, ['is_primary' => false])->assertOk();
        $this->patchJson('/api/admin/product-images/'.$first->id, ['image' => $this->file()])->assertUnprocessable();
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_touched_legacy_zero_or_multiple_primaries_are_normalized_only_for_this_product(): void
    {
        $this->login();
        $product = $this->product();
        $one = $this->image($product);
        $two = $this->image($product);
        $this->patchJson('/api/admin/product-images/'.$two->id, ['sort_order' => 1])->assertOk();
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $one->update(['is_primary' => true]);
        $two->update(['is_primary' => true]);
        $this->patchJson('/api/admin/product-images/'.$two->id, ['is_primary' => true])->assertOk();
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $this->assertTrue($two->fresh()->is_primary);
    }

    public function test_image_delete_fallback_order_tie_break_last_and_other_product_file_preserved(): void
    {
        $this->login();
        $product = $this->product();
        $primary = $this->image($product, ['is_primary' => true, 'sort_order' => 9]);
        $first = $this->image($product, ['sort_order' => 2]);
        $tie = $this->image($product, ['sort_order' => 2]);
        $other = $this->image($this->product(), ['is_primary' => true]);
        $this->deleteJson('/api/admin/product-images/'.$primary->id)->assertOk();
        $this->assertTrue($first->fresh()->is_primary);
        $this->assertFalse($tie->fresh()->is_primary);
        Storage::disk('public')->assertMissing($primary->image_path);
        $this->deleteJson('/api/admin/product-images/'.$tie->id)->assertOk();
        $this->assertTrue($first->fresh()->is_primary);
        $this->deleteJson('/api/admin/product-images/'.$first->id)->assertOk();
        $this->assertSame(0, $product->images()->count());
        Storage::disk('public')->assertExists($other->image_path);
    }

    public function test_missing_physical_file_still_allows_logical_delete(): void
    {
        $this->login();
        $image = $this->image($this->product(), ['is_primary' => true]);
        Storage::disk('public')->delete($image->image_path);
        $this->deleteJson('/api/admin/product-images/'.$image->id)->assertOk();
        $this->assertDatabaseCount('product_images', 0);
    }

    private function failingCleanup(string $path = '', bool $throws = false): void
    {
        $this->app->instance(ProductImageStorage::class, new class($path, $throws) extends ProductImageStorage
        {
            public function __construct(private readonly string $failed, private readonly bool $throws) {}

            protected function deleteFile(string $path): bool
            {
                if ($this->failed === '' || $this->failed === $path) {
                    if ($this->throws) {
                        throw new RuntimeException('isolated storage exception');
                    }

                    return false;
                }

                return parent::deleteFile($path);
            }
        });
    }

    public static function cleanupFailures(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('cleanupFailures')]
    public function test_post_commit_delete_failure_is_logged_without_fake_db_rollback(bool $throws): void
    {
        $this->login();
        $image = $this->image($this->product(), ['is_primary' => true]);
        $this->failingCleanup('', $throws);
        Log::spy();
        $this->deleteJson('/api/admin/product-images/'.$image->id)->assertOk();
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
        Log::shouldHaveReceived('error')->once()->with('Product image physical cleanup failed.', \Mockery::on(fn ($c) => $c['path'] === $image->image_path && $c['image_id'] === $image->id));
    }

    public function test_db_upload_failure_compensates_new_file_and_preserves_primary(): void
    {
        $this->login();
        $product = $this->product();
        $existing = $this->image($product, ['is_primary' => true]);
        ProductImage::creating(fn () => throw new RuntimeException('isolated original DB failure'));
        try {
            $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $this->file(), 'is_primary' => true])->assertStatus(500);
            $this->assertDatabaseCount('product_images', 1);
            $this->assertTrue($existing->fresh()->is_primary);
            $this->assertSame([$existing->image_path], Storage::disk('public')->allFiles());
        } finally {
            ProductImage::flushEventListeners();
        }
    }

    public function test_upload_compensation_failure_logs_but_rethrows_original_exception(): void
    {
        $this->login();
        $product = $this->product();
        $this->failingCleanup();
        Log::spy();
        ProductImage::creating(fn () => throw new RuntimeException('original fixture error'));
        $this->withoutExceptionHandling();
        try {
            try {
                $this->postJson('/api/admin/products/'.$product->id.'/images', ['image' => $this->file()]);
                $this->fail('Must throw original error');
            } catch (RuntimeException $e) {
                $this->assertSame('original fixture error', $e->getMessage());
            }
            $this->assertDatabaseCount('product_images', 0);
            Log::shouldHaveReceived('error')->once()->with('Product image physical cleanup failed.', \Mockery::on(fn ($c) => $c['reason'] === 'upload_compensation'));
        } finally {
            ProductImage::flushEventListeners();
        }
    }

    public function test_unsafe_or_legacy_paths_are_not_deleted_as_managed_files(): void
    {
        $this->login();
        $product = $this->product();
        foreach (['products/legacy.jpg', 'products/999/'.Str::uuid().'.jpg', '../outside.jpg', 'C:/private.jpg'] as $path) {
            $image = $product->images()->create(['image_path' => $path, 'is_primary' => true]);
            if (! str_contains($path, ':') && ! str_starts_with($path, '../')) {
                Storage::disk('public')->put($path, 'preserve');
            }
            $this->deleteJson('/api/admin/product-images/'.$image->id)->assertOk();
            if (! str_contains($path, ':') && ! str_starts_with($path, '../')) {
                Storage::disk('public')->assertExists($path);
            }
        }
    }

    public function test_product_delete_cleans_all_files_and_continues_after_one_failure_and_cart_cascades(): void
    {
        $this->login();
        $product = $this->product();
        $first = $this->image($product, ['is_primary' => true]);
        $last = $this->image($product);
        User::factory()->create()->cart()->create()->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $this->failingCleanup($first->image_path);
        Log::spy();
        $this->deleteJson('/api/admin/products/'.$product->id)->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertDatabaseCount('cart_items', 0);
        Storage::disk('public')->assertExists($first->image_path);
        Storage::disk('public')->assertMissing($last->image_path);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_product_delete_all_files_succeed(): void
    {
        $this->login();
        $product = $this->product();
        $this->image($product);
        $this->image($product);
        $this->deleteJson('/api/admin/products/'.$product->id)->assertOk();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_history_blocks_product_delete_and_keeps_physical_files(): void
    {
        $this->login();
        $product = $this->product();
        $image = $this->image($product);
        $order = User::factory()->create()->orders()->create([
            'order_no' => 'HF-'.Str::ulid(), 'purchaser_name' => '測試', 'purchaser_phone' => '0912345678', 'purchaser_email' => 'test@example.test',
            'recipient_name' => '測試', 'recipient_phone' => '0912345678', 'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => '測試',
            'shipping_method' => 'home_delivery', 'shipping_fee' => 0, 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'order_status' => 'cancelled', 'subtotal' => 1, 'total_amount' => 1,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_code_snapshot' => 'CODE', 'product_name_snapshot' => 'NAME', 'unit_price' => 1, 'quantity' => 1, 'subtotal' => 1]);
        $this->deleteJson('/api/admin/products/'.$product->id)->assertUnprocessable();
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }

    public function test_db_delete_failure_does_not_remove_files_or_primary(): void
    {
        $this->login();
        $product = $this->product();
        $image = $this->image($product, ['is_primary' => true]);
        ProductImage::deleted(fn () => throw new RuntimeException('after DB row delete failure'));
        try {
            $this->deleteJson('/api/admin/product-images/'.$image->id)->assertStatus(500);
            $this->assertDatabaseHas('product_images', ['id' => $image->id, 'is_primary' => true]);
            Storage::disk('public')->assertExists($image->image_path);
        } finally {
            ProductImage::flushEventListeners();
        }
        Product::deleted(fn () => throw new RuntimeException('after product cascade failure'));
        try {
            $this->deleteJson('/api/admin/products/'.$product->id)->assertStatus(500);
            $this->assertDatabaseHas('products', ['id' => $product->id]);
            $this->assertDatabaseHas('product_images', ['id' => $image->id]);
            Storage::disk('public')->assertExists($image->image_path);
        } finally {
            Product::flushEventListeners();
        }
    }

    public function test_after_commit_cleanup_waits_for_outer_commit_and_does_not_run_on_rollback(): void
    {
        $this->login();
        $product = $this->product();
        $image = $this->image($product);
        DB::beginTransaction();
        $this->deleteJson('/api/admin/product-images/'.$image->id)->assertOk();
        Storage::disk('public')->assertExists($image->image_path);
        DB::rollBack();
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists($image->image_path);
    }

    public function test_admin_and_public_detail_order_images_by_sort_order_then_id(): void
    {
        $this->login();
        $product = $this->product();
        $last = $this->image($product, ['sort_order' => 5]);
        $first = $this->image($product, ['sort_order' => 1, 'is_primary' => true]);
        $tie = $this->image($product, ['sort_order' => 1]);
        $ids = [$first->id, $tie->id, $last->id];
        $this->assertSame($ids, array_column($this->getJson('/api/admin/products/'.$product->id)->assertOk()->json('data.images'), 'id'));
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->assertSame($ids, array_column($this->getJson('/api/products/'.$product->id)->assertOk()->json('data.images'), 'id'));
    }

    public static function missingIds(): array
    {
        return [['999999'], ['abc']];
    }

    #[DataProvider('missingIds')]
    public function test_missing_and_non_numeric_ids(string $id): void
    {
        $this->login();
        $this->postJson('/api/admin/products/'.$id.'/images', ['image' => $this->file()])->assertNotFound();
        $this->patchJson('/api/admin/product-images/'.$id, ['sort_order' => 1])->assertNotFound();
        $this->deleteJson('/api/admin/product-images/'.$id)->assertNotFound();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_image_request_does_not_run_disabled_member_c06(): void
    {
        $member = User::factory()->create(['password' => 'member-fixture']);
        $admin = $this->permissionAdmin(['password' => 'admin-fixture']);
        $product = $this->product();
        $member->cart()->create()->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $this->postJson('/api/login', ['email' => $member->email, 'password' => 'member-fixture'])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'admin-fixture'])->assertOk();
        $this->nextRequest();
        DB::table('users')->where('id', $member->id)->update(['status' => 'disabled']);
        $this->upload($product);
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertOk();
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertDatabaseCount('cart_items', 1);
    }

    private function nextRequest(): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        app('session.store')->flush();
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    public function test_real_public_disk_spot_check_with_isolated_sqlite_fixture(): void
    {
        // 與Storage fake案例分開：使用真正設定的public local disk/root。
        Storage::forgetDisk('public');
        $disk = Storage::disk('public');
        $this->login();
        $product = $this->product();
        $paths = [];
        $directoryExisted = is_dir($disk->path('products/'.$product->id));
        $productsExisted = is_dir($disk->path('products'));
        try {
            $first = $this->upload($product);
            $paths[] = $first['image_path'];
            $this->assertSame(str_replace('\\', '/', storage_path('app/public/'.$first['image_path'])), str_replace('\\', '/', $disk->path($first['image_path'])));
            $this->assertFileExists($disk->path($first['image_path']));
            $this->assertDatabaseHas('product_images', ['id' => $first['id'], 'image_path' => $first['image_path']]);
            $this->assertStringEndsWith('/storage/'.$first['image_path'], $first['image_url']);
            $this->deleteJson('/api/admin/product-images/'.$first['id'])->assertOk();
            $this->assertFileDoesNotExist($disk->path($first['image_path']));
            $second = $this->upload($product);
            $paths[] = $second['image_path'];
            $this->assertFileExists($disk->path($second['image_path']));
            $this->deleteJson('/api/admin/products/'.$product->id)->assertOk();
            $this->assertFileDoesNotExist($disk->path($second['image_path']));
        } finally {
            foreach ($paths as $path) {
                $disk->delete($path);
            } // 僅本案例產生的唯一UUID檔，沒有recursive delete。
            foreach ([$disk->path('products/'.$product->id) => $directoryExisted, $disk->path('products') => $productsExisted] as $path => $existed) {
                if (! $existed && is_dir($path) && count(scandir($path)) === 2) {
                    rmdir($path);
                }
            }
        }
        // 無public/storage連結時，只證明disk讀寫與Resource URL；不宣稱HTTP URL可讀。
    }
}
