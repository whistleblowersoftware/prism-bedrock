# Prism Bedrock

A [Prism](https://github.com/prism-php/prism) provider for AWS Bedrock in Laravel applications.

This is a maintained fork of [prism-php/bedrock](https://github.com/prism-php/bedrock) with additional features not yet available upstream, including streaming support, image generation, thinking/reasoning content, cache token tracking, native structured output, strict tool schemas, and citation support.

## Installation

```bash
composer require clinically/prism-bedrock
```

## Configuration

Add the following to your Prism configuration (`config/prism.php`):

```php
'bedrock' => [ // Key should match Bedrock::KEY
    'region' => env('AWS_REGION', 'us-east-1'),

    // Set to true to ignore other auth configuration and use the AWS SDK default credential chain
    // read more at https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_default_chain.html
    'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIAL_PROVIDER', false), 

    'api_key' => env('AWS_ACCESS_KEY_ID'), //  Ignored with `use_default_credential_provider` === true
    'api_secret' => env('AWS_SECRET_ACCESS_KEY'), //  Ignored with `use_default_credential_provider` === true
    'session_token' => env('AWS_SESSION_TOKEN'), // Only required for temporary credentials. Ignored with `use_default_credential_provider` === true
],
```

## Usage

### Text Generation

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

echo $response->text;
```

### Structured Output (JSON)

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\ArraySchema;

$schema = new ObjectSchema(
    name: 'languages',
    description: 'Top programming languages',
    properties: [
        new ArraySchema(
            'languages',
            'List of programming languages',
            items: new ObjectSchema(
                name: 'language',
                description: 'Programming language details',
                properties: [
                    new StringSchema('name', 'The language name'),
                    new StringSchema('popularity', 'Popularity description'),
                ]
            )
        )
    ]
);

$response = Prism::structured()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withSchema($schema)
    ->withPrompt('List the top 3 programming languages')
    ->asStructured();

// Access your structured data
$data = $response->structured;
```

### Embeddings (with Cohere models)

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;

$response = Prism::embeddings()
    ->using(Bedrock::KEY, 'cohere.embed-english-v3')
    ->fromArray(['The sky is blue', 'Water is wet'])
    ->asEmbeddings();

// Access the embeddings
$embeddings = $response->embeddings;
```

### Model names

> [!IMPORTANT]
> When using inference profiles via an ARN, you should urlencode the model name.

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;

$response = Prism::text()
    ->using(Bedrock::KEY, urlencode('arn:aws:bedrock:us-east-1:999999999999:inference-profile/us.anthropic.claude-3-7-sonnet-20250219-v1:0'))
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();
```

## Supported API Schemas

AWS Bedrock supports several API schemas - some LLM vendor specific, some generic.

Prism Bedrock supports three of those API schemas:

- **Converse**: AWS's native interface for chat (default)*
- **Anthropic**: For Claude models**
- **Cohere**: For Cohere embeddings models

Each schema supports different capabilities:

| Schema | Text | Streaming | Structured | Embeddings | Citations | Strict Tools |
|--------|:----:|:---------:|:----------:|:----------:|:---------:|:------------:|
| Converse | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Anthropic | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Cohere | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |

\* A unified interface for multiple providers. See [AWS documentation](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-supported-models-features.html) for a list of supported models.

\*\* The Converse schema does not support Anthropic's native features (e.g. PDF vision analysis). This schema uses Anthropic's native schema and therefore allows use of Anthropic native features. Please note however that Bedrock's Anthropic schema does not yet have feature parity with Anthropic. Prompt caching support may be limited to specific Claude models. See the [AWS documentation](https://docs.aws.amazon.com/bedrock/latest/userguide/prompt-caching.html) for the latest supported models. Documents are supported on the Anthropic schema (base64-encoded only, not URLs or file IDs).

## Auto-resolution of API schemas

Prism Bedrock resolves the most appropriate API schema from the model string. If it is unable to resolve a specific schema (e.g. anthropic for Anthropic), it will default to Converse.

Therefore if you use a model that is not supported by AWS Bedrock Converse, and does not have a specific Prism Bedrock implementation, your request will fail.

If you wish to force Prism Bedrock to use Converse instead of a vendor specific interface, you can do so with `withProviderOptions()`:

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;
use Clinically\PrismBedrock\Enums\BedrockSchema;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

```

## Cache-Optimized Prompts

For [supported models](https://docs.aws.amazon.com/bedrock/latest/userguide/prompt-caching.html), you can enable prompt caching to reduce latency and costs:

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withMessages([
        (new UserMessage('Message with cache breakpoint'))->withProviderOptions(['cacheType' => 'ephemeral']),
        (new UserMessage('Message with another cache breakpoint'))->withProviderOptions(['cacheType' => 'ephemeral']),
        new UserMessage('Compare the last two messages.')
    ])
    ->asText();
```

> [!TIP]
> Anthropic currently supports a cacheType of "ephemeral". Converse currently supports a cacheType of "default". It is possible that Anthropic and/or AWS may add additional types in the future.

## Structured Output

By default, `Prism::structured()` uses native structured output via the API's `outputConfig` / `output_config` parameter. The schema is sent directly to the model, and the JSON response is parsed into the `structured` field automatically.

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;

$response = Prism::structured()
    ->withSchema($schema)
    ->using(Bedrock::KEY, 'anthropic.claude-3-5-haiku-20241022-v1:0')
    ->withPrompt('My prompt')
    ->asStructured();

$data = $response->structured; // Parsed array
```

To fall back to the previous prompt-based approach (appends a user message asking the model to respond with JSON), set `use_native_structured` to `false`:

```php
Prism::structured()
    ->withSchema($schema)
    ->using(Bedrock::KEY, 'anthropic.claude-3-5-haiku-20241022-v1:0')
    ->withProviderOptions([
        'use_native_structured' => false,
        // Optionally override the default prompt-based message:
        'jsonModeMessage' => 'My custom message',
    ])
    ->withPrompt('My prompt')
    ->asStructured();
```

## Strict Tool Schemas

You can enforce strict input validation on tools by setting the `strict` provider option:

```php
use Prism\Prism\Facades\Tool;

$tool = Tool::as('search')
    ->for('Search the web')
    ->withStringParameter('query', 'The search query')
    ->withProviderOptions(['strict' => true])
    ->using(fn (string $query): string => 'results...');
```

This adds `"strict": true` to the tool's input schema in the API payload (both Converse and Anthropic schemas).

## Citations

Citations can be enabled on document queries to get source references in the response. This is supported on the Converse schema (Anthropic schema does not yet support documents on Bedrock).

```php
use Prism\Prism\Prism;
use Clinically\PrismBedrock\Bedrock;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withProviderOptions(['citations' => true])
    ->withMessages([
        new UserMessage(
            content: 'What does this document say?',
            additionalContent: [
                Document::fromPath('path/to/document.pdf', 'My Document'),
            ]
        ),
    ])
    ->asText();

// Citations are available in additionalContent
$citations = $response->additionalContent['citations'] ?? [];

foreach ($citations as $part) {
    echo $part->outputText; // The model's text that references a source
    foreach ($part->citations as $citation) {
        echo $citation->sourceText;  // The cited text from the document
        echo $citation->sourceTitle; // The document title
    }
}
```

You can also enable citations per-document via document provider options instead of at the request level.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Credits

Originally created by [TJ Miller](https://tjmiller.me) and [Chris Bridges](https://github.com/chris-bridges) at [prism-php/bedrock](https://github.com/prism-php/bedrock). This fork is maintained by [Clinically](https://github.com/clinically-au).
