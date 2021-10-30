<?php

namespace App\Classes;

use App\Classes\Git\Diff;
use App\Exceptions\GitException;
use Closure;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Class Git
 *
 * @package App\Classes
 */
class Git
{
    public const GIT_DIR = '.git';

    /**
     * @var string Path of Git repo parent
     */
    protected string $path;

    /**
     * @var string Path of Git repo
     */
    protected string $git_path;

    /**
     * @var Closure|null Callback for process output
     */
    protected ?Closure $output_callback = null;

    /**
     * Git constructor
     *
     * @param string $path
     * @param bool $check
     * @param string $binary
     *
     * @throws GitException
     */
    public function __construct(string $path, bool $check = true, protected string $binary = '/usr/bin/git')
    {
        $this->path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->git_path = $this->path . self::GIT_DIR . DIRECTORY_SEPARATOR;
        if ($check && !$this->isInitialized()) {
            throw new GitException("Git not initialized for path: {$path}");
        }
    }

    /**
     * Set output callback to handle return data from process
     *
     * @param Closure|null $closure
     */
    public function setOutputCallback(?Closure $closure): void
    {
        $this->output_callback = $closure;
    }

    /**
     * Determine if Git repo exists in directory
     *
     * @return bool
     */
    public function isInitialized(): bool
    {
        return is_dir($this->git_path);
    }

    /**
     * Clone repo
     *
     * @param string $repo
     * @throws GitException
     */
    public function cloneRepo(string $repo): void
    {
        $this->execute('clone %s %s', escapeshellarg($repo), escapeshellarg($this->path));
    }

    /**
     * Get remote names
     *
     * @return array
     * @throws GitException
     */
    public function getRemotes(): array
    {
        $output = $this->executeAndCapture('remote');
        return array_filter(array_map('trim', explode("\n", $output)));
    }

    /**
     * Checkout branch
     *
     * @param string $branch
     * @throws GitException
     */
    public function checkout(string $branch): void
    {
        $this->execute(sprintf('checkout %s', escapeshellarg($branch)));
    }

    /**
     * Fetch all or from specific remote
     *
     * @param string|null $remote
     * @throws GitException
     */
    public function fetch(?string $remote = null): void
    {
        $this->execute('fetch' . ($remote !== null ? ' ' . escapeshellarg($remote) : ''));
    }

    /**
     * Pull changes into current branch
     *
     * @param string|null $remote
     * @throws GitException
     */
    public function pull(?string $remote = null): void
    {
        $this->execute('pull' . ($remote !== null ? ' ' . escapeshellarg($remote) : ''));
    }

    /**
     * Push changes to remote
     *
     * @param string|null $remote
     * @param bool $force
     * @throws GitException
     */
    public function push(?string $remote = null, bool $force = false): void
    {
        $command = 'push';
        if ($force) {
            $command .= ' -f';
        }
        if ($remote !== null) {
            $command .= ' ' . escapeshellarg($remote);
        }
        $this->execute($command);
    }

    /**
     * Get local branch names
     *
     * @return array
     * @throws GitException
     */
    public function getLocalBranches(): array
    {
        $output = $this->executeAndCapture('for-each-ref --format=\'%%(refname)\' refs/heads/');
        $branches = array_filter(explode("\n", $output));
        $branches = array_map(fn(string $branch): string => trim(substr($branch, 11)), $branches);
        return $branches;
    }

    /**
     * Get current branch name
     *
     * @return string
     * @throws GitException
     */
    public function getCurrentBranch(): string
    {
        return trim($this->executeAndCapture('symbolic-ref --short HEAD 2>/dev/null'));
    }

    /**
     * Get current commit hash
     *
     * @return string
     * @throws GitException
     */
    public function getCurrentCommitHash(): string
    {
        return trim($this->executeAndCapture('rev-parse --short HEAD'));
    }

    /**
     * Get diff info between two references
     *
     * @param string $from
     * @param string $to
     * @return Diff
     * @throws GitException
     */
    public function getDiff(string $from, string $to): Diff
    {
        return new Diff($this, $from, $to);
    }

    /**
     * Get commit count between two hashes
     *
     * @param string $from_hash
     * @param string $to_hash
     * @return int
     * @throws GitException
     */
    public function getCommitDiffCount(string $from_hash, string $to_hash): int
    {
        return (int) trim($this->executeAndCapture(sprintf('rev-list %s..%s --count', escapeshellarg($from_hash), escapeshellarg($to_hash))));
    }

    /**
     * Update index
     *
     * @throws GitException
     */
    protected function updateIndex(): void
    {
        $this->execute('update-index -q --refresh');
    }

    /**
     * Check if there are staged files
     *
     * @return bool
     */
    public function hasStagedFiles(): bool
    {
        try {
            $this->updateIndex();
            $this->execute('diff-index --quiet --cached HEAD');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Merge one branch into the current
     *
     * @param string $branch
     * @throws GitException
     */
    public function merge(string $branch): void
    {
        $this->execute(sprintf('merge %s', escapeshellarg($branch)));
    }

    /**
     * Builds and returns command string to run
     *
     * @param string $command
     * @param array $args
     * @return string
     */
    protected function getCommand(string $command, array $args): string
    {
        return sprintf(
            'cd %s && %s %s',
            escapeshellarg($this->path),
            $this->binary,
            vsprintf(trim($command), $args)
        );
    }

    /**
     * Get process instance from shell command
     *
     * @param string $command
     * @param array $args
     * @return Process
     */
    protected function getProcess(string $command, array $args): Process
    {
        return Process::fromShellCommandline($this->getCommand($command, $args));
    }

    /**
     * Execute command and return output and status code
     *
     * Does not display Git output to user
     *
     * @param string $command
     * @param ...$args
     * @return Process
     * @throws GitException
     */
    public function execute(string $command, ...$args): Process
    {
        try {
            return $this->getProcess($command, $args)->mustRun($this->output_callback);
        } catch (ProcessFailedException $e) {
            throw new GitException('Unable to run command', 0, $e);
        }
    }

    /**
     * Execute command and return output
     *
     * Does not send output to callback.
     *
     * @param string $command
     * @param mixed ...$args
     * @return string
     * @throws GitException
     */
    public function executeAndCapture(string $command, ...$args): string
    {
        try {
            return $this->getProcess($command, $args)->mustRun()->getOutput();
        } catch (ProcessFailedException $e) {
            throw new GitException('Unable to run command', 0, $e);
        }
    }
}
