<?php

declare(strict_types=1);

use ClaudeCodeProxy\AnthropicResponseAdapter;
use ClaudeCodeProxy\ProviderInterface;
use ClaudeCodeProxy\ProviderRegistry;
use ClaudeCodeProxy\Providers\Bai\BaiProvider;
use ClaudeCodeProxy\ProxyLogger;
use ClaudeCodeProxy\ProxyServer;
use Workerman\Protocols\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';
$providerModule = require dirname(__DIR__) . '/providers/b_ai/BaiProvider.php';

$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$assert($providerModule instanceof BaiProvider, 'B.AI module returns BaiProvider.');

$configuredDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'claude-code-proxy-configured-test-' . bin2hex(random_bytes(5));
mkdir($configuredDirectory);
file_put_contents(
    $configuredDirectory . DIRECTORY_SEPARATOR . '.env',
    "BAI_API_KEY=test-only-key\nUPSTREAM_TIMEOUT=180\n",
);
$bai = new BaiProvider($configuredDirectory);
$assert($bai->isConfigured(), 'B.AI reads configuration from its own directory.');

$registry = ProviderRegistry::fromProviders([$bai]);
$assert(array_keys($registry->providers()) === ['b_ai'], 'Provider ID is b_ai.');
$assert(count($registry->discoveryModels()) === 3, 'Exactly three models are discovered.');
$assert($registry->routeFor('claude-sonnet-4-6') !== null, 'Known model route resolves.');
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
        public function responseAdapter(array $model, string $clientModel): AnthropicResponseAdapter
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

fwrite(STDOUT, "OK: {$tests} assertions passed.\n");
