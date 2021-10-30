<?php

declare(strict_types=1);

namespace App\Classes\Project;

use App\Classes\{Git\Diff, Project};
use App\Exceptions\Project\DeploymentException;
use Monolog\{Handler\StreamHandler, Logger};
use Throwable;

/**
 * Class Deployment
 *
 * @package App\Classes\Project
 */
class Deployment
{
    public const ACTION_SETUP = 1;
    public const ACTION_DRY_RUN = 2;
    public const ACTION_GO = 3;

    /**
     * @var Logger|null Logger instance
     */
    protected ?Logger $logger = null;

    /**
     * @var Diff|null Diff instance of this deployment
     */
    protected ?Diff $diff = null;

    /**
     * Deployment constructor
     *
     * @param Project $project
     * @param string $branch
     * @param string $from_hash
     * @param string $to_hash
     * @param array $options
     */
    public function __construct(
        protected Project $project,
        protected string $branch,
        protected string $from_hash,
        protected string $to_hash,
        protected array $options = []
    ) {}

    /**
     * Get project instance
     *
     * @return Project
     */
    public function getProject(): Project
    {
        return $this->project;
    }

    /**
     * Get and cache logger instance
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = new Logger('deployment');
            $this->logger->pushHandler(new StreamHandler(PATH_STORAGE . "logs/deployment/{$this->from_hash}_{$this->to_hash}.log", Logger::INFO));
        }
        return $this->logger;
    }

    /**
     * Get and cache diff instance of this deployment
     *
     * @return Diff
     * @throws \App\Exceptions\GitException
     */
    public function getDiff(): Diff
    {
        if ($this->diff === null) {
            $this->diff = $this->project->getGit()->getDiff($this->from_hash, $this->to_hash);
        }
        return $this->diff;
    }

    /**
     * Get option by name
     *
     * @param string|null $option
     * @param mixed $default
     * @return mixed
     */
    public function getOption(?string $option = null, mixed $default = null): mixed
    {
        if ($option === null) {
            return $this->options;
        }
        return $this->options[$option] ?? $default;
    }

    /**
     * Run deployment for specified environment and action
     *
     * @param string $env
     * @param int $action
     * @throws DeploymentException
     * @throws \App\Exceptions\GitException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function run(string $env, int $action): void
    {
        $environment = new Project\Deployment\Environment($this, $env);

        $logger = $environment->getLogger();

        if (!in_array($action, [self::ACTION_SETUP, self::ACTION_DRY_RUN, self::ACTION_GO])) {
            throw new DeploymentException("Invalid action: {$action}");
        }

        $action_names = [
            self::ACTION_SETUP => 'Setup',
            self::ACTION_DRY_RUN => 'Dry Run',
            self::ACTION_GO => 'Go'
        ];
        $logger->info("Action: {$action_names[$action]}");

        if ($action === self::ACTION_SETUP) {
            $logger->info('Running setup commands');
            try {
                $environment->runConfigCommands('setup_cmds');
            } catch (Throwable $e) {
                throw new DeploymentException('Setup command failed', 3, $e);
            }
        }

        if ($action === self::ACTION_GO) {
            $logger->info('Running pre deploy commands');
            try {
                $environment->runConfigCommands('pre_deploy_cmds');
            } catch (Throwable $e) {
                throw new DeploymentException('Pre deploy command failed', 4, $e);
            }
        }

        try {
            $logger->info('Running rsync' . ($action !== self::ACTION_GO ? ' [dry-run]' : ''));
            $environment->runRsync($action !== self::ACTION_GO);
        } catch (Throwable $e) {
            throw new DeploymentException('Rsync command failed', 5, $e);
        }

        if ($action === self::ACTION_GO) {
            $logger->info('Running post deploy commands');
            try {
                $environment->runConfigCommands('post_deploy_cmds');
            } catch (Throwable $e) {
                throw new DeploymentException('Post deploy command failed', 6, $e);
            }

            $contents = sprintf('[%s] %s: %s - %s - %s', $this->project->getName(), $env, date('c'), $this->to_hash, $this->branch);

            $environment->runRemoteCommand(sprintf('echo %s >> %s', escapeshellarg($contents), escapeshellarg($environment->getConfig()['remote_log_file'])));

            $logger->info('Deployed successfully');

            if ($environment->isProduction()) {
                $this->project->setLastCommitHash($this->to_hash);

                $git_config = $this->project->getConfig()['git'];
                $merge_branch = match($this->branch) {
                    $git_config['master'] => $git_config['develop'],
                    $git_config['develop'] => $git_config['master']
                };
                $git = $this->project->getGit();
                $logger->info("Checking out branch {$merge_branch} for merge");
                $git->checkout($merge_branch);
                $logger->info("Merging {$this->branch} into {$merge_branch}");
                $git->merge($this->branch);
                $git->push($git_config['upstream']);
            }
        }
    }
}
