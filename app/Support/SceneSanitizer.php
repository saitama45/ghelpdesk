<?php

namespace App\Support;

use Closure;

/**
 * Whitelists a page-based canvas scene (task-board whiteboard, diagrams) before it is stored.
 *
 * Scene shape: { pages: [{ id, ...pageFields, elements: [{ id, type, ...fields }] }] }.
 * Only the fields declared for an element's type survive, each coerced to its kind, so a
 * client can never store arbitrary keys or markup. Kinds:
 *
 *   number[:default]      finite number, clamped, rounded to 0.1 (default 0 when missing)
 *   integer               int or null
 *   bool[:true]           boolean (false when missing, unless the default is true)
 *   text                  string, max 5000 chars
 *   name                  page name, max 120 chars ("Page N" when empty)
 *   points                flat [x, y, x, y, …] numbers, whole pairs only
 *   color[:#default]      hex colour, else the default (#111827)
 *   optional_color        hex colour or null
 *   enum:a|b|c            one of the values, else the first
 *   optional_enum:a|b     one of the values or null
 *   ref                   another element id or null
 *   slug[:default]        lowercase slug (e.g. a shape key), else the default
 *   storage_path          a file served by /serve-storage/… (uploaded image), else null
 */
final class SceneSanitizer
{
    /**
     * @param  array<string, array<string, string>>  $elementFields  type => [field => kind]
     * @param  array<string, string>  $pageFields  field => kind (besides id and elements)
     * @param  Closure|null  $accept  final say on a cleaned element (return false to drop it)
     */
    public function __construct(
        private array $elementFields,
        private array $pageFields,
        private int $maxPages = 30,
        private int $maxElementsPerPage = 5000,
        private int $maxPointValues = 40000,
        private ?Closure $accept = null,
    ) {}

    public function sanitize(array $scene): array
    {
        $pages = [];
        $seenPages = [];

        foreach (array_slice(is_array($scene['pages'] ?? null) ? $scene['pages'] : [], 0, $this->maxPages) as $page) {
            if (! is_array($page) || ! self::validId($page['id'] ?? null) || isset($seenPages[$page['id']])) {
                continue;
            }

            $seenPages[$page['id']] = true;
            $pages[] = $this->page($page, count($pages) + 1);
        }

        if (! $pages) {
            $pages[] = $this->page(['id' => 'page-1'], 1);
        }

        return ['version' => 1, 'pages' => $pages];
    }

    public static function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1;
    }

    private function page(array $page, int $number): array
    {
        $clean = ['id' => $page['id']];

        foreach ($this->pageFields as $field => $kind) {
            $clean[$field] = $kind === 'name'
                ? (mb_substr(trim((string) ($page[$field] ?? '')), 0, 120) ?: "Page {$number}")
                : $this->value($kind, $page[$field] ?? null);
        }

        $elements = [];
        $source = is_array($page['elements'] ?? null) ? $page['elements'] : [];

        foreach (array_slice($source, 0, $this->maxElementsPerPage) as $element) {
            $cleanElement = $this->element($element);

            if ($cleanElement) {
                $elements[$cleanElement['id']] = $cleanElement;
            }
        }

        $clean['elements'] = array_values($elements);

        return $clean;
    }

    private function element(mixed $element): ?array
    {
        if (! is_array($element) || ! self::validId($element['id'] ?? null)) {
            return null;
        }

        $type = $element['type'] ?? null;
        $fields = is_string($type) ? ($this->elementFields[$type] ?? null) : null;

        if (! $fields) {
            return null;
        }

        $clean = ['id' => $element['id'], 'type' => $type];

        foreach ($fields as $field => $kind) {
            $clean[$field] = $this->value($kind, $element[$field] ?? null);
        }

        if ($this->accept && ! ($this->accept)($clean)) {
            return null;
        }

        return $clean;
    }

    private function value(string $kind, mixed $value): mixed
    {
        [$name, $argument] = array_pad(explode(':', $kind, 2), 2, null);

        return match ($name) {
            'number' => $this->number(is_numeric($value) ? $value : ($argument ?? 0)),
            'integer' => is_numeric($value) ? (int) $value : null,
            'bool' => $value === null ? $argument === 'true' : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'text' => mb_substr(is_scalar($value) ? (string) $value : '', 0, 5000),
            'name' => mb_substr(is_scalar($value) ? trim((string) $value) : '', 0, 120),
            'points' => $this->points($value),
            'color' => $this->color($value) ?? ($argument ?: '#111827'),
            'optional_color' => $this->color($value),
            'enum' => in_array($value, $options = explode('|', (string) $argument), true) ? $value : $options[0],
            'optional_enum' => in_array($value, explode('|', (string) $argument), true) ? $value : null,
            'ref' => self::validId($value) ? $value : null,
            'slug' => is_string($value) && preg_match('/^[a-z0-9-]{1,40}$/', $value) === 1 ? $value : $argument,
            'storage_path' => is_string($value) && ! str_contains($value, '..')
                && preg_match('#^/serve-storage/[A-Za-z0-9/_.-]{1,240}$#', $value) === 1 ? $value : null,
        };
    }

    private function points(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $points = array_map(fn ($n) => $this->number($n), array_slice(array_values($value), 0, $this->maxPointValues));

        // Keep whole x,y pairs only.
        return count($points) % 2 === 0 ? $points : array_slice($points, 0, -1);
    }

    private function number(mixed $value): float|int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = round(max(-1_000_000, min(1_000_000, (float) $value)), 1);

        return floor($number) === $number ? (int) $number : $number;
    }

    private function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : null;
    }
}
