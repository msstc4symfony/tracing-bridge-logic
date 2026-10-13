<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Test\Integration;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MakeBundleServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'Msstc4Symfony\\SkeletonBundle\\')) {
                $definition->setPublic(true);
            }
        }
    }
}
