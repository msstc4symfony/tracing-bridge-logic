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
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Override;

/**
 * Outermost, so logs and outgoing calls of every other middleware carry the Action's span.
 */
#[AsActionMiddleware(priority: self::PRIORITY)]
final readonly class TracingMiddleware implements ActionMiddlewareInterface
{
    public const int PRIORITY = 600;

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
        // Both are created on first access; created inside the Action, restore() would drop them.
        $unitSpan = $this->trace->getTraceParent();
        $this->trace->getRequestId();
        $outer = $this->trace->snapshot();

        // Copies every TraceContext field: a field added to it later must be copied here too.
        $this->trace->restore(new TraceContext(
            runtimeId: $outer->runtimeId,
            requestId: $outer->requestId,
            requestFrom: $outer->requestFrom,
            traceParent: $unitSpan->child(),
            remoteTraceParent: $outer->remoteTraceParent,
            traceState: $outer->traceState,
            parentSpanId: $unitSpan->parentId,
        ));

        try {
            return $next($input);
        } finally {
            $this->trace->restore($outer);
        }
    }
}
