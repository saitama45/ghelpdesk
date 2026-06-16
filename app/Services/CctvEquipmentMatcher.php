<?php

namespace App\Services;

use App\Models\StockIn;

class CctvEquipmentMatcher
{
    public const ROLE_CAMERA = 'camera';
    public const ROLE_DVR_NVR = 'dvr_nvr';

    private const CAMERA_PATTERN = '/\b(camera|cctv)\b/i';
    private const DVR_NVR_PATTERN = '/\b(dvr|nvr)\b/i';

    public static function classify(StockIn $unit): ?string
    {
        $haystack = trim(implode(' ', array_filter([
            $unit->asset?->item_code,
            $unit->asset?->brand,
            $unit->asset?->model,
            $unit->asset?->description,
        ])));

        if ($haystack === '') {
            return null;
        }

        if (preg_match(self::DVR_NVR_PATTERN, $haystack)) {
            return self::ROLE_DVR_NVR;
        }

        if (preg_match(self::CAMERA_PATTERN, $haystack)) {
            return self::ROLE_CAMERA;
        }

        return null;
    }

    public static function isCctv(StockIn $unit): bool
    {
        return self::classify($unit) !== null;
    }
}
