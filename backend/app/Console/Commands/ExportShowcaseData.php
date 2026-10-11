<?php

namespace App\Console\Commands;

use App\Services\ShowcaseExportService;
use Illuminate\Console\Command;
use DomainException;
use Throwable;

class ExportShowcaseData extends Command
{
    protected $signature = 'showcase:export';

    protected $description = 'Export the fixed showcase whitelist and its 51 public-disk images into a private package';

    public function handle(ShowcaseExportService $exporter): int
    {
        try {
            $path = $exporter->export();
        } catch (DomainException $error) {
            // Only our validation errors are safe to display, not SQL/config bindings.
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('匯出失敗：無法安全完成封裝。');

            return self::FAILURE;
        }

        $this->info('白名單展示資料匯出完成：'.$path);

        return self::SUCCESS;
    }
}
