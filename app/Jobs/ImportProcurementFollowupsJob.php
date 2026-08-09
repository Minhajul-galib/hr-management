<?php

namespace App\Jobs;

use App\Imports\ProcurementFollowupsUpsertImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportProcurementFollowupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $importId,
        public string $path,
        public array $mapping,
        public string $importBatch,
    ) {
    }

    public function handle(): void
    {
        Cache::put("pf_import:{$this->importId}:state", array_merge(
            Cache::get("pf_import:{$this->importId}:state", []),
            ['status' => 'running']
        ), now()->addHours(6));

        try {
            $import = new ProcurementFollowupsUpsertImport($this->importId, $this->mapping, $this->importBatch);
            Excel::import($import, Storage::path($this->path));

            $state = Cache::get("pf_import:{$this->importId}:state", []);
            $state['status'] = 'completed';
            $state['finished_at'] = now()->toISOString();
            Cache::put("pf_import:{$this->importId}:state", $state, now()->addHours(6));
        } catch (\Throwable $e) {
            $state = Cache::get("pf_import:{$this->importId}:state", []);
            $state['status'] = 'failed';
            $state['finished_at'] = now()->toISOString();
            $state['message'] = $e->getMessage();
            Cache::put("pf_import:{$this->importId}:state", $state, now()->addHours(6));
            throw $e;
        }
    }
}

