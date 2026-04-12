<?php

namespace Clinically\PrismBedrock\Schemas\Converse\Maps;

use Clinically\PrismBedrock\Enums\Mimes;
use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Media;

class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @param  Document  $media
     * @param  array<string, mixed>  $cacheControl
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

        $document = [
            'format' => $this->media->mimeType() ? Mimes::tryFrom($this->media->mimeType())?->toExtension() : null,
            'name' => $this->media->documentTitle(),
            'source' => ['bytes' => $this->media->base64()],
        ];

        if ($citationsEnabled) {
            $document['citationConfig'] = ['type' => 'DOCUMENT'];
        }

        return [
            'document' => $document,
        ];
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

        return $this->media->hasRawContent();
    }
}
