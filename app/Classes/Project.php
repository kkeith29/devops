<?php

declare(strict_types=1);

namespace App\Classes;

use App\Classes\Project\Deployment;
use App\Exceptions\ProjectException;
use Closure;
use Monolog\{Handler\StreamHandler, Logger};
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Class Project
 *
 * @package App\Classes
 */
class Project
{
    /**
     * @var array Current project config
     */
    protected array $config = [];

    /**
     * @var Logger|null Logger instance
     */
    protected ?Logger $logger = null;

    /**
     * @var FilesystemAdapter|null Cache adapter instance
     */
    protected ?FilesystemAdapter $cache = null;

    /**
     * @var Git|null Git instance
     */
    protected ?Git $git = null;

    /**
     * @var CacheItemInterface|null Cache item instance
     */
    protected ?CacheItemInterface $last_commit_hash_cache_item = null;

    /**
     * @var Closure|null Output callback for process return data
     */
    protected ?Closure $output_callback = null;

    /**
     * Project constructor
     *
     * @param string $name
     * @throws ProjectException
     */
    public function __construct(protected string $name)
    {
        $config = include(PATH_CONFIG . 'projects.php');
        if (!isset($config[$name])) {
            throw new ProjectException("Unable to find config for project: {$name}");
        }
        $this->config = $config[$name];
    }

    /**
     * Set output callback for use with process output
     *
     * @param Closure|null $closure
     */
    public function setOutputCallback(?Closure $closure): void
    {
        $this->output_callback = $closure;
    }

    /**
     * Get output callback
     *
     * @return Closure|null
     */
    public function getOutputCallback(): ?Closure
    {
        return $this->output_callback;
    }

    /**
     * Get project name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get or create logger instance
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = new Logger('project');
            $this->logger->pushHandler(new StreamHandler(PATH_STORAGE . "logs/project_{$this->name}.log", Logger::INFO));
        }
        return $this->logger;
    }

    /**
     * Get or create cache instance
     *
     * @return FilesystemAdapter
     */
    public function getCache(): FilesystemAdapter
    {
        if ($this->cache === null) {
            $this->cache = new FilesystemAdapter(directory: PATH_STORAGE . 'cache');
        }
        return $this->cache;
    }

    /**
     * Get project config
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get Git instance configured for project repo
     *
     * @return Git
     * @throws \App\Exceptions\GitException
     */
    public function getGit(): Git
    {
        if ($this->git === null) {
            $this->git = new Git($this->config['git']['repo_path'] ?? $this->config['base_path']);
            $this->git->setOutputCallback($this->output_callback);
        }
        return $this->git;
    }

    /**
     * Get and cache last commit hash cache item instance
     *
     * @return CacheItemInterface
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getLastCommitHashCacheItem(): CacheItemInterface
    {
        if ($this->last_commit_hash_cache_item === null) {
            $this->last_commit_hash_cache_item = $this->getCache()->getItem("{$this->name}.last_commit_hash");
        }
        return $this->last_commit_hash_cache_item;
    }

    /**
     * Get last commit hash if available
     *
     * @return string|null
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getLastCommitHash(): ?string
    {
        return $this->getLastCommitHashCacheItem()->get();
    }

    /**
     * Set last commit hash
     *
     * @param string $hash
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function setLastCommitHash(string $hash): void
    {
        $item = $this->getLastCommitHashCacheItem();
        $item->set($hash);
        $this->getCache()->save($item);
        $this->getLogger()->info('Last commit hash updated', ['hash' => $hash]);
    }

    /**
     * Get deployment instance from project
     *
     * Will make sure git branches are up to date and checked out.
     *
     * @param array $options
     * @return Deployment
     * @throws ProjectException
     * @throws \App\Exceptions\GitException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getDeployment(array $options = []): Deployment
    {
        $logger = $this->getLogger();

        $git = $this->getGit();
        $current_branch = $git->getCurrentBranch();
        $logger->info("Current branch: {$current_branch}");

        $logger->info("Fetching latest changes from upstream '{$this->config['git']['upstream']}'");
        $git->fetch($this->config['git']['upstream']);

        $branches = ['master', 'develop'];
        foreach ($branches as $branch) {
            $upstream = $this->config['git']['upstream'];
            $branch = $this->config['git'][$branch];
            $logger->info("Checking branch '{$branch}' for changes");
            if ($git->getCommitDiffCount($branch, "{$upstream}/{$branch}") === 0) {
                $logger->info("No changes found for branch '{$branch}'");
                continue;
            }
            if ($current_branch !== $branch) {
                $logger->info("Checking out branch '{$branch}'");
                $git->checkout($branch);
                $current_branch = $branch;
            }
            $logger->info('Pulling down latest changes');
            $git->pull($upstream);
            break;
        }

        $last_commit = $this->getLastCommitHash();
        if ($last_commit === null) {
            throw new ProjectException('No last commit hash defined');
        }
        $curr_commit = $git->getCurrentCommitHash();
        if ($last_commit === $curr_commit && (!isset($options['force']) || !$options['force'])) {
            throw new ProjectException('No changes to deploy');
        }

        $logger->info('Creating deployment', ['last_commit' => $last_commit, 'curr_commit' => $curr_commit, 'options' => $options]);
        return new Deployment($this, $current_branch, $last_commit, $curr_commit, $options);
    }
}
