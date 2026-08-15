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

```text
pilot new <directory>
pilot new .
pilot new --path=/absolute/path
pilot new <directory> --branch=main
pilot new <directory> --no-build
```

By default the command downloads the latest GitHub release archive from `WindfallInc/Pilot`. It falls back to the `main` branch until the first release exists.
