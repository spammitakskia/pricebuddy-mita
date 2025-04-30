<?php

namespace App\Dto;

use App\Enums\IsProductPage as IsProductPageEnum;
use App\Models\SearchResultUrl;
use App\Models\Store;
use App\Services\AutoCreateStore;
use App\Services\Helpers\CurrencyHelper;
use App\Services\ScrapeUrl;
use Illuminate\Support\Facades\Cache;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperInterface;

class ProductResearchUrlDto
{
    protected IsProductPageEnum $isProductPage;

    protected ?array $scrapeResult = null;

    protected ?Store $store = null;

    protected WebScraperInterface $scraper;

    protected ?AutoCreateStore $autoCreateStore = null;

    protected ?array $autoCreateStoreStrategies = null;

    protected ?string $html = null;

    protected int $httpTimeout = 10;

    public function __construct(
        protected SearchResultUrl $result,
        protected $cached = false,
    ) {
        $this->store = $this->result->store;

        // Disable notifications and logging for analysis.
        ScrapeUrl::$sendUiNotifications = false;
        ScrapeUrl::$logErrors = false;
        AutoCreateStore::$logErrors = false;

        $this->isProductPage = Cache::remember('search_result_dto_'.$result->url, now()->addMinutes(30), function () {
            $this->isProductPage = IsProductPageEnum::NotProcessed;
            $this->guessIsProductPage();

            return $this->isProductPage;
        });
    }

    public function guessIsProductPage(): self
    {
        if ($this->getIsProductPage() !== IsProductPageEnum::NotProcessed) {
            return $this;
        }

        $newStatus = IsProductPageEnum::Maybe;
        if ($this->canScrapeViaStore()) {
            $newStatus = IsProductPageEnum::YesViaStore;
        } else if ($this->canScrapeViaAuto()) {
            $newStatus = IsProductPageEnum::YesViaAutoCreate;
        }

        $this->isProductPage = $newStatus;

        return $this;
    }

    public function setIsProductPage(IsProductPageEnum $isProductPage): self
    {
        $this->isProductPage = $isProductPage;

        return $this;
    }

    public function getIsProductPage(): IsProductPageEnum
    {
        return $this->isProductPage;
    }

    public function getScrapeUrlService(): ScrapeUrl
    {
        return ScrapeUrl::new($this->result->url)
            ->setMaxAttempts(1)
            ->setConnectTimeout($this->httpTimeout)
            ->setRequestTimeout($this->httpTimeout);
    }

    public function getAutoCreateStoreService(): AutoCreateStore
    {
        if (! $this->autoCreateStore) {
            $this->autoCreateStore = AutoCreateStore::new($this->getUrl(), timeout: $this->httpTimeout);
        }

        return $this->autoCreateStore;
    }

    public function getAutoCreateStoreStrategies(): ?array
    {
        if (! $this->autoCreateStoreStrategies) {
            $this->autoCreateStoreStrategies = $this->getAutoCreateStoreService()->strategyParse();
        }

        return $this->autoCreateStoreStrategies;
    }

    public function getStoreResult(): array
    {
        if ($this->scrapeResult) {
            return $this->scrapeResult;
        }

        $this->scrapeResult = $this->getScrapeUrlService()->scrape();

        return $this->scrapeResult;
    }

    public function getUrl(): string
    {
        return $this->result->url;
    }

    public function getScraper(): WebScraperInterface
    {
        return WebScraper::http()->from($this->result->url)
            ->setConnectTimeout($this->httpTimeout)
            ->setRequestTimeout($this->httpTimeout)
            ->get();
    }

    public function getSearchResult(): SearchResultUrl
    {
        return $this->result;
    }

    public function hasStore(): bool
    {
        return ! empty($this->getStore());
    }

    public function getStore(): ?Store
    {
        return $this->store;
    }

    public function getPriceRaw(): ?string
    {
        return $this->getScrapedValue( 'price');
    }

    public function getImage(): ?string
    {
        return $this->getScrapedValue( 'image');
    }

    protected function getScrapedValue(string $strategy): ?string
    {
        if ($this->getIsProductPage() === IsProductPageEnum::YesViaStore) {
            return $this->getStoreResult()[$strategy] ?? null;
        }

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaAutoCreate) {
            return data_get($this->getAutoCreateStoreStrategies(), $strategy.'.data');
        }

        return null;
    }

    public function getPrice(): ?float
    {
        $price = $this->getPriceRaw();

        return $price ? CurrencyHelper::toFloat($price) : null;
    }

    public function getStrategies(): array
    {
        $strategy = [];

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaStore) {
            $strategy = $this->getStore()->scrape_strategy;
        }

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaAutoCreate) {
            $strategy = $this->getAutoCreateStoreStrategies();
        }

        return $strategy;
    }

    public function getHtml(): ?string
    {
        $html = null;

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaStore) {
            $html = $this->getStoreResult()['body'] ?? '';
        }

        if ($this->getIsProductPage() === IsProductPageEnum::YesViaAutoCreate) {
            $html = $this->getAutoCreateStoreService()->getHtml();
        }

        return $html;
    }

    public function canScrapeViaStore(): bool
    {
        $scraped = $this->getStoreResult();

        return $this->hasStore()
            && ! empty(data_get($scraped, 'title'))
            && ! empty(data_get($scraped, 'price'));
    }

    public function canScrapeViaAuto(): bool
    {
        return ! is_null($this->getAutoCreateStoreService()->getStoreAttributes());
    }
}
