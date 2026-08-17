<?php

namespace Catualus\AppToolkit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Catualus\AppToolkit\Support\AppServer;
use Throwable;

/**
 * Redeploy = restart.
 *
 * The panel has no arbitrary exec, so a plugin cannot run `git pull` directly. What
 * it can do is restart the server, because the Node and Python eggs both run their
 * git pull as part of the startup command - but only when AUTO_UPDATE is 1. That
 * caveat is surfaced rather than hidden, so a restart is never mistaken for a deploy.
 */
class DeployService
{
    public function __construct(private readonly AppServer $app) {}

    /**
     * @return array{repo: ?string, branch: ?string, auto_update: bool, has_git_dir: bool}
     */
    public function status(Server $server): array
    {
        // One batched lookup rather than a pair of queries per variable.
        $variables = $this->app->variables($server, ['GIT_ADDRESS', 'BRANCH', 'AUTO_UPDATE']);

        return [
            'repo' => $variables['GIT_ADDRESS'],
            'branch' => $variables['BRANCH'],
            'auto_update' => in_array($variables['AUTO_UPDATE'], ['1', 'true'], true),
            'has_git_dir' => $this->app->exists($server, '.git'),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function redeploy(Server $server): array
    {
        $status = $this->status($server);

        try {
            app(DaemonServerRepository::class)->setServer($server)->power('restart');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not restart: ' . $e->getMessage()];
        }

        $this->app->forget($server);

        return [
            'ok' => true,
            'message' => $status['auto_update']
                ? 'Restarting. The startup command will pull the latest commit before booting.'
                : 'Restarting. Auto Update is off, so this restarts the current code without pulling.',
        ];
    }
}
