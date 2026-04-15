<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Contracts;

use Clinically\PrismBedrock\Bedrock;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;

abstract class BedrockTextHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): Response;
}
