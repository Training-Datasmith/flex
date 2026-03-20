# Architecture: flex

## Purpose

Symfony Flex is a Composer plugin that automates Symfony application setup. When packages are installed, Flex downloads and applies "recipes" — configuration files, directory structures, and environment variables — so developers get working defaults without manual setup.

## Directory Structure

```
src/
  Flex.php                              — Main Composer plugin: subscribes to install/update/remove events
  Recipe.php                            — Value object: a downloaded recipe with its manifest data
  Lock.php                              — Tracks installed recipes and their versions in symfony.lock
  Downloader.php                        — Downloads recipes from the Symfony recipe server (or custom endpoint)
  Package_Resolver.php                  — Resolves package aliases and unpacks Symfony Packs
  Package_Filter.php                    — Filters packages eligible for recipe installation
  Unpacker.php                          — Expands Symfony Packs into their constituent packages
  Script_Executor.php                   — Runs Composer scripts defined in recipes
  Github_Api.php                        — GitHub API client for recipe update workflows
  Options.php                           — Reads Flex configuration from composer.json extra keys
  Path.php                              — Path utilities for computing relative project paths
  Response.php                          — Wraps Flex API responses
  Symfony_Bundle.php                    — Detects and manages Symfony bundle registration
  Symfony_Pack_Installer.php            — Handles Pack installation lifecycle
  Package_Json_Synchronizer.php         — Keeps package.json in sync when JS assets change

  Configurator.php                      — Dispatches recipe manifest keys to the right configurator
  Configurator/
    Bundles_Configurator.php            — Registers bundles in config/bundles.php
    Copy_From_Recipe_Configurator.php   — Copies files from the recipe into the project
    Copy_From_Package_Configurator.php  — Copies files from the installed Composer package
    Dotenv_Configurator.php             — Adds .env variable definitions
    Env_Configurator.php                — Sets environment variable defaults
    Container_Configurator.php          — Adds DI container parameters/bindings
    Gitignore_Configurator.php          — Appends .gitignore entries
    Makefile_Configurator.php           — Appends Makefile targets
    Dockerfile_Configurator.php         — Modifies Dockerfile
    Docker_Compose_Configurator.php     — Modifies docker-compose.yaml
    Composer_Scripts_Configurator.php   — Adds Composer scripts
    Add_Lines_Configurator.php          — Appends arbitrary lines to existing files

  Command/
    Install_Recipes_Command.php         — `composer recipes:install` — (re)apply a recipe
    Update_Recipes_Command.php          — `composer recipes:update` — update a recipe to a newer version
    Recipes_Command.php                 — `composer recipes` — list installed recipes
    Dump_Env_Command.php                — `composer dump-env` — compile .env to .env.local.php

  Update/
    Recipe_Patcher.php                  — Applies recipe diffs when updating to a newer recipe version
    Diff_Helper.php                     — Unified diff utilities
    Recipe_Patch.php / Recipe_Update.php — Value objects for recipe update data

  Event/
    Update_Event.php                    — Dispatched during recipe update for extension points
```

## Key Design Decisions

- **Recipe manifest dispatch** — each key in a recipe's `manifest.json` (e.g., `bundles`, `copy-from-recipe`, `env`) maps to a dedicated `Configurator` class, making it trivial to add new recipe action types.
- **symfony.lock** — tracks which recipe version is installed per package, enabling deterministic updates and preventing recipe re-application.
- **Pack expansion** — Symfony Packs are meta-packages; Flex expands them to their constituent packages and installs each package's recipe individually.
- **Reversible install** — most configurators implement an `unconfigure()` method used when a package is removed, automatically cleaning up files, bundles, and environment variables.
- **Custom recipe endpoints** — projects can configure alternative recipe servers via `extra.symfony.endpoint` in `composer.json`, enabling private recipe repositories.

## Extension Points

- Implement a custom `Abstract_Configurator` subclass and register it to handle new recipe manifest keys.
- Configure a custom recipe endpoint via `composer.json` → `extra.symfony.endpoint`.

## Dependency Flow

```
Composer install/update
  └── Flex (plugin)
        ├── Package_Resolver (resolve aliases + packs)
        ├── Downloader (fetch recipe manifests from API)
        └── Configurator (apply manifest)
              └── per-key configurators (Bundles, CopyFiles, Dotenv, etc.)
                    └── symfony.lock updated
```
