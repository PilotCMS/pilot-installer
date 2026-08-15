<?php

declare(strict_types=1);

namespace Pilot\Installer\Commands;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

#[AsCommand(name: 'new', description: 'Create a new Pilot CMS project')]
class NewCommand extends Command
{
    private const REPOSITORY = 'WindfallInc/Pilot';

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory name or path', '.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Explicit installation path')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Install a Git branch instead of the latest stable release')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Skip npm install and the production asset build');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem;

        try {
            $target = $this->targetPath((string) ($input->getOption('path') ?: $input->getArgument('directory')));
            $this->assertTargetIsAvailable($target);
            $this->assertRequirements((bool) $input->getOption('no-build'));

            $io->title('Creating a new Pilot project');
            $io->text('Destination: '.$target);

            [$archiveUrl, $version] = $this->resolveArchive($input->getOption('branch'));
            $io->section('Downloading Pilot '.$version);
            $this->downloadAndExtract($archiveUrl, $target, $filesystem);

            $io->section('Installing application dependencies');
            $this->runProcess(['composer', 'install', '--no-interaction', '--prefer-dist'], $target, $output);

            if (! file_exists($target.'/.env')) {
                $filesystem->copy($target.'/.env.example', $target.'/.env');
            }

            $this->runProcess([PHP_BINARY, 'artisan', 'key:generate', '--ansi'], $target, $output);
            $this->runProcess([PHP_BINARY, 'artisan', 'storage:link', '--ansi'], $target, $output, allowFailure: true);

            if (! $input->getOption('no-build')) {
                $io->section('Building frontend assets');
                $this->runProcess(['npm', 'install'], $target, $output);
                $this->runProcess(['npm', 'run', 'build'], $target, $output);
            }

            $projectName = basename($target);
            $io->success('Pilot was installed successfully.');
            $io->writeln([
                'Open the project in your IDE:',
                '  <info>cd '.$target.'</info>',
                '',
                'If this folder is served by Laravel Herd, open:',
                '  <info>http://'.strtolower($projectName).'.test/setup</info>',
                '',
                'Otherwise start the local server:',
                '  <info>php artisan serve</info>',
                '  <info>http://127.0.0.1:8000/setup</info>',
            ]);

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function targetPath(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('Please provide an installation directory.');
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = getcwd().DIRECTORY_SEPARATOR.$path;
        }

        return rtrim(Path::canonicalize($path), DIRECTORY_SEPARATOR);
    }

    private function assertTargetIsAvailable(string $target): void
    {
        if (is_dir($target) && count(scandir($target) ?: []) > 2) {
            throw new RuntimeException('The target directory is not empty: '.$target);
        }

        if (file_exists($target) && ! is_dir($target)) {
            throw new RuntimeException('The target path is not a directory: '.$target);
        }
    }

    private function assertRequirements(bool $skipBuild): void
    {
        if (PHP_VERSION_ID < 80401) {
            throw new RuntimeException('Pilot requires PHP 8.4.1 or newer.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Pilot requires the PHP zip extension.');
        }

        $finder = new ExecutableFinder;

        if (! $finder->find('composer')) {
            throw new RuntimeException('Composer was not found in your PATH.');
        }

        if (! $skipBuild && ! $finder->find('npm')) {
            throw new RuntimeException('npm was not found in your PATH. Use --no-build to install without frontend assets.');
        }
    }

    /** @return array{string, string} */
    private function resolveArchive(mixed $branch): array
    {
        if (is_string($branch) && $branch !== '') {
            return [
                'https://github.com/'.self::REPOSITORY.'/archive/refs/heads/'.rawurlencode($branch).'.zip',
                $branch,
            ];
        }

        try {
            $response = HttpClient::create()->request('GET', 'https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest', [
                'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Pilot-Installer'],
            ]);
            $release = $response->toArray();

            return [$release['zipball_url'], $release['tag_name']];
        } catch (Throwable) {
            return [
                'https://github.com/'.self::REPOSITORY.'/archive/refs/heads/main.zip',
                'main',
            ];
        }
    }

    private function downloadAndExtract(string $url, string $target, Filesystem $filesystem): void
    {
        $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pilot-'.bin2hex(random_bytes(8));
        $archive = $temporary.DIRECTORY_SEPARATOR.'pilot.zip';
        $extracted = $temporary.DIRECTORY_SEPARATOR.'extracted';
        $filesystem->mkdir([$temporary, $extracted, $target]);

        try {
            $client = HttpClient::create();
            $response = $client->request('GET', $url, [
                'headers' => ['User-Agent' => 'Pilot-Installer'],
            ]);
            $handle = fopen($archive, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Could not create the temporary Pilot archive.');
            }

            foreach ($client->stream($response) as $chunk) {
                if (! $chunk->isTimeout() && $chunk->getContent() !== '') {
                    fwrite($handle, $chunk->getContent());
                }
            }

            fclose($handle);
            $zip = new ZipArchive;

            if ($zip->open($archive) !== true || ! $zip->extractTo($extracted)) {
                throw new RuntimeException('The downloaded Pilot archive could not be extracted.');
            }

            $zip->close();
            $roots = array_values(array_filter(scandir($extracted) ?: [], fn (string $item): bool => ! in_array($item, ['.', '..'], true)));

            if (count($roots) !== 1 || ! is_dir($extracted.DIRECTORY_SEPARATOR.$roots[0])) {
                throw new RuntimeException('The downloaded Pilot archive has an unexpected structure.');
            }

            $filesystem->mirror($extracted.DIRECTORY_SEPARATOR.$roots[0], $target);
        } finally {
            $filesystem->remove($temporary);
        }
    }

    /** @param list<string> $command */
    private function runProcess(array $command, string $workingDirectory, OutputInterface $output, bool $allowFailure = false): void
    {
        $process = new Process($command, $workingDirectory, timeout: null);
        $process->run(fn (string $type, string $buffer) => $output->write($buffer));

        if (! $process->isSuccessful() && ! $allowFailure) {
            throw new RuntimeException(sprintf('Command failed: %s', $process->getCommandLine()));
        }
    }
}
