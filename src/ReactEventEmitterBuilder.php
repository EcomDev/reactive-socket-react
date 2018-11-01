<?php
/**
 * Copyright © EcomDev B.V. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace EcomDev\ReactiveSocket\React;

use EcomDev\ReactiveSocket\CompositeStreamObserver;
use EcomDev\ReactiveSocket\EventEmitter;
use EcomDev\ReactiveSocket\EventEmitterBuilder;
use EcomDev\ReactiveSocket\StreamObserver;
use React\EventLoop\LoopInterface;

class ReactEventEmitterBuilder implements EventEmitterBuilder
{
    private const DEFAULT_IDLE_THRESHOLD = 500;

    /** @var int */
    private $idleThreshold;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var callable[]
     */
    private $workers = [];

    /**
     * @var callable[]
     */
    private $idleWorkers = [];

    /** @var StreamObserver[] */
    private $streamObservers = [];

    public function __construct(LoopInterface $loop, int $idleThreshold)
    {
        $this->loop = $loop;
        $this->idleThreshold = $idleThreshold;
    }

    /**
     * Creates event emitter builder instance based on ReactPHP event loop
     */
    public static function createWithEventLoop(LoopInterface $loop): self
    {
        return new self($loop, self::DEFAULT_IDLE_THRESHOLD);
    }

    /**
     * {@inheritdoc}
     *
     * @return ReactEventEmitterBuilder
     */
    public function anEmitter(): EventEmitterBuilder
    {
        return self::createWithEventLoop($this->loop);
    }

    /**
     * {@inheritdoc}
     *
     * @return ReactEventEmitterBuilder
     */
    public function addStreamObserver(StreamObserver $streamObserver): EventEmitterBuilder
    {
        $this->streamObservers[] = $streamObserver;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ReactEventEmitterBuilder
     */
    public function addWorker(callable $worker): EventEmitterBuilder
    {
        $this->workers[] = $worker;
        return $this;
    }

    /**
     * {@inheritdoc}
     * @return ReactEventEmitterBuilder
     */
    public function addIdleWorker(callable $worker): EventEmitterBuilder
    {
        $this->idleWorkers[] = $worker;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ReactEventEmitterBuilder
     */
    public function build(): EventEmitter
    {
        $eventEmitter = new ReactEventEmitter(
            $this->loop,
            $this->createCompositeWorker($this->workers),
            $this->createCompositeWorker($this->idleWorkers),
            CompositeStreamObserver::createFromObservers(...$this->streamObservers),
            $this->idleThreshold
        );

        $this->loop->futureTick($eventEmitter);

        return $eventEmitter;
    }

    /**
     * Returns a builder with a new idle worker threshold
     */
    public function withIdleThreshold(int $idleThreshold): self
    {
        $builder = clone $this;
        $builder->idleThreshold = $idleThreshold;
        return $builder;
    }

    private function createCompositeWorker(array $workers): callable
    {
        return function () use ($workers) {
            array_walk($workers, 'call_user_func');
        };
    }
}
