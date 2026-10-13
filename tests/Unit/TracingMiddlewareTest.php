<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Test\Unit;

use Msstc4Symfony\LogicBundle\Application\Action\InputInterface;
use Msstc4Symfony\LogicBundle\Application\Action\OutputInterface;
use Msstc4Symfony\LogicBundle\Application\Pipeline\ActionContext;
use Msstc4Symfony\LogicBundle\Application\Pipeline\Attribute\AsActionMiddleware;
use Msstc4Symfony\TracingBridgeLogic\Infrastructure\Tracing\TracingMiddleware;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\EchoAction;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\EchoInput;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\EchoOutput;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\FailingAction;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\InnerAction;
use Msstc4Symfony\TracingBundle\Storage\RequestIdService;
use Msstc4Symfony\TracingBundle\Storage\TraceContext;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareTest extends TestCase
{
    private const string RECEIVED = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private RequestIdService $trace;

    private TracingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->trace = new RequestIdService('shop', 'api');
        $this->middleware = new TracingMiddleware($this->trace);
    }

    public function testTheActionRunsInAChildSpanOfTheUnitAndKeepsTheRestOfTheTrace(): void
    {
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);
        $state = TraceState::fromHeaders(['rojo=1']);
        $this->trace->setRequestId('abc')->setRequestFrom('gateway:api')->continueTrace($received, $state);
        $unit = $this->trace->snapshot();
        $output = new EchoOutput('ok');

        $inside = null;
        $result = $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), function (InputInterface $input) use (&$inside, $output): OutputInterface {
            $inside = $this->trace->snapshot();

            return $output;
        });

        self::assertSame($output, $result);
        self::assertInstanceOf(TraceContext::class, $inside);
        self::assertInstanceOf(TraceParent::class, $unit->traceParent);
        self::assertInstanceOf(TraceParent::class, $inside->traceParent);
        self::assertSame($unit->traceParent->traceId, $inside->traceParent->traceId);
        self::assertSame($unit->traceParent->flags, $inside->traceParent->flags);
        self::assertNotSame($unit->traceParent->parentId, $inside->traceParent->parentId);
        self::assertSame($unit->traceParent->parentId, $inside->parentSpanId);
        self::assertSame(
            [$unit->runtimeId, 'abc', 'gateway:api', $received, $state],
            [$inside->runtimeId, $inside->requestId, $inside->requestFrom, $inside->remoteTraceParent, $inside->traceState],
        );
        self::assertEquals($unit, $this->trace->snapshot());
    }

    public function testTheUnitsTraceIsRestoredWhenTheActionThrows(): void
    {
        $this->trace->getTraceParent();
        $this->trace->getRequestId();

        $before = $this->trace->snapshot();
        $failure = new RuntimeException('boom');

        try {
            $this->middleware->handle(new EchoInput(), ActionContext::of(FailingAction::class), static fn (InputInterface $input): never => throw $failure);
            self::fail('The exception must be rethrown.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertEquals($before, $this->trace->snapshot());
    }

    public function testIdsNotCreatedYetAreSharedByTheActionAndTheUnit(): void
    {
        $inside = null;
        $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), function (InputInterface $input) use (&$inside): OutputInterface {
            $inside = [$this->trace->getRequestId(), $this->trace->getTraceParent()->traceId];

            return new EchoOutput('ok');
        });

        self::assertSame([$this->trace->getRequestId(), $this->trace->getTraceParent()->traceId], $inside);
    }

    public function testANestedActionGetsAChildOfTheCallersSpan(): void
    {
        $unit = $this->trace->getTraceParent();
        $spans = [];
        $inner = function (InputInterface $input) use (&$spans): OutputInterface {
            $spans['outer'] = $this->trace->snapshot();
            $this->middleware->handle($input, ActionContext::of(InnerAction::class), function (InputInterface $input) use (&$spans): OutputInterface {
                $spans['inner'] = $this->trace->snapshot();

                return new EchoOutput('inner');
            });
            $spans['outer after inner'] = $this->trace->snapshot();

            return new EchoOutput('outer');
        };

        $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), $inner);

        $outer = $spans['outer'] ?? null;
        $nested = $spans['inner'] ?? null;
        self::assertInstanceOf(TraceContext::class, $outer);
        self::assertInstanceOf(TraceContext::class, $nested);
        self::assertInstanceOf(TraceParent::class, $outer->traceParent);
        self::assertInstanceOf(TraceParent::class, $nested->traceParent);
        self::assertSame($unit->parentId, $outer->parentSpanId);
        self::assertSame($outer->traceParent->parentId, $nested->parentSpanId);
        self::assertSame($unit->traceId, $nested->traceParent->traceId);
        self::assertNotContains($nested->traceParent->parentId, [$unit->parentId, $outer->traceParent->parentId]);
        self::assertEquals($outer, $spans['outer after inner'] ?? null);
    }

    public function testOutgoingCallsFromTheActionCarryItsSpan(): void
    {
        $sent = null;
        $span = null;
        $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), function (InputInterface $input) use (&$sent, &$span): OutputInterface {
            $sent = $this->trace->createOutgoingTraceParent();
            $span = $this->trace->getTraceParent();

            return new EchoOutput('ok');
        });

        self::assertInstanceOf(TraceParent::class, $span);
        self::assertSame($span->toHeader(), $sent?->toHeader());
        self::assertNotSame($this->trace->getTraceParent()->parentId, $span->parentId);
    }

    public function testSupportsEveryActionAtPriority600(): void
    {
        self::assertTrue($this->middleware->supports(ActionContext::of(EchoAction::class)));
        self::assertSame(600, TracingMiddleware::PRIORITY);
        $attribute = new ReflectionClass(TracingMiddleware::class)->getAttributes(AsActionMiddleware::class)[0] ?? null;
        self::assertNotNull($attribute);
        self::assertSame(600, $attribute->newInstance()->priority);
    }
}
