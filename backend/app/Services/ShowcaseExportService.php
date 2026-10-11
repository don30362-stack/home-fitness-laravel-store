<?php

namespace App\Services;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use DomainException;
use Throwable;

class ShowcaseExportService
{
    // Explicit columns also prevent future sensitive columns from silently entering an export.
    public const COLUMNS = [
        'categories' => ['id', 'parent_id', 'name', 'status', 'sort_order', 'created_at', 'updated_at'],
        'products' => ['id', 'product_code', 'category_id', 'name', 'price', 'short_description', 'description', 'stock', 'low_stock_threshold', 'status', 'created_at', 'updated_at'],
        'product_images' => ['id', 'product_id', 'image_path', 'image_type', 'is_primary', 'sort_order', 'created_at', 'updated_at'],
        'product_specifications' => ['id', 'product_id', 'spec_name', 'spec_value', 'sort_order', 'created_at', 'updated_at'],
        'product_variants' => ['id', 'product_id', 'option_name', 'option_value', 'stock', 'status', 'created_at', 'updated_at'],
        'banners' => ['id', 'title', 'subtitle', 'image_path', 'button_text', 'link_url', 'sort_order', 'status', 'created_at', 'updated_at'],
        'recommended_products' => ['id', 'product_id', 'sort_order', 'created_at', 'updated_at'],
        'cities' => ['id', 'name'],
        'districts' => ['id', 'city_id', 'name', 'postal_code'],
        'permissions' => ['id', 'code', 'name'],
    ];

    public const COUNTS = [
        'categories' => 12, 'products' => 16, 'product_images' => 48,
        'product_specifications' => 128, 'product_variants' => 3,
        'banners' => 3, 'recommended_products' => 4, 'cities' => 22,
        'districts' => 368, 'permissions' => 7,
    ];

    public function export(): string
    {
        // SELECT only. No model events, seeders, locks, or database mutations.
        $tables = DB::transaction(fn () => $this->readTables());
        $this->validateTables($tables);
        $assets = $this->inspectAssets($tables);
        $root = $this->outputRoot();
        $suffix = now('UTC')->format('Ymd-His').'-'.Str::uuid();
        $partial = $root.'/.partial-'.$suffix;
        $final = $root.'/showcase-'.$suffix;
        if (! mkdir($partial, 0700)) throw new DomainException('無法建立匯出暫存目錄。');

        try {
            $manifest = ['format_version' => 1, 'created_at' => now('UTC')->toIso8601String(),
                'selection' => ['category_ids' => range(7, 18), 'product_ids' => range(25, 40), 'active_banner_ids' => [1, 2, 3]],
                'tables' => [], 'files' => []];
            foreach ($tables as $table => $rows) {
                $relative = 'data/'.$table.'.json';
                $json = $this->json($rows);
                $this->write($partial.'/'.$relative, $json);
                $manifest['tables'][$table] = ['path' => $relative, 'count' => count($rows),
                    'ids' => array_column($rows, 'id'), 'sha256' => hash('sha256', $json)];
            }
            foreach ($assets as $asset) {
                // Recheck the filesystem boundary after preflight, then copy only this exact file.
                $this->sourcePath($asset['source_path'], $asset['kind']);
                $target = $partial.'/'.$asset['path'];
                File::ensureDirectoryExists(dirname($target), 0700);
                $stream = Storage::disk('public')->readStream($asset['source_path']);
                if (! is_resource($stream)) throw new DomainException('無法讀取白名單圖片。');
                try {
                    if (file_put_contents($target, $stream) === false) throw new DomainException('圖片封裝寫入失敗。');
                } finally {
                    fclose($stream);
                }
                if (hash_file('sha256', $target) !== $asset['sha256']) {
                    throw new DomainException('圖片於匯出期間變更或寫入不完整，已停止封裝。');
                }
                $manifest['files'][] = $asset;
            }
            $json = $this->json($manifest);
            $this->write($partial.'/manifest.json', $json);
            $this->write($partial.'/manifest.sha256', hash('sha256', $json).'  manifest.json'."\n");
            // Same parent/filesystem: publish only a fully verified package, never copy fallback.
            // Windows scanners may briefly hold directory handles. Retry only this
            // atomic filesystem rename, never database operations or a copy fallback.
            retry(5, function () use ($partial, $final): void {
                if (! @rename($partial, $final)) throw new DomainException('無法完成匯出封裝。');
            }, 100);

            return $final;
        } catch (Throwable $error) {
            File::deleteDirectory($partial);
            throw $error;
        }
    }

    private function readTables(): array
    {
        $tables = [];
        foreach (self::COLUMNS as $table => $columns) {
            $query = DB::table($table)->select($columns)->orderBy('id');
            if ($table === 'categories') $query->whereBetween('id', [7, 18]);
            if ($table === 'products') $query->whereBetween('id', [25, 40]);
            if (in_array($table, ['product_images', 'product_specifications', 'product_variants', 'recommended_products'], true)) {
                $query->whereBetween('product_id', [25, 40]);
            }
            if ($table === 'banners') $query->whereIn('id', [1, 2, 3])->where('status', 'active');
            if ($table === 'permissions') $query->whereIn('code', array_keys(Permission::CATALOG));
            $tables[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }
        // Preserve DECIMAL(10,2) as a JSON string, including on SQLite test fixtures.
        foreach ($tables['products'] as &$product) {
            if (is_numeric($product['price'])) $product['price'] = number_format((float) $product['price'], 2, '.', '');
        }

        return $tables;
    }

    public function validateTables(array $tables): void
    {
        foreach (self::COUNTS as $table => $count) {
            $this->require(count($tables[$table]) === $count, $table.' 筆數不符，預期 '.$count.' 筆。');
            foreach ($tables[$table] as $row) {
                $rules = ['id' => 'required|integer|min:1'];
                foreach (['name', 'product_code', 'title', 'spec_name', 'spec_value', 'option_name', 'option_value', 'postal_code', 'code', 'image_path'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $rules[$field] = ['required', 'string', fn ($key, $value, $fail) => trim($value) === '' ? $fail('不可空白。') : null];
                    }
                }
                foreach (['sort_order', 'low_stock_threshold'] as $field) {
                    if (array_key_exists($field, $row)) $rules[$field] = 'required|integer|min:0|max:4294967295';
                }
                if (array_key_exists('stock', $row)) $rules['stock'] = ($table === 'products' ? 'nullable' : 'required').'|integer|min:0|max:4294967295';
                if (array_key_exists('price', $row)) $rules['price'] = 'required|numeric|min:0|max:99999999.99';
                if (array_key_exists('status', $row)) $rules['status'] = $table === 'products' ? 'required|in:active,inactive,disabled' : 'required|in:active,inactive';
                if ($table === 'product_images') $rules += ['is_primary' => 'required|boolean', 'image_type' => 'required|in:gallery,detail'];
                $this->require(! Validator::make($row, $rules)->fails(), $table.' ID '.$row['id'].' 必要欄位無效。');
            }
        }
        $categories = array_column($tables['categories'], null, 'id');
        $products = array_column($tables['products'], null, 'id');
        $cities = array_column($tables['cities'], null, 'id');
        $roots = 0;
        foreach ($categories as $category) {
            $this->require($category['id'] <= 10
                ? $category['parent_id'] === null
                : in_array($category['parent_id'], [7, 8, 9, 10], true),
                '分類ID 7～10必須為主分類，ID 11～18必須隸屬主分類7～10。');
            if ($category['parent_id'] === null) { $roots++; continue; }
            $parent = $categories[$category['parent_id']] ?? null;
            $this->require($parent && $parent['parent_id'] === null, '分類父子關聯必須完整且固定兩層。');
        }
        $this->require($roots === 4, '分類必須為4主分類／8子分類。');
        foreach ($products as $product) {
            $category = $categories[$product['category_id']] ?? null;
            $this->require($category && $category['parent_id'] !== null, '商品分類必須是白名單中的子分類。');
            $variants = array_values(array_filter($tables['product_variants'], fn ($v) => $v['product_id'] === $product['id']));
            $this->require($variants ? $product['stock'] === null : $product['stock'] !== null, '商品庫存模式不完整。');
            if ($variants) {
                $this->require(count(array_unique(array_column($variants, 'option_name'))) === 1
                    && count(array_unique(array_column($variants, 'option_value'))) === count($variants), '商品款式單軸／選項唯一規則不符。');
            }
            $images = array_filter($tables['product_images'], fn ($r) => $r['product_id'] === $product['id']);
            $this->require(count($images) === 3 && count(array_filter($images, fn ($r) => (bool) $r['is_primary'])) === 1,
                '商品 ID '.$product['id'].' 必須有3張圖片且恰好1張主圖。');
            $this->require(count(array_filter($tables['product_specifications'], fn ($r) => $r['product_id'] === $product['id'])) > 0,
                '商品 ID '.$product['id'].' 缺少規格。');
        }
        foreach (['product_images', 'product_specifications', 'product_variants', 'recommended_products'] as $table) {
            foreach ($tables[$table] as $row) $this->require(isset($products[$row['product_id']]), $table.' 商品關聯不完整。');
        }
        foreach ($tables['districts'] as $row) $this->require(isset($cities[$row['city_id']]), '行政區城市關聯不完整。');
        $districtCities = array_column($tables['districts'], 'city_id');
        foreach ($cities as $city) {
            $this->require(in_array($city['id'], $districtCities, true), '每個縣市必須至少有一筆行政區。');
        }
    }

    private function inspectAssets(array $tables): array
    {
        $assets = [];
        foreach (['product_images' => 'products', 'banners' => 'banners'] as $table => $kind) {
            foreach ($tables[$table] as $row) {
                $path = $row['image_path'];
                $source = $this->sourcePath($path, $kind);
                $key = str_replace('\\', '/', $source);
                if (PHP_OS_FAMILY === 'Windows') $key = strtolower($key);
                $this->require(! isset($assets[$key]), '圖片路徑重複，無法封裝51個獨立檔案。');
                $size = filesize($source);
                $hash = hash_file('sha256', $source);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
                $this->require($size > 0 && $hash !== false && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), '圖片無法讀取或不是允許的圖片格式。');
                $assets[$key] = ['path' => 'files/'.$path, 'source_path' => $path,
                    'kind' => $kind, 'table' => $table, 'row_id' => $row['id'],
                    'size' => $size, 'mime' => $mime, 'sha256' => $hash];
            }
        }
        $this->require(count($assets) === 51, '必須封裝51張白名單圖片。');

        return array_values($assets);
    }

    private function sourcePath(string $path, string $kind): string
    {
        $this->require(config('filesystems.disks.public.driver') === 'local', '本工具只支援已核對的public local disk。');
        $segments = explode('/', $path);
        $safe = array_shift($segments) === $kind && count($segments) > 0
            && ! preg_match('/[\\\\\x00-\x1f\x7f:%?#]/', $path)
            && preg_match('/\.(jpg|jpeg|png|webp)$/iD', $path);
        foreach ($segments as $segment) $safe = $safe && $segment !== '' && ! str_starts_with($segment, '.');
        $this->require((bool) $safe, '圖片路徑不在允許目錄或含不安全字元。');
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $this->require($root !== false, 'Public disk不存在。');
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            $current .= '/'.$segment;
            $this->require(! is_link($current), '圖片路徑不得包含符號連結。');
        }
        $real = realpath($disk->path($path));
        $this->require($real !== false && $this->inside($real, $root.'/'.$kind) && is_file($real) && is_readable($real),
            '圖片不存在、不可讀或超出允許目錄。');

        return $real;
    }

    private function outputRoot(): string
    {
        $storage = realpath(storage_path());
        $this->require($storage !== false, 'Storage目錄不存在。');
        $root = $storage;
        foreach (['app', 'private', 'showcase-exports'] as $segment) {
            $root .= '/'.$segment;
            $this->require(! is_link($root), '匯出目錄不得包含符號連結。');
            if (! is_dir($root) && ! mkdir($root, 0700)) throw new DomainException('無法建立私有匯出目錄。');
            $real = realpath($root);
            $this->require($real !== false && $this->inside($real, $storage), '匯出目錄超出Storage範圍。');
        }
        $public = realpath(public_path());
        $disk = realpath(Storage::disk('public')->path(''));
        $this->require($public !== false && $disk !== false && ! $this->inside($root, $public) && ! $this->inside($root, $disk),
            '匯出目錄不可公開存取。');

        return $root;
    }

    private function inside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }

        return str_starts_with($path.'/', $root.'/');
    }

    private function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path), 0700);
        if (file_put_contents($path, $contents) !== strlen($contents)) throw new DomainException('資料封裝寫入失敗。');
        if (hash_file('sha256', $path) !== hash('sha256', $contents)) throw new DomainException('資料封裝校驗失敗。');
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new DomainException($message);
    }
}
