<?php

declare(strict_types=1);

/*
 * This file is part of the package jweiland/events2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Events2\Tests\Functional\Controller;

use JWeiland\Events2\Tests\Functional\AbstractFunctionalTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Core\Bootstrap;

/**
 * Test case.
 */
class ManagementControllerTest extends AbstractFunctionalTestCase
{
    use ProphecyTrait;

    protected ServerRequest $serverRequest;

    /**
     * @var array
     */
    protected $testExtensionsToLoad = [
        'typo3conf/ext/events2',
        'typo3conf/ext/static_info_tables'
    ];

    protected function setUp(): void
    {
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
        if (version_compare($typo3Version->getBranch(), '11', '<')) {
            self::markTestSkipped(
                'Because of missing Context class in TYPO3 10 this test has to be skipped.'
            );
        }

        parent::setUp();

        $this->importDataSet('ntf://Database/pages.xml');
        $this->setUpFrontendRootPage(1, [__DIR__ . '/../Fixtures/TypoScript/setup.typoscript']);

        $this->serverRequest = $this->getServerRequestForFrontendMode();

        $this->getDatabaseConnection()->insertArray(
            'fe_users',
            [
                'pid' => 1,
                'username' => 'froemken',
                'tx_events2_organizer' => 1,
            ]
        );

        $this->getDatabaseConnection()->insertArray(
            'tx_events2_domain_model_organizer',
            [
                'pid' => 1,
                'organizer' => 'Stefan',
            ]
        );

        $date = new \DateTimeImmutable('midnight');
        $this->getDatabaseConnection()->insertArray(
            'tx_events2_domain_model_event',
            [
                'pid' => 1,
                'event_type' => 'single',
                'event_begin' => (int)$date->format('U'),
                'title' => 'Today',
                'organizers' => '1',
            ]
        );

        $this->getDatabaseConnection()->insertArray(
            'tx_events2_event_organizer_mm',
            [
                'uid_local' => 1,
                'uid_foreign' => 1
            ]
        );
    }

    protected function tearDown(): void
    {
        unset(
            $this->request,
            $GLOBALS['TSFE']
        );

        parent::tearDown();
    }

    /**
     * @test
     */
    public function processRequestWithNewActionWillCollectSelectableCategories(): void
    {
        $this->startUpTSFE($this->serverRequest);

        $GLOBALS['TSFE']->fe_user->user = $this->getDatabaseConnection()->selectSingleRow(
            '*',
            'fe_users',
            'uid = 1'
        );

        $extbaseBootstrap = GeneralUtility::makeInstance(Bootstrap::class);
        $content = $extbaseBootstrap->run(
            '',
            [
                'extensionName' => 'Events2',
                'pluginName' => 'Management',
                'format' => 'txt',
                'settings' => [
                    'userGroup' => '1'
                ]
            ]
        );

        self::assertStringContainsString(
            'Event Title 1: Today',
            $content
        );
        self::assertStringContainsString(
            'tx_events2_management%5Baction%5D=edit&amp;tx_events2_management%5Bcontroller%5D=Management',
            $content
        );
    }
}
