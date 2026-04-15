<?php

namespace Clinically\PrismBedrock\Schemas\Anthropic;

use Clinically\PrismBedrock\Contracts\BedrockStructuredHandler;
use Clinically\PrismBedrock\Schemas\Anthropic\Concerns\ExtractsText;
use Clinically\PrismBedrock\Schemas\Anthropic\Concerns\ExtractsThinking;
use Clinically\PrismBedrock\Schemas\Anthropic\Concerns\ExtractsToolCalls;
use Clinically\PrismBedrock\Schemas\Anthropic\Maps\FinishReasonMap;
use Clinically\PrismBedrock\Schemas\Anthropic\Maps\MessageMap;
use Clinically\PrismBedrock\Schemas\Anthropic\Maps\ToolChoiceMap;
use Clinically\PrismBedrock\Schemas\Anthropic\Maps\ToolMap;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class AnthropicStructuredHandler extends BedrockStructuredHandler
{
    use CallsTools, ExtractsText, ExtractsThinking, ExtractsToolCalls;

    protected StructuredResponse $tempResponse;

    protected Response $httpResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->responseBuilder = new ResponseBuilder;
    }

    #[\Override]
    public function handle(Request $request): StructuredResponse
    {
        if ($this->responseBuilder->steps->isEmpty() && ! $this->useNativeStructured($request)) {
            $this->appendMessageForJsonMode($request);
        }

        $this->sendRequest($request);

        $this->prepareTempResponse($request);

        $responseMessage = new AssistantMessage(
            content: $this->tempResponse->text,
            toolCalls: $this->tempResponse->toolCalls,
            additionalContent: $this->tempResponse->additionalContent
        );

        $request->addMessage($responseMessage);

        return match ($this->tempResponse->finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($request),
            default => $this->handleStop($request),
        };
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request, ?string $apiVersion): array
    {
        $useNative = $request->providerOptions('use_native_structured') !== false
            && $request->schema() !== null;

        return array_filter([
            'anthropic_version' => $apiVersion,
            'messages' => MessageMap::map($request->messages(), $request->providerOptions()),
            'max_tokens' => $request->maxTokens(),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'thinking' => AnthropicTextHandler::buildThinkingConfig($request),
            'tools' => ToolMap::map($request->tools()),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'output_config' => self::buildStructuredOutputConfig($request, $useNative),
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function sendRequest(Request $request): void
    {
        try {
            $this->httpResponse = $this->client->post(
                'invoke',
                static::buildPayload($request, $this->provider->apiVersion($request))
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function prepareTempResponse(?Request $request = null): void
    {
        $data = $this->httpResponse->json();

        $text = $this->extractText($data);
        $structured = [];

        if ($request !== null && $this->useNativeStructured($request)) {
            $structured = json_decode($text, associative: true) ?? [];
        }

        $this->tempResponse = new StructuredResponse(
            steps: new Collection,
            text: $text,
            structured: $structured,
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens'),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheWriteInputTokens: data_get($data, 'usage.cache_creation_input_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.cache_read_input_tokens')
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            ),
            toolCalls: $this->extractToolCalls($data),
            additionalContent: Arr::whereNotNull($this->extractThinking($data)),
        );
    }

    protected function handleToolCalls(Request $request): StructuredResponse
    {
        $toolResults = $this->callTools($request->tools(), $this->tempResponse->toolCalls);
        $message = new ToolResultMessage($toolResults);

        $request->addMessage($message);

        $this->addStep($request, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(Request $request): StructuredResponse
    {
        $this->addStep($request);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
            toolCalls: $this->tempResponse->toolCalls,
            toolResults: $toolResults,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function buildStructuredOutputConfig(Request $request, bool $useNative): ?array
    {
        $config = [];

        if ($useNative) {
            $config['format'] = [
                'type' => 'json_schema',
                'schema' => $request->schema()->toArray(),
            ];
        }

        $effort = $request->providerOptions('thinking.effort');
        if ($effort !== null) {
            $config['effort'] = $effort;
        }

        return $config === [] ? null : $config;
    }

    protected function useNativeStructured(Request $request): bool
    {
        return $request->providerOptions('use_native_structured') !== false
            && $request->schema() !== null;
    }

    protected function appendMessageForJsonMode(Request $request): void
    {
        $request->addMessage(new UserMessage(sprintf(
            "%s \n %s",
            $request->providerOptions('jsonModeMessage') ?? 'Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:',
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
