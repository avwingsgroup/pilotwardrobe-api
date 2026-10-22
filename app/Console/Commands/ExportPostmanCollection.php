<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Builds a Postman v2.1 collection (also importable by Bruno and Insomnia) from the exported
 * OpenAPI document. Requests are grouped by resource, authenticate with `{{token}}`, send a fresh
 * Idempotency-Key on mutations, and the login request stores the bearer token automatically.
 *
 *   php artisan scramble:export --path=docs/openapi.json && php artisan dev:postman
 */
final class ExportPostmanCollection extends Command
{
    protected $signature = 'dev:postman {--openapi=docs/openapi.json} {--out=docs/postman/pilotwardrobe.postman_collection.json}';

    protected $description = 'Generate a Postman collection from the OpenAPI document.';

    public function handle(): int
    {
        $spec = json_decode((string) file_get_contents(base_path($this->option('openapi'))), true, flags: JSON_THROW_ON_ERROR);

        $folders = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                $folders[$this->folderFor($path)][] = $this->request($path, $method, $operation);
            }
        }
        ksort($folders);

        $collection = [
            'info' => [
                'name' => 'Pilotwardrobe API v1',
                'description' => 'Generated from docs/openapi.json by `php artisan dev:postman`. Set `baseUrl`, then run Auth / POST /auth/login (stores `token`).',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]],
            'variable' => [
                ['key' => 'baseUrl', 'value' => 'http://localhost:8000/api/v1'],
                ['key' => 'token', 'value' => ''],
            ],
            'item' => array_map(
                fn (string $name, array $items) => ['name' => $name, 'item' => $items],
                array_keys($folders),
                array_values($folders),
            ),
        ];

        $out = base_path($this->option('out'));
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0755, true);
        }
        file_put_contents($out, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info('Wrote '.$this->option('out'));

        return self::SUCCESS;
    }

    private function folderFor(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== '' && ! str_starts_with($s, '{')));
        $first = $segments[0] ?? 'root';

        return $first === 'admin' ? 'Admin / '.Str::headline($segments[1] ?? 'overview') : Str::headline($first);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function request(string $path, string $method, array $operation): array
    {
        $postmanPath = preg_replace('/\{(\w+)\}/', ':$1', $path) ?? $path;
        $segments = array_values(array_filter(explode('/', $postmanPath), fn ($s) => $s !== ''));

        $query = [];
        $variables = [];
        foreach ($operation['parameters'] ?? [] as $parameter) {
            if (($parameter['in'] ?? null) === 'query') {
                $query[] = ['key' => $parameter['name'], 'value' => '', 'disabled' => ! ($parameter['required'] ?? false)];
            } elseif (($parameter['in'] ?? null) === 'path') {
                $variables[] = ['key' => $parameter['name'], 'value' => ''];
            }
        }

        $headers = [['key' => 'Accept', 'value' => 'application/json']];
        if ($method !== 'get') {
            $headers[] = ['key' => 'Idempotency-Key', 'value' => '{{$guid}}'];
        }

        $request = [
            'method' => strtoupper($method),
            'header' => $headers,
            'url' => [
                'raw' => '{{baseUrl}}/'.implode('/', $segments).($query ? '?'.implode('&', array_map(fn ($q) => $q['key'].'=', $query)) : ''),
                'host' => ['{{baseUrl}}'],
                'path' => $segments,
                'query' => $query,
                'variable' => $variables,
            ],
            'description' => trim(($operation['summary'] ?? '')."\n\n".($operation['description'] ?? '')),
        ];

        $schema = $operation['requestBody']['content']['application/json']['schema']
            ?? $operation['requestBody']['content']['multipart/form-data']['schema'] ?? null;
        if (isset($operation['requestBody']['content']['multipart/form-data'])) {
            $request['body'] = ['mode' => 'formdata', 'formdata' => array_map(
                fn (string $key, array $property) => ['key' => $key, 'type' => ($property['format'] ?? null) === 'binary' ? 'file' : 'text', 'value' => ''],
                array_keys($schema['properties'] ?? []),
                array_values($schema['properties'] ?? []),
            )];
        } elseif ($schema !== null) {
            $request['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($this->example($schema), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        $item = ['name' => strtoupper($method).' '.$path, 'request' => $request];
        if ($path === '/auth/login' && $method === 'post') {
            $item['event'] = [[
                'listen' => 'test',
                'script' => ['type' => 'text/javascript', 'exec' => [
                    'const body = pm.response.json();',
                    'if (body.data && body.data.access_token) { pm.collectionVariables.set("token", body.data.access_token); }',
                ]],
            ]];
            $item['request']['body']['raw'] = json_encode(['email' => 'pilot@example.com', 'password' => 'Password1'], JSON_PRETTY_PRINT);
        }

        return $item;
    }

    /** @param array<string, mixed> $schema */
    private function example(array $schema): mixed
    {
        $type = $schema['type'] ?? 'object';
        if (is_array($type)) {
            $type = array_values(array_diff($type, ['null']))[0] ?? 'string';
        }

        return match ($type) {
            'object' => (object) array_map(fn ($property) => $this->example($property), $schema['properties'] ?? []),
            'array' => isset($schema['items']) ? [$this->example($schema['items'])] : [],
            'integer' => $schema['example'] ?? 0,
            'number' => $schema['example'] ?? 0,
            'boolean' => $schema['example'] ?? false,
            default => $schema['example'] ?? ($schema['enum'][0] ?? ''),
        };
    }
}
