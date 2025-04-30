<?php

namespace App\Models;

use App\Dto\ProductResearchUrlDto;
use App\Services\SearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class UrlResearch extends Model
{
    /** @use HasFactory<\Database\Factories\UrlResearchFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'url' => 'string',
        'strategies' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /***************************************************
     * Relationships.
     **************************************************/

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeSearchQuery(Builder $query, string $searchQuery): Builder
    {
        $urls = SearchService::new()->getRawResults($searchQuery)->getResults()->pluck('url');

        return $query->whereIn('url', $urls);
    }

    public function scopeSetFilters(Builder $query, array $filters): Builder
    {
        $min = $filters['min_price'] ?? 0;
        $max = $filters['max_price'] ?? null;

        if ($min) {
            $query->where('price', '>=', $min);
        }

        if (! empty($max)) {
            $query->where('price', '<=', $max);
        }

        return $query;
    }
}
