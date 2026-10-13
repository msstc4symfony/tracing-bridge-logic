<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Test\Fixtures;

use Msstc4Symfony\LogicBundle\Application\Action\ActionInterface;

/**
 * @implements ActionInterface<EchoOutput>
 */
final class EchoAction implements ActionInterface
{
    public function __invoke(EchoInput $input): EchoOutput
    {
        return new EchoOutput($input->text);
    }
}
