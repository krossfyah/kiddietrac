<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * One chat attachment pipeline, for both kinds of thread.
 *
 * This logic was written for family chat and carries a fix that is not obvious and was
 * expensive to find: a voice note recorded in a browser is audio, but the webm/ogg
 * CONTAINER is content-detected server-side as video/webm, so a desktop-recorded voice
 * note gets rejected by a naive `image/* or audio/*` check and, if it survives that,
 * renders as a video element instead of a player.
 *
 * Copying that into the staff-chat controller would have created a second copy destined
 * to drift from this one — which is exactly how this platform ended up with two care-log
 * tables and two timekeeping tables. It lives here instead, and both controllers call it.
 */
final class ChatAttachments
{
    /** @return array<int,array{url:string,mime:string,name:string,size:int}> */
    public static function extract(Request $request, string $field = 'attachment'): array
    {
        if (! $request->hasFile($field)) {
            return [];
        }

        $request->validate([
            // Images OR audio (voice notes). Max 10 MB.
            $field => ['file', 'max:10240', function ($attr, $value, $fail) {
                $m = strtolower((string) $value->getMimeType());
                $c = strtolower((string) $value->getClientMimeType());
                // See the class note: browser voice notes report as a video container.
                $audioContainer = in_array($m, ['video/webm', 'video/ogg', 'application/ogg'], true)
                    || str_starts_with($c, 'audio/');
                if (! str_starts_with($m, 'image/') && ! str_starts_with($m, 'audio/') && ! $audioContainer) {
                    $fail('Only images or audio are allowed.');
                }
            }],
        ]);

        $file = $request->file($field);
        $path = $file->store('chat-attachments', 'public');
        $detected = strtolower((string) $file->getMimeType());
        $client = strtolower((string) $file->getClientMimeType());
        $name = (string) $file->getClientOriginalName();

        // Normalise a voice note to an audio/* mime so the client renders a player.
        $isAudio = str_starts_with($client, 'audio/')
            || in_array($detected, ['video/webm', 'video/ogg', 'application/ogg'], true)
            || (bool) preg_match('/\.(webm|ogg|oga|m4a|mp3|wav|aac|opus)$/i', $name);

        $mime = str_starts_with($detected, 'image/')
            ? $detected
            : ($isAudio ? (str_starts_with($client, 'audio/') ? $client : 'audio/webm') : $detected);

        return [[
            'url' => '/storage/' . $path,
            'mime' => $mime,
            'name' => $name,
            'size' => $file->getSize(),
        ]];
    }
}
