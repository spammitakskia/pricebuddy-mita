<?php

namespace App\Jobs;

use App\Services\SearchService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class CacheSearchResults implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [30, 60, 120];

    /**
     * The number of seconds until the job times out.
     */
    public $timeout = 3600;

    /**
     * How long is the job unique for.
     */
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
        try {
            $this->searchService
                ->log('Job dispatched')
                ->build($this->searchQuery);
        } catch (Exception $e) {
            logger()->error('Search results caching failed', [
                'query' => $this->searchQuery,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }

    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->searchQuery;
    }
}
