<?php

use Mfc\Autoupdater\Autoupdater;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require __DIR__ . '/vendor/autoload.php';

$application = (new SingleCommandApplication())
    ->setName('Composer Autoupdater')
    ->setVersion('@package_version@')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $repositoryPath = getcwd();
        $gitlabProjectName = getenv('AUTOUPDATER_PROJECT_NAME');

        $autoupdater = new Autoupdater($gitlabProjectName, $repositoryPath);
        $autoupdater->run($input, $output);

        return;
    });
$application->run();

