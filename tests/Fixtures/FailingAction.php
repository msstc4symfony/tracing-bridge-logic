<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Test\Fixtures;

use Msstc4Symfony\LogicBundle\Application\Action\ActionInterface;
use RuntimeException;

/**
 * @implements ActionInterface<EchoOutput>
 */
final class FailingAction implements ActionInterface
{
    public function __invoke(EchoInput $input): EchoOutput
    {
        throw new RuntimeException('boom');
    }
}
