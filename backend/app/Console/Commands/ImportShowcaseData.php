<?php

namespace App\Console\Commands;

use App\Services\ShowcaseImportService;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class ImportShowcaseData extends Command
{
    protected $signature = 'showcase:import {package : Private export package directory} {--database= : Required exact target database name} {--check-only : Validate without writing database or images}';

    protected $description = 'Validate and import the fixed showcase package into an empty migrated database';

    public function handle(ShowcaseImportService $importer): int
    {
        try {
            $result = $importer->import($this->argument('package'), (string) $this->option('database'), (bool) $this->option('check-only'));
            $this->info(($this->option('check-only') ? '預檢通過，未寫入資料。' : '展示資料匯入完成。').' 10張資料表／'.$result['records'].'筆／'.$result['images'].'張圖片。');

            return self::SUCCESS;
        } catch (DomainException $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            // Never print SQL bindings, configuration, or exception credentials.
            $this->error('匯入失敗：無法安全完成資料與圖片寫入。');
        }

        return self::FAILURE;
    }
}
