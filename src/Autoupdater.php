<?php
declare(strict_types=1);

namespace Mfc\Autoupdater;

use Composer\Factory;
use DateTime;
use DateTimeZone;
use Exception;
use Gitlab\Client as GitLabClient;
use Gitlab\Model\MergeRequest;
use Gitlab\Model\Project;
use Gitlab\Model\User;
use Gitonomy\Git\Repository;
use Mfc\Autoupdater\Composer\ComposerApplication;
use Mfc\Autoupdater\Configuration\AppConfiguration;
use Mfc\Autoupdater\Configuration\ProjectConfiguration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Class Autoupdater
 * @package Mfc\Autoupdater
 * @author Christian Spoo <cs@marketing-factory.de>
 */
class Autoupdater
{
    private const EXIT_SUCCESS = 0;
    private const EXIT_FAILURE = 1;

    /**
     * @var string
     */
    private $projectRoot;
    /**
     * @var AppConfiguration
     */
    private $appConfiguration;
    /**
     * @var ProjectConfiguration
     */
    private $projectConfiguration;
    /**
     * @var GitLabClient
     */
    private $gitlabClient;
    /**
     * @var array
     */
    private $updateMessages = [];

    /**
     * Autoupdater constructor.
     * @param string $directory
     */
    public function __construct(string $directory)
    {
        $this->projectRoot = $directory;
        $this->loadAppConfiguration();
        $this->loadProjectConfiguration($this->projectRoot . '/autoupdater.yaml');

        $this->gitlabClient = new GitLabClient();
        $this->gitlabClient->setUrl($this->appConfiguration->getGitlabUrl());
        $this->gitlabClient->authenticate($this->appConfiguration->getGitlabAuthToken(), GitLabClient::AUTH_HTTP_TOKEN);
    }

    private function loadAppConfiguration(): void
    {
        $this->appConfiguration = new AppConfiguration();
    }

    private function loadProjectConfiguration(string $filename): void
    {
        $this->projectConfiguration = new ProjectConfiguration($filename);
    }

    /**
     * @return string
     * @throws \ReflectionException
     */
    private static function getComposerHomeDir(): string
    {
        $factoryReflectionClass = new \ReflectionClass(Factory::class);
        $getHomeDirMethod = $factoryReflectionClass->getMethod('getHomeDir');
        $getHomeDirMethod->setAccessible(true);
        return (string)$getHomeDirMethod->invoke(null);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->updateIsNeeded($io)) {
            $io->text('No update needed.');
            return self::EXIT_SUCCESS;
        }

        $this->createUpdateBranch($io);
        if (!$this->performUpdates($io)) {
            return self::EXIT_FAILURE;
        }

        if (!$this->createUpdateCommit($io)) {
            // No updates were made
            return self::EXIT_SUCCESS;
        }

        $this->pushUpdateCommit($io);
        $this->createOrUpdateMergeRequest($io);

        return self::EXIT_SUCCESS;
    }

    private function updateIsNeeded(SymfonyStyle $io): bool
    {
        $io->section('Check if update is needed');

        $composerHomeDir = self::getComposerHomeDir();

        foreach ($this->projectConfiguration->getPackages() as $package) {
            $io->comment("Checking package {$package}...");

            $composer = new ComposerApplication(
                $composerHomeDir,
                realpath($this->projectRoot . '/' . $package)
            );
            $output = $composer->runComposerCommand([
                'command' => 'outdated',
                '--no-ansi' => true,
                '--strict' => true,
                '--minor-only' => true
            ], $this->projectRoot);

            $io->note('Output: ' . PHP_EOL . $output);

            if ($composer->getLastExitCode() > 0) {
                return true;
            }
        }

        return false;
    }

    private function createUpdateBranch(SymfonyStyle $io): void
    {
        $autoupdateBranch = $this->projectConfiguration->getBranch();

        $io->section('Creating fresh update branch');

        $repository = new Repository($this->projectRoot);

        $references = $repository->getReferences();
        if ($references->hasBranch($autoupdateBranch)) {
            $io->comment("Deleting old {$autoupdateBranch} branch");
            $repository->getReferences()->getBranch($autoupdateBranch)->delete();
        }

        $headCommit = $repository->getHeadCommit();
        $io->comment("Create new branch {$autoupdateBranch} based on commit {$headCommit->getHash()}");
        $repository->getReferences()->createBranch($autoupdateBranch, $headCommit->getHash());

        $io->comment("Switching to {$autoupdateBranch} branch");
        $workingCopy = $repository->getWorkingCopy();
        $workingCopy->checkout($autoupdateBranch);
    }

    private function performUpdates(SymfonyStyle $io): bool
    {
        $this->updateMessages = [];

        $composerHomeDir = self::getComposerHomeDir();

        $io->section('Performing package updates');
        foreach ($this->projectConfiguration->getPackages() as $package) {
            $io->comment("Updating package {$package}...");

            $composer = new ComposerApplication(
                $composerHomeDir,
                realpath($this->projectRoot . '/' . $package)
            );
            $composerOutput = $composer->runComposerCommand([
                'command' => 'update',
                '--no-ansi' => true,
                '--no-progress' => true
            ], $this->projectRoot);

            if ($composer->getLastExitCode() > 0) {
                return false;
            }

            $io->note('Output: ' . PHP_EOL . $composerOutput);

            $this->updateMessages[$package] = $composerOutput;
        }

        return true;
    }

    /**
     * @param SymfonyStyle $io
     * @return bool
     * @throws Exception
     */
    private function createUpdateCommit(SymfonyStyle $io): bool
    {
        $io->section('Creating update commit');

        $repository = new Repository($this->projectRoot);

        if (empty($repository->getWorkingCopy()->getDiffPending()->getFiles())) {
            $io->text("No update needed");
            return false;
        }

        $commitTitle = self::getUpdateTitle();
        $commitMessage = $this->getUpdateMessages($commitTitle);

        $repository->run(
            'commit',
            [
                '-a',
                '-m',
                $commitMessage
            ]
        );

        return true;
    }

    /**
     * @return string
     * @throws Exception
     */
    private static function getUpdateTitle(): string
    {
        $date = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('r');
        return "Automatic update on ${date}";
    }

    /**
     * @param string $commitTitle
     * @param bool $markdown
     * @return string
     */
    private function getUpdateMessages(string $commitTitle, bool $markdown = false): string
    {
        $updateMessages = '';
        foreach ($this->updateMessages as $package => $messages) {
            if ($markdown) {
                $updateMessages .= <<<MSG
## Updates for package {$package}

```
{$messages}
```
MSG;
            } else {
                $updateMessages .= <<<MSG
Updates for package {$package}

{$messages}
--------------------------------------------------------------------------------
MSG;
            }
        }

        return <<<MSG
${commitTitle}

${updateMessages}
MSG;
    }

    private function pushUpdateCommit(SymfonyStyle $io): void
    {
        $io->section('Push update commit to upstream repository');

        $repository = new Repository($this->projectRoot);

        try {
            $repository->run(
                'remote',
                [
                    'remove',
                    'upstream'
                ]
            );
        } catch (Exception $ex) {
        }

        $repository->run(
            'remote',
            [
                'add',
                'upstream',
                sprintf(
                    '%1$s/%2$s.git/',
                    $this->appConfiguration->getGitlabUrl(),
                    $this->projectConfiguration->getGitlabProjectName()
                )
            ]
        );

        $repository->run(
            'push',
            [
                '-f',
                'upstream',
                $this->projectConfiguration->getBranch()
            ]
        );

        $repository->getWorkingCopy()->checkout('develop');
    }

    /**
     * @param SymfonyStyle $io
     * @throws Exception
     */
    private function createOrUpdateMergeRequest(SymfonyStyle $io): void
    {
        $gitlabProject = $this->gitlabClient->projects()->show($this->projectConfiguration->getGitlabProjectName());
        $currentMergeRequests = $this->gitlabClient->mergeRequests()->all(
            $gitlabProject['id'],
            [
                'source_branch' => $this->projectConfiguration->getBranch(),
                'state' => 'opened'
            ]
        );

        $assignee = null;
        if ($this->projectConfiguration->getAssignee()) {
            $users = $this->gitlabClient->users()->all([
                'username' => $this->projectConfiguration->getAssignee()
            ]);

            $assignee = $users[0];
        }

        $mergeRequestTitle = self::getUpdateTitle();
        $mergeRequestDescription = $this->getUpdateMessages($mergeRequestTitle, true);

        $io->section('Creating or updating merge request');
        if (empty($currentMergeRequests)) {
            $io->comment("Creating merge request...");

            $this->gitlabClient->mergeRequests()->create(
                $gitlabProject['id'],
                $this->projectConfiguration->getBranch(),
                $this->projectConfiguration->getTargetBranch(),
                $mergeRequestTitle,
                [
                    'assignee_id' => is_array($assignee) ? $assignee['id'] : null,
                    'description' => $mergeRequestDescription,
                    'remove_source_branch' => true,
                    'squash' => true,
                ],
            );
        } else {
            $io->comment("Updating merge request...");

            $currentMergeRequest = $currentMergeRequests[0];
            $this->gitlabClient->mergeRequests()->update(
                $gitlabProject['id'],
                $currentMergeRequest['iid'],
                [
                    'title' => $mergeRequestTitle,
                    'description' => $mergeRequestDescription,
                    'assignee_id' => $assignee instanceof User ? $assignee->id : null,
                    'remove_source_branch' => true,
                    'squash' => true
                ]
            );
        }
    }
}
