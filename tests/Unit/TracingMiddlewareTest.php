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
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\InMemoryTraceHolder;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\InnerAction;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceState;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareTest extends TestCase
{
    private const RECEIVED = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private InMemoryTraceHolder $trace;

    private TracingMiddleware $middleware;

    #[Override]
    protected function setUp(): void
    {
        $this->trace = new InMemoryTraceHolder();
        $this->middleware = new TracingMiddleware($this->trace);
    }

    public function testTheActionRunsInAChildSpanOfTheUnitAndKeepsTheRestOfTheTrace(): void
    {
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);
        $state = TraceState::fromHeaders(['rojo=1']);
        $baggage = Baggage::fromHeaders(['msstc4symfony.caller=gateway%3Aapi']);
        $this->trace->replace($this->trace->current()->continuing($received, $state, $baggage));
        $unit = $this->trace->current();
        $output = new EchoOutput('ok');

        $inside = null;
        $result = $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), function (InputInterface $input) use (&$inside, $output): OutputInterface {
            $inside = $this->trace->current();

            return $output;
        });

        self::assertSame($output, $result);
        self::assertInstanceOf(TraceContext::class, $inside);
        self::assertSame($unit->traceParent->traceId, $inside->traceParent->traceId);
        self::assertSame($unit->traceParent->flags, $inside->traceParent->flags);
        self::assertNotSame($unit->traceParent->spanId, $inside->traceParent->spanId);
        self::assertSame($unit->traceParent->spanId, $inside->parentSpanId);
        self::assertSame(
            [$unit->runtimeId, $received, $state, $baggage],
            [$inside->runtimeId, $inside->remoteTraceParent, $inside->traceState, $inside->baggage],
        );
        self::assertSame($unit, $this->trace->current());
    }

    public function testTheUnitsContextIsRestoredWhenTheActionThrows(): void
    {
        $before = $this->trace->current();
        $failure = new RuntimeException('boom');

        try {
            $this->middleware->handle(new EchoInput(), ActionContext::of(FailingAction::class), static fn (InputInterface $input): never => throw $failure);
            self::fail('The exception must be rethrown.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame($before, $this->trace->current());
    }

    public function testANestedActionGetsAChildOfTheCallersSpan(): void
    {
        $unit = $this->trace->current();
        $seen = [];
        $outer = function (InputInterface $input) use (&$seen): OutputInterface {
            $seen['outer'] = $this->trace->current();
            $this->middleware->handle($input, ActionContext::of(InnerAction::class), function (InputInterface $input) use (&$seen): OutputInterface {
                $seen['inner'] = $this->trace->current();

                return new EchoOutput('inner');
            });
            $seen['outer after inner'] = $this->trace->current();

            return new EchoOutput('outer');
        };

        $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), $outer);

        $action = $seen['outer'] ?? null;
        $nested = $seen['inner'] ?? null;
        self::assertInstanceOf(TraceContext::class, $action);
        self::assertInstanceOf(TraceContext::class, $nested);
        self::assertSame($unit->traceParent->spanId, $action->parentSpanId);
        self::assertSame($action->traceParent->spanId, $nested->parentSpanId);
        self::assertSame($unit->traceParent->traceId, $nested->traceParent->traceId);
        self::assertNotContains($nested->traceParent->spanId, [$unit->traceParent->spanId, $action->traceParent->spanId]);
        self::assertSame($action, $seen['outer after inner'] ?? null);
    }

    public function testOutgoingCallsFromTheActionCarryItsSpanAsTheirParent(): void
    {
        $unit = $this->trace->current();
        $sent = null;
        $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), function (InputInterface $input) use (&$sent): OutputInterface {
            $sent = $this->trace->current()->outgoing('shop:api')->traceParent;

            return new EchoOutput('ok');
        });

        self::assertInstanceOf(TraceParent::class, $sent);
        self::assertSame($unit->traceParent->traceId, $sent->traceId);
        self::assertNotSame($unit->traceParent->spanId, $sent->spanId);
        self::assertSame($unit->traceParent, $this->trace->current()->outgoing('shop:api')->traceParent);
    }

    public function testANestedActionThatThrowsGivesBackTheCallersSpanThenTheUnits(): void
    {
        $unit = $this->trace->current();
        $afterInner = null;
        $action = null;
        $this->middleware->handle(new EchoInput(), ActionContext::of(EchoAction::class), function (InputInterface $input) use (&$afterInner, &$action): OutputInterface {
            $action = $this->trace->current();

            try {
                $this->middleware->handle($input, ActionContext::of(InnerAction::class), static fn (InputInterface $input): never => throw new RuntimeException('inner'));
            } catch (RuntimeException) {
            }

            $afterInner = $this->trace->current();

            return new EchoOutput('outer');
        });

        self::assertInstanceOf(TraceContext::class, $action);
        self::assertSame($action, $afterInner);
        self::assertSame($unit, $this->trace->current());
    }

    public function testSupportsEveryActionAtPriority600(): void
    {
        self::assertTrue($this->middleware->supports(ActionContext::of(EchoAction::class)));
        self::assertSame(600, TracingMiddleware::PRIORITY);
        $attribute = (new ReflectionClass(TracingMiddleware::class))->getAttributes(AsActionMiddleware::class)[0] ?? null;
        self::assertNotNull($attribute);
        self::assertSame(600, $attribute->newInstance()->priority);
    }
}
