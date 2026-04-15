<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Contracts;

use Clinically\PrismBedrock\Bedrock;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;

abstract class BedrockImagesHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): Response;
}
