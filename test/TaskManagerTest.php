<?php

/**
 * This file is part of OPUS. The software OPUS has been originally developed
 * at the University of Stuttgart with funding from the German Research Net,
 * the Federal Department of Higher Education and Research and the Ministry
 * of Science, Research and the Arts of the State of Baden-Wuerttemberg.
 *
 * OPUS 4 is a complete rewrite of the original OPUS software and was developed
 * by the Stuttgart University Library, the Library Service Center
 * Baden-Wuerttemberg, the Cooperative Library Network Berlin-Brandenburg,
 * the Saarland University and State Library, the Saxon State Library -
 * Dresden State and University Library, the Bielefeld University Library and
 * the University Library of Hamburg University of Technology with funding from
 * the German Research Foundation and the European Regional Development Fund.
 *
 * LICENCE
 * OPUS is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the Licence, or any later version.
 * OPUS is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details. You should have received a copy of the GNU General Public License
 * along with OPUS; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * @copyright   Copyright (c) 2023, OPUS 4 development team
 * @license     http://www.gnu.org/licenses/gpl.html General Public License
 */

namespace OpusTest\Job\Task;

use Opus\Common\Config;
use Opus\Job\TaskConfig;
use Opus\Job\TaskManager;
use OpusTest\Resources\DummyTask1;
use OpusTest\Resources\DummyTask2;
use PHPUnit\Framework\TestCase;
use Zend_Config;

use function array_pop;
use function call_user_func;
use function count;
use function is_callable;

class TaskManagerTest extends TestCase
{
    /**
     * Overwrites selected properties of current configuration.
     *
     * @note A test doesn't need to backup and recover replaced configuration as
     *       this is done in setup and tear-down phases.
     * @param array         $overlay properties to overwrite existing values in configuration
     * @param null|callable $callback callback to invoke with adjusted configuration before enabling e.g. to delete some options
     * @return Zend_Config reference on previously set configuration
     */
    protected function adjustConfiguration($overlay, $callback = null)
    {
        $previous = Config::get();
        $updated  = new Zend_Config([], true);

        $updated
            ->merge($previous)
            ->merge(new Zend_Config($overlay));

        if (is_callable($callback)) {
            $updated = call_user_func($callback, $updated);
        }

        $updated->setReadOnly();

        Config::set($updated);

        return $previous;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->adjustConfiguration(
            [
                'cron' => [
                    'enabled'    => 'true',
                    'taskRunner' => 'scripts/tasks/task-runner.php',
                    'configFile' => 'test/Resources/taskmanagertest-tasks.ini',
                ],
            ]
        );
    }

    public function testGetTaskConfigurations()
    {
        $taskManager        = new TaskManager();
        $taskConfigurations = $taskManager->getTaskConfigurations();
        $this->assertNotEmpty($taskConfigurations);
        $this->assertEquals(2, count($taskConfigurations));

        $taskConfig = new TaskConfig();
        $taskConfig->setEnabled(true)
            ->setName('testTask1')
            ->setSchedule('*/1 * * * *')
            ->setClass(DummyTask1::class)
            ->setPreventOverlapping(true)
            ->setOptions(
                [
                    'optionName1' => 'option1Value',
                    'optionName2' => 'option2Value',
                ]
            );

        $this->assertEquals($taskConfig, $taskConfigurations['testTask1']);

        $taskConfig = new TaskConfig();
        $taskConfig->setEnabled(false)
            ->setName('testTask2')
            ->setSchedule('*/2 * * * *')
            ->setClass(DummyTask2::class)
            ->setPreventOverlapping(false)
            ->setOptions([]);

        $this->assertEquals($taskConfig, $taskConfigurations['testTask2']);
    }

    public function testGetActiveTaskConfigurations()
    {
        $taskManager        = new TaskManager();
        $taskConfigurations = $taskManager->getActiveTaskConfigurations();
        $this->assertNotEmpty($taskConfigurations);
        $this->assertEquals(1, count($taskConfigurations));
        $this->assertTrue(array_pop($taskConfigurations)->isEnabled());
    }

    public function testGetTaskConfig()
    {
        $taskManager       = new TaskManager();
        $taskConfiguration = $taskManager->getTaskConfig('unknownTask');
        $this->assertFalse($taskConfiguration);

        $taskConfiguration = $taskManager->getTaskConfig('testTask2');
        $this->assertEquals(DummyTask2::class, $taskConfiguration->getClass());
    }

    public function testNotExistingTaskClass()
    {
        $taskManager = new TaskManager();
        $this->assertFalse($taskManager->isValidTaskClass('UnknownClass'));
    }

    public function testTaskClassWithNotImplementingTaskInterface()
    {
        $taskManager = new TaskManager();
        $this->assertFalse($taskManager->isValidTaskClass("UnknownClassName"));
        $this->assertFalse($taskManager->isValidTaskClass(DummyTask1::class));
    }

    public function testGetTaskRunnerScriptPath()
    {
        $taskManager = new TaskManager();
        $this->assertEquals("scripts/tasks/task-runner.php", $taskManager->getTaskRunnerScriptPath());
    }

    public function testIsCrunzSchedulerEnabled()
    {
        $this->adjustConfiguration(['cron' => ['enabled' => 'true']]);
        $taskManager = new TaskManager();
        $this->assertTrue($taskManager->isCrunzSchedulerEnabled());

        $this->adjustConfiguration(['cron' => ['enabled' => 'false']]);
        $taskManager = new TaskManager();
        $this->assertFalse($taskManager->isCrunzSchedulerEnabled());

        $this->adjustConfiguration(['cron' => ['enabled' => 'unknown']]);
        $taskManager = new TaskManager();
        $this->assertFalse($taskManager->isCrunzSchedulerEnabled());
    }
}
