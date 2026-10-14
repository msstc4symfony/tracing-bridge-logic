<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Infrastructure\Tracing;

use Closure;
use Msstc4Symfony\LogicBundle\Application\Action\InputInterface;
use Msstc4Symfony\LogicBundle\Application\Action\OutputInterface;
use Msstc4Symfony\LogicBundle\Application\Pipeline\ActionContext;
use Msstc4Symfony\LogicBundle\Application\Pipeline\ActionMiddlewareInterface;
use Msstc4Symfony\LogicBundle\Application\Pipeline\Attribute\AsActionMiddleware;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Override;

/**
 * Outermost, so logs and outgoing calls of every other middleware carry the Action's span.
 */
#[AsActionMiddleware(priority: self::PRIORITY)]
final readonly class TracingMiddleware implements ActionMiddlewareInterface
{
    public const PRIORITY = 600;

    public function __construct(
        private RequestIdServiceInterface $trace,
    ) {
    }

    #[Override]
    public function supports(ActionContext $context): bool
    {
        return true;
    }

    #[Override]
    public function handle(InputInterface $input, ActionContext $context, Closure $next): OutputInterface
    {
        // Both are created on first access; created inside the Action, restore() would drop them. withChildSpan() also needs the trace.
        $this->trace->getTraceParent();
        $this->trace->getRequestId();

        $outer = $this->trace->snapshot();

        $this->trace->restore($outer->withChildSpan());

        try {
            return $next($input);
        } finally {
            $this->trace->restore($outer);
        }
    }
}
