<?php

/*
 * Payload compatibility audit: replays every sample in docs/api-payloads/*.md against the real
 * application (in-process HTTP kernel, seeded dev database), inside a transaction that is rolled
 * back, and diffs response key paths against the sample. Output: JSON report on stdout.
 * Run from the repo root against the seeded dev database (php artisan migrate:fresh --seed):
 *   php scripts/payload-audit.php > storage/app/payload-audit.json
 */

use App\Models\Listing;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(HttpKernel::class);
$app->make(ConsoleKernel::class)->bootstrap();

// ---------------------------------------------------------------- parse samples
$samples = [];
foreach (glob('docs/api-payloads/*.md') as $file) {
    $md = file_get_contents($file);
    $parts = preg_split('/^### /m', $md);
    array_shift($parts);
    foreach ($parts as $part) {
        $title = trim(strtok($part, "\n"));
        if (! preg_match('/^`(GET|POST|PUT|PATCH|DELETE) ([^`]+)`(?: · as (?:`([^`]+)`|Visitor))?/m', $part, $m)) {
            continue;
        }
        $request = null;
        if (preg_match('/Request[^\n]*:\s*\n+```json\n(.*?)\n```/s', $part, $rq)) {
            $request = json_decode($rq[1], true);
        }
        $response = null;
        $status = null;
        if (preg_match('/Response `(\d{3})`:\s*\n+```json\n(.*?)\n```/s', $part, $rs)) {
            $status = (int) $rs[1];
            $response = json_decode($rs[2], true);
        }
        $samples[] = [
            'file' => basename($file), 'title' => $title, 'method' => $m[1], 'path' => $m[2],
            'actor' => $m[3] ?? null, 'request' => $request, 'status' => $status, 'response' => $response,
        ];
    }
}

// ---------------------------------------------------------------- helpers
$tokens = [];
function bearer(?string $email): array
{
    global $tokens;
    if ($email === null) {
        return [];
    }
    if (! isset($tokens[$email])) {
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return ['__missing_user' => $email];
        }
        $tokens[$email] = $user->createToken('payload-audit')->plainTextToken;
    }

    return ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens[$email]];
}

function call(string $method, string $uri, ?string $actor, ?array $body = null): array
{
    global $kernel;
    $server = array_merge(['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.9.'.random_int(0, 255).'.'.random_int(1, 254)], bearer($actor));
    if ($method !== 'GET') {
        $server['HTTP_IDEMPOTENCY_KEY'] = (string) Str::uuid();
    }
    $request = Request::create('/api/v1'.$uri, $method, [], [], [], $server, $body !== null ? json_encode($body) : null);
    app()->instance('request', $request);
    auth()->forgetGuards();
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $json = json_decode((string) $response->getContent(), true);

    return [$response->getStatusCode(), $json];
}

/** Paths whose value is null or an empty list (children cannot be verified). */
function nullish(mixed $value, string $prefix = ''): array
{
    $out = [];
    if ($value === null || $value === []) {
        return [$prefix];
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $p = array_is_list($value) ? $prefix.'[]' : ($prefix === '' ? (string) $k : $prefix.'.'.$k);
            $out = array_merge($out, nullish($v, $p));
        }
    }

    return array_values(array_unique($out));
}

/** Key paths of a JSON value; list elements are merged under "[]". */
function paths(mixed $value, string $prefix = ''): array
{
    $out = [];
    if (is_array($value)) {
        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (is_string($item) && str_starts_with($item, '… ')) {
                    continue;
                }
                $out = array_merge($out, paths($item, $prefix.'[]'));
            }
        } else {
            foreach ($value as $k => $v) {
                $p = $prefix === '' ? (string) $k : $prefix.'.'.$k;
                $out[] = $p;
                $out = array_merge($out, paths($v, $p));
            }
        }
    }

    return array_values(array_unique($out));
}

// ---------------------------------------------------------------- id resolution
function resolveIds(string $path, ?string $actor, array &$ctx): ?string
{
    $slug = Listing::query()->where('slug', 'like', 'pilotwardrobe-aviator-uniform-shirt%')->value('slug');
    $path = preg_replace('#/listings/pilotwardrobe-aviator-uniform-shirt(?=/|$|\?)#', '/listings/'.$slug, $path);

    return preg_replace_callback('#/(?:(lst|ord|off|swp|cnv|dsp|ntf|fit|usr|rep|po|kyc|role|hs)_[A-Za-z0-9_]+|(PW)-[0-9\-]+)#', function ($m) use ($actor, &$ctx) {
        $id = $ctx['resolve']($m[1] !== '' ? $m[1] : ($m[2] ?? ''), $m[0], $actor);
        if ($id === null) {
            throw new RuntimeException('unresolved '.$m[0]);
        }

        return '/'.$id;
    }, $path);
}

$report = [];
$fixtures = require __DIR__.'/payload-audit-fixtures.php';

DB::beginTransaction();
try {
    $ctx = $fixtures();
    foreach ($samples as $sample) {
        $row = ['file' => $sample['file'], 'title' => $sample['title'], 'method' => $sample['method'], 'path' => $sample['path'], 'actor' => $sample['actor'], 'expected_status' => $sample['status']];
        try {
            $uri = resolveIds($sample['path'], $sample['actor'], $ctx);
            $body = $sample['request'];
            if ($body !== null && isset($ctx['body'])) {
                $body = $ctx['body']($sample, $body);
            }
            bearer($sample['actor']); // token outside the savepoint so it survives the rollback
            DB::beginTransaction(); // savepoint per sample
            [$status, $json] = call($sample['method'], $uri, $sample['actor'], $body);
            DB::rollBack();
            $row['uri'] = $uri;
            $row['status'] = $status;
            if ($sample['response'] !== null && is_array($json)) {
                $expected = paths($sample['response']);
                $actual = paths($json);
                $empty = nullish($json);
                $missing = array_values(array_diff($expected, $actual));
                $row['missing'] = [];
                $row['unverified'] = [];
                foreach ($missing as $path) {
                    $covered = false;
                    foreach ($empty as $e) {
                        if ($e !== '' && ($path === $e || str_starts_with($path, $e.'.') || str_starts_with($path, $e.'[]'))) {
                            $covered = true;
                            break;
                        }
                    }
                    $row[$covered ? 'unverified' : 'missing'][] = $path;
                }
                $row['extra'] = array_values(array_diff($actual, $expected));
            }
            if ($status >= 400) {
                $row['error'] = ($json['code'] ?? '').' '.($json['message'] ?? '').(isset($json['errors']) ? ' '.json_encode($json['errors']) : '');
            }
        } catch (Throwable $e) {
            while (DB::transactionLevel() > 1) {
                DB::rollBack();
            }
            $row['status'] = 'skipped';
            $row['error'] = $e->getMessage();
        }
        $report[] = $row;
    }
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
