<?php

declare(strict_types=1);

namespace Pilot\Installer;

use Pilot\Installer\Commands\NewCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('Pilot Installer', '0.1.0');

        $this->add(new NewCommand);
        $this->setDefaultCommand('new');
    }
}
