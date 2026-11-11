<?php

declare(strict_types=1);

/**
 * Test bootstrap. The kernel's `vendor/autoload.php` is required (LetMigrate and
 * Ground ride on it); the sibling Authorization plugin is resolved from its
 * checkout so the policy engine under test is the real one.
 *
 *   1. HKM_KERNEL_PATH   — an explicit checkout
 *   2. vendor/autoload.php
 *   3. ../../../PHP      — the conventional workspace layout
 */

$pluginRoot = \dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $map = [
        'Tests\\Plugins\\Teams\\'  => $pluginRoot . '/tests/',
        'Plugins\\Teams\\'         => $pluginRoot . '/',
        'Plugins\\Authorization\\' => \dirname($pluginRoot) . '/hkm-plugin-authorization/',
    ];

    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $base . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});

$kernelRoot = getenv('HKM_KERNEL_PATH') ?: null;

if ($kernelRoot === null && is_file($pluginRoot . '/vendor/autoload.php')) {
    require_once $pluginRoot . '/vendor/autoload.php';
    $kernelRoot = false;
}

if ($kernelRoot !== false) {
    $kernelRoot ??= \dirname($pluginRoot, 3) . '/PHP';

    if (!is_file($kernelRoot . '/vendor/autoload.php')) {
        fwrite(STDERR, "\nCannot find the kernel's vendor/autoload.php at {$kernelRoot}. Set HKM_KERNEL_PATH or run composer install.\n\n");
        exit(1);
    }

    require_once $kernelRoot . '/vendor/autoload.php';

    if (is_file($kernelRoot . '/src/Kernel/Support/helpers.php')) {
        require_once $kernelRoot . '/src/Kernel/Support/helpers.php';
    }
}

// The Authorization engine's global helper functions (its composer "files" entry).
$authz = \dirname($pluginRoot) . '/hkm-plugin-authorization/Engine/functions.php';
if (is_file($authz)) {
    require_once $authz;
}
