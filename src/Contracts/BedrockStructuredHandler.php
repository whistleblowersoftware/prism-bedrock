<?php

namespace Clinically\PrismBedrock\Contracts;

use Clinically\PrismBedrock\Bedrock;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;

abstract class BedrockStructuredHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): StructuredResponse;
}
