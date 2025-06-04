<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Models\Product; // Add this at the top
use Filament\Forms\Components\TextInput;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class SetNotifyPercentBulkAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'set_notify_percent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Set Notify Percent'));
        $this->icon('heroicon-o-bell-alert');
        $this->color('primary');
        $this->form([
            TextInput::make('notify_percent')
                ->label('Notify Percent')
                ->suffix('%')
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(100),
        ]);
        $this->action(function (array $data, Collection $records) {
            $notifyPercent = floatval($data['notify_percent']);
            /** @var Product $product */
            foreach ($records as $product) {
                $product->notify_percent = $notifyPercent;
                $product->save();
            }
            $this->success();
        });
        $this->deselectRecordsAfterCompletion();
    }
}
