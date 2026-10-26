<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Test\Integration;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\LogicBundle\Application\Logging\LoggingMiddleware;
use Msstc4Symfony\LogicBundle\Application\Pipeline\ActionMiddlewareInterface;
use Msstc4Symfony\LogicBundle\Application\Pipeline\MiddlewareChainInterface;
use Msstc4Symfony\TracingBridgeLogic\Infrastructure\Tracing\TracingMiddleware;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\EchoAction;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\EchoInput;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\ExceptionHandlerSnapshot;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\FailingAction;
use Msstc4Symfony\TracingBridgeLogic\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\Baggage;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceContext;
use Msstc4Symfony\TracingBundle\Domain\ValueObject\TraceParent;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
final class KernelBridgeTest extends TestCase
{
    private const RECEIVED = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private TestKernel $kernel;

    /** @var list<string> */
    private array $bundleDeprecations = [];

    private ExceptionHandlerSnapshot $exceptionHandlers;

    #[Override]
    protected function setUp(): void
    {
        // trigger_deprecation() silences its notice with @, which PHPUnit ignores; record it directly.
        set_error_handler(function (int $level, string $message): bool {
            if (str_contains($message, 'msstc4symfony/')) {
                $this->bundleDeprecations[] = $message;
            }

            return false;
        }, E_USER_DEPRECATED);
        (new Filesystem())->remove(TestKernel::cacheRoot());
        $this->exceptionHandlers = ExceptionHandlerSnapshot::take();
        $this->kernel = new TestKernel('test', false);
        $this->kernel->boot();
    }

    #[Override]
    protected function tearDown(): void
    {
        restore_error_handler();
        // Guarded so that a failing setUp() is reported instead of an uninitialised property.
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }

        // FrameworkBundle::boot() on symfony/error-handler before 7.4.17 leaves its exception handler
        // installed when another error handler is active, which PHPUnit reports as risky.
        if (isset($this->exceptionHandlers)) {
            $this->exceptionHandlers->restore();
        }

        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testActionLogsCarryTheActionsSpanInTheUnitsTrace(): void
    {
        $received = TraceParent::fromHeader(self::RECEIVED);
        self::assertInstanceOf(TraceParent::class, $received);
        $this->trace()->replace(TraceContext::start()->continuing($received, null, Baggage::empty()));
        $unit = $this->trace()->current()->traceParent;
        $action = $this->container()->get(EchoAction::class);
        self::assertIsCallable($action);

        $action(new EchoInput());

        $records = $this->actionLogExtras();
        self::assertCount(2, $records);
        [$start, $finish] = $records;
        self::assertSame($start, $finish);
        self::assertSame($unit->traceId, $start['trace_id']);
        self::assertSame($unit->spanId, $start['parent_span_id']);
        self::assertNotSame($unit->spanId, $start['span_id']);
        self::assertSame($unit, $this->trace()->current()->traceParent);
        self::assertSame($received->spanId, $this->trace()->current()->parentSpanId);
        self::assertSame([], $this->bundleDeprecations);
    }

    public function testAFailingActionLogsInItsSpanAndTheUnitsTraceComesBack(): void
    {
        $unit = $this->trace()->current()->traceParent;
        $action = $this->container()->get(FailingAction::class);
        self::assertIsCallable($action);

        try {
            $action(new EchoInput());
            self::fail('The Action must throw.');
        } catch (RuntimeException) {
        }

        $records = $this->actionLogExtras();
        self::assertCount(2, $records);
        self::assertSame($unit->spanId, $records[1]['parent_span_id']);
        self::assertSame($unit, $this->trace()->current()->traceParent);
        self::assertNull($this->trace()->current()->parentSpanId);
    }

    public function testTheMiddlewareRunsOutsideLogging(): void
    {
        $classes = array_map(static fn (object $middleware): string => $middleware::class, $this->middlewares());

        $tracing = array_search(TracingMiddleware::class, $classes, true);
        $logging = array_search(LoggingMiddleware::class, $classes, true);
        self::assertIsInt($tracing);
        self::assertIsInt($logging);
        self::assertLessThan($logging, $tracing);
    }

    /**
     * @return list<array{trace_id: mixed, span_id: mixed, parent_span_id: mixed}>
     */
    private function actionLogExtras(): array
    {
        $handler = $this->container()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $extras = [];
        foreach ($handler->getRecords() as $record) {
            if (str_contains($record->message, 'application action')) {
                $extras[] = [
                    'trace_id' => $record->extra['trace_id'] ?? null,
                    'span_id' => $record->extra['span_id'] ?? null,
                    'parent_span_id' => $record->extra['parent_span_id'] ?? null,
                ];
            }
        }

        return $extras;
    }

    private function trace(): TraceContextHolderInterface
    {
        $trace = $this->container()->get(TraceContextHolderInterface::class);
        self::assertInstanceOf(TraceContextHolderInterface::class, $trace);

        return $trace;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @return list<ActionMiddlewareInterface>
     */
    private function middlewares(): array
    {
        if (!interface_exists(MiddlewareChainInterface::class)) {
            self::markTestSkipped('logic-bundle exposes the middleware order from 2.1 on.');
        }

        $action = $this->container()->get(EchoAction::class);
        self::assertInstanceOf(MiddlewareChainInterface::class, $action);

        return $action->middlewares();
    }
}
