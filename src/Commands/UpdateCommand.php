<?php

declare(strict_types=1);

namespace Pilot\Installer\Commands;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(name: 'update', description: 'Update an existing Pilot CMS project')]
class UpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Pilot project path', '.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check for an update without changing files')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Skip the frontend production build')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Continue when Composer files have uncommitted changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $path = (string) $input->getOption('path');
            $project = Path::canonicalize(str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : getcwd().DIRECTORY_SEPARATOR.$path);

            if (! file_exists($project.'/artisan') || ! file_exists($project.'/composer.json')) {
                throw new RuntimeException('Run this command inside a Pilot project or provide --path.');
            }

            $composer = json_decode((string) file_get_contents($project.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

            if (! isset($composer['require']['pilotcms/core'])) {
                throw new RuntimeException('This project does not use the updatable pilotcms/core package.');
            }

            $command = [PHP_BINARY, 'artisan', 'pilot:update'];

            foreach (['dry-run', 'no-build', 'force'] as $option) {
                if ($input->getOption($option)) {
                    $command[] = '--'.$option;
                }
            }

            $io->title('Updating Pilot');
            $process = new Process($command, $project, timeout: null);
            $process->run(fn (string $type, string $buffer) => $output->write($buffer));

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
