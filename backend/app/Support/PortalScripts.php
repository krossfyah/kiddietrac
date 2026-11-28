<?php

namespace App\Support;

/**
 * The portal's script SOURCES (parent-portal/js/*.js), without build output.
 *
 * Since 2026-11-28 (portal speed step 4) deploy builds js/ktb-<n>-<hash>.js bundles from these files
 * (backend/build/kt-bundle.cjs). A bundle repeats every file it holds, so a static check that globbed
 * js/*.js reported each finding twice, and a rule keyed on a file name (kt-badges.js may draw tile badges)
 * fired on the bundle that contains it. Every check reads the sources, never the bundles.
 */
class PortalScripts
{
    public const BUNDLE = '/(^|\/)ktb-\d+-[0-9a-f]{12}\.js$/';

    /** @return string[] absolute paths of parent-portal/js/*.js minus the bundles */
    public static function sources(string $root): array
    {
        return array_values(array_filter(glob(rtrim($root, '/') . '/js/*.js') ?: [], function ($f) {
            return !preg_match(self::BUNDLE, $f);
        }));
    }
}
