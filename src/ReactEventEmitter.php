<?php
/**
 * Copyright © EcomDev B.V. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace EcomDev\ReactiveSocket\React;

use EcomDev\ReactiveSocket\EventEmitter;
use EcomDev\ReactiveSocket\Stream;
use EcomDev\ReactiveSocket\StreamObserver;
use React\EventLoop\LoopInterface;

class ReactEventEmitter implements EventEmitter
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var callable
     */
    private $tickWorker;

    /**
     * @var callable
     */
    private $idleWorker;

    /**
     * @var resource[]
     */
    private $watchedResources = [];
    /**
     * @var StreamObserver
     */
    private $streamObserver;

    /**
     * @var float
     */
    private $lastIdleAnchor;
    /**
     * @var int
     */
    private $idleThreshold;

    public function __construct(
        LoopInterface $loop,
        callable $tickWorker,
        callable $idleWorker,
        StreamObserver $streamObserver,
        int $idleThreshold
    ) {
        $this->loop = $loop;
        $this->tickWorker = $tickWorker;
        $this->idleWorker = $idleWorker;
        $this->streamObserver = $streamObserver;
        $this->idleThreshold = $idleThreshold;
    }

    /**
     * Attaches stream to event emitter
     */
    public function attachStream(Stream $stream, $resource): void
    {
        $this->watchedResources[(int)$resource] = $stream;

        $this->loop->addReadStream($resource, function () use ($stream) {
            $stream->notifyReadable($this->streamObserver);
            $this->moveIdleAnchorForward();
        });

        $this->loop->addWriteStream($resource, function () use ($stream) {
            $stream->notifyWritable($this->streamObserver);
        });

        $this->streamObserver->handleConnected($stream);
    }

    /**
     * Detaches stream from event emitter
     */
    public function detachStream(Stream $stream, $resource): void
    {
        $this->loop->removeReadStream($resource);
        $this->loop->removeWriteStream($resource);
        $stream->notifyDisconnected($this->streamObserver);
        unset($this->watchedResources[(int)$resource]);
    }

    public function __invoke()
    {
        call_user_func($this->tickWorker);

        $this->executeIdleWorkerIfApplicable();
    }

    private function moveIdleAnchorForward(): void
    {
        $this->lastIdleAnchor = microtime(true);
    }

    private function isIdleThresholdReached(): bool
    {
        $thresholdInMicrotime = $this->idleThreshold / 1000;

        return $this->lastIdleAnchor !== null
            && (microtime(true) - $this->lastIdleAnchor) >= $thresholdInMicrotime;
    }

    private function executeIdleWorkerIfApplicable(): void
    {
        if ($this->isEmptyResourceListOnFirstRun() || $this->isIdleThresholdReached()) {
            call_user_func($this->idleWorker);
            $this->moveIdleAnchorForward();
            return;
        }

        $this->startIdleTimerOnFirstRun();
    }

    private function startIdleTimerOnFirstRun(): void
    {
        if ($this->lastIdleAnchor === null) {
            $this->moveIdleAnchorForward();
        }
    }

    /**
     *
     * @return bool
     */
    private function isEmptyResourceListOnFirstRun(): bool
    {
        return !$this->watchedResources && $this->lastIdleAnchor === null;
    }
}
