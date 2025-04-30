<?php

namespace App\Services;

use App\Dto\ProductResearchUrlDto;
use App\Enums\IntegratedServices;
use App\Models\SearchResultUrl;
use App\Models\Store;
use App\Models\UrlResearch;
use App\Services\Helpers\SettingsHelper;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SearchService
{
    public const CACHE_KEY = 'search:';

    public const CACHE_TTL_MINS = 30;

    public const LOG_KEY = self::CACHE_KEY.'log:';

    public const LOG_TTL_MINS = 60; // 1 hour

    public int $timeoutSecondsPerUrl = 20;

    public Collection $results;

    public ?string $searchQuery = null;

    protected bool $useLaravelLog = false;

    public function __construct(?string $query = null)
    {
        $this->results = collect();
        $this->searchQuery = $query;
    }

    public static function new(?string $query = null): self
    {
        return resolve(static::class, ['query' => $query]);
    }

    public function build(string $searchQuery): self
    {
        $this->searchQuery = $searchQuery;

        $this->logReset();
        $this->log('Starting research for: '.$searchQuery);

        try {
            $this
                ->setIsComplete(false)
                ->setInProgress(true)
                ->getRawResults($searchQuery)
                ->normalizeStructure()
                ->addStores()
                ->hydrateWithScrapedData()
                ->saveUrlResearch()
                ->log('Completed research for: '.$searchQuery)
                ->setIsComplete(true);
        } catch(Exception $e) {
            logger()->error($e->getMessage());
        }

        $this
            ->setInProgress(false);

        return $this;
    }

    public function getResults(): Collection
    {
        return $this->results;
    }

    public function saveUrlResearch(): self
    {
        $this->log('Saving URL research');

        $this->results->each(function ($result) {
            UrlResearch::updateOrCreate(
                ['url' => $result['url']],
                collect($result)->only([
                    'html', 'title', 'image', 'price', 'store_id', 'strategies', 'execution_time',
                ])->all()
            );
        });

        return $this;
    }

    public function getRawResults(string $searchQuery): self
    {
        $this->log('Fetching raw search results');

        $results = Cache::remember(
            $this->getCacheKey('results', $searchQuery),
            now()->addMinutes(self::CACHE_TTL_MINS),
            fn () => Http::get(data_get(self::getSettings(), 'url'), [
                'format' => 'json',
                'q' => $searchQuery,
            ])->json('results'));

        $this->results = collect($results);

        $this->log(__('Found :count results', ['count' => $this->results->count()]));

        return $this;
    }

    protected function normalizeStructure(): self
    {
        $this->log('Normalizing search results');

        $this->results = $this->results->map(function ($result, $idx) {
            return [
                'title' => data_get($result, 'title'),
                'url' => data_get($result, 'url'),
                'snippet' => data_get($result, 'content'),
                'thumbnail' => data_get($result, 'thumbnail'),
                'domain' => parse_url(data_get($result, 'url'), PHP_URL_HOST),
                'relevance' => $idx,
            ];
        });

        return $this;
    }

    protected function addStores(): self
    {
        $this->log('Adding stores to search results');

        $domains = $this->results->pluck('domain')->toArray();
        $stores = Store::query()->select('id', 'domains')->domainFilter($domains)->get();

        $this->results = $this->results
            ->map(function ($result) use ($stores) {
                $store = $stores->filter(fn ($store) => $store->hasDomain($result['domain']))->first();
                $result['store_id'] = $store->id ?? null;

                return $result;
            });

        return $this;
    }

    public function hydrateWithScrapedData(): self
    {
        $this->log('Hydrating results');

        $existing = UrlResearch::query()
            ->whereIn('url', $this->results->pluck('url'))
            ->get()
            ->keyBy('url');

        $this->results = $this->results
            ->map(function ($result) use ($existing) {
                if ($existing->get($result['url'])) {
                    $this->log(__('Using cache ":title" (:domain)', $result), ['subtitle' => $result['url']]);

                    return $result;
                }

                $timeStart = microtime(true);
                $this->log(__('Analyzing ":title" (:domain)', $result), ['subtitle' => $result['url']]);

                try {
                    $dto = new ProductResearchUrlDto(result: new SearchResultUrl($result), cached: true);

                    $result = array_merge($result, [
                        'price' => $dto->getPrice(),
                        'image' => $dto->getImage(),
                        'strategies' => $dto->getStrategies(),
                        'is_product_page' => $dto->getIsProductPage()->value,
                        'html' => $dto->getHtml(),
                    ]);
                } catch (Exception $e) {
                    $this->log(__('Failed for ":title": '.$e->getMessage(), $result), ['subtitle' => $result['url']]);
                }

                $result['execution_time'] = (microtime(true) - $timeStart);

                return $result;
            });

            return $this;
        }

    public static function getSettings(): array
    {
        return SettingsHelper::getSetting(
            'integrated_services.'.IntegratedServices::SearXng->value,
            []
        );
    }

    protected function getCacheKey(string $type, string $key): string
    {
        return self::CACHE_KEY.$type.':'.urlencode($key);
    }

    protected function getInProgressKey(): string
    {
        return $this->getCacheKey('in_progress', $this->searchQuery);
    }

    protected function getIsCompleteKey(): string
    {
        return $this->getCacheKey('complete', $this->searchQuery);
    }

    protected function getLogKey(): string
    {
        return self::LOG_KEY.urlencode($this->searchQuery);
    }

    public function getInProgress(?string $searchQuery = null): false|string
    {
        if ($searchQuery) {
            $this->searchQuery = $searchQuery;
        }

        return Cache::get($this->getInProgressKey(), false);
    }

    protected function setInProgress(bool $inProgress): self
    {
        if ($inProgress) {
            Cache::put($this->getInProgressKey(), now()->toDateTimeString(), now()->addMinutes(self::CACHE_TTL_MINS));
        } else {
            Cache::forget($this->getInProgressKey());
        }

        return $this;
    }

    public function getIsComplete(): false|string
    {
        return Cache::get($this->getIsCompleteKey(), false);
    }

    protected function setIsComplete(bool $complete): self
    {
        if ($complete) {
            Cache::put($this->getIsCompleteKey(), now()->toDateTimeString(), now()->addMinutes(self::CACHE_TTL_MINS));
        } else {
            Cache::forget($this->getIsCompleteKey());
        }

        return $this;
    }

    public function log(string $message, array $data = []): self
    {
        $cache = Cache::get($this->getLogKey(), []);

        $cache[] = [
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toDateTimeString(),
        ];

        Cache::put($this->getLogKey(), $cache, now()->addMinutes(self::LOG_TTL_MINS));

        if ($this->useLaravelLog) {
            logger()->info($message, $data);
        }

        return $this;
    }

    public function setUseLaravelLog(bool $useLaravelLog): self
    {
        $this->useLaravelLog = $useLaravelLog;

        return $this;
    }

    public function getLog(?string $searchQuery = null): array
    {
        if ($searchQuery) {
            $this->searchQuery = $searchQuery;
        }

        return Cache::get($this->getLogKey(), []);
    }

    public function logReset(): self
    {
        Cache::delete($this->getLogKey());

        return $this;
    }
}
