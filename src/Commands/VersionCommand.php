<?php

declare(strict_types=1);

namespace Pilot\Installer\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'version', description: 'Show the Pilot Installer version')]
class VersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->getApplication()?->getLongVersion() ?? 'Pilot Installer');

        return self::SUCCESS;
    }
}
