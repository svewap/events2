<?php

declare(strict_types=1);

/*
 * This file is part of the package jweiland/events2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Events2\Task;

use JWeiland\Events2\Service\DatabaseService;
use JWeiland\Events2\Service\DayRelationService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Scheduler\ProgressProviderInterface;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/*
 * This class loops through all events and re-creates the day records.
 * Instead of the RepairCommand, this class does NOT truncate the whole day table.
 */
class ReGenerateDays extends AbstractTask implements ProgressProviderInterface
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var CacheManager
     */
    protected $cacheManager;

    /**
     * @var DatabaseService
     */
    protected $databaseService;

    /**
     * @var Registry
     */
    protected $registry;

    public function __construct(
        ObjectManagerInterface $objectManager,
        CacheManager $cacheManager,
        DatabaseService $databaseService,
        Registry $registry
    ) {
        parent::__construct();

        $this->objectManager = $objectManager;
        $this->cacheManager = $cacheManager;
        $this->databaseService = $databaseService;
        $this->registry = $registry;
    }

    public function execute(): bool
    {
        // Do not move these lines of code into constructor.
        // It will break serialization. Error: Serialization of 'Closure' is not allowed
        $dayRelationService = $this->objectManager->get(DayRelationService::class);
        $persistenceManager = $this->objectManager->get(PersistenceManagerInterface::class);

        // with each changing PID pageTSConfigCache will grow by roundabout 200KB
        // which may exceed memory_limit
        $runtimeCache = $this->cacheManager->getCache('runtime');

        $this->registry->removeAllByNamespace('events2TaskCreateUpdate');

        $events = $this->databaseService->getCurrentAndFutureEvents();
        if (!empty($events)) {
            $counter = 0;
            foreach ($events as $event) {
                $counter++;
                $this->registry->set('events2TaskCreateUpdate', 'info', [
                    'uid' => $event['uid'],
                    'pid' => $event['pid']
                ]);

                try {
                    $dayRelationService->createDayRelations((int)$event['uid']);
                } catch (\Exception $e) {
                    $this->addMessage(sprintf(
                        'Event UID: %d, PID: %d, Error: %s, File: %s, Line: %d',
                        $event['uid'],
                        $event['pid'],
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ), FlashMessage::ERROR);
                    return false;
                }

                // clean up persistence manager to reduce in-memory
                $persistenceManager->clearState();

                $this->registry->set('events2TaskCreateUpdate', 'progress', [
                    'records' => count($events),
                    'counter' => $counter
                ]);

                // clean up persistence manager to reduce memory usage
                // it also clears persistence session
                $persistenceManager->clearState();
                $runtimeCache->flush();
                gc_collect_cycles();
            }
        }

        $this->registry->remove('events2TaskCreateUpdate', 'info');

        return true;
    }

    /**
     * This method is designed to return some additional information about the task,
     * that may help to set it apart from other tasks from the same class
     * This additional information is used - for example - in the Scheduler's BE module
     * This method should be implemented in most task classes
     *
     * @return string Information to display
     */
    public function getAdditionalInformation(): string
    {
        $content = '';
        $info = $this->registry->get('events2TaskCreateUpdate', 'info');
        if ($info) {
            $content = sprintf(
                'Current event: uid: %d, pid: %d, memory: %d.',
                $info['uid'],
                $info['pid'],
                memory_get_usage()
            );
        }
        return $content;
    }

    /**
     * Gets the progress of a task.
     *
     * @return float Progress of the task as a two decimal precision float. f.e. 44.87
     */
    public function getProgress()
    {
        $progress = $this->registry->get('events2TaskCreateUpdate', 'progress');
        if ($progress) {
            return 100 / $progress['records'] * $progress['counter'];
        }
        return 0.0;
    }

    /**
     * This method is used to add a message to the internal queue
     *
     * @param string $message The message itself
     * @param int $severity Message level (according to \TYPO3\CMS\Core\Messaging\FlashMessage class constants)
     * @throws \Exception
     */
    public function addMessage(string $message, int $severity = FlashMessage::OK): void
    {
        $flashMessage = GeneralUtility::makeInstance(FlashMessage::class, $message, '', $severity);
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue($flashMessage);
    }

    protected function getConnectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }

    public function __sleep()
    {
        return array_keys(get_object_vars($this));
    }
}
