<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Filament\Resources\ProductResource\Actions\AddSearchResultUrlAction;
use App\Filament\Resources\ProductResource\Actions\IgnoreSearchResultUrlAction;
use App\Models\Product;
use App\Models\SearchResultUrl;
use App\Models\UrlResearch;
use App\Services\Helpers\CurrencyHelper;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ProductSearch extends BaseWidget
{
    public Model|Product|null $record = null;

    public $listeners = ['ResetProductSearchTable' => 'refreshTable'];

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->record;

        //        SearchResultUrl::$populateResearch = true;
        //        SearchResultUrl::setProductSearchQuery($product->title);

        return $table
            ->heading('Search results')
            ->query(
                SearchResultUrl::query()->with('store')
                    ->whereNotIn('url', $product->ignored_search_urls)
                    ->orderByDesc('store_id')
                    ->orderByDesc('relevance')
            )
            ->columns(self::tableColumns())
            ->actions([
                //                AddSearchResultUrlAction::make('add_url')
                //                    ->setProduct($product),
                //                AddSearchResultUrlAction::make('add_store')
                //                    ->label(__('Add store'))
                //                    ->color('gray')
                //                    ->setProduct($product)
                //                    ->visible(fn (SearchResultUrl $record) => is_null($record->store_id)),
                //                IgnoreSearchResultUrlAction::make('ignore_result')
                //                    ->setProduct($product)
                //                    ->after(fn () => $this->dispatch('ResetProductSearchTable')),
            ])
            ->bulkActions([
                // Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function tableColumns(): array
    {
        return [
            Tables\Columns\Layout\Split::make([

                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('title')
                        ->label('Title')
                        ->weight(FontWeight::Bold)
                        ->url(fn (UrlResearch $record) => $record->url),
                    Tables\Columns\TextColumn::make('url')
                        ->label('Url')
                        ->color('gray')
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<a href="'.$state.'" title="'.$state.'" target="_blank">'.Str::limit($state, 80).'</a>')
                        ),
                ])->extraAttributes(['class' => 'w-xl']),

                Tables\Columns\Layout\Stack::make([

                    Tables\Columns\TextColumn::make('price')
                        ->label('Price')
                        ->weight(FontWeight::Bold)
                        ->formatStateUsing(fn (?float $state, UrlResearch $record): HtmlString => new HtmlString(
                            empty($state) ? 'No price' : CurrencyHelper::toString($state, locale: $record->store?->locale, iso: $record->store?->currency)
                        ))
                        ->extraAttributes(['class' => 'md:text-right md:justify-end']),

                    Tables\Columns\TextColumn::make('store.name')
                        ->label('Store')
                        ->color('gray')
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(
                            $state ?: 'Add store'
                        ))
                        ->extraAttributes(['class' => 'md:text-right md:justify-end']),

                ])->extraAttributes(['class' => 'md:w-sm md:align-right md:pr-8'])->grow(false),

            ])->from('sm'),

        ];
    }

    public function refreshTable(): void
    {
        // dd('ref');
        $this->record->refresh();
        $this->getTableRecords()->fresh();
        $this->resetTable();
    }
}
