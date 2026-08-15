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
    private const REPOSITORY = 'PilotCMS/Pilot';

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory name or path', '.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Explicit installation path')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Install a Git branch instead of the latest stable release')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Skip npm install and the production asset build')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Herd site name (defaults to the directory name)')
            ->addOption('secure', null, InputOption::VALUE_NONE, 'Secure the Herd site with a trusted HTTPS certificate')
            ->addOption('no-herd', null, InputOption::VALUE_NONE, 'Do not automatically link the project with Laravel Herd');
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
            $composer = (new ExecutableFinder)->find('composer');
            $this->runProcess([PHP_BINARY, (string) $composer, 'install', '--no-interaction', '--prefer-dist'], $target, $output);

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

            $herdUrl = $this->configureHerd($input, $output, $io, $target);

            $io->success('Pilot was installed successfully.');
            $instructions = [
                'Open the project in your IDE:',
                '  <info>cd '.$target.'</info>',
                '',
            ];

            if ($herdUrl !== null) {
                $instructions[] = 'Finish setup in your browser:';
                $instructions[] = '  <info>'.$herdUrl.'/setup</info>';
            } else {
                $instructions[] = 'Start the local server and finish setup:';
                $instructions[] = '  <info>php artisan serve</info>';
                $instructions[] = '  <info>http://127.0.0.1:8000/setup</info>';
            }

            $io->writeln($instructions);

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

    private function configureHerd(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $target): ?string
    {
        if ($input->getOption('no-herd')) {
            return null;
        }

        $herd = (new ExecutableFinder)->find('herd');

        if ($herd === null) {
            if ($input->getOption('secure')) {
                throw new RuntimeException('The --secure option requires Laravel Herd, but Herd was not found in your PATH.');
            }

            return null;
        }

        $site = $this->herdSiteName((string) ($input->getOption('site') ?: basename($target)));
        $phpVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $command = [
            $herd,
            'link',
            $site,
            '--isolate='.$phpVersion,
            '--update-env',
            '--no-interaction',
        ];

        if ($input->getOption('secure')) {
            $command[] = '--secure';
        }

        $io->section('Linking the project with Laravel Herd');
        $linked = $this->runProcess($command, $target, $output, allowFailure: true);

        if (! $linked) {
            $io->warning('Pilot was installed, but Herd could not link the site. Run `herd link` inside the project or use `php artisan serve`.');

            return null;
        }

        return ($input->getOption('secure') ? 'https://' : 'http://').$site.'.'.$this->herdTld($herd);
    }

    private function herdTld(string $herd): string
    {
        $process = new Process([$herd, 'tld'], timeout: 10);
        $process->run();
        $tld = trim($process->getOutput());

        return $process->isSuccessful() && preg_match('/^[a-z0-9-]+$/i', $tld) === 1
            ? strtolower($tld)
            : 'test';
    }

    private function herdSiteName(string $name): string
    {
        $name = strtolower($name);
        $name = (string) preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = trim($name, '-');

        if ($name === '') {
            throw new RuntimeException('The Herd site name must contain at least one letter or number.');
        }

        return $name;
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
    private function runProcess(array $command, string $workingDirectory, OutputInterface $output, bool $allowFailure = false): bool
    {
        $process = new Process($command, $workingDirectory, timeout: null);
        $process->run(fn (string $type, string $buffer) => $output->write($buffer));

        if (! $process->isSuccessful() && ! $allowFailure) {
            throw new RuntimeException(sprintf('Command failed: %s', $process->getCommandLine()));
        }

        return $process->isSuccessful();
    }
}
