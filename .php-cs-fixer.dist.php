<?php

declare(strict_types=1);

// Existing application formatting is separate from this RC11 integration lot.
$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/tests/Fixtures/ObjectStorage',
        __DIR__.'/tools',
    ])
    ->append([
        __DIR__.'/tests/Integration/ObjectStorageTest.php',
        __DIR__.'/tests/Integration/ObjectStorageMessengerTest.php',
        __FILE__,
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
