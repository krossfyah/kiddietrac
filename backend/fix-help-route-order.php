<?php
// v22p69: fix /help/dashboard 404 — move dashboard route BEFORE /help/{slug}
$file = __DIR__ . '/routes/api.php';
$src = file_get_contents($file);

// Strategy: remove the v22p68 trailing append block, and re-insert dashboard + analytics
// + featured routes BEFORE the /help/{slug} route (which is in the auth:sanctum group).
// /help/{slug}/view and /help/{slug}/feedback can keep their position (different verbs).

// Remove the existing trailing block
$pattern = "/\/\* v22p68 help routes \*\/\nRoute::prefix\('v1'\)->middleware\('auth:sanctum'\)->group\(function \(\) \{[^}]+\}\);\n/s";
$src = preg_replace($pattern, '', $src, 1);

// Find the /help/{slug} GET route and insert dashboard/analytics/featured routes BEFORE it
$marker = "Route::get('help/{slug}', [HelpController::class, 'show']);";
if (!str_contains($src, $marker)) {
    echo "ERROR: marker /help/{slug} not found\n";
    exit(1);
}

$beforeBlock = <<<'PHP'
    /* v22p69: dashboard + analytics + featured + view + feedback (must come before /help/{slug}) */
    Route::get('help/dashboard', [HelpController::class, 'dashboard']);
    Route::get('help/analytics', [HelpController::class, 'analytics']);
    Route::post('help/featured', [HelpController::class, 'pinFeatured']);
    Route::delete('help/featured/{slug}', [HelpController::class, 'unpinFeatured']);
    Route::post('help/{slug}/view', [HelpController::class, 'trackView']);
    Route::post('help/{slug}/feedback', [HelpController::class, 'feedback']);

    PHP;

$src = str_replace($marker, $beforeBlock . $marker, $src);

file_put_contents($file, $src);
echo "  ✓ moved /help/dashboard + analytics + featured + view + feedback BEFORE /help/{slug}\n";
