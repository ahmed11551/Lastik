<?php

declare(strict_types=1);

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */

$root = $argv[1] ?? __DIR__ . '/../..';
$skipped = ['vendor', 'node_modules', 'storage', '.git', 'dist', 'mid', 'html', 'database/migrations.disabled'];
$count = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getRealPath();
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);

    $skip = false;
    foreach ($skipped as $s) {
        if (str_starts_with($relative, $s)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $header = <<<'HEADER'
<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */

HEADER;

    if (str_starts_with($content, $header)) {
        continue;
    }

    $content = preg_replace('/^#!.*\R/', '', $content);
    $content = preg_replace('/^(declare\s*\([^)]*\);\s*)/', '', $content);
    $content = preg_replace('/^\s*<\?php\s*\R/', '', $content);

    $newContent = $header . ltrim($content);

    if (file_put_contents($path, $newContent) !== false) {
        $count++;
        echo "HEADER: $relative\n";
    }
}

echo "Done. Updated $count files.\n";
