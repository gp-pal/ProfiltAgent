<x-filament-panels::page>
    {{-- Inline styles: this project has no app Tailwind build, so utility
         classes beyond what Filament already ships (fi-text, text-sm, etc.)
         do not compile. --}}
    <div class="fi-section-content-ctn">
        <p class="fi-text text-sm text-gray-500 dark:text-gray-400" style="margin-bottom:1rem">
            Live tail of <code>{{ $this->getLogPath() }}</code> &mdash; last 200 lines, auto-refreshing
            every 10 seconds so you can watch the enrichment process.
        </p>

        <div wire:poll.10s>
            <pre style="max-height:32rem;overflow:auto;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,monospace;font-size:0.8rem;line-height:1.4;margin:0;padding:1rem;background:rgba(15,23,42,0.4);border-radius:0.5rem">{{ $this->logTail() }}</pre>
        </div>
    </div>
</x-filament-panels::page>
