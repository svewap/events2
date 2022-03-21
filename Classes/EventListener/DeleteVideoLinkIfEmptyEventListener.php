<?php

declare(strict_types=1);

/*
 * This file is part of the package jweiland/events2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Events2\EventListener;

use JWeiland\Events2\Domain\Model\Link;
use JWeiland\Events2\Domain\Repository\LinkRepository;
use JWeiland\Events2\Event\PostProcessControllerActionEvent;

/*
 * As extbase can not automatically remove a related property if it is empty, we have to remove
 * Link model on our own.
 */
class DeleteVideoLinkIfEmptyEventListener extends AbstractControllerEventListener
{
    protected LinkRepository $linkRepository;

    protected array $allowedControllerActions = [
        'Management' => [
            'create',
            'update'
        ]
    ];

    public function __construct(LinkRepository $linkRepository)
    {
        $this->linkRepository = $linkRepository;
    }

    public function __invoke(PostProcessControllerActionEvent $controllerActionEvent): void
    {
        if (
            $this->isValidRequest($controllerActionEvent)
            && ($eventObject = $controllerActionEvent->getEvent())
            && $eventObject->getVideoLink() instanceof Link
            && empty($eventObject->getVideoLink()->getLink())
        ) {
            $this->linkRepository->remove($eventObject->getVideoLink());
            $eventObject->setVideoLink(null);
        }
    }
}
