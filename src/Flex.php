<?php

declare (strict_types=1);
/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Symfony\Flex;

use Composer\Command\Base_Config_Command;
use Composer\Command\Global_Command;
use Composer\Composer;
use Composer\Console\Application;
use Composer\Dependency_Resolver\Operation\Install_Operation;
use Composer\Dependency_Resolver\Operation\Operation_Interface;
use Composer\Dependency_Resolver\Operation\Uninstall_Operation;
use Composer\Dependency_Resolver\Operation\Update_Operation;
use Composer\Dependency_Resolver\Transaction;
use Composer\Event_Dispatcher\Event_Subscriber_Interface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\Installer_Event;
use Composer\Installer\Installer_Events;
use Composer\Installer\Package_Event;
use Composer\Installer\Package_Events;
use Composer\Installer\Suggested_Packages_Reporter;
use Composer\IO\Io_Interface;
use Composer\IO\Null_Io;
use Composer\Json\Json_File;
use Composer\Json\Json_Manipulator;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Plugin\Plugin_Events;
use Composer\Plugin\Plugin_Interface;
use Composer\Plugin\Pre_Pool_Create_Event;
use Composer\Script\Event;
use Composer\Script\Script_Events;
use Composer\Semver\Version_Parser;
use Symfony\Component\Console\Input\Argv_Input;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Event\Update_Event;
use Symfony\Flex\Unpack\Operation;
use Symfony\Thanks\Thanks;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Flex implements Plugin_Interface, Event_Subscriber_Interface
{
    public static $stored_operations = [];
    private ?\Composer\Composer $composer = null;
    private ?\Composer\IO\Io_Interface $io = null;
    private $config;
    private ?\Symfony\Flex\Options $options = null;
    private ?\Symfony\Flex\Configurator $configurator = null;
    private ?\Symfony\Flex\Downloader $downloader = null;
    /**
     * @var Installer
     */
    private $installer;
    private array $post_install_output = [''];
    private $operations = [];
    private ?\Symfony\Flex\Lock $lock = null;
    private int $display_thanks_reminder = 0;
    private bool $ignore_preleases = false;
    private ?bool $reinstall = null;
    private static bool $activated = true;
    private static array $alias_resolve_commands = ['require' => true, 'update' => false, 'remove' => false];
    private ?\Symfony\Flex\Package_Filter $filter = null;
    public function activate(Composer $composer, Io_Interface $io): void
    {
        if (!\extension_loaded('openssl')) {
            self::$activated = false;
            $io->write_error('<warning>Symfony Flex has been disabled. You must enable the openssl extension in your "php.ini" file.</>');
            return;
        }
        // to avoid issues when Flex is upgraded, we load all PHP classes now
        // that way, we are sure to use all classes from the same version
        foreach (new \Recursive_Iterator_Iterator(new \Recursive_Directory_Iterator(__DIR__, \Filesystem_Iterator::SKIP_DOTS)) as $file) {
            if (str_ends_with((string) $file, '.php')) {
                class_exists(__NAMESPACE__ . str_replace('/', '\\', substr((string) $file, \strlen(__DIR__), -4)));
            }
        }
        $composer->get_installation_manager()->add_installer(new Symfony_Pack_Installer($io));
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $composer->get_config();
        $composer_file = Factory::get_composer_file();
        $composer_lock = 'json' === pathinfo($composer_file, \PATHINFO_EXTENSION) ? substr($composer_file, 0, -4) . 'lock' : $composer_file . '.lock';
        $symfony_lock = str_replace('composer', 'symfony', basename($composer_lock));
        $this->lock = new Lock(getenv('SYMFONY_LOCKFILE') ?: \dirname($composer_lock) . '/' . (basename($composer_lock) !== $symfony_lock ? $symfony_lock : 'symfony.lock'));
        $this->options = $this->init_options();
        // if Flex is being upgraded, the original operations from the original Flex
        // instance are stored in the static property, so we can reuse them now.
        if (property_exists(Flex::class, 'storedOperations') && Flex::$stored_operations) {
            $this->operations = Flex::$stored_operations;
            Flex::$stored_operations = [];
        }
        $rfs = $composer->get_loop()->get_http_downloader();
        $this->downloader = $downloader = new Downloader($composer, $io, $rfs);
        $this->configurator = new Configurator($composer, $io, $this->options);
        $disable = true;
        foreach (array_merge($composer->get_package()->get_requires() ?? [], $composer->get_package()->get_dev_requires() ?? []) as $link) {
            // recipes apply only when symfony/flex is found in "require" or "require-dev" in the root package
            if ('symfony/flex' === $link->get_target()) {
                $disable = false;
                break;
            }
        }
        if ($disable) {
            $downloader->disable();
        }
        $backtrace = $this->configure_installer();
        foreach ($backtrace as $trace) {
            if (!isset($trace['object'])) {
                continue;
            }
            if (!isset($trace['args'][0])) {
                continue;
            }
            if (!$trace['object'] instanceof Application) {
                continue;
            }
            if (!$trace['args'][0] instanceof Argv_Input) {
                continue;
            }
            // In Composer 1.0.*, $input knows about option and argument definitions
            // Since Composer >=1.1, $input contains only raw values
            $input = $trace['args'][0];
            $app = $trace['object'];
            $resolver = new Package_Resolver($this->downloader);
            try {
                $command = $input->get_first_argument();
                $command = $command ? $app->find($command)->get_name() : null;
            } catch (\InvalidArgumentException) {
            }
            if ('create-project' === $command) {
                if ($input->has_option('remove-vcs')) {
                    $input->set_option('remove-vcs', true);
                }
            } elseif ('update' === $command) {
                $this->display_thanks_reminder = 1;
            } elseif ('outdated' === $command) {
                $symfony_require = null;
            }
            if (isset(self::$alias_resolve_commands[$command])) {
                if ($input->has_argument('packages')) {
                    $input->set_argument('packages', $resolver->resolve($input->get_argument('packages'), self::$alias_resolve_commands[$command]));
                }
            }
            if (class_exists(Base_Config_Command::class)) {
                // composer 2.9+
                $_SERVER['COMPOSER_PREFER_DEV_OVER_PRERELEASE'] = '1';
            } else {
                $this->ignore_preleases = $input->has_parameter_option('--prefer-lowest', true) && $input->has_parameter_option('--prefer-stable', true);
            }
            $add_command = 'add' . (method_exists($app, 'addCommand') ? 'Command' : '');
            $app->{$add_command}(new Command\Recipes_Command($this, $this->lock, $rfs));
            $app->{$add_command}(new Command\Install_Recipes_Command($this, $this->options->get('root-dir'), $this->options->get('runtime')['dotenv_path'] ?? '.env'));
            $app->{$add_command}(new Command\Update_Recipes_Command($this, $this->downloader, $rfs, $this->configurator, $this->options->get('root-dir')));
            $app->{$add_command}(new Command\Dump_Env_Command($this->config, $this->options));
            break;
        }
        $symfony_require = preg_replace('/\.x$/', '.x-dev', (string) getenv('SYMFONY_REQUIRE') ?: $composer->get_package()->get_extra()['symfony']['require'] ?? '');
        if ($symfony_require || $this->ignore_preleases) {
            $this->filter = new Package_Filter($io, $symfony_require, $this->downloader, $this->ignore_preleases);
        }
    }
    public function deactivate(Composer $composer, Io_Interface $io): void
    {
        // Using `Flex::` instead of `self::` to avoid issues when
        // composer renames plugin classes when upgrading them
        Flex::$stored_operations = $this->operations;
        self::$activated = false;
    }
    /**
     * @return array{function: string, line?: int, file?: string, class?: class-string, type?: ('->' | '::'), args?: list<mixed>, object?: object}[]
     */
    public function configure_installer(): array
    {
        $backtrace = debug_backtrace();
        foreach ($backtrace as $trace) {
            if (isset($trace['object']) && $trace['object'] instanceof Installer) {
                $this->installer = $trace['object']->set_suggested_packages_reporter(new Suggested_Packages_Reporter(new Null_Io()));
            }
            if (isset($trace['object']) && $trace['object'] instanceof Global_Command) {
                $this->downloader->disable();
            }
        }
        return $backtrace;
    }
    public function configure_project(Event $event): void
    {
        if (!$this->downloader->is_enabled()) {
            $this->io->write_error('<warning>Project configuration is disabled: "symfony/flex" not found in the root composer.json</>');
            return;
        }
        // Remove LICENSE (which do not apply to the user project)
        @unlink('LICENSE');
        // Update composer.json (project is proprietary by default)
        $file = Factory::get_composer_file();
        $contents = file_get_contents($file);
        $manipulator = new Json_Manipulator($contents);
        // new projects are most of the time proprietary
        $manipulator->add_main_key('license', 'proprietary');
        // extra.branch-alias doesn't apply to the project
        $manipulator->remove_sub_node('extra', 'branch-alias');
        // 'name' and 'description' are only required for public packages
        // don't use $manipulator->removeProperty() for BC with Composer 1.0
        $contents = preg_replace(['{^\s*+"name":.*,$\n}m', '{^\s*+"description":.*,$\n}m'], '', $manipulator->get_contents(), 1);
        file_put_contents($file, $contents);
        $this->update_composer_lock();
    }
    public function record_flex_install(Package_Event $event): void
    {
        if (null === $this->reinstall && 'symfony/flex' === $event->get_operation()->get_package()->get_name()) {
            $this->reinstall = true;
        }
    }
    public function record(Package_Event $event): void
    {
        if ($this->should_record_operation($event->get_operation(), $event->is_dev_mode(), $event->get_composer())) {
            $this->operations[] = $event->get_operation();
        }
    }
    public function record_operations(Installer_Event $event): void
    {
        if (!$event->is_executing_operations()) {
            return;
        }
        $version_parser = new Version_Parser();
        $packages = [];
        foreach ($this->lock->all() as $name => $info) {
            if ('9999999.9999999' === $info['version']) {
                // Fix invalid versions found in some lock files
                $info['version'] = '99999.9999999';
            }
            $packages[] = new Package($name, $version_parser->normalize($info['version']), $info['version']);
        }
        $transation = \Closure::bind(fn() => new Transaction($packages, $event->get_transaction()->result_package_map), null, Transaction::class)();
        foreach ($transation->get_operations() as $operation) {
            if (!$operation instanceof Uninstall_Operation && $this->should_record_operation($operation, $event->is_dev_mode(), $event->get_composer())) {
                $this->operations[] = $operation;
            }
        }
    }
    public function update(Event $event, $operations = []): void
    {
        if ($operations) {
            $this->operations = $operations;
        }
        $this->install($event);
        $file = Factory::get_composer_file();
        $contents = file_get_contents($file);
        $json = Json_File::parse_json($contents);
        if (!$this->reinstall && !isset($json['flex-require']) && !isset($json['flex-require-dev'])) {
            $this->unpack();
            return;
        }
        // merge "flex-require" with "require"
        $manipulator = new Json_Manipulator($contents);
        $sort_packages = $this->composer->get_config()->get('sort-packages');
        $symfony_version = $json['extra']['symfony']['require'] ?? null;
        $versions = $symfony_version ? $this->downloader->get_versions() : null;
        foreach (['require', 'require-dev'] as $type) {
            if (!isset($json['flex-' . $type])) {
                continue;
            }
            foreach ($json['flex-' . $type] as $package => $constraint) {
                if ($symfony_version && '*' === $constraint && isset($versions['splits'][$package])) {
                    // replace unbounded constraints for symfony/* packages by extra.symfony.require
                    $constraint = $symfony_version;
                }
                $manipulator->add_link($type, $package, $constraint, $sort_packages);
            }
            $manipulator->remove_main_key('flex-' . $type);
        }
        file_put_contents($file, $manipulator->get_contents());
        $this->reinstall($event);
    }
    public function install(Event $event): void
    {
        $root_dir = $this->options->get('root-dir');
        $runtime = $this->options->get('runtime');
        $dotenv_path = $root_dir . '/' . ($runtime['dotenv_path'] ?? '.env');
        if (!file_exists($dotenv_path) && !file_exists($dotenv_path . '.local') && file_exists($dotenv_path . '.dist') && !str_contains(file_get_contents($dotenv_path . '.dist'), '.env.local')) {
            copy($dotenv_path . '.dist', $dotenv_path);
        }
        // Execute missing recipes
        $recipes = Script_Events::POST_UPDATE_CMD === $event->get_name() ? $this->fetch_recipes($this->operations, $event instanceof Update_Event && $event->reset()) : [];
        $this->operations = [];
        // Reset the operation after getting recipes
        if (2 === $this->display_thanks_reminder) {
            $love = '\\' === \DIRECTORY_SEPARATOR ? 'love' : '💖 ';
            $star = '\\' === \DIRECTORY_SEPARATOR ? 'star' : '★ ';
            $this->io->write_error('');
            $this->io->write_error('What about running <comment>composer global require symfony/thanks && composer thanks</> now?');
            $this->io->write_error(\sprintf('This will spread some %s by sending a %s to the GitHub repositories of your fellow package maintainers.', $love, $star));
        }
        $this->io->write_error('');
        if (!$recipes) {
            if (Script_Events::POST_UPDATE_CMD === $event->get_name()) {
                $this->finish($root_dir);
            }
            if ($this->downloader->is_enabled()) {
                $this->io->write_error('Run <comment>composer recipes</> at any time to see the status of your Symfony recipes.');
                $this->io->write_error('');
            }
            return;
        }
        $this->io->write_error(\sprintf('<info>Symfony operations: %d recipe%s (%s)</>', \count($recipes), \count($recipes) > 1 ? 's' : '', $this->downloader->get_session_id()));
        $install_contribs = $this->composer->get_package()->get_extra()['symfony']['allow-contrib'] ?? false;
        $manifest = null;
        $original_composer_json_hash = $this->get_composer_json_hash();
        $post_install_recipes = [];
        foreach ($recipes as $recipe) {
            if ('install' === $recipe->get_job() && !$install_contribs && $recipe->is_contrib()) {
                $warning = $this->io->is_interactive() ? 'WARNING' : 'IGNORING';
                $this->io->write_error(\sprintf('  - <warning> %s </> %s', $warning, $this->format_origin($recipe)));
                $question = \sprintf('    The recipe for this package comes from the "contrib" repository, which is open to community contributions.
    Review the recipe at %s

    Do you want to execute this recipe?
    [<comment>y</>] Yes
    [<comment>n</>] No
    [<comment>a</>] Yes for all packages, only for the current installation session
    [<comment>p</>] Yes permanently, never ask again for this project
    (defaults to <comment>n</>): ', $recipe->get_url());
                $answer = $this->io->ask_and_validate($question, function ($value): string {
                    if (null === $value) {
                        return 'n';
                    }
                    $value = strtolower((string) $value[0]);
                    if (!\in_array($value, ['y', 'n', 'a', 'p'])) {
                        throw new \InvalidArgumentException('Invalid choice.');
                    }
                    return $value;
                }, null, 'n');
                if ('n' === $answer) {
                    continue;
                }
                if ('a' === $answer) {
                    $install_contribs = true;
                }
                if ('p' === $answer) {
                    $install_contribs = true;
                    $json = new Json_File(Factory::get_composer_file());
                    $manipulator = new Json_Manipulator(file_get_contents($json->get_path()));
                    $manipulator->add_sub_node('extra', 'symfony.allow-contrib', true);
                    file_put_contents($json->get_path(), $manipulator->get_contents());
                }
            }
            switch ($recipe->get_job()) {
                case 'install':
                    $post_install_recipes[] = $recipe;
                    $this->io->write_error(\sprintf('  - Configuring %s', $this->format_origin($recipe)));
                    $this->configurator->install($recipe, $this->lock, ['force' => $event instanceof Update_Event && $event->force(), 'assumeYesForPrompts' => $event instanceof Update_Event && $event->assume_yes_for_prompts()]);
                    $manifest = $recipe->get_manifest();
                    if (isset($manifest['post-install-output'])) {
                        $this->post_install_output[] = \sprintf('<bg=yellow;fg=white> %s </> instructions:', $recipe->get_name());
                        $this->post_install_output[] = '';
                        foreach ($manifest['post-install-output'] as $line) {
                            $this->post_install_output[] = $this->options->expand_target_dir($line);
                        }
                        $this->post_install_output[] = '';
                    }
                    break;
                case 'update':
                    break;
                case 'uninstall':
                    $this->io->write_error(\sprintf('  - Unconfiguring %s', $this->format_origin($recipe)));
                    $this->configurator->unconfigure($recipe, $this->lock);
                    break;
            }
        }
        if (method_exists($this->configurator, 'postInstall')) {
            foreach ($post_install_recipes as $recipe) {
                $this->configurator->post_install($recipe, $this->lock, ['force' => $event instanceof Update_Event && $event->force(), 'assumeYesForPrompts' => $event instanceof Update_Event && $event->assume_yes_for_prompts()]);
            }
        }
        if (null !== $manifest) {
            array_unshift($this->post_install_output, '<bg=blue;fg=white>              </>', '<bg=blue;fg=white> What\'s next? </>', '<bg=blue;fg=white>              </>', '', '<info>Some files have been created and/or updated to configure your new packages.</>', 'Please <comment>review</>, <comment>edit</> and <comment>commit</> them: these files are <comment>yours</>.');
        }
        $this->finish($root_dir, $original_composer_json_hash);
    }
    public function finish(string $root_dir, ?string $original_composer_json_hash = null): void
    {
        $this->synchronize_package_json($root_dir);
        $this->lock->write();
        if ($original_composer_json_hash && $this->get_composer_json_hash() !== $original_composer_json_hash) {
            $this->update_composer_lock();
        }
    }
    private function synchronize_package_json(string $root_dir): void
    {
        if (!($this->composer->get_package()->get_extra()['symfony/flex']['synchronize_package_json'] ?? true)) {
            $this->io->write_error('<info>Skip synchronizing package.json with PHP packages</>');
            return;
        }
        if (!$this->downloader->is_enabled()) {
            $this->io->write_error('<warning>Synchronizing package.json is disabled: "symfony/flex" not found in the root composer.json</>');
            return;
        }
        $root_dir = realpath($root_dir);
        $vendor_dir = trim((new Filesystem())->make_path_relative($this->config->get('vendor-dir'), $root_dir), '/');
        $executor = new Script_Executor($this->composer, $this->io, $this->options);
        $synchronizer = new Package_Json_Synchronizer($root_dir, $vendor_dir, $executor, $this->io);
        if ($synchronizer->should_synchronize()) {
            $lock_data = $this->composer->get_locker()->get_lock_data();
            if ($synchronizer->synchronize(array_merge($lock_data['packages'] ?? [], $lock_data['packages-dev'] ?? []))) {
                $this->io->write_error('<info>Synchronizing package.json with PHP packages</>');
                $this->io->write_error('<warning>Don\'t forget to run npm install --force or yarn install --force to refresh your JavaScript dependencies!</>');
                $this->io->write_error('');
            }
        }
    }
    public function uninstall(Composer $composer, Io_Interface $io): void
    {
        $this->lock->delete();
    }
    public function enable_thanks_reminder(): void
    {
        if (1 === $this->display_thanks_reminder) {
            $this->display_thanks_reminder = !class_exists(Thanks::class, false) ? 2 : 0;
        }
    }
    public function execute_auto_scripts(Event $event): void
    {
        $event->stop_propagation();
        // force reloading scripts as we might have added and removed during this run
        $json = new Json_File(Factory::get_composer_file());
        $json_contents = $json->read();
        $executor = new Script_Executor($this->composer, $this->io, $this->options);
        foreach ($json_contents['scripts']['auto-scripts'] as $cmd => $type) {
            $executor->execute($type, $cmd);
        }
        $this->io->write($this->post_install_output);
        $this->post_install_output = [];
    }
    /**
     * @return Recipe[]
     */
    public function fetch_recipes(array $operations, bool $reset): array
    {
        if (!$this->downloader->is_enabled()) {
            $this->io->write_error('<warning>Symfony recipes are disabled: "symfony/flex" not found in the root composer.json</>');
            return [];
        }
        $dev_packages = null;
        $data = $this->downloader->get_recipes($operations);
        $manifests = $data['manifests'] ?? [];
        $locks = $data['locks'] ?? [];
        // symfony/flex recipes should always be applied first
        $flex_recipe = [];
        // symfony/framework-bundle recipe should always be applied first after the metapackages
        $recipes = ['symfony/framework-bundle' => null];
        $pack_recipes = [];
        $meta_recipes = [];
        foreach ($operations as $operation) {
            if ($operation instanceof Update_Operation) {
                $package = $operation->get_target_package();
            } else {
                $package = $operation->get_package();
            }
            // FIXME: Multi name with getNames()
            $name = $package->get_name();
            $job = method_exists($operation, 'getOperationType') ? $operation->get_operation_type() : $operation->get_job_type();
            if (!isset($manifests[$name]) && isset($data['conflicts'][$name])) {
                $this->io->write_error(\sprintf('  - Skipping recipe for %s: all versions of the recipe conflict with your package versions.', $name));
                continue;
            }
            while ($this->does_recipe_conflict($manifests[$name] ?? [], $operation)) {
                $this->downloader->remove_recipe_from_index($name, $manifests[$name]['version']);
                $new_data = $this->downloader->get_recipes([$operation]);
                $new_manifests = $new_data['manifests'] ?? [];
                if (!isset($new_manifests[$name])) {
                    // no older recipe found
                    $this->io->write_error(\sprintf('  - Skipping recipe for %s: all versions of the recipe conflict with your package versions.', $name));
                    continue 2;
                }
                // push the "old" recipe into the $manifests
                $manifests[$name] = $new_manifests[$name];
                $locks[$name] = $new_data['locks'][$name];
            }
            if ($operation instanceof Install_Operation && isset($locks[$name])) {
                $ref = $this->lock->get($name)['recipe']['ref'] ?? null;
                if (!$reset && $ref && ($locks[$name]['recipe']['ref'] ?? null) === $ref) {
                    continue;
                }
                $this->lock->set($name, $locks[$name]);
            } elseif ($operation instanceof Uninstall_Operation) {
                if (!$this->lock->has($name)) {
                    continue;
                }
                $this->lock->remove($name);
            }
            if (isset($manifests[$name])) {
                $recipe = new Recipe($package, $name, $job, $manifests[$name], $locks[$name] ?? []);
                if ('symfony-pack' === $package->get_type()) {
                    $pack_recipes[$name] = $recipe;
                } elseif ('metapackage' === $package->get_type()) {
                    $meta_recipes[$name] = $recipe;
                } elseif ('symfony/flex' === $name) {
                    $flex_recipe = [$name => $recipe];
                } else {
                    $recipes[$name] = $recipe;
                }
            } else {
                $bundles = [];
                if (null === $dev_packages) {
                    $dev_packages = array_column($this->composer->get_locker()->get_lock_data()['packages-dev'], 'name');
                }
                $envs = \in_array($name, $dev_packages) ? ['dev', 'test'] : ['all'];
                $bundle = new Symfony_Bundle($this->composer, $package, $job);
                foreach ($bundle->get_class_names() as $bundle_class) {
                    $bundles[$bundle_class] = $envs;
                }
                if ($bundles) {
                    $manifest = ['origin' => \sprintf('%s:%s@auto-generated recipe', $name, $package->get_pretty_version()), 'manifest' => ['bundles' => $bundles]];
                    $recipes[$name] = new Recipe($package, $name, $job, $manifest);
                    if ($operation instanceof Install_Operation) {
                        $this->lock->set($name, ['version' => $package->get_pretty_version()]);
                    }
                }
            }
        }
        return array_merge($flex_recipe, $pack_recipes, $meta_recipes, array_filter($recipes));
    }
    public function truncate_packages(Pre_Pool_Create_Event $event): void
    {
        if (!$this->filter) {
            return;
        }
        $root_package = $this->composer->get_package();
        $locked_packages = $event->get_request()->get_fixed_or_locked_packages();
        $event->set_packages($this->filter->remove_legacy_packages($event->get_packages(), $root_package, $locked_packages));
    }
    public function get_composer_json_hash(): string
    {
        return md5_file(Factory::get_composer_file());
    }
    public function get_lock(): Lock
    {
        if (null === $this->lock) {
            throw new \Exception('Cannot access lock before calling activate().');
        }
        return $this->lock;
    }
    private function init_options(): Options
    {
        $extra = $this->composer->get_package()->get_extra();
        $options = array_merge(['bin-dir' => 'bin', 'conf-dir' => 'conf', 'config-dir' => 'config', 'src-dir' => 'src', 'var-dir' => 'var', 'public-dir' => 'public', 'root-dir' => $extra['symfony']['root-dir'] ?? '.', 'runtime' => $extra['runtime'] ?? []], $extra);
        return new Options($options, $this->io, $this->lock);
    }
    private function format_origin(Recipe $recipe): string
    {
        if (method_exists($recipe, 'getFormattedOrigin')) {
            return $recipe->get_formatted_origin();
        }
        // BC with upgrading from flex < 1.18
        $origin = $recipe->get_origin();
        // symfony/translation:3.3@github.com/symfony/recipes:branch
        if (!preg_match('/^([^:]++):([^@]++)@(.+)$/', $origin, $matches)) {
            return $origin;
        }
        return \sprintf('<info>%s</> (<comment>>=%s</>): From %s', $matches[1], $matches[2], 'auto-generated recipe' === $matches[3] ? '<comment>' . $matches[3] . '</>' : $matches[3]);
    }
    private function should_record_operation(Operation_Interface $operation, bool $is_dev_mode, ?Composer $composer = null): bool
    {
        if ($this->reinstall) {
            return false;
        }
        if ($operation instanceof Update_Operation) {
            $package = $operation->get_target_package();
        } else {
            $package = $operation->get_package();
        }
        // when Composer runs with --no-dev, ignore uninstall operations on packages from require-dev
        if (!$is_dev_mode && $operation instanceof Uninstall_Operation) {
            foreach (($composer ?? $this->composer)->get_locker()->get_lock_data()['packages-dev'] as $p) {
                if ($package->get_name() === $p['name']) {
                    return false;
                }
            }
        }
        // FIXME: Multi name with getNames()
        $name = $package->get_name();
        if ($operation instanceof Install_Operation) {
            if (!$this->lock->has($name)) {
                return true;
            }
        } elseif ($operation instanceof Uninstall_Operation) {
            return true;
        }
        return false;
    }
    private function update_composer_lock(): void
    {
        $lock = substr(Factory::get_composer_file(), 0, -4) . 'lock';
        $composer_json = file_get_contents(Factory::get_composer_file());
        $lock_file = new Json_File($lock, null, $this->io);
        $locker = new Locker($this->io, $lock_file, $this->composer->get_installation_manager(), $composer_json);
        $lock_data = $locker->get_lock_data();
        $lock_data['content-hash'] = Locker::get_content_hash($composer_json);
        $lock_file->write($lock_data);
    }
    private function unpack(): void
    {
        $json_path = Factory::get_composer_file();
        $json = Json_File::parse_json(file_get_contents($json_path));
        $sort_packages = $this->composer->get_config()->get('sort-packages');
        $unpack_op = new Operation(true, $sort_packages);
        foreach (['require', 'require-dev'] as $type) {
            foreach ($json[$type] ?? [] as $package => $constraint) {
                $unpack_op->add_package($package, $constraint, 'require-dev' === $type);
            }
        }
        $unpacker = new Unpacker($this->composer, new Package_Resolver($this->downloader), false);
        // 3rd arg to ease upgrading from flex <= 2.6.0
        $result = $unpacker->unpack($unpack_op);
        if (!$result->get_unpacked()) {
            return;
        }
        foreach ($result->get_unpacked() as $pkg) {
            $this->io->write_error(\sprintf('  - Unpacked <info>%s</>', $pkg->get_name()));
        }
        $unpacker->update_lock($result, $this->io);
    }
    private function reinstall(Event $event): void
    {
        $this->reinstall = false;
        $event->stop_propagation();
        $ed = $this->composer->get_event_dispatcher();
        $disable_scripts = !method_exists($ed, 'setRunScripts') || !((array) $ed)["\x00*\x00runScripts"];
        $composer = Factory::create($this->io, null, false, $disable_scripts);
        $composer->get_installation_manager()->add_installer(new Symfony_Pack_Installer($this->io));
        $installer = clone $this->installer;
        $installer->__construct($this->io, $composer->get_config(), $composer->get_package(), $composer->get_download_manager(), $composer->get_repository_manager(), $composer->get_locker(), $composer->get_installation_manager(), $composer->get_event_dispatcher(), $composer->get_autoload_generator());
        if (method_exists($installer, 'setPlatformRequirementFilter')) {
            $installer->set_platform_requirement_filter(((array) $this->installer)["\x00*\x00platformRequirementFilter"]);
        }
        $installer->run();
        $this->io->write($this->post_install_output);
        $this->post_install_output = [];
    }
    public static function get_subscribed_events(): array
    {
        if (!self::$activated) {
            return [];
        }
        return [Package_Events::POST_PACKAGE_UPDATE => 'enableThanksReminder', Package_Events::POST_PACKAGE_INSTALL => 'recordFlexInstall', Package_Events::POST_PACKAGE_UNINSTALL => 'record', Installer_Events::PRE_OPERATIONS_EXEC => 'recordOperations', Plugin_Events::PRE_POOL_CREATE => 'truncatePackages', Script_Events::POST_CREATE_PROJECT_CMD => 'configureProject', Script_Events::POST_INSTALL_CMD => 'install', Script_Events::PRE_UPDATE_CMD => 'configureInstaller', Script_Events::POST_UPDATE_CMD => 'update', 'auto-scripts' => 'executeAutoScripts'];
    }
    private function does_recipe_conflict(array $recipe_data, Operation_Interface $operation): bool
    {
        if (empty($recipe_data['manifest']['conflict']) || $operation instanceof Uninstall_Operation) {
            return false;
        }
        $locked_repository = $this->composer->get_locker()->get_locked_repository(true);
        foreach ($recipe_data['manifest']['conflict'] as $conflicting_package => $constraint) {
            if ($locked_repository->find_package($conflicting_package, $constraint)) {
                return true;
            }
        }
        return false;
    }
}