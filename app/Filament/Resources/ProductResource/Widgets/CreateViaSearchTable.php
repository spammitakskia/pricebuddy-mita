<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Filament\Resources\ProductResource\Actions\AddSearchResultUrlBulkAction;
use App\Models\Product;
use App\Models\UrlResearch;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\Helpers\IntegrationHelper;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Database\Eloquent\Builder;

class CreateViaSearchTable extends BaseWidget
{
    public const DEFAULT_PAGINATION = 100;

    protected $listeners = [
        'updateCreateViaSearchTable' => 'reRenderTable',
        'emptyCreateViaSearchTable' => 'emptyRenderTable',
    ];

    public ?string $searchQuery = null;

    public array $filters = [];

    public ?Product $product = null;

    public static function canView(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $settings = IntegrationHelper::getSearchSettings();
        $prefix = data_get($settings, 'search_prefix');

        return $table
            ->heading('Search results for "'.($prefix ? $prefix.' ' : '').$this->searchQuery.'"')
            ->description('Select the results you want to add to '.($this->product ? '"'.$this->product->title.'"' : 'a new product'))
            ->query(
                UrlResearch::query()->searchQuery($this->searchQuery)
                    ->orderByRaw('ISNULL(price), price ASC')
                    ->orderByDesc('store_id')
                    ->orderByDesc('id')
            )
            ->columns(ProductSearch::tableColumns())
            ->recordClasses(fn (UrlResearch $record) => empty($record->price) ? 'opacity-50' : '')
            ->filters([
                Filter::make('min')
                    ->label('Min price')
                    ->form([
                        TextInput::make('min_price')->numeric()->nullable()->placeholder('0')->default('0'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $query->setFilters($data);
                    }),
                Filter::make('max')
                    ->label('Max price')
                    ->form([
                        TextInput::make('max_price')->numeric()->nullable()->placeholder('No limit'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $query->setFilters($data);
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultPaginationPageOption(self::DEFAULT_PAGINATION)
            ->bulkActions([
                AddSearchResultUrlBulkAction::make()->withSearchQuery($this->searchQuery)
                    ->withProduct($this->product),
            ])
            ->emptyStateHeading(fn () => 'No results found for "'.$this->searchQuery.'"')
            ->emptyStateDescription(fn () => 'Unable to parse any prices from results')
            ->checkIfRecordIsSelectableUsing(fn (UrlResearch $record): bool => ! empty($record->price));
    }

    public function reRenderTable(?string $searchQuery): void
    {
        $this->searchQuery = $searchQuery;

        $this->resetTable();
    }

    public function emptyRenderTable(): void
    {
        $this->searchQuery = null;

        $this->resetTable();
    }
}
