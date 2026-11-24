<?php
/* The one that actually bit Lloydene: does consuming a reset token change ONLY the
   account that token was minted for? */

use Illuminate\Support\Facades\DB;

$E = 'kt-sweep@kiddietrac.test';

$before = DB::table('users')->where('email', $E)->orderBy('id')
    ->get(['id', 'username', 'password', 'status'])
    ->keyBy('id');

$tok = DB::table('password_resets')->where('email', $E)->whereNull('used_at')
    ->orderBy('id')->first();
$target = DB::table('users')->where('id', $tok->user_id)->first();

printf("=== consuming the token minted for %s (#%d) ===\n", $target->username, $target->id);

/* The token is stored as a sha256, so mint a fresh known one for the same account —
   the same thing forgotPassword does — rather than trying to reverse the hash. */
$plain = \Illuminate\Support\Str::random(64);
DB::table('password_resets')->where('id', $tok->id)->update([
    'token' => hash('sha256', $plain),
]);

$res = app(\App\Http\Controllers\Api\AuthController::class)->resetPassword(
    \Illuminate\Http\Request::create('/auth/reset', 'POST', [
        'email' => $E,
        'token' => $plain,
        'new_password' => 'Sweep-Changed-4417!',
    ])
);
printf("  endpoint said: %s\n", mb_substr((string) $res->getContent(), 0, 90));

$after = DB::table('users')->where('email', $E)->orderBy('id')
    ->get(['id', 'username', 'password', 'status'])->keyBy('id');

echo "\n=== which accounts changed ===\n";
foreach ($before as $id => $b) {
    $a = $after[$id];
    $changed = $b->password !== $a->password;
    printf("  #%-4s %-16s password %-10s status %s -> %s\n", $id, $b->username,
        $changed ? 'CHANGED' : 'untouched', $b->status, $a->status);
}

$changedIds = collect($before)->filter(fn ($b, $id) => $b->password !== $after[$id]->password)->keys();
printf("\n  accounts whose password moved: %s  (expected exactly [%d])\n",
    $changedIds->implode(', '), $target->id);
printf("  VERDICT: %s\n",
    ($changedIds->count() === 1 && (int) $changedIds->first() === (int) $target->id)
        ? 'correct — only the token\'s own account'
        : '*** WRONG ***');
