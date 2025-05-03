<?php

namespace App\Services\Helpers;

use App\Enums\IntegratedServices;

class IntegrationHelper
{
    public static function getSettings(): array
    {
        return SettingsHelper::getSetting('integrated_services', []);
    }

    public static function setSettings(array $settings): void
    {
        SettingsHelper::setSetting('integrated_services', $settings);
    }

    public static function getSearchSettings(): array
    {
        return data_get(self::getSettings(), IntegratedServices::SearXng->value, []);
    }

    public static function isSearchEnabled(): bool
    {
        $searchSettings = self::getSearchSettings();

        return data_get($searchSettings, 'enabled', false)
            && data_get($searchSettings, 'url', null);
    }
}
