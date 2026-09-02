<?php

declare(strict_types=1);

namespace FreeGateway;

use RuntimeException;
use Throwable;
use Workerman\Timer;

final class CurlTransport
{
    private \CurlMultiHandle $multi;
    private mixed $timer;

    /** @var array<int, object> */
    private array $requests = [];

    public function __construct(
        private readonly int $connectTimeoutSeconds,
        private readonly int $timeoutSeconds,
        private readonly string $caBundlePath,
    )
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('The PHP cURL extension is required for upstream messages.');
        }
        if (!is_file($caBundlePath) || !is_readable($caBundlePath)) {
            throw new RuntimeException("cURL CA bundle is not readable: {$caBundlePath}");
        }
        $this->multi = curl_multi_init();
        $this->timer = Timer::add(0.01, fn (): int => $this->poll());
    }

    public function request(
        UpstreamRequest $request,
        callable $response,
        callable $progress,
        callable $success,
        callable $error,
    ): void {
        $handle = curl_init();
        if (!$handle instanceof \CurlHandle) {
            throw new RuntimeException('Unable to initialize cURL request.');
        }
        $state = (object) [
            'handle' => $handle,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'responseSent' => false,
            'response' => $response,
            'progress' => $progress,
            'success' => $success,
            'error' => $error,
        ];
        $id = spl_object_id($handle);
        $this->requests[$id] = $state;
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $request->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CAINFO => $this->caBundlePath,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function (\CurlHandle $unused, string $line) use ($state): int {
                $trimmed = trim($line);
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $trimmed, $matches)) {
                    $state->status = (int) $matches[1];
                    $state->headers = [];
                    $state->responseSent = false;
                } elseif ($trimmed === '' && $state->status > 0 && !$state->responseSent) {
                    $state->responseSent = true;
                    ($state->response)(new CurlUpstreamResponse($state->status, $state->headers, ''));
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $state->headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $unused, string $chunk) use ($state): int {
                $state->body .= $chunk;
                ($state->progress)($chunk);
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($this->multi, $handle);
    }

    private function poll(): int
    {
        do {
            $result = curl_multi_exec($this->multi, $running);
        } while ($result === CURLM_CALL_MULTI_PERFORM);

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'];
            $id = spl_object_id($handle);
            $state = $this->requests[$id] ?? null;
            unset($this->requests[$id]);
            $errno = curl_errno($handle);
            $message = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($this->multi, $handle);
            curl_close($handle);
            if (!$state instanceof \stdClass) {
                continue;
            }
            try {
                if ($info['result'] !== CURLE_OK || $errno !== CURLE_OK) {
                    ($state->error)(new RuntimeException($message !== '' ? $message : 'cURL upstream request failed.'));
                    continue;
                }
                if (!$state->responseSent) {
                    $state->status = $status;
                    ($state->response)(new CurlUpstreamResponse($status, $state->headers, ''));
                }
                ($state->success)(new CurlUpstreamResponse($status, $state->headers, $state->body));
            } catch (Throwable $exception) {
                ($state->error)($exception);
            }
        }
        return 0;
    }
}
