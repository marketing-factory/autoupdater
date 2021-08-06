<?php
declare(strict_types=1);

namespace Mfc\Autoupdater\Configuration;

use Symfony\Component\Yaml\Yaml;

/**
 * Class ProjectConfiguration
 * @package Mfc\Autoupdater\ProjectConfiguration
 * @author Christian Spoo <cs@marketing-factory.de>
 */
class ProjectConfiguration
{
    public const DEFAULT_BRANCH_NAME = 'support/autoupdate';
    public const DEFAULT_TARGET_BRANCH_NAME = 'develop';

    /**
     * @var string|null
     */
    private $assignee = null;
    /**
     * @var string
     */
    private $branch = self::DEFAULT_BRANCH_NAME;
    /**
     * @var string
     */
    private $targetBranch = self::DEFAULT_TARGET_BRANCH_NAME;
    /**
     * @var string
     */
    private $gitlabProjectName;
    /**
     * @var string[]
     */
    private $packages = [
        'composer.json'
    ];

    public function __construct(string $filename)
    {
        $config = Yaml::parseFile($filename);

        if (isset($config['assignee'])) {
            $this->assignee = (string)$config['assignee'];
        }

        if (isset($config['branch'])) {
            $this->branch = (string)$config['branch'];
        }

        if (isset($config['target_branch'])) {
            $this->targetBranch = (string)$config['target_branch'];
        }

        if (isset($config['packages'])) {
            $this->packages = array_values($config['packages']);
        }

        if (!($this->gitlabProjectName = getenv('AUTOUPDATER_PROJECT_NAME'))) {
            if (isset($config['gitlab_project_name'])) {
                $this->gitlabProjectName = $config['gitlab_project_name'];
            } else {
                throw new \RuntimeException(
                    'Please either specify gitlab_project_name in autoupdater.yaml or supply the ' .
                    'AUTOUPDATER_PROJECT_NAME env variable'
                );
            }
        }
    }

    /**
     * @return string|null
     */
    public function getAssignee(): ?string
    {
        return $this->assignee;
    }

    /**
     * @return string
     */
    public function getBranch(): string
    {
        return $this->branch;
    }

    /**
     * @return string
     */
    public function getTargetBranch(): string
    {
        return $this->targetBranch;
    }

    /**
     * @return string[]
     */
    public function getPackages(): array
    {
        return $this->packages;
    }

    /**
     * @return string
     */
    public function getGitlabProjectName(): string
    {
        return $this->gitlabProjectName;
    }
}
