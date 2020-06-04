<?php
declare(strict_types=1);

namespace Mfc\Autoupdater\Composer;

use Symfony\Component\Console\Input\ArrayInput;

/**
 * Class ConsoleArrayInputFactory
 * @package Mfc\Autoupdater\Composer
 * @author Christian Spoo <cs@marketing-factory.de>
 */
class ConsoleArrayInputFactory
{
    /**
     * Create arrayInput instance.
     *
     * @param array $params
     * @return ArrayInput
     */
    public function create(array $params)
    {
        return new ArrayInput($params);
    }
}
