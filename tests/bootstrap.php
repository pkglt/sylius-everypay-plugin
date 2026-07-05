<?php

declare(strict_types=1);

// The test application configures asset packages with json_manifest_path and
// Encore builds, but no frontend build ever runs here — seed empty manifests
// so server-side page rendering does not trip over missing files.
$publicBuildDir = __DIR__ . '/../vendor/sylius/test-application/public/build';
foreach (['default', 'shop', 'admin', 'app/admin', 'app/shop'] as $build) {
    $dir = $publicBuildDir . '/' . $build;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    foreach (['manifest.json' => '{}', 'entrypoints.json' => '{"entrypoints":{}}'] as $file => $contents) {
        if (!file_exists($dir . '/' . $file)) {
            file_put_contents($dir . '/' . $file, $contents);
        }
    }
}

// Sylius encrypts gateway configs at rest; the test application ships no
// encryption key, so generate one on first run (same as sylius:payment:generate-key).
$encryptionKeyPath = __DIR__ . '/../vendor/sylius/test-application/config/encryption/key';
if (!file_exists($encryptionKeyPath)) {
    if (!is_dir(dirname($encryptionKeyPath))) {
        mkdir(dirname($encryptionKeyPath), 0777, true);
    }
    \ParagonIE\Halite\KeyFactory::save(\ParagonIE\Halite\KeyFactory::generateEncryptionKey(), $encryptionKeyPath);
}

require __DIR__ . '/../vendor/sylius/test-application/config/bootstrap.php';
