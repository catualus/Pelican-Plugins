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
 * git pull as part of the startup command - but only when AUTO_UPDATE is on. That
 * caveat is surfaced rather than hidden, so a restart is never mistaken for a deploy.
 */
class DeployService
{
    /**
     * Values eggs use for "yes". The stock eggs write 1, but forks and hand-edited
     * variables use plenty of others, and reading one of those as "off" would have
     * the page warn that a restart will not pull when it will.
     */
    private const TRUTHY = ['1', 'true', 'yes', 'on', 'enabled'];

    public function __construct(private readonly AppServer $app) {}

    /**
     * @return array{repo: ?string, branch: ?string, auto_update: bool, has_git_dir: bool, commit: ?string, commit_ref: ?string}
     */
    public function status(Server $server): array
    {
        // One batched lookup rather than a pair of queries per variable.
        $variables = $this->app->variables($server, ['GIT_ADDRESS', 'BRANCH', 'AUTO_UPDATE']);

        $hasGit = $this->app->exists($server, '.git');
        $head = $hasGit ? $this->head($server) : ['commit' => null, 'ref' => null];

        return [
            'repo' => $variables['GIT_ADDRESS'],
            'branch' => $variables['BRANCH'],
            'auto_update' => in_array(mb_strtolower((string) $variables['AUTO_UPDATE']), self::TRUTHY, true),
            'has_git_dir' => $hasGit,
            'commit' => $head['commit'],
            'commit_ref' => $head['ref'],
        ];
    }

    /**
     * Which commit is actually on disk, read straight out of .git.
     *
     * Knowing the repository and branch tells you what *should* be running; this
     * tells you what is. `.git/HEAD` is either a raw sha (detached) or a pointer to
     * a ref file holding one, and both cases are two small reads through the daemon.
     *
     * @return array{commit: ?string, ref: ?string}
     */
    private function head(Server $server): array
    {
        $head = trim((string) $this->app->contents($server, '.git/HEAD'));

        if ($head === '') {
            return ['commit' => null, 'ref' => null];
        }

        if (!str_starts_with($head, 'ref:')) {
            // Detached head - the file is the sha itself.
            return ['commit' => $this->shorten($head), 'ref' => null];
        }

        $ref = trim(substr($head, 4));
        $sha = trim((string) $this->app->contents($server, '.git/' . $ref));

        if ($sha === '') {
            // A packed ref, which lives in .git/packed-refs rather than its own file.
            $sha = $this->fromPackedRefs($server, $ref);
        }

        return [
            'commit' => $sha === '' ? null : $this->shorten($sha),
            'ref' => str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref,
        ];
    }

    private function fromPackedRefs(Server $server, string $ref): string
    {
        $packed = $this->app->contents($server, '.git/packed-refs');

        if ($packed === null) {
            return '';
        }

        foreach (preg_split('/\R/', $packed) ?: [] as $line) {
            if (str_starts_with($line, '#') || !str_ends_with($line, ' ' . $ref)) {
                continue;
            }

            return trim(strtok($line, ' ') ?: '');
        }

        return '';
    }

    private function shorten(string $sha): ?string
    {
        return preg_match('/^[0-9a-f]{40}$/i', $sha) ? substr($sha, 0, 7) : null;
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
