<?php

declare(strict_types=1);

$firstPatchedReleaseByLine = [
    '8.3' => '8.3.36',
    '8.4' => '8.4.27',
    '8.5' => '8.5.12',
];

$runningLine = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$firstPatchedRelease = $firstPatchedReleaseByLine[$runningLine] ?? null;
$isLineReleasedAfterTheFixes = version_compare($runningLine, (string) array_key_last($firstPatchedReleaseByLine), '>');

$isPatched = $firstPatchedRelease === null
    ? $isLineReleasedAfterTheFixes
    : version_compare(PHP_VERSION, $firstPatchedRelease, '>=');

echo 'PHP ', PHP_VERSION, PHP_EOL;

if (!$isPatched) {
    $patchedReleases = array_values($firstPatchedReleaseByLine);
    $newestPatchedRelease = array_pop($patchedReleases);
    fwrite(STDERR, sprintf(
        "PHP %s lacks the fixes for CVE-2026-109618 and CVE-2026-109619, which arrived in PHP %s and %s."
        . " Upgrade to one of those or a later release on the same line.\n",
        PHP_VERSION,
        implode(', ', $patchedReleases),
        $newestPatchedRelease,
    ));
    exit(1);
}
