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

use Composer\Composer;
use Composer\Event_Dispatcher\Script_Execution_Exception;
use Composer\IO\Io_Interface;
use Composer\Semver\Constraint\Match_All_Constraint;
use Composer\Util\Process_Executor;
use Symfony\Component\Console\Output\Output_Interface;
use Symfony\Component\Console\Output\Stream_Output;
use Symfony\Component\Process\Php_Executable_Finder;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Script_Executor
{
    private readonly \Composer\Util\Process_Executor $executor;
    public function __construct(private readonly Composer $composer, private readonly Io_Interface $io, private readonly Options $options, ?Process_Executor $executor = null)
    {
        $this->executor = $executor ?: new Process_Executor();
    }
    /**
     * @throws ScriptExecutionException if the executed command returns a non-0 exit code
     */
    public function execute(string $type, string $cmd, array $arguments = []): void
    {
        $parsed_cmd = $this->options->expand_target_dir($cmd);
        if (null === $expanded_cmd = $this->expand_cmd($type, $parsed_cmd, $arguments)) {
            return;
        }
        $cmd_output = new Stream_Output(fopen('php://temp', 'rw'), Output_Interface::VERBOSITY_VERBOSE, $this->io->is_decorated());
        $output_handler = function ($type, string|iterable $buffer) use ($cmd_output): void {
            $cmd_output->write($buffer, false, Output_Interface::OUTPUT_RAW);
        };
        $this->io->write_error(\sprintf('Executing script %s', $parsed_cmd), $this->io->is_verbose());
        $exit_code = $this->executor->execute($expanded_cmd, $output_handler);
        $code = 0 === $exit_code ? ' <info>[OK]</>' : ' <error>[KO]</>';
        if ($this->io->is_verbose()) {
            $this->io->write_error(\sprintf('Executed script %s %s', $cmd, $code));
        } else {
            $this->io->write_error($code);
        }
        if (0 !== $exit_code) {
            $this->io->write_error(' <error>[KO]</>');
            $this->io->write_error(\sprintf('<error>Script %s returned with error code %s</>', $cmd, $exit_code));
            fseek($cmd_output->get_stream(), 0);
            foreach (explode("\n", stream_get_contents($cmd_output->get_stream())) as $line) {
                $this->io->write_error('!!  ' . $line);
            }
            throw new Script_Execution_Exception($cmd, $exit_code);
        }
    }
    private function expand_cmd(string $type, string $cmd, array $arguments)
    {
        return match ($type) {
            'symfony-cmd' => $this->expand_symfony_cmd($cmd, $arguments),
            'php-script' => $this->expand_php_script($cmd, $arguments),
            'script' => $cmd,
            default => throw new \InvalidArgumentException(\sprintf('Invalid symfony/flex auto-script in composer.json: "%s" is not a valid type of command.', $type)),
        };
    }
    private function expand_symfony_cmd(string $cmd, array $arguments): ?string
    {
        $repo = $this->composer->get_repository_manager()->get_local_repository();
        if (!$repo->find_package('symfony/console', new Match_All_Constraint())) {
            $this->io->write_error(\sprintf('<warning>Skipping "%s" (needs symfony/console to run).</>', $cmd));
            return null;
        }
        $console = Process_Executor::escape($this->options->get('root-dir') . '/' . $this->options->get('bin-dir') . '/console');
        if ($this->io->is_decorated()) {
            $console .= ' --ansi';
        }
        return $this->expand_php_script($console . ' ' . $cmd, $arguments);
    }
    private function expand_php_script(string $cmd, array $script_arguments): string
    {
        $php_finder = new Php_Executable_Finder();
        if (!$php = $php_finder->find(false)) {
            throw new \RuntimeException('The PHP executable could not be found, add it to your PATH and try again.');
        }
        $arguments = $php_finder->find_arguments();
        if ($env = (string) getenv('COMPOSER_ORIGINAL_INIS')) {
            $paths = explode(\PATH_SEPARATOR, $env);
            $ini = array_shift($paths);
        } else {
            $ini = php_ini_loaded_file();
        }
        if ($ini) {
            $arguments[] = '--php-ini=' . $ini;
        }
        if ($memory_limit = (string) getenv('COMPOSER_MEMORY_LIMIT')) {
            $arguments[] = '-d';
            $arguments[] = 'memory_limit=' . $memory_limit;
        }
        $php_args = implode(' ', array_map([Process_Executor::class, 'escape'], $arguments));
        $script_args = implode(' ', array_map([Process_Executor::class, 'escape'], $script_arguments));
        return Process_Executor::escape($php) . ($php_args ? ' ' . $php_args : '') . ' ' . $cmd . ($script_args ? ' ' . $script_args : '');
    }
}