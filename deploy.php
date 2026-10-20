<?php

namespace Deployer;

/*
| Zero-downtime deploy to the Hostinger VPS (PRD 9).
|
|   vendor/bin/dep deploy production
|
| CI builds the frontend assets (public/build) and uploads them; the server
| only runs composer, migrations and cache warm-up. Releases live in releases/,
| `current` is a symlink, the last 5 releases are kept for instant rollback:
|
|   vendor/bin/dep rollback production
*/

require 'recipe/laravel.php';

set('application', 'pos');
set('repository', getenv('DEPLOY_REPOSITORY') ?: 'git@github.com:OWNER/REPO.git');
set('keep_releases', 5);
set('git_tty', false);
set('writable_mode', 'chmod');
set('shared_files', ['.env']);
set('shared_dirs', ['storage']);

host('production')
    ->setHostname(getenv('DEPLOY_HOST') ?: 'pos.example.com')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setPort((int) (getenv('DEPLOY_PORT') ?: 22))
    ->set('deploy_path', '/var/www/pos')
    ->set('branch', 'main');

host('staging')
    ->setHostname(getenv('DEPLOY_HOST') ?: 'pos.example.com')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->set('deploy_path', '/var/www/pos-staging')
    ->set('branch', 'main');

// Assets are built in CI (see .github/workflows/deploy.yml) and shipped as-is.
task('deploy:upload-assets', function () {
    upload('public/build/', '{{release_path}}/public/build/');
});

// Money-moving migrations must carry a written rollback plan in the PR (PRD 9.5).
task('deploy:restart-services', function () {
    // Graceful: Horizon finishes running jobs; Supervisor starts the new code.
    run('{{bin/php}} {{release_path}}/artisan horizon:terminate || true');
    run('{{bin/php}} {{release_path}}/artisan reverb:restart || true');
});

after('deploy:vendors', 'deploy:upload-assets');
after('deploy:symlink', 'deploy:restart-services');
after('deploy:failed', 'deploy:unlock');
