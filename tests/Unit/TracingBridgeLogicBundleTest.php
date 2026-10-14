<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Test\Unit;

use Msstc4Symfony\TracingBridgeLogic\TracingBridgeLogicBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TracingBridgeLogicBundle::class)]
final class TracingBridgeLogicBundleTest extends TestCase
{
    public function testExtensionAliasFollowsTheFamilyNaming(): void
    {
        self::assertSame('msstc4symfony_tracing_bridge_logic', (new TracingBridgeLogicBundle())->getContainerExtension()?->getAlias());
    }
}
