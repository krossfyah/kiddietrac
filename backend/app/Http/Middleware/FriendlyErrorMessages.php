<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * NO RAW ERROR MESSAGES, EVER (Anthony, 2026-10-22: "no raw error messages ever on the
 * portal - full sweep").
 *
 * About 390 places in the controllers answer a failure with `$e->getMessage()`. Most of
 * those exceptions are our own sentences ("This invoice is already paid"), and those are
 * left alone. But the same line also hands a person whatever PHP or MySQL said:
 * "SQLSTATE[23000]: Integrity constraint violation…", "Undefined array key "rate"",
 * "Call to a member function format() on null", a file path on the server. A screen
 * printed it as-is.
 *
 * So every JSON reply leaving the API is checked here, once. A message that reads like
 * code, not like a person, is replaced with a plain sentence and a short reference. The
 * original goes to the log under that reference, so "Ref 7F3A2C" in a screenshot leads
 * straight to the cause. Laravel's own "Server Error" (a 500 with debug off) is treated
 * the same way. Debug fields (exception, file, line, trace) are never sent.
 *
 * Validation messages ("The email field is required.") and our own sentences pass
 * through untouched: only text matching TECH below is replaced.
 */
class FriendlyErrorMessages
{
    /** Text that came from PHP, the database, the framework or the server, not from us. */
    private const TECH = '/SQLSTATE|\bSQL:\s|Integrity constraint|Column not found|Base table or view|Unknown column|Duplicate entry \'|Data truncated for column|Incorrect \w+ value:|'
        . 'Undefined (array key|variable|index|offset|property|method|constant)|Call to (undefined|a member function|private|protected)|'
        . 'Attempt to (read|assign) property|must be of type|Argument #\d|Too few arguments|Cannot (redeclare|instantiate|access (private|protected))|'
        . 'Class "[^"]+" not found|Array to string conversion|Object of class|Unsupported operand|Division by zero|syntax error, unexpected|'
        . 'Trying to access array offset|on null\b|No such file or directory|[Ff]ailed to open stream|cURL error \d|Connection refused|'
        . 'Operation timed out|Allowed memory size|Maximum execution time|Stack trace|Illuminate\\\\|Symfony\\\\|GuzzleHttp|Doctrine\\\\|'
        . 'App\\\\[A-Z]\w*\\\\|\.php(\s+on line|:\d+|\b)|PDOException|ErrorException|\bTypeError\b|\bValueError\b|ArgumentCountError|'
        . 'json_decode|htmlspecialchars\(|\/home\/\w+\/|\/vendor\/|^\s*(Internal )?Server Error\s*$|^\s*Service Unavailable\s*$|'
        // The framework's own 404/405 wording names internal routes and model classes.
        . '^The route .+ could not be found\.?$|^No query results for model|^The \w+ method is not supported for route|'
        . '^\s*(Not Found|Method Not Allowed|Too Many Requests|Forbidden|Bad Request|Unprocessable (Entity|Content))\s*$/u';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! $this->isJson($response)) {
                return $response;
            }
            $status = $response->getStatusCode();
            $body = (string) $response->getContent();
            /* A successful reply is only looked at when it is small and carries an error
               field (the `['ok' => false, 'error' => $e->getMessage()]` shape); a list of
               500 children is never decoded twice. */
            if ($status < 400 && (strlen($body) > 8192 || ! preg_match('/"(error|message|detail|reason)"\s*:\s*"/', $body))) {
                return $response;
            }
            $data = json_decode($body, true);
            if (! is_array($data)) {
                return $response;
            }

            $hidden = [];
            foreach (['message', 'error', 'detail', 'reason'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && $this->isTechnical($data[$k])) {
                    $hidden[$k] = $data[$k];
                    $data[$k] = $this->sentence($status);
                }
            }
            if (isset($data['errors']) && is_array($data['errors'])) {
                foreach ($data['errors'] as $f => $list) {
                    foreach ((array) $list as $i => $m) {
                        if (is_string($m) && $this->isTechnical($m)) {
                            $hidden['errors.' . $f] = $m;
                            if (is_array($data['errors'][$f])) {
                                $data['errors'][$f][$i] = 'This could not be saved. Please check it and try again.';
                            } else {
                                $data['errors'][$f] = 'This could not be saved. Please check it and try again.';
                            }
                        }
                    }
                }
            }
            $debug = array_intersect_key($data, array_flip(['exception', 'file', 'line', 'trace']));
            foreach (array_keys($debug) as $k) {
                unset($data[$k]);
            }

            if (! $hidden && ! $debug) {
                return $response;
            }

            // A 5xx with no message of its own still says something useful.
            if ($status >= 500 && empty($data['message'])) {
                $data['message'] = $this->sentence($status);
            }
            $ref = strtoupper(bin2hex(random_bytes(3)));
            $data['ref'] = $ref;
            if ($hidden && isset($data['message']) && is_string($data['message']) && ! str_contains($data['message'], 'Ref ')) {
                $data['message'] .= ' · Ref ' . $ref;   // " · " so kt-i18n still translates the sentence
            }
            Log::warning('Raw error hidden from the user', [
                'ref' => $ref,
                'status' => $status,
                'route' => $request->method() . ' /' . ltrim($request->path(), '/'),
                'user_id' => optional($request->user())->id,
                'original' => $hidden ?: null,
                'exception' => $debug['exception'] ?? null,
                'at' => isset($debug['file']) ? ($debug['file'] . ':' . ($debug['line'] ?? '')) : null,
            ]);

            if ($response instanceof JsonResponse) {
                $response->setData($data);
            } else {
                $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            // Never let the guard become the error.
        }

        return $response;
    }

    private function isJson(Response $response): bool
    {
        if ($response instanceof JsonResponse) {
            return true;
        }
        $type = (string) $response->headers->get('Content-Type');

        return str_contains($type, 'json') && ! method_exists($response, 'getFile');
    }

    private function isTechnical(string $text): bool
    {
        return $text !== '' && preg_match(self::TECH, $text) === 1;
    }

    private function sentence(int $status): string
    {
        if ($status === 404) {
            return 'We could not find that. It may have been removed, or the link may be out of date.';
        }
        if ($status === 405) {
            return 'That action is not available here.';
        }
        if ($status === 403) {
            return 'You do not have access to that.';
        }
        if ($status === 429) {
            return 'Too many attempts. Please wait a moment and try again.';
        }
        if ($status >= 500) {
            return 'Something went wrong on our side and we have been told about it. Please try again in a moment.';
        }

        return 'That could not be completed. Please check the details and try again.';
    }
}
