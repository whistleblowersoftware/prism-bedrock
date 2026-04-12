<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Converse\Concerns;

use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

trait ExtractsCitations
{
    /**
     * Extract citations from a Converse API response.
     *
     * Converse content blocks use `{"text": "...", "citations": [...]}` format
     * rather than the Anthropic `{"type": "text", "text": "...", "citations": [...]}`.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, MessagePartWithCitations>|null
     */
    protected function extractCitations(array $data): ?array
    {
        $content = data_get($data, 'output.message.content', []);

        if ($content === []) {
            return null;
        }

        $hasCitations = false;
        foreach ($content as $block) {
            if (isset($block['citations']) && $block['citations'] !== []) {
                $hasCitations = true;
                break;
            }
        }

        if (! $hasCitations) {
            return null;
        }

        $parts = [];

        foreach ($content as $block) {
            if (! isset($block['text'])) {
                continue;
            }

            // Normalize to Anthropic format for the CitationsMapper
            $anthropicBlock = [
                'type' => 'text',
                'text' => $block['text'],
                'citations' => $block['citations'] ?? [],
            ];

            $mapped = CitationsMapper::mapFromAnthropic($anthropicBlock);

            if ($mapped !== null) {
                $parts[] = $mapped;
            }
        }

        return $parts === [] ? null : $parts;
    }
}
