@props([
    'label',
    'value',
    'description' => null,
    'icon' => null,
    'tone' => 'neutral',
    'url' => null,
])

{{--
    A stat tile.

    Colours are derived from currentColor rather than from Filament's own CSS
    variables, whose names and formats have changed between major versions - a tile
    built this way inherits the panel's text colour and therefore inverts correctly
    in dark mode without knowing anything about the theme. Only the accent is a fixed
    value, and those five were picked to stay legible on both a white and a near-black
    surface.
--}}
@php
    $accent = match ($tone) {
        'danger' => '#ef4444',
        'warning' => '#f59e0b',
        'success' => '#10b981',
        'info' => '#3b82f6',
        default => null,
    };

    $tag = $url ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($url) href="{{ $url }}" @endif
    {{ $attributes->class(['fi-section']) }}
    style="
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        padding: 1rem 1.25rem;
        border-radius: 0.75rem;
        border: 1px solid color-mix(in srgb, currentColor 12%, transparent);
        background-color: color-mix(in srgb, currentColor 3%, transparent);
        text-decoration: none;
        color: inherit;
        {{ $url ? 'cursor: pointer;' : '' }}
    "
>
    <span style="display: flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; line-height: 1rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.65;">
        @if ($icon)
            <x-filament::icon :icon="$icon" style="width: 0.875rem; height: 0.875rem;{{ $accent ? ' color: ' . $accent . ';' : '' }}" />
        @endif

        {{ $label }}
    </span>

    <span style="font-size: 1.5rem; line-height: 2rem; font-weight: 600;{{ $accent ? ' color: ' . $accent . ';' : '' }}">
        {{ $value }}
    </span>

    @if ($description)
        <span style="font-size: 0.75rem; line-height: 1.1rem; opacity: 0.7;">{{ $description }}</span>
    @endif
</{{ $tag }}>
