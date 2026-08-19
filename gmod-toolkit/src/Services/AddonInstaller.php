<?php

namespace Catualus\GmodToolkit\Services;

use App\Models\Server;
use Catualus\GmodToolkit\Support\GarrysMod;

/**
 * Installs a catalogue addon by fetching it and having the daemon extract it.
 *
 * The panel cannot run shell commands, but it can fetch a URL and the daemon can
 * write bytes and extract an archive in place. The daemon's own remote-download
 * endpoint would be the obvious way to do the first part, but it rejects any response
 * without a Content-Length and GitHub never sends one for a generated archive - see
 * GarrysMod::fetchToServer().
 *
 * The work runs as a sequence of named steps rather than one long method, for two
 * reasons. It lets the page say what it is doing instead of freezing on a click, and
 * it means every step's result is checked before the next one starts.
 *
 * That second part is not decoration. An earlier version ran the whole thing in one
 * go and trusted each daemon call's response, and when an addon silently failed to
 * appear it still reported success and wrote a manifest entry saying so. Nothing here
 * is believed now: after the folder is moved into place the directory is read back,
 * and if the addon is not actually there the install fails and says so.
 */
class AddonInstaller
{
    /** Ordered, with the label shown while each is running. */
    public const STAGES = [
        'download' => 'Downloading the archive',
        'extract' => 'Extracting it',
        'locate' => 'Finding what was extracted',
        'park' => 'Moving the old copy aside',
        'place' => 'Putting it in place',
        'verify' => 'Checking it actually landed',
        'cleanup' => 'Tidying up',
        'record' => 'Recording what was installed',
    ];

    public function __construct(
        private readonly GarrysMod $gmod,
        private readonly AddonCatalogue $catalogue,
    ) {}

    /**
     * Prepares the state for installing one addon, without doing any work.
     *
     * @return array<string, mixed>  a plan, or one already marked failed
     */
    public function plan(Server $server, string $id, bool $update = false): array
    {
        $entry = $this->catalogue->find($server, $id);

        if ($entry === null) {
            return $this->failed($id, $id, 'That addon is not in the catalogue.');
        }

        if (!$entry['installable']) {
            return $this->failed($id, $entry['name'], "{$entry['name']} has no GitHub source, so it cannot be installed from here.");
        }

        if ($update && !$entry['installed']) {
            return $this->failed($id, $entry['name'], "{$entry['name']} is not installed.");
        }

        if (!$update && $entry['installed']) {
            return $this->failed($id, $entry['name'], "{$entry['name']} is already installed as {$entry['installed_folder']}.");
        }

        $root = 'garrysmod/' . $entry['target'];

        // Fresh, because the whole install is a diff against this list - a listing a
        // minute out of date would make the extracted folder impossible to identify.
        $before = $this->gmod->directoryNames($server, $root, fresh: true);
        $replacing = $update ? (string) $entry['installed_folder'] : null;

        // A stray folder under the target name that is not the addon itself would be
        // silently overwritten by the rename, so refuse rather than guess.
        if ($replacing === null && in_array(mb_strtolower($entry['folder']), array_map('mb_strtolower', $before), true)) {
            return $this->failed($id, $entry['name'], "{$root}/{$entry['folder']} already exists. Remove or rename it first.");
        }

        return [
            'id' => $id,
            'name' => $entry['name'],
            'repo' => $entry['repo'],
            'ref' => $entry['ref'],
            'target' => $entry['target'],
            'zip_url' => $entry['zip_url'],
            'root' => $root,
            'folder' => $entry['folder'],
            'archive' => $id . '.gmod-toolkit.zip',
            'before' => $before,
            'replacing' => $replacing,
            'parked' => null,
            'extracted' => null,
            'stage' => 'download',
            'attempts' => 0,
            'log' => [],
            'done' => false,
            'ok' => false,
            'message' => '',
        ];
    }

    /**
     * Runs the plan's current stage and returns the plan advanced by one step.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function step(Server $server, array $plan): array
    {
        if ($plan['done']) {
            return $plan;
        }

        return match ($plan['stage']) {
            'download' => $this->stepDownload($server, $plan),
            'extract' => $this->stepExtract($server, $plan),
            'locate' => $this->stepLocate($server, $plan),
            'park' => $this->stepPark($server, $plan),
            'place' => $this->stepPlace($server, $plan),
            'verify' => $this->stepVerify($server, $plan),
            'cleanup' => $this->stepCleanup($server, $plan),
            'record' => $this->stepRecord($server, $plan),
            default => $this->fail($server, $plan, 'Unknown installation stage.'),
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepDownload(Server $server, array $plan): array
    {
        $result = $this->gmod->fetchToServer($server, $plan['zip_url'], $plan['root'], $plan['archive']);

        if (!$result['ok']) {
            return $this->fail($server, $plan, "Could not download {$plan['name']}: {$result['message']}", cleanArchive: false);
        }

        return $this->advance($plan, 'extract', $result['message']);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepExtract(Server $server, array $plan): array
    {
        $result = $this->gmod->decompress($server, $plan['root'], $plan['archive']);

        if (!$result['ok']) {
            return $this->fail($server, $plan, "Could not extract {$plan['name']}: {$result['message']}");
        }

        return $this->advance($plan, 'locate', 'Extracted.');
    }

    /**
     * Works out which folder the archive created by diffing the directory listing,
     * rather than predicting GitHub's "<repo>-<ref>" naming - so a change in how
     * GitHub names its archives cannot leave a half-installed addon behind.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepLocate(Server $server, array $plan): array
    {
        $created = array_values(array_diff(
            $this->gmod->directoryNames($server, $plan['root'], fresh: true),
            $plan['before'],
        ));

        if (count($created) === 1) {
            $plan['extracted'] = $created[0];

            return $this->advance(
                $plan,
                $plan['replacing'] === null ? 'place' : 'park',
                "Extracted {$created[0]}.",
            );
        }

        // One retry, in case the listing was read a moment too early.
        if ($created === [] && $plan['attempts'] < 2) {
            $plan['attempts']++;

            return $this->advance($plan, 'locate', 'Nothing new yet, looking again.');
        }

        return $this->fail($server, $plan, $created === []
            ? "The archive for {$plan['name']} extracted no new folder into {$plan['root']}."
            : "The archive for {$plan['name']} created " . count($created) . ' folders (' . implode(', ', $created) . '), so which one to keep is ambiguous.');
    }

    /**
     * On an update the existing copy is moved aside rather than deleted, and only
     * removed once the new one is verifiably in place - so a failure leaves the
     * server with a working addon rather than none.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepPark(Server $server, array $plan): array
    {
        $parked = $plan['folder'] . '.gmod-toolkit-old';

        if (!$this->gmod->rename($server, $plan['root'], $plan['replacing'], $parked)) {
            return $this->fail($server, $plan, "Could not move the existing {$plan['replacing']} aside. Nothing was changed.");
        }

        $plan['parked'] = $parked;

        return $this->advance($plan, 'place', "Moved {$plan['replacing']} aside.");
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepPlace(Server $server, array $plan): array
    {
        if (!$this->gmod->rename($server, $plan['root'], $plan['extracted'], $plan['folder'])) {
            return $this->fail($server, $plan, "Extracted, but could not rename {$plan['extracted']} to {$plan['folder']}.");
        }

        return $this->advance($plan, 'verify', "Renamed {$plan['extracted']} to {$plan['folder']}.");
    }

    /**
     * Reads the directory back and confirms the addon is really there.
     *
     * Every step before this trusted a daemon response. This one trusts the
     * filesystem, because a chain of calls that each reported success is not the same
     * thing as an addon being installed - and reporting success when it is not there
     * is worse than reporting a failure.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepVerify(Server $server, array $plan): array
    {
        // Straight to the daemon. Anything cached is by definition not evidence of
        // what the rename a moment ago actually did.
        $present = array_map('mb_strtolower', $this->gmod->directoryNames($server, $plan['root'], fresh: true));

        if (!in_array(mb_strtolower($plan['folder']), $present, true)) {
            return $this->fail($server, $plan, sprintf(
                'Every step reported success, but %s/%s is not there afterwards, so nothing has been recorded as installed.',
                $plan['root'],
                $plan['folder'],
            ));
        }

        return $this->advance($plan, 'cleanup', "Confirmed {$plan['root']}/{$plan['folder']} exists.");
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepCleanup(Server $server, array $plan): array
    {
        $rubbish = [$plan['archive']];

        if ($plan['parked'] !== null) {
            $rubbish[] = $plan['parked'];
        }

        $this->gmod->delete($server, $plan['root'], $rubbish);

        return $this->advance($plan, 'record', 'Removed the archive.');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stepRecord(Server $server, array $plan): array
    {
        $this->catalogue->recordInstall($server, $plan['id'], [
            'repo' => $plan['repo'],
            'ref' => $plan['ref'],
            'sha' => $this->catalogue->headSha($plan['repo'], $plan['ref']),
            'folder' => $plan['folder'],
            'target' => $plan['target'],
            'installed_at' => now()->toIso8601String(),
        ]);

        $this->gmod->forget($server);
        $this->catalogue->forgetManifest($server);

        $plan['log'][] = 'Recorded the commit it came from.';
        $plan['stage'] = 'done';
        $plan['done'] = true;
        $plan['ok'] = true;
        $plan['message'] = $plan['replacing'] === null
            ? "Installed {$plan['name']} to {$plan['root']}/{$plan['folder']}."
            : "Updated {$plan['name']} in {$plan['root']}/{$plan['folder']}.";

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function advance(array $plan, string $stage, string $note): array
    {
        $plan['log'][] = $note;
        $plan['stage'] = $stage;

        return $plan;
    }

    /**
     * Gives up, leaving the server as close to how it was found as possible.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function fail(Server $server, array $plan, string $message, bool $cleanArchive = true): array
    {
        $rubbish = $cleanArchive && $plan['archive'] !== '' ? [$plan['archive']] : [];

        // Only tidy away the extracted folder if it never got renamed into place.
        // Once `place` has succeeded that folder IS the addon, and deleting it because
        // a later step was unhappy would throw away a working install to tidy up after
        // ourselves - which is the opposite of what a failed install should do.
        if ($plan['extracted'] !== null && in_array($plan['stage'], ['park', 'place'], true)) {
            $rubbish[] = $plan['extracted'];
        }

        // One at a time: the daemon refuses the whole batch if a single entry is
        // already gone, which would otherwise leave the archive sitting there.
        foreach ($rubbish as $file) {
            $this->gmod->delete($server, $plan['root'], [$file]);
        }

        // An update that got as far as moving the old copy aside must put it back.
        if ($plan['parked'] !== null) {
            $this->gmod->rename($server, $plan['root'], $plan['parked'], $plan['replacing']);
        }

        $this->gmod->forget($server);

        $plan['log'][] = $message;
        $plan['stage'] = 'done';
        $plan['done'] = true;
        $plan['ok'] = false;
        $plan['message'] = $message;

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $id, string $name, string $message): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'stage' => 'done',
            'done' => true,
            'ok' => false,
            'message' => $message,
            'log' => [$message],
            'root' => '',
            'folder' => '',
            'archive' => '',
            'before' => [],
            'replacing' => null,
            'parked' => null,
            'extracted' => null,
            'attempts' => 0,
        ];
    }

    /**
     * Removes an installed addon from disk and from the manifest.
     *
     * Only ever touches the folder the catalogue actually found - which may be under
     * one of the entry's detect aliases, or sitting in addons/__disabled - rather
     * than the name it would have used had it installed the addon itself.
     *
     * Like the install, this does not take the daemon's word for it: the directory is
     * read back and the folder must be gone before anything is recorded.
     *
     * @return array{ok: bool, message: string}
     */
    public function uninstall(Server $server, string $id): array
    {
        $entry = $this->catalogue->find($server, $id);

        if ($entry === null) {
            return ['ok' => false, 'message' => 'That addon is not in the catalogue.'];
        }

        if (!$entry['installed'] || $entry['installed_root'] === null) {
            return ['ok' => false, 'message' => "{$entry['name']} is not on this server."];
        }

        $root = (string) $entry['installed_root'];
        $folder = (string) $entry['installed_folder'];

        if (!$this->gmod->delete($server, $root, [$folder])) {
            return ['ok' => false, 'message' => "Could not delete {$root}/{$folder}."];
        }

        $present = array_map('mb_strtolower', $this->gmod->directoryNames($server, $root, fresh: true));

        if (in_array(mb_strtolower($folder), $present, true)) {
            return [
                'ok' => false,
                'message' => "The daemon reported the delete as done, but {$root}/{$folder} is still there. Nothing has been changed in the record.",
            ];
        }

        $forgotten = $this->catalogue->forgetInstall($server, $id);

        $this->gmod->forget($server);
        $this->catalogue->forgetManifest($server);

        return [
            'ok' => true,
            'message' => $forgotten
                ? "Removed {$root}/{$folder}. Restart the server to unload it."
                : "Removed {$root}/{$folder}, but the install record could not be rewritten.",
        ];
    }

    /**
     * The ids to install for an entry, dependencies first.
     *
     * Shallow on purpose - the catalogue's dependency graph is one level deep and a
     * general solver would be more machinery than the problem deserves.
     *
     * @return list<string>
     */
    public function order(Server $server, string $id): array
    {
        return array_map(
            static fn (array $entry): string => $entry['id'],
            $this->catalogue->withDependencies($server, $id),
        );
    }
}
