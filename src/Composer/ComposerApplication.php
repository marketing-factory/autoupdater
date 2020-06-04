<?php
declare(strict_types=1);

namespace Mfc\Autoupdater\Composer;

use Composer\Console\Application;
use Composer\Factory as ComposerFactory;
use Composer\IO\BufferIO;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Copyright © 2016 Magento. All rights reserved.
 *
 * Class ComposerApplication
 * @package Mfc\Autoupdater\Composer
 * @author Max Lesechko
 */
class ComposerApplication
{
    const COMPOSER_WORKING_DIR = '--working-dir';

    /**
     * Path to Composer home directory
     *
     * @var string
     */
    private $composerHome;

    /**
     * Path to composer.json file
     *
     * @var string
     */
    private $composerJson;

    /**
     * Buffered output
     *
     * @var BufferedOutput
     */
    private $consoleOutput;

    /**
     * @var ConsoleArrayInputFactory
     */
    private $consoleArrayInputFactory;

    /**
     * @var Application
     */
    private $consoleApplication;

    /**
     * @var int|null
     */
    private $lastExitCode = null;

    /**
     * Constructs class
     *
     * @param string $pathToComposerHome
     * @param string $pathToComposerJson
     * @param Application $consoleApplication
     * @param ConsoleArrayInputFactory $consoleArrayInputFactory
     * @param BufferedOutput $consoleOutput
     */
    public function __construct(
        $pathToComposerHome,
        $pathToComposerJson,
        Application $consoleApplication = null,
        ConsoleArrayInputFactory $consoleArrayInputFactory = null,
        BufferedOutput $consoleOutput = null
    ) {
        $this->consoleApplication = $consoleApplication ? $consoleApplication : new Application();
        $this->consoleArrayInputFactory = $consoleArrayInputFactory ? $consoleArrayInputFactory
            : new ConsoleArrayInputFactory();
        $this->consoleOutput = $consoleOutput ? $consoleOutput : new BufferedOutput();

        $this->composerJson = $pathToComposerJson;
        $this->composerHome = $pathToComposerHome;

        putenv('COMPOSER_HOME=' . $pathToComposerHome);

        $this->consoleApplication->setAutoExit(false);
    }

    /**
     * Creates composer object
     *
     * @return \Composer\Composer
     * @throws \Exception
     */
    public function createComposer()
    {
        return ComposerFactory::create(new BufferIO(), $this->composerJson);
    }

    /**
     * Runs composer command
     *
     * @param array $commandParams
     * @param string|null $workingDir
     * @return string
     * @throws \RuntimeException
     */
    public function runComposerCommand(array $commandParams, $workingDir = null): string
    {
        $this->consoleApplication->resetComposer();

        if ($workingDir) {
            $commandParams[self::COMPOSER_WORKING_DIR] = $workingDir;
        } else {
            $commandParams[self::COMPOSER_WORKING_DIR] = dirname($this->composerJson);
        }

        $input = $this->consoleArrayInputFactory->create($commandParams);

        $exitCode = $this->consoleApplication->run($input, $this->consoleOutput);
        $this->lastExitCode = $exitCode;

        return $this->consoleOutput->fetch();
    }

    /**
     * @return int|null
     */
    public function getLastExitCode(): ?int
    {
        return $this->lastExitCode;
    }
}
