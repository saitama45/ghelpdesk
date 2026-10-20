<?php

namespace App\Support;

/**
 * Element whitelist for whiteboard scenes, shared by the task-board whiteboard
 * (TaskBoardWhiteboardController) and standalone whiteboards (WhiteboardController).
 * Only the task-board one keeps `card` elements: they snapshot that board's cards.
 */
final class WhiteboardScene
{
    public const BACKGROUNDS = ['dots', 'grid', 'plain'];

    public const ELEMENT_FIELDS = [
        'pen' => ['points' => 'points', 'color' => 'color', 'size' => 'number', 'opacity' => 'number'],
        'line' => ['x1' => 'number', 'y1' => 'number', 'x2' => 'number', 'y2' => 'number', 'color' => 'color', 'size' => 'number'],
        'arrow' => ['x1' => 'number', 'y1' => 'number', 'x2' => 'number', 'y2' => 'number', 'color' => 'color', 'size' => 'number'],
        'rect' => ['x1' => 'number', 'y1' => 'number', 'x2' => 'number', 'y2' => 'number', 'color' => 'color', 'size' => 'number', 'fill' => 'optional_color'],
        'ellipse' => ['x1' => 'number', 'y1' => 'number', 'x2' => 'number', 'y2' => 'number', 'color' => 'color', 'size' => 'number', 'fill' => 'optional_color'],
        'text' => ['x' => 'number', 'y' => 'number', 'text' => 'text', 'color' => 'color', 'size' => 'number'],
        'sticky' => ['x' => 'number', 'y' => 'number', 'w' => 'number', 'h' => 'number', 'text' => 'text', 'color' => 'color'],
        'card' => ['x' => 'number', 'y' => 'number', 'w' => 'number', 'h' => 'number', 'cardId' => 'integer', 'title' => 'text', 'status' => 'text', 'color' => 'color'],
    ];

    public static function sanitizer(bool $withCards): SceneSanitizer
    {
        $fields = self::ELEMENT_FIELDS;

        if (! $withCards) {
            unset($fields['card']);
        }

        return new SceneSanitizer(
            elementFields: $fields,
            pageFields: ['name' => 'name', 'background' => 'enum:'.implode('|', self::BACKGROUNDS)],
            maxPages: 30,
            maxElementsPerPage: 5000,
            maxPointValues: 40000,
            accept: fn (array $element) => $element['type'] !== 'pen' || count($element['points']) >= 2,
        );
    }
}
