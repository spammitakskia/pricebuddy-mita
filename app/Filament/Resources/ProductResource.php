<?php

namespace App\Filament\Resources;

use App\Enums\Icons;
use App\Enums\Statuses;
use App\Filament\Resources\ProductResource\Actions\FetchBulkAction;
use App\Filament\Resources\ProductResource\Actions\SetNotifyPercentBulkAction;
use App\Filament\Resources\ProductResource\Columns\ProductCardColumn;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Providers\Filament\AdminPanelProvider;
use App\Rules\StoreUrl;
use App\Services\Helpers\CurrencyHelper;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form
            ->schema(fn ($livewire) => $livewire instanceof Pages\CreateProduct
                ? self::createForm($form)
                : self::editForm($form)
            );
    }

    public static function createForm(?Form $form = null, ?int $productId = null): array
    {
        $components = [];

        if (is_null($productId)) {
            $components[] = Forms\Components\Select::make('product_id')
                ->label('Existing product')
                ->searchable(['title'])
                ->getSearchResultsUsing(fn (string $search): array => auth()->user()->products()->where('title', 'like', "%{$search}%"
                )
                    ->limit(50)->pluck('title', 'id')
                    ->toArray()
                )
                ->hintIcon(Icons::Help->value, 'Add this URL to an existing product, leave empty to create a new product')
                ->nullable();
        }

        $components[] = TextInput::make('url')
            ->label('Product URL')
            ->hintIcon(Icons::Help->value, 'The domain of the URL must be in the list of available stores')
            ->rules([new StoreUrl]);

        $components[] = Forms\Components\Toggle::make('create_store')
            ->label('Create store if it doesn\'t exist')
            ->hintIcon(Icons::Help->value, 'Attempt to create automatically create a store. Does not always work')
            ->default(true);

        // --- Add notify fields and persist their state ---
        $components[] = Forms\Components\Grid::make(2)
            ->schema([
                Forms\Components\TextInput::make('notify_price')
                    ->label('Notify price')
                    ->suffix(CurrencyHelper::getSymbol())
                    ->numeric()
                    ->nullable()
                    ->default(fn () => session('notify_price'))
                    ->hintIcon(Icons::Help->value, 'Get notified when price is equal or less than this value'),

                Forms\Components\TextInput::make('notify_percent')
                    ->label('Notify percent')
                    ->suffix('%')
                    ->numeric()
                    ->nullable()
                    ->default(fn () => session('notify_percent'))
                    ->hintIcon(Icons::Help->value, 'Get notified when price drops below specified percentage'),
            ]);

        return [
            Forms\Components\Section::make(__('Url of the product'))->schema($components)
                ->description(__('Given the url we will scrape the product information. Products and their urls are unique to your user account')),
        ];
    }

    public static function editForm(Form $form): array
    {
        return [
            Forms\Components\Section::make('Basics')->schema([
                TextInput::make('title')
                    ->label('Product title')
                    ->hintIcon(Icons::Help->value, 'The name of the product'),

                TextInput::make('image')
                    ->label('Image Url')
                    ->hintIcon(Icons::Help->value, 'The Image URL of the product'),

                Forms\Components\Select::make('status')
                    ->options(Statuses::class)
                    ->default(Statuses::Published)
                    ->preload()
                    ->hintIcon(Icons::Help->value, 'Only published products get price history')
                    ->native(false),

                Select::make('tags')
                    ->relationship(
                        'tags',
                        'name',
                        fn (Builder $query) => $query->where('user_id', auth()->id())
                    )
                    ->createOptionForm([
                        TextInput::make('name')->required(),
                        Hidden::make('user_id')->default(auth()->id()),
                    ])
                    ->multiple()
                    ->nullable()
                    ->preload(),
                Forms\Components\Select::make('weight')
                    ->label('Homepage sort order')
                    ->hintIcon(Icons::Help->value, 'The lower the number the higher it will appear on the homepage')
                    ->default('0')
                    ->options(collect(range(-50, 50))->mapWithKeys(fn ($value) => [strval($value) => strval($value)])->all()),
                Forms\Components\Toggle::make('favourite')
                    ->label('Favourite')
                    ->hintIcon(Icons::Help->value, 'Mark this product as favourite')
                    ->default(true),

                Forms\Components\TextInput::make('initial_price')
                    ->label('Initial Price')
                    ->numeric()
                    ->nullable()
                    ->default(fn ($record) => $record?->initial_price)
                    ->suffix(\App\Services\Helpers\CurrencyHelper::getSymbol())
                    ->hint('Set a new initial price for this product'),
            ])
                ->columns(2)
                ->description('Product info'),

            Forms\Components\Section::make('Notifications')->schema([
                TextInput::make('notify_price')
                    ->nullable()
                    ->suffix(CurrencyHelper::getSymbol())
                    ->hintIcon(Icons::Help->value, 'Get notified when price is equal or less than this value')
                    ->numeric(),

                TextInput::make('notify_percent')
                    ->nullable()
                    ->hintIcon(Icons::Help->value, 'Get notified when price drops below specified percentage')
                    ->suffix('%')
                    ->numeric(),
            ])
                ->columns(2)
                ->description('Notification settings'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\ImageColumn::make('primary_image')
                            ->width(72) // was 60
                            ->height(72) // was 60
                            ->extraImgAttributes(['class' => 'rounded-md p-1 bg-white mr-2'])
                            ->label('Image')
                            ->url(fn ($record): string => $record->action_urls['view'])
                            ->grow(false),

                        Tables\Columns\Layout\Stack::make([
                            TextColumn::make('title')
                                ->searchable()
                                ->sortable() // Name sortable
                                ->formatStateUsing(fn ($state): HtmlString => new HtmlString('<span title="'.$state.'">'.Str::limit($state, 50).'</span>'))
                                ->weight(FontWeight::Bold)
                                ->extraAttributes(['class' => 'pr-4 min-w-40'])
                                ->url(fn (Product $record): string => $record->action_urls['view']),

                            TextColumn::make('current_price')
                                ->label('Current Price')
                                ->sortable() // Current price sortable
                                ->formatStateUsing(fn ($state, Product $record) => \App\Services\Helpers\CurrencyHelper::toString($record->current_price))
                                ->weight(FontWeight::Bold)
                                ->color(fn ($state, Product $record) => $record->current_discount > 0
                                        ? Color::Emerald
                                        : ($record->current_discount < 0
                                            ? Color::Rose
                                            : Color::Gray
                                        )
                                )
                                ->extraAttributes(['class' => 'text-lg']),

                            TextColumn::make('current_discount')
                                ->label('Discount')
                                // ->sortable()
                                ->formatStateUsing(function ($state, $record) {
                                    if (! $record) {
                                        return '0%';
                                    }
                                    $discount = ($record->current_discount * -1).'%';
                                    $initialPrice = $record->initial_price ?? null;
                                    if ($initialPrice) {
                                        $initialPriceStr = \App\Services\Helpers\CurrencyHelper::toString($initialPrice);

                                        return "{$discount} (was {$initialPriceStr})";
                                    }

                                    return $discount;
                                })
                                ->color(fn ($state, $record) => $record && $record->current_discount > 0
                                        ? Color::Emerald
                                        : ($record && $record->current_discount < 0
                                            ? Color::Rose
                                            : Color::Gray
                                        )
                                )
                                ->extraAttributes(['class' => 'text-xs ml-2']),

                            Tables\Columns\ViewColumn::make('badges')
                                ->view('components.product-badges')
                                ->viewData(['attributes' => new ComponentAttributeBag(['class' => 'flex md:gap-3 flex-col md:flex-row'])]),

                            TextColumn::make('tags')
                                ->color(Color::Gray)
                                ->formatStateUsing(fn ($record): string => $record->tags->pluck('name')->join(', '))
                                ->label('Tags')
                                ->url(null)
                                ->grow(false)
                                ->extraAttributes(['class' => 'mt-2 text-xs']),

                            TextColumn::make('created_at')
                                ->label('Added')
                                ->dateTime('Y-m-d H:i')
                                ->sortable() // Time added sortable
                                ->extraAttributes(['class' => 'text-xs text-gray-500'])
                                ->visible(fn ($record) => true), // Always visible, or adjust as needed
                        ]),
                    ])->extraAttributes(['class' => 'max-w-md mb-2']),

                    ProductCardColumn::make('product_card')
                        ->label('Detail'),
                ])->extraAttributes(['class' => 'w-full'])->from('sm'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Statuses::class)
                    ->label('Status')
                    ->native(false),
                SelectFilter::make('lowest_in_period')
                    ->label('Current price is lowest in')
                    ->placeholder('All time')
                    ->options([
                        '7' => 'Last week',
                        '30' => 'Last month',
                        '90' => 'Last 90 days',
                        '365' => 'Last year',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        if (! empty($data['value'])) {
                            $query->lowestPriceInDays($data['value']);
                        }
                    }),
                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->label('Tags')
                    ->multiple()
                    ->native(false),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    FetchBulkAction::make(),
                    SetNotifyPercentBulkAction::make(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                $query->currentUser()->with(['tags']);
            })
            ->recordUrl(null);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
            'view' => Pages\ViewProduct::route('/{record}'),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->where('user_id', auth()->id());
    }

    protected static function getAggregateTableColumn(string $method): TextColumn
    {
        return TextColumn::make($method.'_price')
            ->label(ucfirst($method))
            ->color(Color::Gray)
            ->formatStateUsing(fn (Product $record): string => strtoupper($method).' '.CurrencyHelper::toString($record->getPriceCacheAggregate($method)));
    }
}
