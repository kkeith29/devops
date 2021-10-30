<?php

declare(strict_types=1);

namespace App\Classes\Project\Deployment;

use App\Classes\{Process, Project\Deployment};
use App\Exceptions\Project\Deployment\EnvironmentException;
use Monolog\Logger;

use function Core\Functions\env;

/**
 * Class Environment
 *
 * @package App\Classes\Project\Deployment
 */
class Environment
{
    /**
     * @var Logger|null Logger instance
     */
    protected ?Logger $logger = null;

    /**
     * @var array Environment config
     */
    protected array $config = [];

    /**
     * @var array Cache of commands which have been ran
     */
    protected array $ran_commands = [];

    /**
     * Environment constructor
     *
     * @param Deployment $deployment
     * @param string $env
     * @throws EnvironmentException
     */
    public function __construct(protected Deployment $deployment, protected string $env)
    {
        $project_config = $this->deployment->getProject()->getConfig();
        if (!isset($project_config['environments'][$env])) {
            throw new EnvironmentException("Environment {$env} not defined in project config");
        }
        $this->config = array_merge($project_config['environments']['*'] ?? [], $project_config['environments'][$env]);
    }

    /**
     * Get deployment instance
     *
     * @return Deployment
     */
    public function getDeployment(): Deployment
    {
        return $this->deployment;
    }

    /**
     * Get and cache logger instance
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = $this->deployment->getLogger()->withName("env_{$this->env}");
        }
        return $this->logger;
    }

    /**
     * Get config
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Determines if environment is marked as production
     *
     * @return bool
     */
    public function isProduction(): bool
    {
        return $this->config['production'] ?? false;
    }

    /**
     * Prep command and create Process instance
     *
     * If remote, we wrap the command in an ssh call to the proper host.
     *
     * @param string $command
     * @param bool $remote
     * @return Process
     */
    protected function prepCommand(string $command, bool $remote = false): Process
    {
        if ($remote) {
            $command = sprintf('ssh -T %s %s', $this->config['ssh_host'], escapeshellarg($command));
        }
        return Process::fromShellCommandline($command, $this->deployment->getProject()->getConfig()['base_path']);
    }

    /**
     * Determines if command has run
     *
     * @param string $name
     * @return bool
     */
    public function isCommandRan(string $name): bool
    {
        return isset($this->ran_commands[$name]);
    }

    /**
     * Run command and return Process instance
     *
     * @param string $command
     * @param bool $remote
     * @param callable|bool|null $process_callback
     * @return Process
     */
    public function runCommand(string $command, bool $remote = false, callable|bool|null $process_callback = null): Process
    {
        $process = $this->prepCommand($command, $remote)->setTimeout(null)->disableOutput();
        $process_callback = is_bool($process_callback) ? null : ($process_callback ?? $this->getDeployment()->getProject()->getOutputCallback());
        return $process->mustRun($process_callback);
    }

    /**
     * Run remote command
     *
     * @param string $command
     * @return Process
     */
    public function runRemoteCommand(string $command): Process
    {
        return $this->runCommand($command, true);
    }

    /**
     * Run commands defined in config by their associated key
     *
     * @param string $config_key
     */
    public function runConfigCommands(string $config_key): void
    {
        if (!isset($this->config[$config_key])) {
            return;
        }
        foreach ($this->config[$config_key] as $command) {
            $name = null;
            $local = false;
            if (is_array($command)) {
                if (isset($command['when']) && !$command['when']($this)) {
                    continue;
                }
                $name = $command['name'] ?? null;
                $local = isset($command['local']) && $command['local'];
                $command = $command['cmd'];
            }
            $this->getLogger()->info("Running command: {$command}");
            $this->runCommand($command, !$local);
            if ($name !== null) {
                $this->ran_commands[$name] = true;
            }
        }
    }

    /**
     * Parse rsync command response to determine what is being done
     *
     * @param string $output
     * @return array[]
     * @throws EnvironmentException
     */
    public function parseRsync(string $output): array
    {
        $info = [
            'deleted' => [],
            'sent_new' => [],
            'sent_modified' => [],
            'received_new' => [],
            'received_modified' => [],
            'modified' => []
        ];
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$change, $path] = explode(' ', $line, 2);
            switch ($change[0]) {
                case '<':
                case '>':
                    if ($change[1] !== 'f') {
                        break;
                    }
                    $transfer_map = [
                        '<' => 'sent',
                        '>' => 'received'
                    ];
                    $action_map = [
                        '+' => 'new',
                        'c' => 'modified'
                    ];
                    $info["{$transfer_map[$change[0]]}_{$action_map[$change[2]]}"][] = $path;
                    break;
                case '*':
                    if (str_contains($change, 'deleting')) {
                        $info['deleted'][] = $path;
                    }
                    break;
                case '.':
                    $options = [
                        2 => ['c', 'checksum'],
                        3 => ['s', 'size'],
                        4 => ['t', 'time'],
                        5 => ['p', 'permission']
                    ];
                    $reasons = [];
                    foreach ($options as $key => [$value, $reason]) {
                        if ($change[$key] !== $value) {
                            continue;
                        }
                        $reasons[] = $reason;
                    }
                    $info['modified'][] = [
                        'path' => $path,
                        'reason' => $reasons
                    ];
                    break;
                case 'c':
                    break;
                default:
                    throw new EnvironmentException("Rsync output parse error: {$line}");
            }
        }
        return $info;
    }

    /**
     * Build rsync command, run, and parse output
     *
     * @param bool $dry_run
     * @return Process
     * @throws EnvironmentException
     */
    public function runRsync(bool $dry_run = true): Process
    {
        $project = $this->deployment->getProject();
        $exclude_file = PATH_RESOURCES . "rsync-exclude/{$project->getName()}.txt";
        if (!file_exists($exclude_file)) {
            $exclude_file = null;
        }

        $command = vsprintf('%s%s %s %s:%s', [
            env('BIN_RSYNC'),
            Process::buildOptions([
                'a',
                'z',
                'O',
                ['e', sprintf('ssh -p%d', $this->config['ssh_port'])],
                'dry-run' => $dry_run,
                'exclude-from' => $exclude_file ?? false,
                'force' => true,
                'delete' => true,
                'checksum' => true,
                'copy-links' => true,
                'itemize-changes' => true,
                'no-times' => true
            ]),
            $project->getConfig()['base_path'],
            $this->config['ssh_host'],
            $this->config['remote_path']
        ]);
        $process = $this->prepCommand($command)->setTimeout(null)->mustRun();
        $results = $this->parseRsync($process->getOutput());
        $results = array_filter($results, fn(array $data): bool => count($data) > 0);

        $logger = $this->getLogger();
        $logger->info('Rsync results', $results);
        if (count($results) === 0) {
            $logger->info('No files to sync');
        }

        return $process;
    }
}
