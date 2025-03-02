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

namespace Sonata\NotificationBundle\Entity;

use Doctrine\ORM\QueryBuilder;
use Sonata\DatagridBundle\Pager\Doctrine\Pager;
use Sonata\DatagridBundle\Pager\PagerInterface;
use Sonata\Doctrine\Entity\BaseEntityManager;
use Sonata\NotificationBundle\Model\MessageInterface;
use Sonata\NotificationBundle\Model\MessageManagerInterface;

/**
 * @final since sonata-project/notification-bundle 3.13
 */
class MessageManager extends BaseEntityManager implements MessageManagerInterface
{
    public function save($entity, $andFlush = true): void
    {
        //Hack for ConsumerHandlerCommand->optimize()
        if ($entity->getId() && !$this->getEntityManager()->getUnitOfWork()->isInIdentityMap($entity)) {
            $entity = $this->getEntityManager()->getUnitOfWork()->merge($entity);
        }

        parent::save($entity, $andFlush);
    }

    public function findByTypes(array $types, $state, $batchSize)
    {
        $params = [];
        $query = $this->prepareStateQuery($state, $types, $batchSize, $params);

        $query->setParameters($params);

        return $query->getQuery()->execute();
    }

    public function findByAttempts(array $types, $state, $batchSize, $maxAttempts = null, $attemptDelay = 10)
    {
        $params = [];
        $query = $this->prepareStateQuery($state, $types, $batchSize, $params);

        if ($maxAttempts) {
            $query
                ->andWhere('m.restartCount < :maxAttempts')
                ->andWhere('m.updatedAt < :delayDate');

            $params['maxAttempts'] = $maxAttempts;
            $now = new \DateTime();
            $params['delayDate'] = $now->add(\DateInterval::createFromDateString(($attemptDelay * -1).' second'));
        }

        $query->setParameters($params);

        return $query->getQuery()->execute();
    }

    public function countStates()
    {
        $tableName = $this->getEntityManager()->getClassMetadata($this->class)->table['name'];

        $stm = $this->getConnection()->query(
            sprintf('SELECT state, count(state) as cnt FROM %s GROUP BY state', $tableName)
        );

        $states = [
            MessageInterface::STATE_DONE => 0,
            MessageInterface::STATE_ERROR => 0,
            MessageInterface::STATE_IN_PROGRESS => 0,
            MessageInterface::STATE_OPEN => 0,
        ];

        while ($data = $stm->fetch()) {
            $states[$data['state']] = $data['cnt'];
        }

        return $states;
    }

    public function cleanup($maxAge): void
    {
        $tableName = $this->getEntityManager()->getClassMetadata($this->class)->table['name'];

        $date = new \DateTime('now');
        $date->sub(new \DateInterval(sprintf('PT%sS', $maxAge)));

        $qb = $this->getRepository()->createQueryBuilder('message')
            ->delete()
            ->where('message.state = :state')
            ->andWhere('message.completedAt < :date')
            ->setParameter('state', MessageInterface::STATE_DONE)
            ->setParameter('date', $date);

        $qb->getQuery()->execute();
    }

    public function cancel(MessageInterface $message, $force = false): void
    {
        if (($message->isRunning() || $message->isError()) && !$force) {
            return;
        }

        $message->setState(MessageInterface::STATE_CANCELLED);

        $this->save($message);
    }

    public function restart(MessageInterface $message)
    {
        if ($message->isOpen() || $message->isRunning() || $message->isCancelled()) {
            return;
        }

        $this->cancel($message, true);

        $newMessage = clone $message;
        $newMessage->setRestartCount($message->getRestartCount() + 1);
        $newMessage->setType($message->getType());

        return $newMessage;
    }

    public function getPager(array $criteria, int $page, int $limit = 10, array $sort = []): PagerInterface
    {
        $query = $this->getRepository()
            ->createQueryBuilder('m')
            ->select('m');

        $fields = $this->getEntityManager()->getClassMetadata($this->class)->getFieldNames();
        foreach ($sort as $field => $direction) {
            if (!\in_array($field, $fields, true)) {
                throw new \RuntimeException(sprintf("Invalid sort field '%s' in '%s' class", $field, $this->class));
            }
        }
        if (0 === \count($sort)) {
            $sort = ['type' => 'ASC'];
        }
        foreach ($sort as $field => $direction) {
            $query->orderBy(sprintf('m.%s', $field), strtoupper($direction));
        }

        $parameters = [];

        if (isset($criteria['type'])) {
            $query->andWhere('m.type = :type');
            $parameters['type'] = $criteria['type'];
        }

        if (isset($criteria['state'])) {
            $query->andWhere('m.state = :state');
            $parameters['state'] = $criteria['state'];
        }

        $query->setParameters($parameters);

        return Pager::create($query, $limit, $page);
    }

    /**
     * @param int   $state
     * @param array $types
     * @param int   $batchSize
     * @param array $parameters
     *
     * @return QueryBuilder
     */
    protected function prepareStateQuery($state, $types, $batchSize, &$parameters)
    {
        $query = $this->getRepository()
            ->createQueryBuilder('m')
            ->where('m.state = :state')
            ->orderBy('m.createdAt');

        $parameters['state'] = $state;

        if (\count($types) > 0) {
            if (\array_key_exists('exclude', $types) || \array_key_exists('include', $types)) {
                if (\array_key_exists('exclude', $types)) {
                    $query->andWhere('m.type NOT IN (:exclude)');
                    $parameters['exclude'] = $types['exclude'];
                }

                if (\array_key_exists('include', $types)) {
                    $query->andWhere('m.type IN (:include)');
                    $parameters['include'] = $types['include'];
                }
            } else { // BC
                $query->andWhere('m.type IN (:types)');
                $parameters['types'] = $types;
            }
        }

        $query->setMaxResults($batchSize);

        return $query;
    }
}
