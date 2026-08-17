# Pilot Installer

The Pilot installer provides the global `pilot` executable used to create new Pilot CMS projects.

```bash
composer global require pilotcms/installer
pilot new my-project
```

During local installer development:

```bash
cd installer
composer install
composer global config repositories.pilot-installer path "$PWD"
composer global require pilotcms/installer:@dev
```

## Commands

Check the installed CLI with `pilot version` or `pilot --version`.

```text
pilot new <directory>
pilot new .
pilot new --path=/absolute/path
pilot new <directory> --branch=main
pilot new <directory> --no-build
pilot update
pilot update --path=/absolute/path
pilot update --dry-run
```

By default the command downloads the latest GitHub release archive from `PilotCMS/Pilot`. It falls back to the `main` branch until the first release exists.

`pilot update` updates the complete versioned application in `pilotcms/core`, advances legacy managed package constraints when needed, migrates legacy host integration, installs managed frontend dependencies, runs new database migrations, rebuilds frontend assets, and clears application caches. It refuses to change dirty Composer files unless you pass `--force`.

## Laravel Herd

When Laravel Herd is available, `pilot new` automatically links the project, isolates it to the active PHP 8.4 release, updates `APP_URL`, and prints the browser setup URL:

```bash
pilot new my-project
# Open http://my-project.test/setup
```

Use a trusted local HTTPS certificate:

```bash
pilot new my-project --secure
# Open https://my-project.test/setup
```

Customize the Herd domain or skip Herd integration:

```bash
pilot new my-project --site=content-hub
pilot new my-project --no-herd
```
