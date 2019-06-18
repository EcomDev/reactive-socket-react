<?php
/**
 * Copyright © EcomDev B.V. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace EcomDev\ReactiveSocket\React;

use EcomDev\ReactiveSocket\BufferedStreamFactory;
use EcomDev\ReactiveSocket\FakeStreamObserver;
use EcomDev\ReactiveSocket\SocketStreamBufferFactory;
use EcomDev\ReactiveSocket\Stream;
use EcomDev\ReactiveSocket\StreamBuffer;
use EcomDev\ReactiveSocket\StreamObserverNotificationState;
use EcomDev\ReactTestUtil\LoopFactory;
use EcomDev\SocketTester\SocketTester;
use EcomDev\SocketTester\SocketTesterPool;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;

class ReactEventEmitterTest extends TestCase
{
    /** @var LoopFactory */
    private $loopFactory;

    /** @var SocketTester[] */
    private $sockets = [];

    /** @var SocketTesterPool */
    private static $streamPool;

    /** @var ReactEventEmitterBuilder */
    private $builder;

    /** @var LoopInterface */
    private $loop;

    /** @var string[] */
    private $calls = [];

    protected function setUp()
    {
        $this->loopFactory = LoopFactory::create();
        $this->createAlwaysRunningLoopWithTimeout(0.004);
    }

    protected function tearDown()
    {
        foreach ($this->sockets as $socket) {
            self::$streamPool->releaseSocket($socket);
        }
    }

    public static function setUpBeforeClass()
    {
        self::$streamPool = new SocketTesterPool();
    }

    public static function tearDownAfterClass()
    {
        self::$streamPool = null;
    }

    /** @test */
    public function runsAllBackgroundWorkersOnLoopRun()
    {
        $this->builder->anEmitter()
            ->addWorker($this->registerCall('Background Worker #1'))
            ->addWorker($this->registerCall('Background Worker #2'))
            ->build();

        $this->loop->run();

        $this->assertWorkerCalls('Background Worker #1', 'Background Worker #2');
    }

    /** @test */
    public function runsIdleWorkersWhenNothingElseRunning()
    {
        $this->builder->anEmitter()
            ->addIdleWorker($this->registerCall('Idle Worker #1'))
            ->addIdleWorker($this->registerCall('Idle Worker #2'))
            ->build();

        $this->loop->run();

        $this->assertWorkerCalls('Idle Worker #1', 'Idle Worker #2');
    }

    /** @test */
    public function notifiesStreamObserverOfConnectedStream()
    {
        $observer = new FakeStreamObserver();

        $emitter = $this->builder->anEmitter()
            ->addStreamObserver($observer)
            ->build();

        $stream = $this->createStream();
        $stream->attach($emitter);

        $this->assertEquals(
            StreamObserverNotificationState::createEmpty()
                ->withConnectedNotification($stream),
            $observer->fetchNotifications()
        );
    }

    /** @test */
    public function notifiesStreamObserverOfWritableConnectedStreams()
    {
        $observer = new FakeStreamObserver();

        $emitter = $this->builder->anEmitter()
            ->addStreamObserver($observer)
            ->build();

        [$streamOne, $streamTwo] = [$this->createStream(), $this->createStream()];

        $streamOne->attach($emitter);
        $streamTwo->attach($emitter);

        $this->runLoopOnce();

        $this->assertEquals(
            StreamObserverNotificationState::createEmpty()
                ->withConnectedNotification($streamOne)
                ->withConnectedNotification($streamTwo)
                ->withWritableNotification($streamOne)
                ->withWritableNotification($streamTwo),
            $observer->fetchNotifications()
        );
    }

    /** @test */
    public function reportsDisconnectedStream()
    {
        $observer = new FakeStreamObserver();

        $emitter = $this->builder->anEmitter()
            ->addStreamObserver($observer)
            ->build();

        $socket = self::$streamPool->acquireSocket();

        $streamBuffer = $this->createStreamBuffer($socket);
        $streamBuffer->write('Data #1');
        $streamBuffer->write('Data #2');

        $stream = $this->createStream($streamBuffer);

        $stream->attach($emitter);
        $socket->closeRemote();

        $this->loop->run();

        $this->assertEquals(
            StreamObserverNotificationState::createEmpty()
                ->withConnectedNotification($stream)
                ->withReadableNotification($stream, '')
                ->withDisconnectedNotification($stream, ['Data #1', 'Data #2']),
            $observer->fetchNotifications()
        );
    }

    /** @test */
    public function notifiesStreamObserverOfReadableDataInConnectedStreams()
    {
        $observer = new FakeStreamObserver();

        $emitter = $this->builder->anEmitter()
            ->addStreamObserver($observer)
            ->build();

        [$streamOne, $streamTwo] = [$this->createStream(), $this->createStream()];

        $streamOne->attach($emitter);
        $streamTwo->attach($emitter);

        foreach ($this->sockets as $id => $socket) {
            $socket->writeToRemote(sprintf('Some data #%d', $id+1));
            $socket->polluteSocket();
        }

        $this->loop->run();

        $this->assertEquals(
            StreamObserverNotificationState::createEmpty()
                ->withConnectedNotification($streamOne)
                ->withConnectedNotification($streamTwo)
                ->withReadableNotification($streamOne, 'Some data #1')
                ->withReadableNotification($streamTwo, 'Some data #2'),
            $observer->fetchNotifications()
        );
    }

    /** @test */
    public function detachesStreamFromListener()
    {
        $observer = new FakeStreamObserver();

        $emitter = $this->builder->anEmitter()
            ->addStreamObserver($observer)
            ->build();

        $buffer = $this->createStreamBuffer();
        $stream = $this->createStream($buffer);

        $stream->attach($emitter);
        $buffer->detachResourceFromEmitter($stream, $emitter);

        $this->loop->run();

        $this->assertEquals(
            StreamObserverNotificationState::createEmpty()
                ->withConnectedNotification($stream)
                ->withDisconnectedNotification($stream, []),
            $observer->fetchNotifications()
        );
    }

    /** @test */
    public function doesNotExecuteIdleWorkerWhenStreamHasDataInReadQueue()
    {
        $this->createAlwaysRunningLoopWithTimeout(0.03);

        $socket = $this->createSocket();
        $stream = $this->createStream($this->createStreamBuffer($socket));

        $emitter = $this->builder->anEmitter()
            ->addIdleWorker($this->registerCall('Idle that should not be called'))
            ->build();

        $stream->attach($emitter);
        $socket->writeToRemote('Some data that has something in it');

        $this->loop->run();

        $this->assertWorkerCalls();
    }

    /** @test */
    public function whenReadQueueIsEmptyIdleWorkerIsInvokedAfterInactivityTimeout()
    {
        $callsGotRecorded = function () {
            return !empty($this->calls);
        };

        $this->loop = $this->loopFactory->createConditionRunLoop($callsGotRecorded);

        $this->builder = ReactEventEmitterBuilder::createWithEventLoop(
            $this->loop
        );

        $socket = $this->createSocket();
        $stream = $this->createStream($this->createStreamBuffer($socket));

        $emitter = $this->builder->anEmitter()
            ->addIdleWorker($this->registerCall('Some cleanup during idle'))
            ->build();

        $stream->attach($emitter);

        $this->loop->run();

        $this->assertWorkerCalls('Some cleanup during idle');
    }

    /** @test */
    public function whenReadQueueIsEmptyIdleWorkerIsInvokedEveryHalfASecondOfInactivity()
    {
        $this->createAlwaysRunningLoopWithTimeout(3);

        $socket = $this->createSocket();
        $stream = $this->createStream($this->createStreamBuffer($socket));

        $emitter = $this->builder->anEmitter()
            ->addIdleWorker($this->registerCall('Cleanup every half second'))
            ->build();

        $stream->attach($emitter);

        $this->loop->run();

        $this->assertWorkerCalls(
            'Cleanup every half second',
            'Cleanup every half second',
            'Cleanup every half second',
            'Cleanup every half second',
            'Cleanup every half second'
        );
    }

    /** @test */
    public function allowsToSpecifyCustomIdleWorkerThreshold()
    {
        $this->createAlwaysRunningLoopWithTimeout(3);

        $socket = $this->createSocket();
        $stream = $this->createStream($this->createStreamBuffer($socket));

        $emitter = $this->builder->anEmitter()
            ->withIdleThreshold(1000)
            ->addIdleWorker($this->registerCall('Some cleanup during idle'))
            ->build();

        $stream->attach($emitter);

        $this->loop->run();

        $this->assertWorkerCalls(
            'Some cleanup during idle',
            'Some cleanup during idle'
        );
    }



    /** @test */
    public function executesAllAssignedObservers()
    {
        $observer = new FakeStreamObserver();

        $emitter = $this->builder->anEmitter()
            ->addStreamObserver($observer)
            ->addStreamObserver($observer)
            ->addStreamObserver($observer)
            ->build();

        $stream = $this->createStream();

        $stream->attach($emitter);

        $this->runLoopOnce();

        $this->assertEquals(
            StreamObserverNotificationState::createEmpty()
                ->withConnectedNotification($stream)
                ->withConnectedNotification($stream)
                ->withConnectedNotification($stream)
                ->withWritableNotification($stream)
                ->withWritableNotification($stream)
                ->withWritableNotification($stream),
            $observer->fetchNotifications()
        );
    }

    private function registerCall(string $name): callable
    {
        return function () use ($name) {
            $this->calls[] = $name;
        };
    }

    private function assertWorkerCalls(string... $expectedCalls): void
    {
        $this->assertEquals($expectedCalls, $this->calls);
    }

    private function createSocket(): SocketTester
    {
        $socket = self::$streamPool->acquireSocket();
        $this->sockets[] = $socket;
        return $socket;
    }

    private function createStream(StreamBuffer $streamBuffer = null): Stream
    {
        $streamBuffer = $streamBuffer ?? $this->createStreamBuffer();
        return (new BufferedStreamFactory())->createFromBuffer($streamBuffer);
    }

    private function createStreamBuffer(SocketTester $socket = null): StreamBuffer
    {
        $socket = $socket ?? $this->createSocket();

        return $socket->wrapSocket([new SocketStreamBufferFactory(), 'createFromSocket']);
    }

    private function createAlwaysRunningLoopWithTimeout(float $timeout): void
    {
        $this->loop = $this->loopFactory->createConditionRunLoopWithTimeout(
            function () {
                return false;
            },
            $timeout
        );

        $this->builder = ReactEventEmitterBuilder::createWithEventLoop(
            $this->loop
        );
    }

    private function runLoopOnce(): void
    {
        $this->loop->futureTick(
            function () {
                $this->loop->stop();
            }
        );
        $this->loop->run();
    }
}
