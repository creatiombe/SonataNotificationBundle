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

namespace Sonata\NotificationBundle\Command;

use Sonata\NotificationBundle\Backend\BackendInterface;
use Sonata\NotificationBundle\Backend\QueueDispatcherInterface;
use Sonata\NotificationBundle\Consumer\ConsumerInterface;
use Sonata\NotificationBundle\Event\IterateEvent;
use Sonata\NotificationBundle\Exception\HandlingException;
use Sonata\NotificationBundle\Model\MessageInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @final since sonata-project/notification-bundle 3.13
 */
class ConsumerHandlerCommand extends Command
{
    /**
     * @var BackendInterface
     */
    private $backend;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    public function __construct(BackendInterface $backend, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct(null);

        $this->backend = $backend;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function configure(): void
    {
        $this->setName('sonata:notification:start');
        $this->setDescription('Listen for incoming messages');
        $this->addOption('iteration', 'i', InputOption::VALUE_OPTIONAL, 'Only run n iterations before exiting', false);
        $this->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Use a specific backed based on a message type, "all" with doctrine backend will handle all notifications no matter their type', null);
        $this->addOption('show-details', 'd', InputOption::VALUE_OPTIONAL, 'Show consumers return details', true);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $startDate = new \DateTime();

        $output->writeln(sprintf('[%s] <info>Checking listeners</info>', $startDate->format('r')));
        foreach ($this->eventDispatcher->getListeners() as $type => $listeners) {
            $output->writeln(sprintf(' - %s', $type));
            foreach ($listeners as $listener) {
                if (!$listener[0] instanceof ConsumerInterface) {
                    throw new \RuntimeException(sprintf(
                        'The registered service does not implement the ConsumerInterface (class=%s',
                        \get_class($listener[0])
                    ));
                }

                $output->writeln(sprintf('   > %s', \get_class($listener[0])));
            }
        }

        $type = $input->getOption('type');
        $showDetails = $input->getOption('show-details');

        $output->write(sprintf('[%s] <info>Retrieving backend</info> ...', $startDate->format('r')));
        $backend = $this->getBackend($type);

        $output->writeln('');
        $output->write(sprintf('[%s] <info>Initialize backend</info> ...', $startDate->format('r')));

        // initialize the backend
        $backend->initialize();

        $output->writeln(' done!');

        if (null === $type) {
            $output->writeln(sprintf(
                '[%s] <info>Starting the backend handler</info> - %s',
                $startDate->format('r'),
                \get_class($backend)
            ));
        } else {
            $output->writeln(sprintf(
                '[%s] <info>Starting the backend handler</info> - %s (type: %s)',
                $startDate->format('r'),
                \get_class($backend),
                $type
            ));
        }

        $startMemoryUsage = memory_get_usage(true);
        $i = 0;
        $iterator = $backend->getIterator();
        foreach ($iterator as $message) {
            ++$i;

            if (!$message instanceof MessageInterface) {
                throw new \RuntimeException('The iterator must return a MessageInterface instance');
            }

            if (!$message->getType()) {
                $output->write('<error>Skipping : no type defined </error>');

                continue;
            }

            $date = new \DateTime();
            $output->write(sprintf('[%s] <info>%s</info> #%s: ', $date->format('r'), $message->getType(), $i));
            $memoryUsage = memory_get_usage(true);

            try {
                $start = microtime(true);
                $returnInfos = $backend->handle($message, $this->eventDispatcher);

                $currentMemory = memory_get_usage(true);

                $output->writeln(sprintf(
                    '<comment>OK! </comment> - %0.04fs, %ss, %s, %s - %s = %s, %0.02f%%',
                    microtime(true) - $start,
                    $date->format('U') - $message->getCreatedAt()->format('U'),
                    $this->formatMemory($currentMemory - $memoryUsage),
                    $this->formatMemory($currentMemory),
                    $this->formatMemory($startMemoryUsage),
                    $this->formatMemory($currentMemory - $startMemoryUsage),
                    ($currentMemory - $startMemoryUsage) / $startMemoryUsage * 100
                ));

                if ($showDetails && null !== $returnInfos) {
                    $output->writeln($returnInfos->getReturnMessage());
                }
            } catch (HandlingException $e) {
                $output->writeln(sprintf('<error>KO! - %s</error>', $e->getPrevious()->getMessage()));
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>KO! - %s</error>', $e->getMessage()));
            }

            $this->eventDispatcher->dispatch(
                new IterateEvent($iterator, $backend, $message),
                IterateEvent::EVENT_NAME
            );

            if ($input->getOption('iteration') && $i >= (int) $input->getOption('iteration')) {
                $output->writeln('End of iteration cycle');

                return 0;
            }
        }

        return 0;
    }

    /**
     * @param string $type
     * @param object $backend
     *
     * @throws \RuntimeException
     */
    protected function throwTypeNotFoundException($type, $backend): void
    {
        throw new \RuntimeException(
            "The requested backend for the type '".$type." 'does not exist. \nMake sure the backend '".
            \get_class($backend)."' \nsupports multiple queues and the routing_key is defined. (Currently rabbitmq only)"
        );
    }

    /**
     * @param $memory
     *
     * @return string
     */
    private function formatMemory($memory)
    {
        if ($memory < 1024) {
            return $memory.'b';
        } elseif ($memory < 1048576) {
            return round($memory / 1024, 2).'Kb';
        }

        return round($memory / 1048576, 2).'Mb';
    }

    /**
     * @param string $type
     *
     * @return BackendInterface
     */
    private function getBackend($type = null)
    {
        if ($type && !\array_key_exists($type, $this->eventDispatcher->getListeners())) {
            throw new \RuntimeException(sprintf('The type `%s` does not exist, available types: %s', $type, implode(', ', array_keys($this->eventDispatcher->getListeners()))));
        }

        if (null !== $type && !$this->backend instanceof QueueDispatcherInterface) {
            throw new \RuntimeException(sprintf(
                'Unable to use the provided type %s with a non QueueDispatcherInterface backend',
                $type
            ));
        }

        if ($this->backend instanceof QueueDispatcherInterface) {
            return $this->backend->getBackend($type);
        }

        return $this->backend;
    }
}
