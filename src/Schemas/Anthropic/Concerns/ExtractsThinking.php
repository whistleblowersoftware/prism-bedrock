<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Anthropic\Concerns;

use Illuminate\Support\Arr;

trait ExtractsThinking
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractThinking(array $data): array
    {
        $thinking = Arr::first(
            data_get($data, 'content', []),
            fn ($content): bool => data_get($content, 'type') === 'thinking'
        );

        if ($thinking === null) {
            return [];
        }

        return Arr::whereNotNull([
            'thinking' => data_get($thinking, 'thinking'),
            'thinking_signature' => data_get($thinking, 'signature'),
        ]);
    }
}
