<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\Icons;
use App\Filament\Actions\BaseAction;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected ?float $initialPriceToPrepend = null;

    protected function getHeaderActions(): array
    {
        return [
            ProductResource\Actions\AddUrlAction::make(),
            BaseAction::make('view')->icon(Icons::View->value)
                ->label(__('View'))
                ->resourceName('product')
                ->resourceUrl('view', $this->record),
            Actions\DeleteAction::make()->icon(Icons::Delete->value),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getFooterWidgets(): array
    {
        return [
            UrlsTableWidget::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function afterUpdate(): void
    {
        /** @var Product $product */
        $product = $this->record;

        $product->tags()->sync($this->data['tags']);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Remove initial_price from direct save, we'll handle it manually
        $this->initialPriceToPrepend = $data['initial_price'] ?? null;
        unset($data['initial_price']);

        return $data;
    }

    protected function afterSave(): void
    {
        $initialPrice = $this->initialPriceToPrepend ?? null;
        if ($initialPrice !== null) {
            $product = $this->record;

            if (! ($product instanceof \App\Models\Product)) {
                return;
            }

            $priceCache = $product->getPriceCache();
            if ($priceCache->isNotEmpty()) {
                $firstCache = $priceCache->first();
                $history = $firstCache->getHistory();
                $newHistory = $product->prependValueToHistory(collect($history), floatval($initialPrice));
                $firstCache->setHistory($newHistory->toArray());
                $product->price_cache = $priceCache->map(fn ($dto) => $dto->toArray())->values()->all();
                $product->save();
            }
        }
    }
}
