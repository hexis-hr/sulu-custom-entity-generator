<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services
        ->load('Hexis\SuluCustomEntityGeneratorBundle\\', dirname(__DIR__, 2) . '/')
        ->exclude([
            dirname(__DIR__, 2) . '/DependencyInjection/',
            dirname(__DIR__, 2) . '/Resources/',
        ]);
};
