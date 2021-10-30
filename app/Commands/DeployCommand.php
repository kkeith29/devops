<?php

declare(strict_types=1);

namespace App\Commands;

use App\Classes\{Process, Project};
use Exception;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\{Command\Command, Output\OutputInterface};
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Throwable;

/**
 * Class DeployCommand
 *
 * @package App\Commands
 */
class DeployCommand extends Command
{
    /**
     * @var string Name of command
     */
    protected static $defaultName = 'deploy';

    /**
     * Command configuration
     */
    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project id to deploy');
        $this->addArgument('env', InputArgument::REQUIRED, 'Environment');
        $this->addArgument('action', InputArgument::OPTIONAL, 'Action to do', 'setup');
        $this->addOption('last-commit-hash', mode: InputOption::VALUE_OPTIONAL, description: 'Sets last commit hash if not previously set');
        $this->addOption('force', mode: InputOption::VALUE_NONE, description: 'Forces deployment even if no changes are present');
        $this->addOption('run-webpack', mode: InputOption::VALUE_NONE, description: 'Forces a webpack run');
    }

    /**
     * Handle command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \App\Exceptions\GitException
     * @throws \App\Exceptions\ProjectException
     * @throws \App\Exceptions\Project\DeploymentException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function handle(InputInterface $input, OutputInterface $output): void
    {
        $project_name = $input->getArgument('project');

        $project = new Project($project_name);
        $project->getLogger()->pushHandler(new ConsoleHandler($output));
        $project->setOutputCallback(function (string $type, string $buffer) use ($output): void {
            $lines = array_filter(explode(PHP_EOL, $buffer), fn(string $line): bool => $line !== '');
            $output->writeln(array_map(fn(string $line): string => ($type === Process::ERR ? '<bg=red;fg=black> ERR </>' : '<bg=green;fg=black> OUT </>') . " => {$line}", $lines));
        });

        if (($last_commit_hash = $input->getOption('last-commit-hash')) !== null) {
            $project->setLastCommitHash($last_commit_hash);
        }

        $options = $input->getOptions();
        $options = array_intersect_key($options, array_flip(['force', 'run-webpack'])); // only allow select options

        $deployment = $project->getDeployment($options);
        $deployment->getLogger()->pushHandler(new ConsoleHandler($output));

        $env = $input->getArgument('env');
        $action = $input->getArgument('action');
        $action_map = [
            'setup' => Project\Deployment::ACTION_SETUP,
            'dry-run' => Project\Deployment::ACTION_DRY_RUN,
            'go' => Project\Deployment::ACTION_GO
        ];
        if (!isset($action_map[$action])) {
            throw new Exception("Invalid action: {$action}", 2);
        }

        $deployment->run($env, $action_map[$action]);
    }

    /**
     * Handle command execution
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
            $this->handle($input, $output);
            return Command::SUCCESS;
        } catch (Throwable $e) {
            if ($input->getOption('verbose')) {
                $output->write((string) $e);
            }
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
