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

namespace Sonata\NotificationBundle\DependencyInjection\Compiler;

use Sonata\NotificationBundle\Event\IterateEvent;
use Sonata\NotificationBundle\Event\IterationListener;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @final since sonata-project/notification-bundle 3.13
 *
 * @internal since sonata-project/notification-bundle 4.0
 */
class NotificationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('sonata.notification.dispatcher')) {
            return;
        }

        $definition = $container->getDefinition('sonata.notification.dispatcher');

        $informations = [];

        foreach ($container->findTaggedServiceIds('sonata.notification.consumer') as $id => $events) {
            $container->getDefinition($id)->setPublic(true);

            foreach ($events as $event) {
                $priority = $event['priority'] ?? 0;

                if (!isset($event['type'])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Service "%s" must define the "type" attribute on "sonata.notification" tags.',
                        $id
                    ));
                }

                if (!isset($informations[$event['type']])) {
                    $informations[$event['type']] = [];
                }

                $informations[$event['type']][] = $id;

                $definition->addMethodCall(
                    'addListener',
                    [
                        $event['type'],
                        [new ServiceClosureArgument(new Reference($id)), 'process'],
                        $priority,
                    ]
                );
            }
        }

        $container->getDefinition('sonata.notification.consumer.metadata')->replaceArgument(0, $informations);

        if ($container->getParameter('sonata.notification.event.iteration_listeners')) {
            $ids = $container->getParameter('sonata.notification.event.iteration_listeners');

            foreach ($ids as $serviceId) {
                $definition = $container->getDefinition($serviceId);

                $class = new \ReflectionClass($definition->getClass());
                if (!$class->implementsInterface(IterationListener::class)) {
                    throw new RuntimeException(
                        'Iteration listeners must implement Sonata\NotificationBundle\Event\IterationListener'
                    );
                }

                $definition->addTag(
                    'kernel.event_listener',
                    ['event' => IterateEvent::EVENT_NAME, 'method' => 'iterate']
                );
            }
        }
    }
}
