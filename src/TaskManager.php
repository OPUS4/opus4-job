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

namespace Opus\Job;

use Crunz\Event;
use Crunz\Schedule;
use Exception;
use Opus\Common\ConfigTrait;
use Opus\Common\LoggingTrait;
use ReflectionClass;
use Zend_Config_Ini;

use function array_filter;
use function class_exists;
use function filter_var;
use function is_readable;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const PHP_BINARY;
use const PHP_EOL;

/**
 * Class to read configuration data for tasks
 */
class TaskManager
{
    use ConfigTrait;
    use LoggingTrait;

    /** @var Zend_Config */
    protected $tasksConfig;

    public function __construct()
    {
        $this->init();
    }

    /**
     * Initializes the task configuration from the task ini file.
     * The name/path of the INI file to be used should be configured in the global configuration,
     * if not set, the global configuration file will be used to determine a configuration as a fallback.
     */
    protected function init()
    {
        $config = $this->getConfig();
        $logger = $this->getLogger();

        $fileName = $config->cron->configFile ?? '';

        $this->tasksConfig = [];

        if ($fileName) {
            if (! is_readable($fileName)) {
                $logger->err("Could not find or read task ini file: '$fileName'");
            } else {
                $tasksConfig = new Zend_Config_Ini($fileName);
                if ($tasksConfig === false) {
                    $logger->err("Could not parse task ini file: '$fileName'");
                } else {
                    $this->tasksConfig = $tasksConfig;
                }
            }
        } else {
            if (isset($config->cron->tasks)) {
                $this->tasksConfig = $config->cron->tasks;
            }
        }
    }

    /**
     * Determines all task configurations for existing task classes.
     *
     * @return array
     */
    public function getTaskConfigurations()
    {
        $tasks = [];
        foreach ($this->tasksConfig as $name => $config) {
            if (isset($config->class)) {
                $tasks[$name] = $this->createTaskConfig($name, $config);
            }
        }

        return $tasks;
    }

    /**
     * Determines all active task configurations for existing task classes.
     *
     * @return array
     */
    public function getActiveTaskConfigurations()
    {
        return array_filter($this->getTaskConfigurations(), function ($taskConfig) {
            if ($taskConfig->isEnabled()) {
                return $taskConfig;
            }
        });
    }

    /**
     * Gets task configuration by name
     *
     * @param string $name
     * @return TaskConfig|false
     */
    public function getTaskConfig($name)
    {
        if (isset($this->tasksConfig->$name)) {
            $taskConfig = $this->tasksConfig->$name;

            if (isset($taskConfig->class)) {
                return $this->createTaskConfig($name, $taskConfig);
            }
        }

        return false;
    }

    /**
     * @param string $name
     * @param mixed $config
     * @return TaskConfig
     */
    protected function createTaskConfig($name, $config)
    {
        $taskConfig = new TaskConfig();

        $taskConfig->setName($name)
            ->setClass($config->class ?? '')
            ->setSchedule(
                $config->schedule ?? TaskConfig::SCHEDULE_DEFAULT
            );

        if (
            isset($config->preventOverlapping) &&
            false === filter_var($config->preventOverlapping, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        ) {
            $taskConfig->setPreventOverlapping(false);
        } else {
            $taskConfig->setPreventOverlapping(true);
        }

        $taskConfig->setEnabled(
            isset($config->enabled) &&
            filter_var($config->enabled, FILTER_VALIDATE_BOOLEAN)
        );

        $options = [];
        if (isset($config->options)) {
            foreach ($config->options as $optionName => $optionValue) {
                $options[$optionName] = $optionValue;
            }
        }

        $taskConfig->setOptions($options);

        return $taskConfig;
    }

    /**
     * Check if a task class exists and implements the correct task interface.
     *
     * @param string $className
     * @return bool
     */
    public function isValidTaskClass($className)
    {
        if (! class_exists($className)) {
            $this->getLogger()->err('Task class unknown: ' . $className);
            return false;
        }

        $class       = new ReflectionClass($className);
        $parentClass = $class->getParentClass();

        if ($parentClass === false || $parentClass->getName() !== AbstractTask::class) {
            $this->getLogger()->err(
                'Task class does not extend interface: ' . AbstractTask::class
            );

            return false;
        }

        return true;
    }

    /**
     * Gets a Crunz scheduler initialized with all active tasks by adding them to the Crunz scheduler.
     *
     * @return Schedule
     */
    public function getCrunzSchedule()
    {
        $log      = $this->getLogger();
        $schedule = new Schedule();

        if ($this->isCrunzSchedulerEnabled()) {
            foreach ($this->getActiveTaskConfigurations() as $taskConfig) {
                $crunzTask = $schedule->run(
                    PHP_BINARY . " " . $this->getTaskRunnerScriptPath(),
                    ['--taskname' => $taskConfig->getName()]
                );

                if ($taskConfig->isPreventOverlapping()) {
                    $crunzTask->preventOverlapping();
                }

                $crunzTask
                    ->cron($taskConfig->getSchedule())
                    ->description($taskConfig->getName());

                $schedule
                    ->onError(function (Event $evt) use (&$error) {
                        $error .= $evt->getExpression() . ' ' . $evt->buildCommand() . PHP_EOL;
                        throw new Exception($error);
                    });
            }
        } else {
            $log->err("Couldn't access task scheduler configuration from ini file");
        }

        return $schedule;
    }

    /**
     * Gets the full path of the task runner script from the main configuration.
     *
     * @return string|null
     */
    public function getTaskRunnerScriptPath()
    {
        $config = $this->getConfig();
        $log    = $this->getLogger();

        $taskRunner = $config->cron->taskRunner;

        if (! isset($taskRunner)) {
            $log->err("Could not read the task runner path from configuration");
        }

        if (! is_readable($taskRunner)) {
            $log->err("Could not find or read task runner file: '" . $taskRunner . "'");
        }

        return $taskRunner;
    }

    /**
     * Checks if task scheduling is enabled in the main configuration
     *
     * @return bool
     */
    public function isCrunzSchedulerEnabled()
    {
        $config = $this->getConfig();
        return filter_var($config->cron->enabled, FILTER_VALIDATE_BOOLEAN) &&
            $this->getTaskRunnerScriptPath();
    }
}
