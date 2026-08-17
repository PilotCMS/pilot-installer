<?php

declare(strict_types=1);

namespace Pilot\Installer;

use Pilot\Installer\Commands\NewCommand;
use Pilot\Installer\Commands\UpdateCommand;
use Pilot\Installer\Commands\VersionCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('Pilot Installer', '0.2.2');

        $this->add(new NewCommand);
        $this->add(new UpdateCommand);
        $this->add(new VersionCommand);
        $this->setDefaultCommand('new');
    }
}
