<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\NotificationBundle\Iterator;

use Sonata\NotificationBundle\Model\MessageInterface;
use Sonata\NotificationBundle\Model\MessageManagerInterface;

class MessageManagerMessageIterator implements MessageIteratorInterface
{
    /**
     * @var MessageManagerInterface
     */
    protected $messageManager;

    /**
     * @var int
     */
    protected $counter;

    /**
     * @var mixed
     */
    protected $current;

    /**
     * @var array
     */
    protected $types;

    /**
     * @var int
     */
    protected $batchSize;

    /**
     * @var array
     */
    protected $buffer = [];

    /**
     * @var int
     */
    protected $pause;

    /**
     * @param array $types
     * @param int   $pause
     * @param int   $batchSize
     */
    public function __construct(MessageManagerInterface $messageManager, $types = [], $pause = 500000, $batchSize = 10)
    {
        $this->messageManager = $messageManager;
        $this->counter = 0;
        $this->pause = $pause;
        $this->types = $types;
        $this->batchSize = $batchSize;
    }

    public function current()
    {
        return $this->current;
    }

    public function next(): void
    {
        $this->setCurrent();
        ++$this->counter;
    }

    public function key()
    {
        return $this->counter;
    }

    public function valid()
    {
        return true;
    }

    public function rewind(): void
    {
        $this->setCurrent();
    }

    /**
     * Return true if the internal buffer is empty.
     *
     * @return bool
     */
    public function isBufferEmpty()
    {
        return 0 === \count($this->buffer);
    }

    /**
     * Assign current pointer a message.
     */
    protected function setCurrent(): void
    {
        if (0 === \count($this->buffer)) {
            $this->bufferize($this->types);
        }

        $this->current = array_shift($this->buffer);
    }

    /**
     * Fill the inner messages buffer.
     *
     * @param array $types
     */
    protected function bufferize($types = []): void
    {
        while (true) {
            $this->buffer = $this->findNextMessages($types);

            if (\count($this->buffer) > 0) {
                break;
            }

            usleep($this->pause);
        }
    }

    /**
     * Find open messages.
     *
     * @param array $types
     *
     * @return mixed
     */
    protected function findNextMessages($types)
    {
        return $this->messageManager->findByTypes($types, MessageInterface::STATE_OPEN, $this->batchSize);
    }
}
