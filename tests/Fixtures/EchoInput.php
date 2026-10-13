<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Test\Fixtures;

use Msstc4Symfony\LogicBundle\Application\Action\InputInterface;

final readonly class EchoInput implements InputInterface
{
    public function __construct(public string $text = 'ok')
    {
    }
}
