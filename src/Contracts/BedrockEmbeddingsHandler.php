<?php

namespace Clinically\PrismBedrock\Contracts;

use Clinically\PrismBedrock\Bedrock;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;

abstract class BedrockEmbeddingsHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): Response;
}
