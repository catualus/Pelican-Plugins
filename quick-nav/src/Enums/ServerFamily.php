<?php

namespace Catualus\QuickNav\Enums;

use App\Models\Server;

enum ServerFamily: string
{
    case GarrysMod = 'garrys_mod';
    case Source = 'source';
    case Minecraft = 'minecraft';
    case Node = 'node';
    case Python = 'python';
    case Postgres = 'postgres';
    case Generic = 'generic';

    /**
     * Work out what kind of server this is from its egg.
     *
     * Egg tags are the ecosystem convention for this (see ModrinthProjectType::fromServer
     * in the minecraft-modrinth plugin), but tags are optional and plenty of eggs ship
     * without them, so this falls through to egg features and finally the docker image.
     *
     * Note this cannot tell Garry's Mod apart from any other Source game - every Source
     * egg carries the same features. QuickNavResolver refines that with a directory probe.
     */
    public static function fromServer(Server $server): self
    {
        $server->loadMissing('egg');

        $tags = array_map('strtolower', (array) ($server->egg?->tags ?? []));
        $features = array_map('strtolower', (array) ($server->egg?->features ?? []));
        $image = strtolower($server->image ?? '');

        return match (true) {
            (bool) array_intersect($tags, ['gmod', 'garrysmod', 'garrys-mod']) => self::GarrysMod,
            in_array('minecraft', $tags, true) => self::Minecraft,
            (bool) array_intersect($tags, ['source', 'source-engine', 'srcds']) => self::Source,
            in_array('eula', $features, true) => self::Minecraft,
            (bool) array_intersect($features, ['steam_disk_space', 'gsl_token']) => self::Source,
            str_contains($image, 'nodejs') => self::Node,
            str_contains($image, 'python') => self::Python,
            str_contains($image, 'postgres') => self::Postgres,
            default => self::Generic,
        };
    }
}
