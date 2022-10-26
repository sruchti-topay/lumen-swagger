<?php

namespace RonasIT\Support\AutoDoc\Tests\PhpUnitExtensions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use PHPUnit\Runner\AfterLastTestHook;
use RonasIT\Support\AutoDoc\Services\SwaggerService;

/**
 * This interface, as well as the associated mechanism for extending PHPUnit,
 * will be removed in PHPUnit 10. There is no alternative available in this
 * version of PHPUnit.
 *
 * @see https://github.com/sebastianbergmann/phpunit/issues/4676
 */
class SwaggerExtension implements AfterLastTestHook
{
    public function executeAfterLastTest(): void
    {
        $this->createApplication();

        app(SwaggerService::class)->saveProductionData();
    }

    protected function createApplication(): Container
    {
        $app = require __DIR__ . '/../../../../../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
