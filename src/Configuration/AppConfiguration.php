<?php
declare(strict_types=1);

namespace Mfc\Autoupdater\Configuration;

use Symfony\Component\Yaml\Yaml;

/**
 * Class AppConfiguration
 * @package Mfc\Autoupdater\Configuration
 * @author Christian Spoo <cs@marketing-factory.de>
 */
class AppConfiguration
{
    /**
     * @var string
     */
    private $gitlabUrl;
    /**
     * @var string
     */
    private $gitlabUserUsername;
    /**
     * @var string
     */
    private $gitlabUserEmail;
    /**
     * @var string
     */
    private $gitlabAuthToken;

    public function __construct(string $filename = null)
    {
        $configLocations = [
            '/etc/autoupdater.yaml',
            getenv('HOME') . '/.autoupdater.yaml'
        ];

        if (!is_null($filename)) {
            $configLocations[] = $filename;
        }

        $this->loadConfiguration($configLocations);

        if ($gitlabUrl = getenv('AUTOUPDATER_GITLAB_URL')) {
            $this->gitlabUrl = $gitlabUrl;
        }
    }

    private function loadConfiguration(array $searchPaths)
    {
        $config = [];
        foreach ($searchPaths as $file) {
            if (file_exists($file) && is_readable($file)) {
                $config = array_merge_recursive($config, $this->loadConfigurationFromFile($file));
            }
        }

        $this->gitlabUrl = $config['gitlab_url'];
        $this->gitlabUserUsername = $config['gitlab_user_username'];
        $this->gitlabUserEmail = $config['gitlab_user_email'];
        $this->gitlabAuthToken = $config['gitlab_auth_token'];
    }

    private function loadConfigurationFromFile(string $filename)
    {
        $config = Yaml::parseFile($filename);

        return [
            'gitlab_url' => $config['gitlab_url'],
            'gitlab_user_username' => $config['gitlab_user_username'],
            'gitlab_user_email' => $config['gitlab_user_email'],
            'gitlab_auth_token' => $config['gitlab_auth_token']
        ];
    }

    /**
     * @return string
     */
    public function getGitlabUrl(): string
    {
        return $this->gitlabUrl;
    }

    /**
     * @return string
     */
    public function getGitlabUserUsername(): string
    {
        return $this->gitlabUserUsername;
    }

    /**
     * @return string
     */
    public function getGitlabUserEmail(): string
    {
        return $this->gitlabUserEmail;
    }

    /**
     * @return string
     */
    public function getGitlabAuthToken(): string
    {
        return $this->gitlabAuthToken;
    }
}
