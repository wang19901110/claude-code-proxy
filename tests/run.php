<?php

declare(strict_types=1);

use ClaudeCodeProxy\AnthropicResponseAdapter;
use ClaudeCodeProxy\OpenAIChatResponseAdapter;
use ClaudeCodeProxy\ProviderInterface;
use ClaudeCodeProxy\ProviderRegistry;
use ClaudeCodeProxy\Providers\Bai\BaiProvider;
use ClaudeCodeProxy\Providers\Groq\GroqProvider;
use ClaudeCodeProxy\Providers\SiliconFlow\SiliconFlowProvider;
use ClaudeCodeProxy\ProxyLogger;
use ClaudeCodeProxy\ProxyServer;
use ClaudeCodeProxy\ResponseAdapterInterface;
use Workerman\Protocols\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';
$providerModule = require dirname(__DIR__) . '/providers/b_ai/BaiProvider.php';
$groqModule = require dirname(__DIR__) . '/providers/groq/GroqProvider.php';
$siliconFlowModule = require dirname(__DIR__) . '/providers/siliconflow/SiliconFlowProvider.php';

$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$assert($providerModule instanceof BaiProvider, 'B.AI module returns BaiProvider.');
$assert($groqModule instanceof GroqProvider, 'Groq module returns GroqProvider.');
$assert($siliconFlowModule instanceof SiliconFlowProvider, 'SiliconFlow module returns SiliconFlowProvider.');

$configuredDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'claude-code-proxy-configured-test-' . bin2hex(random_bytes(5));
mkdir($configuredDirectory);
file_put_contents(
    $configuredDirectory . DIRECTORY_SEPARATOR . '.env',
    "BAI_API_KEY=test-only-key\nUPSTREAM_TIMEOUT=180\n",
);
$bai = new BaiProvider($configuredDirectory);
$assert($bai->isConfigured(), 'B.AI reads configuration from its own directory.');

$groqDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'claude-code-proxy-groq-test-' . bin2hex(random_bytes(5));
mkdir($groqDirectory);
file_put_contents($groqDirectory . DIRECTORY_SEPARATOR . '.env', "GROQ_API_KEY=test-only-key\n");
$groq = new GroqProvider($groqDirectory);
$assert($groq->isConfigured(), 'Groq reads configuration from its own directory.');

$siliconFlowDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'claude-code-proxy-siliconflow-test-' . bin2hex(random_bytes(5));
mkdir($siliconFlowDirectory);
file_put_contents($siliconFlowDirectory . DIRECTORY_SEPARATOR . '.env', "SILICONFLOW_API_KEY=test-only-key\n");
$siliconFlow = new SiliconFlowProvider($siliconFlowDirectory);
$assert($siliconFlow->isConfigured(), 'SiliconFlow reads configuration from its own directory.');

$registry = ProviderRegistry::fromProviders([$bai, $groq, $siliconFlow]);
$assert(array_keys($registry->providers()) === ['b_ai', 'groq', 'siliconflow'], 'All provider IDs are registered.');
$assert(count($registry->discoveryModels()) === 7, 'Exactly seven models are discovered.');
$assert($registry->routeFor('claude-sonnet-4-6') !== null, 'Known model route resolves.');
$assert($registry->routeFor('groq-qwen3.8-27b') !== null, 'Groq model route resolves.');
$assert($registry->routeFor('siliconflow-qwen3-8b') !== null, 'SiliconFlow model route resolves.');
$assert($registry->routeFor('claude-groq-qwen3-8-27b') !== null, 'Claude-compatible Groq model route resolves.');
$assert($registry->routeFor('claude-siliconflow-qwen3-8b') !== null, 'Claude-compatible SiliconFlow model route resolves.');
$assert($registry->routeFor('claude-sonnet-1-1') !== null, 'B.AI discovery slot resolves.');
$assert($registry->routeFor('claude-sonnet-2-1') !== null, 'SiliconFlow discovery slot resolves.');
$assert($registry->routeFor('claude-sonnet-3-1') !== null, 'Groq discovery slot resolves.');
$assert(count(array_filter(
    $registry->discoveryModels(),
    static fn (array $model): bool => preg_match('/^claude-sonnet-[1-9]-[1-9]$/', $model['alias']) === 1,
)) === 7, 'All discovery aliases use Claude Desktop virtual slots.');
$assert($registry->routeFor('unknown-model') === null, 'Unknown model route is rejected.');

$request = new Request("POST /v1/messages HTTP/1.1\r\nHost: 127.0.0.1\r\nanthropic-version: 2023-06-01\r\n\r\n");
$expectedTokens = [
    'deepseek-v4-flash' => 32000,
    'qwen3.8-flash' => 64000,
    'hy3' => 32000,
];
foreach ($bai->models() as $model) {
    $prepared = $bai->prepareRequest([
        'model' => $model['alias'],
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'test']],
    ], $model, $request);
    $body = json_decode($prepared['body'], true);
    $assert($body['model'] === $model['upstream_model'], 'Upstream model is applied.');
    $assert($body['max_tokens'] === $expectedTokens[$model['upstream_model']], 'Per-model max_tokens is applied.');
}

$probe = $bai->prepareRequest([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 2,
    'messages' => [['role' => 'user', 'content' => 'probe']],
], $bai->models()[0], $request);
$probeBody = json_decode($probe['body'], true);
$assert($probeBody['max_tokens'] === 16, 'Probe max_tokens is raised to 16.');

$groqPrepared = $groq->prepareRequest([
    'model' => 'groq-qwen3.8-27b',
    'system' => [['type' => 'text', 'text' => 'Use tools when needed.']],
    'max_tokens' => 100,
    'stream' => true,
    'tools' => [[
        'name' => 'read_file',
        'description' => 'Read one file.',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
        ],
    ]],
    'messages' => [
        ['role' => 'user', 'content' => 'Read README.md'],
        ['role' => 'assistant', 'content' => [[
            'type' => 'tool_use',
            'id' => 'tool_1',
            'name' => 'read_file',
            'input' => ['path' => 'README.md'],
        ]]],
        ['role' => 'user', 'content' => [[
            'type' => 'tool_result',
            'tool_use_id' => 'tool_1',
            'content' => 'README content',
        ]]],
    ],
], $groq->models()[0], $request);
$groqBody = json_decode($groqPrepared['body'], true);
$assert($groqPrepared['endpoint'] === 'https://api.groq.com/openai/v1/chat/completions', 'Groq endpoint is correct.');
$assert($groqBody['model'] === 'qwen/qwen3.8-27b', 'Groq upstream model is applied.');
$assert($groqBody['max_completion_tokens'] === 16384, 'Groq max completion tokens are mapped.');
$assert($groqBody['messages'][0]['role'] === 'system', 'Anthropic system prompt becomes an OpenAI system message.');
$assert($groqBody['messages'][2]['tool_calls'][0]['function']['name'] === 'read_file', 'Tool use becomes an OpenAI tool call.');
$assert($groqBody['messages'][3]['role'] === 'tool', 'Tool result becomes an OpenAI tool message.');
$assert($groqBody['tools'][0]['function']['parameters']['required'] === ['path'], 'Tool schema is preserved.');

$groqJsonAdapter = $groq->responseAdapter($groq->models()[0], 'groq-qwen3.8-27b');
$groqJson = json_decode($groqJsonAdapter->rewriteJson(json_encode([
    'id' => 'chatcmpl_1',
    'choices' => [[
        'finish_reason' => 'tool_calls',
        'message' => [
            'role' => 'assistant',
            'content' => 'Checking.',
            'tool_calls' => [[
                'id' => 'tool_2',
                'type' => 'function',
                'function' => ['name' => 'read_file', 'arguments' => '{"path":"README.md"}'],
            ]],
        ],
    ]],
    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
], JSON_UNESCAPED_SLASHES)), true);
$assert($groqJson['type'] === 'message', 'OpenAI JSON becomes an Anthropic message.');
$assert($groqJson['model'] === 'groq-qwen3.8-27b', 'Converted Groq JSON uses the client model alias.');
$assert($groqJson['stop_reason'] === 'tool_use', 'OpenAI tool finish reason becomes Anthropic tool_use.');
$assert($groqJson['content'][1]['input']['path'] === 'README.md', 'OpenAI tool arguments become Anthropic tool input.');

$groqSseAdapter = new OpenAIChatResponseAdapter('groq-qwen3.8-27b');
$groqSseState = $groqSseAdapter->initialSseState();
$groqSsePending = '';
$groqSse = "data: {\"id\":\"chatcmpl_2\",\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\n"
    . "data: {\"id\":\"chatcmpl_2\",\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"tool_3\",\"function\":{\"name\":\"read_file\",\"arguments\":\"{\\\"path\\\":\"}}]},\"finish_reason\":null}]}\n\n"
    . "data: {\"id\":\"chatcmpl_2\",\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"\\\"README.md\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n\n"
    . "data: [DONE]\n\n";
$convertedSse = $groqSseAdapter->rewriteSseBuffer($groqSsePending, substr($groqSse, 0, 80), $groqSseState);
$convertedSse .= $groqSseAdapter->rewriteSseBuffer($groqSsePending, substr($groqSse, 80), $groqSseState);
$convertedSse .= $groqSseAdapter->finishSse($groqSseState);
$assert(str_contains($convertedSse, 'event: message_start'), 'OpenAI SSE emits Anthropic message_start.');
$assert(str_contains($convertedSse, '"type":"text_delta","text":"Hi"'), 'OpenAI text delta is converted.');
$assert(str_contains($convertedSse, '"type":"tool_use"'), 'OpenAI streamed tool call is converted.');
$assert(str_contains($convertedSse, '"stop_reason":"tool_use"'), 'OpenAI stream finish reason is converted.');
$assert(substr_count($convertedSse, 'event: message_stop') === 1, 'OpenAI SSE emits exactly one message_stop.');

$siliconPrepared = $siliconFlow->prepareRequest([
    'model' => 'siliconflow-qwen3-8b',
    'max_tokens' => 100,
    'stream' => true,
    'messages' => [['role' => 'user', 'content' => 'test']],
], $siliconFlow->models()[0], $request);
$siliconBody = json_decode($siliconPrepared['body'], true);
$assert($siliconPrepared['endpoint'] === 'https://api.siliconflow.cn/v1/messages', 'SiliconFlow Anthropic endpoint is correct.');
$assert($siliconBody['model'] === 'Qwen/Qwen3-8B', 'SiliconFlow upstream model is applied.');
$assert($siliconBody['max_tokens'] === 16384, 'SiliconFlow max tokens are mapped.');
$silicon35Prepared = $siliconFlow->prepareRequest([
    'model' => 'claude-sonnet-2-2',
    'max_tokens' => 100,
    'stream' => false,
    'messages' => [['role' => 'user', 'content' => 'test']],
], $siliconFlow->models()[1], $request);
$silicon35Body = json_decode($silicon35Prepared['body'], true);
$assert($silicon35Body['model'] === 'Qwen/Qwen3.5-4B', 'SiliconFlow Qwen3.5 coding model is applied.');
$assert($registry->routeFor('siliconflow-qwen2.5-7b') === null, 'Removed Qwen2.5 alias is not silently rerouted.');
$assert($registry->routeFor('siliconflow-qwen3.5-4b') !== null, 'Qwen3.5 compatibility alias resolves.');
$siliconJson = $siliconFlow->responseAdapter($siliconFlow->models()[0], 'siliconflow-qwen3-8b')
    ->rewriteJson('{"type":"message","model":"Qwen/Qwen3-8B","content":[]}');
$assert(str_contains($siliconJson, '"model":"siliconflow-qwen3-8b"'), 'SiliconFlow response model is rewritten.');

$hyAdapter = $bai->responseAdapter($bai->models()[2], 'claude-opus-4-5');
$pending = '';
$state = $hyAdapter->initialSseState();
$partialStream = "event: message_start\n"
    . 'data: {"type":"message_start","message":{"model":"hy3"}}' . "\n\n"
    . "event: content_block_start\n"
    . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
    . "event: content_block_delta\n"
    . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"ok"}}' . "\n\n";
$rewritten = $hyAdapter->rewriteSseBuffer($pending, $partialStream, $state);
$repaired = $hyAdapter->finishSse($state);
$assert(str_contains($rewritten, '"model":"claude-opus-4-5"'), 'SSE model is rewritten.');
$assert(str_contains($repaired, 'event: content_block_stop'), 'Hy3 content block stop is repaired.');
$assert(str_contains($repaired, 'event: message_stop'), 'Hy3 message stop is repaired.');

$normalAdapter = new AnthropicResponseAdapter('claude-sonnet-4-6', false);
$normalState = $normalAdapter->initialSseState();
$normalPending = '';
$normalAdapter->rewriteSseBuffer($normalPending, $partialStream, $normalState);
$assert($normalAdapter->finishSse($normalState) === '', 'Normal streams are not force-repaired.');

$missingConfigDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'claude-code-proxy-test-' . bin2hex(random_bytes(5));
mkdir($missingConfigDirectory);
$missingBai = new BaiProvider($missingConfigDirectory);
$assert(!$missingBai->isConfigured(), 'Provider without .env is disabled.');
$missingRegistry = ProviderRegistry::fromProviders([$missingBai]);
$assert(!$missingRegistry->hasConfiguredProviders(), 'Registry has no enabled providers when configuration is missing.');
$startupRejected = false;
try {
    $testLog = $missingConfigDirectory . DIRECTORY_SEPARATOR . 'proxy.log';
    (new ProxyServer([
        'host' => '127.0.0.1',
        'port' => 8787,
    ], $missingRegistry, new ProxyLogger($testLog)))->run();
} catch (RuntimeException $exception) {
    $startupRejected = str_contains($exception->getMessage(), 'providers/b_ai/.env');
}
$assert($startupRejected, 'Startup is rejected with a safe configuration path when no provider is enabled.');
rmdir($missingConfigDirectory);

$stub = static function (string $id, string $alias): ProviderInterface {
    return new class ($id, $alias) implements ProviderInterface {
        public function __construct(private string $providerId, private string $alias)
        {
        }
        public function id(): string { return $this->providerId; }
        public function displayName(): string { return $this->providerId; }
        public function isConfigured(): bool { return true; }
        public function configurationHint(): string { return ''; }
        public function requestTimeout(int $default): int { return $default; }
        public function models(): array
        {
            return [[
                'alias' => $this->alias,
                'upstream_model' => $this->alias . '-upstream',
                'max_tokens' => 100,
            ]];
        }
        public function prepareRequest(array $payload, array $model, Request $request): array
        {
            throw new LogicException('Not used by this test.');
        }
        public function responseAdapter(array $model, string $clientModel): ResponseAdapterInterface
        {
            return new AnthropicResponseAdapter($clientModel);
        }
    };
};

$duplicateRejected = false;
try {
    ProviderRegistry::fromProviders([
        $stub('first_provider', 'claude-opus-4-6'),
        $stub('second_provider', 'claude-opus-4-6'),
    ]);
} catch (RuntimeException) {
    $duplicateRejected = true;
}
$assert($duplicateRejected, 'Duplicate model aliases are rejected.');

$duplicateIdRejected = false;
try {
    ProviderRegistry::fromProviders([
        $stub('same_provider', 'claude-sonnet-4-6'),
        $stub('same_provider', 'claude-opus-4-6'),
    ]);
} catch (RuntimeException) {
    $duplicateIdRejected = true;
}
$assert($duplicateIdRejected, 'Duplicate provider IDs are rejected.');

$healthJson = json_encode($registry->health(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert(!str_contains(strtolower((string) $healthJson), 'authorization'), 'Health output contains no authorization data.');
$assert(!str_contains(strtolower((string) $healthJson), 'api_key'), 'Health output contains no API key field.');

unlink($configuredDirectory . DIRECTORY_SEPARATOR . '.env');
rmdir($configuredDirectory);
unlink($groqDirectory . DIRECTORY_SEPARATOR . '.env');
rmdir($groqDirectory);
unlink($siliconFlowDirectory . DIRECTORY_SEPARATOR . '.env');
rmdir($siliconFlowDirectory);

fwrite(STDOUT, "OK: {$tests} assertions passed.\n");
