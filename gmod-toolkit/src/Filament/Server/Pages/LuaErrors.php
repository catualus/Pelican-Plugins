<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\EditFiles;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use BackedEnum;
use Catualus\GmodToolkit\Services\LuaErrorService;
use Filament\Notifications\Notification;

class LuaErrors extends GmodPage
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-alert-triangle';

    protected static ?int $navigationSort = 22;

    protected string $view = 'gmod-toolkit::lua-errors';

    /** @var list<array<string, mixed>> */
    public array $sources = [];

    public bool $writable = false;

    public function mount(): void
    {
        $this->writable = $this->canWriteFiles();
        $this->load();
    }

    public function load(): void
    {
        $this->sources = app(LuaErrorService::class)->sources($this->server());
    }

    /**
     * Truncating is the only way to tell a stale error from a live one here: the
     * logs carry no timestamps, so a clean slate is what makes the next report
     * meaningful.
     */
    public function clear(string $key): void
    {
        if (!$this->canWriteFiles()) {
            Notification::make()
                ->title('You do not have permission to modify this server\'s files.')
                ->danger()
                ->send();

            return;
        }

        $cleared = app(LuaErrorService::class)->clear($this->server(), $key);

        Notification::make()
            ->title($cleared ? 'Log cleared.' : 'Could not clear the log.')
            ->status($cleared ? 'success' : 'danger')
            ->send();

        $this->load();
    }

    public function fileUrl(string $path): string
    {
        return ListFiles::getUrl(['path' => 'garrysmod/' . trim($path, '/')]);
    }

    /**
     * Opens the failing file itself in the editor rather than its folder. The parser
     * already captures "addons/x/lua/y.lua:42", so the line number is stripped and
     * the rest is handed to the editor - one click from an error to the code.
     */
    public function editUrl(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $file = preg_replace('/:\d+$/', '', str_replace('\\', '/', $location));

        if (!is_string($file) || !str_ends_with($file, '.lua')) {
            return null;
        }

        return EditFiles::getUrl(['path' => 'garrysmod/' . ltrim($file, '/')]);
    }

    public static function getNavigationLabel(): string
    {
        return 'Lua errors';
    }

    public function getTitle(): string
    {
        return 'Lua errors';
    }

    public function getSubheading(): ?string
    {
        $total = array_sum(array_column($this->sources, 'total'));

        return $total === 0 ? null : $total . ' errors grouped by addon';
    }
}
