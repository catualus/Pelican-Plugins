<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Catualus\GmodToolkit\Services\AddonCatalogue;
use Catualus\GmodToolkit\Services\AddonService;
use Catualus\GmodToolkit\Services\DiskUsageService;
use Catualus\GmodToolkit\Services\LuaErrorService;
use Catualus\GmodToolkit\Services\WorkshopService;
use Catualus\GmodToolkit\Support\Bytes;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * The page that answers "is this server all right" without making you visit four
 * others to find out.
 *
 * Everything here is already computed for one of the other pages - the point is the
 * arrangement, not new data: what the collection costs a joining player, which addons
 * are failing right now, and how much of the disk the addon folder is using.
 */
class Overview extends GmodPage
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-brand-steam';

    protected static ?int $navigationSort = 20;

    protected string $view = 'gmod-toolkit::overview';

    /** @var array<string, mixed> */
    public array $collection = [];

    /** @var array<string, mixed> */
    public array $errors = [];

    /** @var list<array<string, mixed>> */
    public array $offenders = [];

    /** @var array<string, mixed>|null */
    public ?array $disk = null;

    public int $addonCount = 0;

    public int $disabledCount = 0;

    public int $installedFromLibrary = 0;

    public ?string $collectionId = null;

    public float $downloadMbps = 50;

    public function mount(): void
    {
        $server = $this->server();

        $workshop = app(WorkshopService::class);
        $recentDays = (int) config('gmod-toolkit.recent_update_days', 7);

        $this->downloadMbps = (float) config('gmod-toolkit.client_download_mbps', 50);
        $this->collectionId = $workshop->collectionId($server);

        $addons = $this->collectionId === null ? [] : $workshop->addons($this->collectionId);
        $this->collection = $workshop->summarise($addons, $recentDays);

        $this->errors = app(LuaErrorService::class)->totals($server);

        $installed = app(AddonService::class)->all($server);
        $this->addonCount = count($installed);
        $this->disabledCount = count(array_filter($installed, static fn (array $a): bool => !$a['enabled']));

        // The five worth looking at first: still failing, worst first.
        $this->offenders = array_slice(
            array_values(array_filter(
                app(LuaErrorService::class)->digest($server),
                static fn (array $group): bool => $group['recent'] > 0,
            )),
            0,
            5,
        );

        $this->disk = app(DiskUsageService::class)->cached($server);

        $this->installedFromLibrary = count(array_filter(
            app(AddonCatalogue::class)->all($server),
            static fn (array $entry): bool => $entry['installed'],
        ));
    }

    /**
     * The wait a player who has never joined before is in for. Nothing else in the
     * panel surfaces this, and it is the number that decides whether a collection has
     * grown past what people will sit through.
     */
    public function downloadWait(): ?string
    {
        $size = (int) ($this->collection['size'] ?? 0);

        if ($size <= 0) {
            return null;
        }

        return Bytes::humanDuration(Bytes::downloadSeconds($size, $this->downloadMbps));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('restart')
                ->label('Restart server')
                ->icon('tabler-refresh')
                ->color('warning')
                ->authorize(fn (): bool => (bool) user()?->can(SubuserPermission::ControlRestart, $this->server()))
                ->requiresConfirmation()
                ->modalHeading('Restart the server?')
                ->modalDescription('Everyone online is disconnected. Addon changes - enabling, disabling or installing - only take effect after this.')
                ->action(fn () => $this->restart()),

            // The scan itself lives on the Addons page, where its progress and the
            // chart it feeds are both visible. Sending you there beats running a long
            // job from a page that cannot show you how it is going.
            Action::make('scan')
                ->label($this->disk === null ? 'Measure disk usage' : 'Re-measure disk usage')
                ->icon('tabler-ruler-measure')
                ->color('gray')
                ->url(fn (): string => Addons::getUrl()),
        ];
    }

    public function restart(): void
    {
        if (!user()?->can(SubuserPermission::ControlRestart, $this->server())) {
            Notification::make()->title('You do not have permission to restart this server.')->danger()->send();

            return;
        }

        try {
            app(DaemonServerRepository::class)->setServer($this->server())->power('restart');
        } catch (Throwable $e) {
            Notification::make()->title('Could not restart: ' . $e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Restarting the server.')->success()->send();
    }

    public static function getNavigationLabel(): string
    {
        return 'Overview';
    }

    public function getTitle(): string
    {
        return "Garry's Mod overview";
    }

    public function getSubheading(): ?string
    {
        return $this->server()->name;
    }
}
