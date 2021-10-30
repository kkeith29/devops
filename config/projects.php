<?php

use App\Classes\Project\Deployment\Environment;

use function Core\Functions\env;

return [
    'app' => [
        'name' => 'App',
        'base_path' => env('APP_LOCAL_BASE_PATH'),
        'git' => [
            'upstream' => 'origin',
            'master' => 'master',
            'develop' => 'develop'
        ],
        'environments' => [
            '*' => [
                'remote_path' => '/var/www/domain.com/site-files',
                'remote_log_file' => '/var/www/domain.com/logs/deploy.log'
            ],
            'staging' => [
                'ssh_host' => env('APP_STAGING_SSH_HOST'),
                'ssh_port' => env('APP_STAGING_SSH_PORT', 22),
                'setup_cmds' => [
                    ['cmd' => 'vagrant up', 'local' => true],
                    [
                        'cmd' => "vagrant ssh -c 'ENV=production /var/www/applications/company/build/composer-install.sh'",
                        'local' => true,
                        'when' => function (Environment $env): bool {
                            return $env->getDeployment()->getDiff()->hasFile('applications/company/composer.lock');
                        }
                    ],
                    [
                        'name' => 'yarn-install',
                        'cmd' => "vagrant ssh -c '/var/www/applications/company/build/yarn-install.sh'",
                        'local' => true,
                        'when' => function (Environment $env): bool {
                            return $env->getDeployment()->getDiff()->hasFile('applications/company/yarn.lock');
                        }
                    ],
                    [
                        'cmd' => "vagrant ssh -c 'cd /var/www/applications/company && yarn run compile-sass'",
                        'local' => true,
                        'when' => function (Environment $env): bool {
                            return $env->getDeployment()->getDiff()->hasDirectory('applications/company/resources/assets/sass');
                        }
                    ],
                    [
                        'cmd' => "vagrant ssh -c 'cd /var/www/applications/company && yarn run copy-files --mode=production'",
                        'local' => true
                    ],
                    [
                        'cmd' => "vagrant ssh -c 'cd /var/www/applications/company && yarn run build --mode=production'",
                        'local' => true,
                        'when' => function (Environment $env): bool {
                            $deployment = $env->getDeployment();
                            return (
                                $deployment->getOption('run-webpack', false) ||
                                $env->isCommandRan('yarn-install') ||
                                $deployment->getDiff()->hasDirectory('applications/company/resources/assets')
                            );
                        }
                    ]
                ]
            ],
            'prod' => [
                'production' => true,
                'ssh_host' => env('APP_PROD_SSH_HOST'),
                'ssh_port' => env('APP_PROD_SSH_PORT', 22)
            ]
        ]
    ]
];
