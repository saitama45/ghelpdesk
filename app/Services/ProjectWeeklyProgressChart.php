<?php

namespace App\Services;

use Carbon\Carbon;

class ProjectWeeklyProgressChart
{
    public function render(array $series): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return null;
        }

        $weeks = array_values($series['weeks'] ?? []);
        $planned = array_values($series['planned'] ?? []);
        $actual = array_values($series['actual'] ?? []);

        if ($weeks === []) {
            return null;
        }

        $width = 1600;
        $height = 260;
        $left = 80;
        $right = 35;
        $top = 28;
        $bottom = 58;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;
        $count = count($weeks);

        $image = imagecreatetruecolor($width, $height);
        imageantialias($image, true);

        $white = imagecolorallocate($image, 255, 255, 255);
        $grid = imagecolorallocate($image, 226, 232, 240);
        $verticalGrid = imagecolorallocate($image, 241, 245, 249);
        $text = imagecolorallocate($image, 100, 116, 139);
        $muted = imagecolorallocate($image, 148, 163, 184);
        $plannedColor = imagecolorallocate($image, 59, 130, 246);
        $actualColor = imagecolorallocate($image, 239, 85, 71);
        imagefill($image, 0, 0, $white);

        $x = fn (int $index): int => $count <= 1
            ? (int) round($left + ($plotWidth / 2))
            : (int) round($left + (($index * $plotWidth) / ($count - 1)));
        $y = fn ($value): int => (int) round($top + (((100 - max(0, min(100, (float) $value))) / 100) * $plotHeight));

        foreach ([0, 25, 50, 75, 100] as $gridValue) {
            $gridY = $y($gridValue);
            imageline($image, $left, $gridY, $width - $right, $gridY, $grid);
            $this->drawCenteredText($image, $gridValue.'%', $left - 35, $gridY - 4, 3, $text);
        }

        foreach ($weeks as $index => $week) {
            $weekX = $x($index);
            imageline($image, $weekX, $top, $weekX, $height - $bottom, $verticalGrid);
            $this->drawCenteredText($image, 'W'.($week['index'] ?? $index + 1), $weekX, $height - 43, 3, $text);
            $start = Carbon::parse($week['start'] ?? now());
            $this->drawCenteredText($image, $start->format('M d'), $weekX, $height - 23, 2, $muted);
        }

        imagesetthickness($image, 5);
        for ($index = 1; $index < count($planned); $index++) {
            imageline($image, $x($index - 1), $y($planned[$index - 1]), $x($index), $y($planned[$index]), $plannedColor);
        }
        for ($index = 1; $index < count($actual); $index++) {
            if ($actual[$index - 1] !== null && $actual[$index] !== null) {
                imageline($image, $x($index - 1), $y($actual[$index - 1]), $x($index), $y($actual[$index]), $actualColor);
            }
        }
        imagesetthickness($image, 1);

        foreach ($planned as $index => $value) {
            if (! isset($weeks[$index])) {
                break;
            }
            imagefilledellipse($image, $x($index), $y($value), 11, 11, $plannedColor);
            $this->drawCenteredText($image, round((float) $value).'%', $x($index), max(2, $y($value) - 22), 3, $plannedColor);
        }

        foreach ($actual as $index => $value) {
            if ($value === null || ! isset($weeks[$index])) {
                continue;
            }
            imagefilledellipse($image, $x($index), $y($value), 11, 11, $actualColor);
            $this->drawCenteredText($image, round((float) $value).'%', $x($index), min($height - $bottom - 13, $y($value) + 10), 3, $actualColor);
        }

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);

        return is_string($png) ? 'data:image/png;base64,'.base64_encode($png) : null;
    }

    private function drawCenteredText($image, string $value, int $centerX, int $y, int $font, int $color): void
    {
        $width = imagefontwidth($font) * strlen($value);
        imagestring($image, $font, (int) round($centerX - ($width / 2)), $y, $value, $color);
    }
}
