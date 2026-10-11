<?php

namespace App\Services;

use App\Models\Permission;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ShowcaseImportService
{
    private const PROTECTED_TABLES = ['users', 'admins', 'admin_permission', 'orders', 'order_items',
        'carts', 'cart_items', 'user_addresses', 'personal_access_tokens', 'sessions', 'password_reset_tokens'];

    public function import(string $package, string $expectedDatabase, bool $checkOnly = false): array
    {
        $this->target($expectedDatabase);
        [$tables, $assets] = $this->package($package);
        $this->destinations($assets);
        $result = ['records' => array_sum(array_map('count', $tables)), 'images' => count($assets)];
        if ($checkOnly) return $result;
        // Initialization only: web/queue writes must remain stopped. Serialize other import CLIs.
        $private = storage_path('app/private');
        $this->noLinks($private);
        File::ensureDirectoryExists($private, 0700);
        $this->require(! $this->inside(realpath($private), public_path())
            && ! $this->inside(realpath($private), Storage::disk('public')->path('')), '暫存目錄不可公開。');
        $lockPath = $private.'/showcase-import.lock';
        $this->noLinks($lockPath);
        $lock = fopen($lockPath, 'c');
        $this->require(is_resource($lock), '無法取得匯入鎖。');
        $stage = $private.'/showcase-import-'.Str::uuid();
        $created = [];
        $mysqlLock = false;
        try {
            $this->require(flock($lock, LOCK_EX | LOCK_NB), '另一個匯入正在執行。');
            if (DB::getDriverName() === 'mysql') {
                $mysqlLock = (int) DB::selectOne("SELECT GET_LOCK('home_fitness_showcase_import', 0) AS acquired")->acquired === 1;
                $this->require($mysqlLock, '另一個資料庫匯入正在執行。');
            }
            $this->target($expectedDatabase);
            $this->destinations($assets);
            File::ensureDirectoryExists($stage, 0700);
            foreach ($assets as $index => $asset) {
                $this->copyVerified($asset['source'], $stage.'/'.$index, $asset['sha256']);
            }
            DB::transaction(function () use ($tables, $assets, $stage, $expectedDatabase, &$created): void {
                $this->target($expectedDatabase); // Empty-state and FK checks inside the transaction too.
                foreach (['cities', 'districts', 'permissions', 'categories', 'products', 'product_variants',
                    'product_specifications', 'product_images', 'banners', 'recommended_products'] as $table) {
                    $rows = $tables[$table];
                    if ($table === 'categories') {
                        $roots = array_values(array_filter($rows, fn ($row) => $row['parent_id'] === null));
                        DB::table($table)->insert($roots);
                        $rows = array_values(array_filter($rows, fn ($row) => $row['parent_id'] !== null));
                    }
                    foreach (array_chunk($rows, 100) as $chunk) DB::table($table)->insert($chunk);
                }
                foreach ($assets as $index => $asset) {
                    $target = $this->destination($asset['source_path']);
                    File::ensureDirectoryExists(dirname($target), 0755);
                    $this->noLinks($target);
                    // Exclusive creation: never overwrite a pre-existing image, even after preflight.
                    $out = @fopen($target, 'xb');
                    $this->require(is_resource($out), '圖片已存在或無法建立，已拒絕匯入。');
                    $created[] = ['path' => $target, 'stat' => fstat($out)];
                    try {
                        $this->streamVerified($stage.'/'.$index, $out, $asset['sha256']);
                    } finally {
                        fclose($out);
                    }
                    $this->require(hash_file('sha256', $target) === $asset['sha256'], '圖片寫入後校驗失敗。');
                }
            });
        } catch (Throwable $error) {
            // DB rollback cannot undo filesystem writes. Remove only files this run created.
            foreach (array_reverse($created) as $entry) {
                $stat = @lstat($entry['path']);
                if ($stat && ! is_link($entry['path']) && $stat['ino'] === $entry['stat']['ino'] && $stat['dev'] === $entry['stat']['dev']) {
                    if (! @unlink($entry['path'])) Log::error('Showcase import compensation failed.', ['path' => $entry['path']]);
                } elseif ($stat) {
                    Log::error('Showcase import compensation refused changed file.', ['path' => $entry['path']]);
                }
            }
            throw $error;
        } finally {
            // Post-commit housekeeping must not report a committed import as failed.
            try {
                if (is_dir($stage) && ! File::deleteDirectory($stage)) Log::error('Showcase import staging cleanup failed.');
            } catch (Throwable) {
                Log::error('Showcase import staging cleanup failed.');
            }
            try {
                if ($mysqlLock) DB::selectOne("SELECT RELEASE_LOCK('home_fitness_showcase_import')");
            } catch (Throwable) {
                Log::error('Showcase import advisory lock release failed; disconnect required.');
                DB::disconnect();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    private function target(string $expected): void
    {
        $driver = DB::getDriverName();
        $this->require(in_array($driver, ['mysql', 'sqlite'], true), '不支援的資料庫連線。');
        $actual = $driver === 'mysql' ? DB::selectOne('SELECT DATABASE() AS db')->db : DB::connection()->getDatabaseName();
        $this->require($expected !== '' && $expected === $actual, '必須明確指定與實際連線一致的目標資料庫名稱。');
        $this->require($driver !== 'sqlite' || $actual === ':memory:', 'SQLite僅允許隔離記憶體測試。');
        $this->require(Schema::hasTable('migrations'), '目標尚未完成migrations。');
        $ran = DB::table('migrations')->pluck('migration')->all();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            $this->require(in_array(basename($file, '.php'), $ran, true), '目標尚未完成目前版本migrations。');
        }
        foreach (array_merge(array_keys(ShowcaseExportService::COLUMNS), self::PROTECTED_TABLES) as $table) {
            $this->require(Schema::hasTable($table), '缺少必要資料表。');
            $this->require(! DB::table($table)->exists(), '目標業務資料表非空，拒絕覆寫或合併。');
            if (isset(ShowcaseExportService::COLUMNS[$table])) {
                $this->require(array_diff(ShowcaseExportService::COLUMNS[$table], Schema::getColumnListing($table)) === [], '目標資料表欄位不完整。');
                if ($driver === 'mysql') {
                    $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
                    $this->require($engine && strtolower($engine->engine) === 'innodb', '目標展示資料表必須使用InnoDB。');
                }
            }
        }
        $enabled = $driver === 'mysql' ? (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled
            : (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
        $this->require($enabled === 1, '外鍵檢查必須啟用。');
    }

    private function package(string $directory): array
    {
        $this->noLinks($directory);
        $root = realpath($directory);
        $this->require($root !== false && is_dir($root), '封裝目錄不存在。');
        $this->require(config('filesystems.disks.public.driver') === 'local', '僅支援public local disk。');
        $this->require(! $this->inside($root, public_path()) && ! $this->inside($root, Storage::disk('public')->path('')), '封裝必須位於非公開目錄。');
        $manifestText = $this->read($root.'/manifest.json', 2097152);
        $this->require($this->read($root.'/manifest.sha256', 256) === hash('sha256', $manifestText)."  manifest.json\n", 'Manifest SHA-256不符。');
        $manifest = json_decode($manifestText, true, 64, JSON_THROW_ON_ERROR);
        $this->keys($manifest, ['format_version', 'created_at', 'selection', 'tables', 'files']);
        $this->require($manifest['format_version'] === 1 && is_string($manifest['created_at']), '不支援的Manifest版本或格式。');
        $this->require($manifest['selection'] === ['category_ids' => range(7, 18), 'product_ids' => range(25, 40), 'active_banner_ids' => [1, 2, 3]], '封裝選取範圍不符。');
        $this->keys($manifest['tables'], array_keys(ShowcaseExportService::COLUMNS));
        $expectedFiles = ['manifest.json', 'manifest.sha256'];
        $tables = [];
        foreach (ShowcaseExportService::COLUMNS as $table => $columns) {
            $entry = $manifest['tables'][$table];
            $this->keys($entry, ['path', 'count', 'ids', 'sha256']);
            $path = 'data/'.$table.'.json';
            $this->require($entry['path'] === $path && $entry['count'] === ShowcaseExportService::COUNTS[$table], '資料表路徑或筆數不符。');
            $text = $this->read($root.'/'.$path, 16777216);
            $this->require(hash('sha256', $text) === $entry['sha256'], '資料表SHA-256不符。');
            $rows = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
            $this->require(is_array($rows) && array_is_list($rows) && count($rows) === $entry['count'], '資料表JSON格式或筆數無效。');
            foreach ($rows as $row) {
                $this->keys($row, $columns);
                $this->row($table, $row);
            }
            $ids = array_column($rows, 'id');
            $sorted = $ids; sort($sorted, SORT_NUMERIC);
            $this->require($ids === $entry['ids'] && $ids === $sorted && count(array_unique($ids)) === count($ids), '資料表ID清單不符或重複。');
            $tables[$table] = $rows;
            $expectedFiles[] = $path;
        }
        $this->require(array_column($tables['categories'], 'id') === range(7, 18)
            && array_column($tables['products'], 'id') === range(25, 40)
            && array_column($tables['banners'], 'id') === [1, 2, 3], '固定原始ID範圍不符。');
        $codes = array_column($tables['permissions'], 'code'); sort($codes);
        $catalog = array_keys(Permission::CATALOG); sort($catalog);
        $this->require($codes === $catalog, 'Permission Catalog不完整。');
        $this->require(count(array_unique(array_column($tables['products'], 'product_code'))) === 16
            && count(array_unique(array_column($tables['recommended_products'], 'product_id'))) === 4, '商品編號或推薦商品重複。');
        foreach ($tables['banners'] as $banner) $this->require($banner['status'] === 'active', 'Banner必須啟用。');
        app(ShowcaseExportService::class)->validateTables($tables);
        $this->require(is_array($manifest['files']) && array_is_list($manifest['files']) && count($manifest['files']) === 51, '必須有51張圖片。');
        $references = [];
        foreach (['product_images' => 'products', 'banners' => 'banners'] as $table => $kind) {
            foreach ($tables[$table] as $row) {
                $path = $row['image_path'];
                $this->safeImagePath($path, $kind);
                $key = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
                $this->require(! isset($references[$key]), '圖片路徑重複。');
                $references[$key] = ['path' => $path, 'table' => $table, 'kind' => $kind, 'id' => $row['id']];
            }
        }
        $assets = [];
        foreach ($manifest['files'] as $asset) {
            $this->keys($asset, ['path', 'source_path', 'kind', 'table', 'row_id', 'size', 'mime', 'sha256']);
            $this->require(is_string($asset['source_path']), '圖片路徑格式無效。');
            $key = PHP_OS_FAMILY === 'Windows' ? strtolower($asset['source_path']) : $asset['source_path'];
            $ref = $references[$key] ?? null;
            $this->require($ref && $asset['source_path'] === $ref['path'] && $asset['table'] === $ref['table']
                && $asset['kind'] === $ref['kind'] && $asset['row_id'] === $ref['id']
                && $asset['path'] === 'files/'.$ref['path'], '圖片Manifest關聯不符。');
            unset($references[$key]);
            $source = $root.'/'.$asset['path'];
            $this->noLinks($source);
            $this->require(is_file($source) && is_readable($source) && is_int($asset['size']) && $asset['size'] > 0
                && filesize($source) === $asset['size'] && hash_file('sha256', $source) === $asset['sha256'], '圖片大小或SHA-256不符。');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
            $this->require($mime === $asset['mime'] && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
                && @getimagesize($source) !== false, '圖片內容或MIME無效。');
            $assets[] = $asset + ['source' => $source];
            $expectedFiles[] = $asset['path'];
        }
        $actualFiles = [];
        $this->inventory($root, '', $actualFiles, $expectedFiles);
        sort($actualFiles); sort($expectedFiles);
        $this->require($actualFiles === $expectedFiles, '封裝有缺漏或非白名單檔案。');

        return [$tables, $assets];
    }

    private function row(string $table, array $row): void
    {
        $lengths = ['name' => match ($table) { 'categories' => 100, 'products' => 150, 'cities' => 20, 'districts' => 30, default => 50 },
            'product_code' => 20, 'short_description' => 255, 'title' => 150, 'subtitle' => 255, 'image_path' => 255,
            'button_text' => 50, 'link_url' => 255, 'spec_name' => 100, 'spec_value' => 255, 'option_name' => 50, 'option_value' => 100,
            'postal_code' => 10, 'code' => 50];
        foreach ($row as $field => $value) {
            if ($field === 'id' || in_array($field, ['product_id', 'category_id', 'city_id', 'parent_id'], true)) {
                $this->require(($field === 'parent_id' && $value === null) || (is_int($value) && $value > 0), 'ID型別無效。');
            } elseif (in_array($field, ['stock', 'sort_order', 'low_stock_threshold'], true)) {
                $this->require(($field === 'stock' && $table === 'products' && $value === null)
                    || (is_int($value) && $value >= 0 && $value <= 4294967295), '整數欄位無效。');
            } elseif ($field === 'is_primary') {
                $this->require(in_array($value, [true, false, 0, 1], true), '主圖欄位無效。');
            } elseif ($field === 'price') {
                $this->require(is_string($value) && preg_match('/^\d{1,8}\.\d{2}$/D', $value) === 1, '商品價格格式無效。');
            } elseif (in_array($field, ['created_at', 'updated_at'], true)) {
                $this->require($value === null || (is_string($value) && ! Validator::make([$field => $value], [$field => 'date_format:Y-m-d H:i:s'])->fails()), '時間格式無效。');
            } else {
                $nullable = in_array($field, ['short_description', 'description', 'subtitle', 'button_text', 'link_url'], true);
                $this->require(($nullable && $value === null) || (is_string($value) && (! isset($lengths[$field]) || mb_strlen($value) <= $lengths[$field])), '字串欄位格式或長度無效。');
                if ($field === 'description' && $value !== null) $this->require(strlen($value) <= 65535, '描述超出TEXT範圍。');
            }
        }
    }

    private function inventory(string $root, string $relative, array &$files, array $allowed): void
    {
        foreach (new \FilesystemIterator($root.($relative === '' ? '' : '/'.$relative)) as $entry) {
            $path = ($relative === '' ? '' : $relative.'/').$entry->getFilename();
            $this->require(! $entry->isLink(), '封裝不得包含Symlink。');
            if ($entry->isDir()) {
                $this->require(count(array_filter($allowed, fn ($file) => str_starts_with($file, $path.'/'))) > 0, '不明封裝目錄。');
                $this->inventory($root, $path, $files, $allowed);
            } else {
                $this->require($entry->isFile() && in_array($path, $allowed, true), '不明封裝檔案。');
                $files[] = $path;
            }
        }
    }

    private function safeImagePath(string $path, string $kind): void
    {
        $this->require(strlen($path) <= 255 && preg_match('~^'.$kind.'/[^\\\\\x00-\x1f\x7f:%?#]+\.(jpg|jpeg|png|webp)$~iD', $path) === 1, '圖片路徑不安全。');
        foreach (explode('/', $path) as $part) $this->require($part !== '' && ! str_starts_with($part, '.'), '圖片路徑不安全。');
    }

    private function destinations(array $assets): void
    {
        foreach ($assets as $asset) {
            $path = $this->destination($asset['source_path']);
            $this->require(! file_exists($path) && ! is_link($path), '目標圖片已存在，拒絕覆寫。');
        }
    }

    private function destination(string $relative): string
    {
        $path = Storage::disk('public')->path($relative);
        $this->noLinks($path);

        return $path;
    }

    private function copyVerified(string $source, string $target, string $hash): void
    {
        $out = fopen($target, 'xb');
        $this->require(is_resource($out), '無法建立圖片暫存。');
        try { $this->streamVerified($source, $out, $hash); } finally { fclose($out); }
    }

    private function streamVerified(string $source, $out, string $hash): void
    {
        $this->noLinks($source);
        $in = fopen($source, 'rb');
        $this->require(is_resource($in), '無法讀取圖片。');
        try {
            $this->require(stream_copy_to_stream($in, $out) !== false && fflush($out), '圖片寫入失敗。');
        } finally { fclose($in); }
        $meta = stream_get_meta_data($out);
        $this->require(hash_file('sha256', $meta['uri']) === $hash, '圖片於操作期間變更或寫入失敗。');
    }

    private function read(string $path, int $limit): string
    {
        $this->noLinks($path);
        $this->require(is_file($path) && is_readable($path) && filesize($path) <= $limit, '必要封裝檔案不存在或過大。');
        $text = file_get_contents($path);
        $this->require($text !== false, '封裝檔案無法讀取。');

        return $text;
    }

    private function noLinks(string $path): void
    {
        $current = str_replace('\\', '/', $path);
        do {
            $this->require(! is_link($current), '路徑不得包含Symlink。');
            $parent = dirname($current);
            if ($parent === $current || $current === '.') break;
            $current = $parent;
        } while (true);
    }

    private function inside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }

        return str_starts_with($path.'/', $root.'/');
    }

    private function keys(mixed $value, array $keys): void
    {
        $this->require(is_array($value), '封裝物件格式無效。');
        $actual = array_keys($value); sort($actual); sort($keys);
        $this->require($actual === $keys, '封裝包含不明或缺少必要欄位／資料表。');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new DomainException($message);
    }
}
