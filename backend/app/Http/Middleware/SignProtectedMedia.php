<?php

namespace App\Http\Middleware;

use App\Support\ProtectedMedia;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every protected file URL leaving the API is signed on the way out.
 *
 * More than a dozen controllers build `'/storage/' . $path` by hand, and dozens
 * of screens render whatever they are given. Changing each of them would be a
 * long edit with a long tail of misses — and a miss here is a file still readable
 * by anyone holding the link.
 *
 * Doing it in one place at the boundary means producers keep writing the plain
 * path, consumers keep using the URL they are handed, and there is exactly one
 * line to check when asking "is this actually signed?".
 *
 * Only JSON is walked, only string values, and only strings that look like a
 * protected path — the common case is a handful of comparisons per response.
 */
class SignProtectedMedia
{
    /** Guards against pathological payloads rather than legitimate ones. */
    private const MAX_DEPTH = 12;

    /** Protected paths seen in a WRITE response (raw, before signing): what the upload ledger may record. */
    private array $seen = [];
    private bool $collect = false;

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data)) {
            return $response;
        }

        $this->seen = [];
        $this->collect = ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        $response->setData($this->walk($data, 0));
        if ($this->collect && $this->seen) {
            $this->ledger($request);
        }

        return $response;
    }

    private function walk(array $node, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $depth + 1);
                continue;
            }
            if (! is_string($value) || $value === '') {
                continue;
            }
            /* Cheapest possible rejection first: almost every string in a response
               is not a path, and isProtected() does real parsing. */
            if (! str_contains($value, '/storage/') && ! str_starts_with($value, 'storage/')) {
                continue;
            }
            if (ProtectedMedia::isProtected($value)) {
                if ($this->collect && count($this->seen) < 50) {
                    $this->seen[(string) ProtectedMedia::relative($value)] = true;
                }
                $node[$key] = ProtectedMedia::sign($value);
            }
        }

        return $node;
    }

    /**
     * THE UPLOAD LEDGER (2026-11-28, App\Support\FileRefs). A file this write request has just CREATED (written in the
     * last few minutes) and handed back is the caller's agency's file: recorded, so the save that follows - the form
     * sending the new photo_url back - passes RefuseForeignFileRefs. Older files in a reply are not recorded: a reply
     * echoing a file is not evidence of who owns it.
     */
    private function ledger(Request $request): void
    {
        try {
            $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
            $agencyId = (int) $request->header('X-Active-Agency-Id');
            if (! $user || $agencyId <= 0) {
                return;
            }
            foreach (array_keys($this->seen) as $rel) {
                if ($rel !== '' && \App\Support\FileRefs::isFresh($rel)) {
                    \App\Support\FileRefs::record($rel, $agencyId, (int) $user->id, 'upload');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
