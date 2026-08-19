{{--
    Every distinct message an addon produced, not just the newest one. An addon
    failing in three different places reads as one recurring problem in the table;
    this is where that turns back into three.
--}}
<div class="fi-ta-ctn" style="overflow-x: auto;">
    <table class="fi-ta-table" style="width: 100%; text-align: start;">
        <thead>
            <tr>
                <th style="text-align: end; padding: 0.5rem 0.75rem; white-space: nowrap;">Count</th>
                <th style="text-align: start; padding: 0.5rem 0.75rem;">Message</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($variants as $variant)
                <tr wire:key="variant-{{ $loop->index }}">
                    <td style="padding: 0.5rem 0.75rem; text-align: end; vertical-align: top;">
                        <x-filament::badge :color="$loop->first ? 'danger' : 'gray'">
                            {{ number_format($variant['count']) }}
                        </x-filament::badge>
                    </td>
                    <td style="padding: 0.5rem 0.75rem;">
                        <span class="fi-text-sm">{{ $variant['message'] }}</span>

                        @if ($variant['location'])
                            @php $url = $editUrl($variant['location']); @endphp
                            <br>
                            @if ($url)
                                <x-filament::link :href="$url" size="xs">
                                    <code class="fi-text-xs">{{ $variant['location'] }}</code>
                                </x-filament::link>
                            @else
                                <code class="fi-text-xs fi-opacity-70">{{ $variant['location'] }}</code>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if (count($variants) >= 25)
    <p class="fi-text-xs fi-opacity-70" style="margin-top: 0.75rem;">
        Only the first 25 distinct messages are kept per addon - past that the tail is
        noise rather than information.
    </p>
@endif
