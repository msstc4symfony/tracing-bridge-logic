<?php

declare(strict_types=1);

namespace Msstc4Symfony\TracingBridgeLogic\Test\Integration;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\LogicBundle\Application\Logging\LoggingMiddleware;
use Msstc4Symfony\LogicBundle\Application\Pipeline\ActionPipeline;
use Msstc4Symfony\TracingBridgeLogic\Infrastructure\Tracing\TracingMiddleware;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\EchoAction;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\EchoInput;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\ExceptionHandlerSnapshot;
use Msstc4Symfony\TracingBridgeLogic\Test\Fixtures\FailingAction;
use Msstc4Symfony\TracingBridgeLogic\Test\Integration\Kernel\TestKernel;
use Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface;
use Msstc4Symfony\TracingBundle\Storage\W3c\TraceParent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
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
        $unit = $this->trace()->continueTrace($received)->getTraceParent();
        $action = $this->container()->get(EchoAction::class);
        self::assertIsCallable($action);

        $action(new EchoInput());

        $records = $this->actionLogExtras();
        self::assertCount(2, $records);
        [$start, $finish] = $records;
        self::assertSame($start, $finish);
        self::assertSame($unit->traceId, $start['trace_id']);
        self::assertSame($unit->parentId, $start['parent_span_id']);
        self::assertNotSame($unit->parentId, $start['span_id']);
        self::assertSame($unit, $this->trace()->getTraceParent());
        self::assertSame($received->parentId, $this->trace()->snapshot()->parentSpanId);
        self::assertSame([], $this->bundleDeprecations);
    }

    public function testAFailingActionLogsInItsSpanAndTheUnitsTraceComesBack(): void
    {
        $unit = $this->trace()->getTraceParent();
        $action = $this->container()->get(FailingAction::class);
        self::assertIsCallable($action);

        try {
            $action(new EchoInput());
            self::fail('The Action must throw.');
        } catch (RuntimeException) {
        }

        $records = $this->actionLogExtras();
        self::assertCount(2, $records);
        self::assertSame($unit->parentId, $records[1]['parent_span_id']);
        self::assertSame($unit, $this->trace()->getTraceParent());
        self::assertNull($this->trace()->snapshot()->parentSpanId);
    }

    public function testTheMiddlewareRunsOutsideLogging(): void
    {
        // Reads ActionPipeline's private middleware list: logic-bundle exposes no public order; see known-issues.md.
        $pipeline = $this->container()->get(EchoAction::class);
        self::assertInstanceOf(ActionPipeline::class, $pipeline);
        $middlewares = (new ReflectionProperty($pipeline, 'middlewares'))->getValue($pipeline);
        self::assertIsIterable($middlewares);
        $classes = [];
        foreach ($middlewares as $middleware) {
            self::assertIsObject($middleware);
            $classes[] = $middleware::class;
        }

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

    private function trace(): RequestIdServiceInterface
    {
        $trace = $this->container()->get(RequestIdServiceInterface::class);
        self::assertInstanceOf(RequestIdServiceInterface::class, $trace);

        return $trace;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
