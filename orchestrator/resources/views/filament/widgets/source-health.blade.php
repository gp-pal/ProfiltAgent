<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Source health</x-slot>
        <x-slot name="description">Per-source action outcomes across all runs</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No collection actions recorded yet.
            </p>
        @else
            {{-- Inline styles: the panel ships Filament's stock stylesheet, which carries
                 none of the Tailwind utilities a hand-written table would need. --}}
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                    <thead>
                        <tr style="text-align:left;opacity:0.7">
                            <th style="padding:0.5rem 1rem 0.5rem 0;font-weight:500">Source</th>
                            @foreach ($statuses as $status)
                                <th style="padding:0.5rem 0.75rem;font-weight:500;text-align:right">{{ $status }}</th>
                            @endforeach
                            <th style="padding:0.5rem 0.75rem;font-weight:500;text-align:right">total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $source => $counts)
                            <tr style="border-top:1px solid rgba(128,128,128,0.2)">
                                <td style="padding:0.5rem 1rem 0.5rem 0;font-weight:500">
                                    {{ $source }}
                                </td>
                                @foreach ($statuses as $status)
                                    <td style="padding:0.5rem 0.75rem;text-align:right;font-variant-numeric:tabular-nums">
                                        {{ $counts[$status] ?? 0 }}
                                    </td>
                                @endforeach
                                <td style="padding:0.5rem 0.75rem;text-align:right;font-weight:600;font-variant-numeric:tabular-nums">
                                    {{ $counts['total'] ?? 0 }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
