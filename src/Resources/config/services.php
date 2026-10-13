<?php

declare(strict_types=1);

use Msstc4Symfony\TracingBridgeLogic\Infrastructure\Tracing\TracingMiddleware;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set(TracingMiddleware::class);
};
