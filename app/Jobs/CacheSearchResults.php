<?php

namespace App\Jobs;

use App\Services\SearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class CacheSearchResults implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = 3600;

    public $uniqueFor = 600; // 10 mins

    public SearchService $searchService;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $searchQuery)
    {
        $this->searchService = SearchService::new($searchQuery);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->searchService
            ->log('Job dispatched')
            ->build($this->searchQuery);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->searchQuery;
    }
}
