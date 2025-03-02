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

namespace Sonata\NotificationBundle\Consumer;

use Sonata\NotificationBundle\Model\MessageInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @final since sonata-project/notification-bundle 3.13
 */
class ConsumerEvent extends Event implements ConsumerEventInterface
{
    /**
     * @var MessageInterface
     */
    protected $message;

    /**
     * @var ConsumerReturnInfo
     */
    protected $returnInfo;

    public function __construct(MessageInterface $message)
    {
        $this->message = $message;
    }

    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param ConsumerReturnInfo $returnInfo
     */
    public function setReturnInfo($returnInfo): void
    {
        $this->returnInfo = $returnInfo;
    }

    /**
     * @return ConsumerReturnInfo
     */
    public function getReturnInfo()
    {
        return $this->returnInfo;
    }
}
