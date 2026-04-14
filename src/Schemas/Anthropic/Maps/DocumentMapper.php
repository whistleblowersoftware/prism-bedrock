<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Anthropic\Maps;

use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Media;

class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @param  Document  $media
     * @param  array<string, mixed>|null  $cacheControl
     * @param  array<string, mixed>  $requestProviderOptions
     */
    public function __construct(
        public readonly Media $media,
        public ?array $cacheControl = null,
        public array $requestProviderOptions = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        $providerOptions = $this->media->providerOptions();

        $citationsEnabled = data_get($this->requestProviderOptions, 'citations', data_get($providerOptions, 'citations', false));

        return array_filter([
            'type' => 'document',
            'title' => $this->media->documentTitle(),
            'context' => $providerOptions['context'] ?? null,
            'cache_control' => $this->cacheControl,
            'citations' => $citationsEnabled ? ['enabled' => true] : null,
            'source' => [
                'type' => 'base64',
                'media_type' => $this->media->mimeType(),
                'data' => $this->media->base64(),
            ],
        ]);
    }

    protected function provider(): string|Provider
    {
        return 'bedrock';
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return false;
        }

        if ($this->media->isFileId()) {
            return false;
        }

        if ($this->media->isChunks()) {
            return false;
        }

        return $this->media->hasRawContent();
    }
}
