<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Forms\Mcp\Service\CircleDetached;

final class EventService extends \OCA\Circles\Service\EventService
{
    public function __construct(private readonly \OCA\Forms\Mcp\Service\CircleDetached\Lease $lease, private readonly \OCP\EventDispatcher\IEventDispatcher $eventDispatcher, private readonly \OCA\Forms\Mcp\Service\CircleDetached\ActivityService $activityService)
    {
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function circleCreating(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function circleCreated(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function circleEditing(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function circleEdited(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function circleDestroying(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function circleDestroyed(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberPreparing(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberAdding(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function memberAdded(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberInviting(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function memberInvited(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberRequesting(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function memberRequested(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberJoining(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function memberJoined(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberLevelEditing(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function memberLevelEdited(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberNameEditing(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function memberNameEdited(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberRemoving(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param array $results
     */
    public function memberRemoved(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function memberLeaving(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param SimpleDataStore[] $results
     */
    public function memberLeft(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $results): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param FederatedEvent $federatedEvent
     */
    public function fileSharePreparing(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
    {
        $this->lease->assertActive();
        $event = new \OCA\Circles\Events\Files\PreparingFileShareEvent($federatedEvent);
        $this->eventDispatcher->dispatchTyped($event);
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param Mount|null $mount
     */
    public function fileShareCreating(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, ?\OCA\Circles\Model\Mount $mount = null): void
    {
        $this->lease->assertActive();
        $event = new \OCA\Circles\Events\Files\CreatingFileShareEvent($federatedEvent);
        if (!is_null($mount)) {
            $event->setMount($mount);
        }
        $this->eventDispatcher->dispatchTyped($event);
    }
    /**
     * @param FederatedEvent $federatedEvent
     * @param SimpleDataStore[] $result
     */
    public function fileShareCreated(\OCA\Circles\Model\Federated\FederatedEvent $federatedEvent, array $result): void
    {
        $this->lease->assertActive();
        $event = new \OCA\Circles\Events\Files\FileShareCreatedEvent($federatedEvent, $result);
        $this->eventDispatcher->dispatchTyped($event);
        $this->activityService->onShareNew($event->getCircle(), $event->getFederatedEvent());
    }
    /**
     * @param Membership[] $new
     */
    public function membershipsCreated(array $new): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param Membership[] $deprecated
     */
    public function membershipsRemoved(array $deprecated): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
    /**
     * @param ShareWrapper $wrappedShare
     */
    public function localShareCreated(\OCA\Circles\Model\ShareWrapper $wrappedShare): void
    {
        $this->lease->assertActive();
    }
    /**
     * @param ShareWrapper $wrappedShare
     */
    public function localShareDeleted(\OCA\Circles\Model\ShareWrapper $wrappedShare): void
    {
        $this->lease->assertActive();
    }
    /**
     * @param ShareWrapper $wrappedShare
     */
    public function federatedShareDeleted(\OCA\Circles\Model\ShareWrapper $wrappedShare): void
    {
        $this->lease->assertActive();
        throw new \RuntimeException("Circle event dependency method is not qualified");
    }
}
