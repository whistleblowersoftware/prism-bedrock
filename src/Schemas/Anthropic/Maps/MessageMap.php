<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Anthropic\Maps;

use BackedEnum;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageMap
{
    /**
     * @param  array<int, Message>  $messages
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<int, mixed>
     */
    public static function map(array $messages, array $requestProviderOptions = []): array
    {
        if (array_filter($messages, fn (Message $message): bool => $message instanceof SystemMessage) !== []) {
            throw new PrismException('Anthropic does not support SystemMessages in the messages array. Use withSystemPrompt or withSystemPrompts instead.');
        }

        $mapped = array_map(
            fn (Message $message): array => self::mapMessage($message, $requestProviderOptions),
            $messages
        );

        return self::mergeConsecutiveSameRoleMessages($mapped);
    }

    /**
     * Merge consecutive messages with the same role by concatenating their content arrays.
     *
     * The Anthropic Messages API requires strict role alternation (user, assistant, user, ...).
     * When conversation history is replayed, a ToolResultMessage (role: user) may be followed
     * by a UserMessage (role: user), producing two consecutive user-role messages. This method
     * merges them into a single message with combined content blocks.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected static function mergeConsecutiveSameRoleMessages(array $messages): array
    {
        if (count($messages) <= 1) {
            return $messages;
        }

        $merged = [];
        $current = null;

        foreach ($messages as $message) {
            if ($current === null) {
                $current = $message;

                continue;
            }

            if ($current['role'] === $message['role']) {
                $current['content'] = array_merge(
                    is_array($current['content']) ? $current['content'] : [['type' => 'text', 'text' => $current['content']]],
                    is_array($message['content']) ? $message['content'] : [['type' => 'text', 'text' => $message['content']]],
                );

                continue;
            }

            $merged[] = $current;
            $current = $message;
        }

        $merged[] = $current;

        return $merged;
    }

    /**
     * @param  SystemMessage[]  $messages
     * @return array<int, mixed>
     */
    public static function mapSystemMessages(array $messages): array
    {
        return array_map(
            self::mapSystemMessage(...),
            $messages
        );
    }

    /**
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<string, mixed>
     */
    protected static function mapMessage(Message $message, array $requestProviderOptions = []): array
    {
        return match ($message::class) {
            UserMessage::class => self::mapUserMessage($message, $requestProviderOptions),
            AssistantMessage::class => self::mapAssistantMessage($message),
            ToolResultMessage::class => self::mapToolResultMessage($message),
            default => throw new PrismException('Anthropic: Could not map message type '.$message::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapSystemMessage(SystemMessage $systemMessage): array
    {
        $providerOptions = $systemMessage->providerOptions();

        $cacheType = data_get($providerOptions, 'cacheType');

        return array_filter([
            'type' => 'text',
            'text' => $systemMessage->content,
            'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $toolResult): array => [
                'type' => 'tool_result',
                'tool_use_id' => $toolResult->toolCallId,
                'content' => $toolResult->result,
            ], $message->toolResults),
        ];
    }

    /**
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<string, mixed>
     */
    protected static function mapUserMessage(UserMessage $message, array $requestProviderOptions = []): array
    {
        $providerOptions = $message->providerOptions();

        $cacheType = data_get($providerOptions, 'cacheType');
        $cache_control = $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null;

        return [
            'role' => 'user',
            'content' => [
                array_filter([
                    'type' => 'text',
                    'text' => $message->text(),
                    'cache_control' => $cache_control,
                ]),
                ...self::mapImageParts($message->images(), $cache_control),
                ...self::mapDocumentParts($message->documents(), $cache_control, $requestProviderOptions),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAssistantMessage(AssistantMessage $message): array
    {
        $providerOptions = $message->providerOptions();

        $cacheType = data_get($providerOptions, 'cacheType');

        $content = [];

        if (isset($message->additionalContent['citations'])) {
            foreach ($message->additionalContent['citations'] as $part) {
                $content[] = array_filter([
                    ...CitationsMapper::mapToAnthropic($part),
                    'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
                ]);
            }
        } elseif ($message->content !== '' && $message->content !== '0') {

            $content[] = array_filter([
                'type' => 'text',
                'text' => $message->content,
                'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
            ]);
        }

        $toolCalls = $message->toolCalls
            ? array_map(fn (ToolCall $toolCall): array => [
                'type' => 'tool_use',
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $toolCall->arguments() === [] ? new \stdClass : $toolCall->arguments(),
            ], $message->toolCalls)
            : [];

        return [
            'role' => 'assistant',
            'content' => array_merge($content, $toolCalls),
        ];
    }

    /**
     * @param  Image[]  $parts
     * @param  array<string, mixed>|null  $cache_control
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $parts, ?array $cache_control = null): array
    {
        return array_map(
            fn (Image $image): array => (new ImageMapper($image, $cache_control))->toPayload(),
            $parts
        );
    }

    /**
     * @param  Document[]  $parts
     * @param  array<string, mixed>|null  $cache_control
     * @param  array<string, mixed>  $requestProviderOptions
     * @return array<int, mixed>
     */
    protected static function mapDocumentParts(array $parts, ?array $cache_control = null, array $requestProviderOptions = []): array
    {
        return array_map(
            fn (Document $document): array => (new DocumentMapper($document, $cache_control, $requestProviderOptions))->toPayload(),
            $parts
        );
    }
}
