<?php
declare(strict_types=1);

namespace Mfc\Autoupdater;

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

        $httpClient = new Psr18Client(
            HttpClient::createForBaseUri($this->appConfiguration->getGitlabUrl())
        );

        $this->gitlabClient = GitLabClient::createWithHttpClient($httpClient)
            ->authenticate($this->appConfiguration->getGitlabAuthToken(), GitLabClient::AUTH_HTTP_TOKEN);
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
            return 0;
        }

        $this->createUpdateBranch($io);
        $this->performUpdates($io);

        if (!$this->createUpdateCommit($io)) {
            // No updates were made
            return 0;
        }

        $this->pushUpdateCommit($io);
        $this->createOrUpdateMergeRequest($io);

        return 0;
    }

    private function updateIsNeeded(SymfonyStyle $io): bool
    {
        $io->section('Check if update is needed');

        foreach ($this->projectConfiguration->getPackages() as $package) {
            $io->comment("Checking package {$package}...");

            $composer = new ComposerApplication(
                getenv('HOME') . '/.composer',
                realpath($this->projectRoot . '/' . $package)
            );
            $composer->runComposerCommand([
                'command' => 'outdated',
                '--no-ansi' => true,
                '--strict' => true,
                '--minor-only' => true
            ], $this->projectRoot);

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

    private function performUpdates(SymfonyStyle $io): void
    {
        $this->updateMessages = [];

        $io->section('Performing package updates');
        foreach ($this->projectConfiguration->getPackages() as $package) {
            $io->comment("Updating package {$package}...");

            $composer = new ComposerApplication(
                getenv('HOME') . '/.composer',
                realpath($this->projectRoot . '/' . $package)
            );
            $composerOutput = $composer->runComposerCommand([
                'command' => 'update',
                '--no-ansi' => true,
                '--no-progress' => true
            ], $this->projectRoot);

            $this->updateMessages[$package] = $composerOutput;
        }
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
        $gitlabProject = Project::fromArray(
            $this->gitlabClient,
            $this->gitlabClient->projects->show($this->projectConfiguration->getGitlabProjectName())
        );
        $currentMergeRequests = $this->gitlabClient->merge_requests->all(
            $gitlabProject->id,
            [
                'source_branch' => $this->projectConfiguration->getBranch(),
                'state' => 'opened'
            ]
        );

        $assignee = null;
        if ($this->projectConfiguration->getAssignee()) {
            $users = $this->gitlabClient->users->all([
                'username' => $this->projectConfiguration->getAssignee()
            ]);

            $assignee = User::fromArray($this->gitlabClient, $users[0]);
        }

        $mergeRequestTitle = self::getUpdateTitle();
        $mergeRequestDescription = $this->getUpdateMessages($mergeRequestTitle, true);

        $io->section('Creating or updating merge request');
        if (empty($currentMergeRequests)) {
            $io->comment("Creating merge request...");

            $this->gitlabClient->merge_requests->create(
                $gitlabProject->id,
                $this->projectConfiguration->getBranch(),
                'develop',
                $mergeRequestTitle,
                $assignee instanceof User ? $assignee->id : null,
                null,
                $mergeRequestDescription,
                [
                    'remove_source_branch' => true,
                    'squash' => true
                ]
            );
        } else {
            $io->comment("Updating merge request...");

            $currentMergeRequest = MergeRequest::fromArray(
                $this->gitlabClient,
                $gitlabProject,
                $currentMergeRequests[0]
            );
            $this->gitlabClient->merge_requests->update(
                $gitlabProject->id,
                $currentMergeRequest->iid,
                [
                    'title' => $mergeRequestTitle,
                    'description' => $mergeRequestDescription,
                    'assignee' => $assignee instanceof User ? $assignee->id : null,
                    'remove_source_branch' => true,
                    'squash' => true
                ]
            );
        }
    }
}
