<?php

declare(strict_types=1);

namespace App\Classes\Git;

use App\Classes\Git;
use App\Exceptions\Git\DiffException;
use App\Exceptions\GitException;

/**
 * Class Diff
 *
 * @package App\Classes\Git
 */
class Diff
{
    public const ACTION_ADDED = 'A';
    public const ACTION_MODIFIED = 'M';
    public const ACTION_RENAMED = 'R';
    public const ACTION_DELETED = 'D';
    public const ACTION_COPIED = 'C';

    /**
     * @var array List of files found in diff
     */
    protected array $files = [];

    /**
     * @var array List of directories found in diff
     */
    protected array $directories = [];

    /**
     * Diff constructor
     *
     * @param Git $git
     * @param string $from
     * @param string $to
     * @throws DiffException
     * @throws GitException
     */
    public function __construct(protected Git $git, protected string $from, protected string $to)
    {
        $output = $git->executeAndCapture(sprintf('diff --name-status --no-color %s..%s', escapeshellarg($from), escapeshellarg($to)));
        $files = array_filter(explode("\n", $output), fn(string $line): bool => $line !== '');
        foreach ($files as $file) {
            if (preg_match('@^([AMDRC])([0-9]+)?\s+([^\s]+)(\s+([^\s]+))?$@', $file, $match) !== 1) {
                throw new DiffException("Unable to parse diff line: {$file}");
            }
            $action = $match[1];
            $file = match ($action) {
                self::ACTION_ADDED, self::ACTION_DELETED, self::ACTION_MODIFIED => $match[3],
                self::ACTION_RENAMED, self::ACTION_COPIED => $match[5],
                default => throw new DiffException('Unsupported action found')
            };
            $info = ['action' => $action];
            if (!empty($match[2])) {
                $info['score'] = intval($match[2]);
            }
            if (isset($match[5])) {
                $info['source_file'] = $match[3];
            }
            $this->files[$file] = $info;
            $directory = dirname($file);
            while (!isset($this->directories[$directory])) {
                $this->directories[$directory] = true;
                $directory = dirname($directory);
            }
        }
    }

    /**
     * Get list of files in diff
     *
     * @return array
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Determines if file exists in diff
     *
     * @param string $file
     * @return bool
     */
    public function hasFile(string $file): bool
    {
        return isset($this->files[$file]);
    }

    /**
     * Get list of all directories in diff
     *
     * @return array
     */
    public function getDirectories(): array
    {
        return $this->directories;
    }

    /**
     * Determines if directory exists in diff
     *
     * @param string $path
     * @return bool
     */
    public function hasDirectory(string $path): bool
    {
        return isset($this->directories[$path]);
    }
}
