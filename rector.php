<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/lib',
    ]);

    $rectorConfig->skip([
        __DIR__ . '/src/*/tests/*Test.php',
        __DIR__ . '/src/*/web/assets/*.php',
    ]);

    $rectorConfig->sets([
        \Rector\Set\ValueObject\SetList::CODE_QUALITY,
        LevelSetList::UP_TO_PHP_82
    ]);
};