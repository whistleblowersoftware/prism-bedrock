<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Converse\Maps;

use Prism\Prism\Tool as PrismTool;

class ToolMap
{
    /**
     * @param  PrismTool[]  $tools
     * @return array<string, mixed>
     */
    public static function map(array $tools): array
    {
        return array_map(fn (PrismTool $tool): array => [
            'toolSpec' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => [
                    'json' => array_filter([
                        'type' => 'object',
                        'properties' => $tool->parametersAsArray() ?: (object) [],
                        'required' => $tool->requiredParameters(),
                        'strict' => data_get($tool->providerOptions(), 'strict') ? true : null,
                    ], fn (mixed $value): bool => $value !== null),
                ],
            ],
        ], $tools);
    }
}
