<?php

namespace App\Tools;

use Illuminate\Support\Facades\Storage;

class SessionFs
{
    private string $runsDir;

    public function __construct()
    {
        $this->runsDir = 'runs';
    }

    /**
     * Create domain slug from domain name
     */
    public function createDomainSlug(string $domain): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9.-]/', '_', $domain));

        // Collapse any '..' run so the slug can never become a path-traversal segment.
        $slug = preg_replace('/\.{2,}/', '_', $slug);

        return trim($slug, '.-');
    }

    /**
     * Get full analysis path for session/domain
     */
    public function getAnalysisPath(string $session, string $domain): string
    {
        $domainSlug = $this->createDomainSlug($domain);
        return "{$this->runsDir}/{$session}/{$domainSlug}/analyze";
    }

    /**
     * Ensure all required directories exist, writable by the runner container.
     *
     * This tree is shared by two containers running as different users: php-fpm
     * (www-data, uid 33) creates these directories in phase 1, and the Playwright
     * runner (pwuser, uid 1000) writes screenshots into them in phase 5. Neither
     * the uid nor the gid match, so only the "other" bits admit the runner —
     * Laravel's default private visibility (0700) made every capture die with
     * "EACCES: permission denied, mkdir .../analyze/screenshots", which surfaced
     * as a generic runner_failed and left official_site with zero successful runs.
     *
     * Every level is widened, not just the leaf: the runner fails on the first
     * inaccessible component of the path. The parent `runs/` directory is already
     * world-writable, so this lowers no barrier that was holding.
     */
    public function ensureDirectories(string $basePath): void
    {
        $dirs = [
            $basePath,
            "{$basePath}/iframes",
            "{$basePath}/screenshots",
            "{$basePath}/plans",
            "{$basePath}/logs"
        ];

        foreach ($dirs as $dir) {
            if (!Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->makeDirectory($dir);
            }
        }

        $this->openToRunner($basePath, $dirs);
    }

    /**
     * chmod 0777 every directory from the runs root down to the created leaves.
     * makeDirectory() creates intermediate levels with private visibility too, so
     * widening only the listed directories would still leave the runner blocked at
     * `runs/{session}` or `runs/{session}/{domain}`.
     *
     * @param array<int, string> $dirs
     */
    private function openToRunner(string $basePath, array $dirs): void
    {
        $root = rtrim((string) config('filesystems.disks.local.root'), '/');
        $ancestors = [];
        $walk = $basePath;

        while ($walk !== '' && $walk !== '.' && $walk !== $this->runsDir) {
            $ancestors[] = $walk;
            $walk = dirname($walk);
        }

        foreach (array_unique([...$dirs, ...$ancestors]) as $dir) {
            $absolute = "{$root}/{$dir}";
            if (is_dir($absolute)) {
                @chmod($absolute, 0777);
            }
        }
    }

    /**
     * Check if Step-1 is satisfied.
     *
     * Requires a non-empty main.html (the captured page) AND at least one
     * screenshot — the screenshot is the visual proof of what the agent saw, so
     * the DSL plan is expected to capture one into the screenshots/ directory.
     */
    public function isStep1Satisfied(string $basePath): bool
    {
        if (!Storage::disk('local')->exists("{$basePath}/main.html")) {
            return false;
        }

        if (Storage::disk('local')->size("{$basePath}/main.html") <= 0) {
            return false;
        }

        return count(Storage::disk('local')->files("{$basePath}/screenshots")) > 0;
    }

    /**
     * Get paths object for API response
     */
    public function getPaths(string $basePath): array
    {
        $storagePath = storage_path('app');
        $fullBasePath = "{$storagePath}/{$basePath}";

        return [
            'main_html' => "{$fullBasePath}/main.html",
            'iframes_dir' => "{$fullBasePath}/iframes",
            'screenshots_dir' => "{$fullBasePath}/screenshots",
            'plans_dir' => "{$fullBasePath}/plans",
            'logs_dir' => "{$fullBasePath}/logs",
            'relative_base' => $basePath
        ];
    }

    /**
     * Write plan to plans directory
     */
    public function writePlan(string $basePath, string $filename, array $plan): void
    {
        Storage::disk('local')->put(
            "{$basePath}/plans/{$filename}",
            json_encode($plan, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Write main HTML content
     */
    public function writeMainHtml(string $basePath, string $content): void
    {
        Storage::disk('local')->put(
            "{$basePath}/main.html",
            $content
        );
    }

    /**
     * Write main page markdown (converted by the runner's extract_markdown action)
     */
    public function writeMainMarkdown(string $basePath, string $content): void
    {
        Storage::disk('local')->put("{$basePath}/main.md", $content);
    }

    /**
     * Store one news article's captured HTML beside its extracted text, under
     * `news/`. Both are kept for the same reason `main.html` is kept next to
     * `main.md`: the text is what the pipeline and the dashboard read, and the HTML is
     * what a human opens when the extraction looks wrong.
     *
     * Returns the disk-relative paths written, so the mention can point at them.
     *
     * @return array{html: string, markdown: string}
     */
    public function writeNewsArticle(string $basePath, string $slug, string $html, string $markdown): array
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '', '-');
        $slug = $slug === '' ? 'article' : mb_strtolower(mb_substr($slug, 0, 80));

        $htmlPath = "{$basePath}/news/{$slug}.html";
        $markdownPath = "{$basePath}/news/{$slug}.md";

        Storage::disk('local')->put($htmlPath, $html);
        Storage::disk('local')->put($markdownPath, $markdown);

        return ['html' => $htmlPath, 'markdown' => $markdownPath];
    }

    /**
     * Append ReAct search steps as JSONL under the session logs.
     *
     * @param array<int, mixed> $steps
     */
    public function writeReactTrace(string $basePath, array $steps): void
    {
        if ($steps === []) {
            return;
        }
        $lines = array_map(static fn ($s): string => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $steps);
        Storage::disk('local')->put("{$basePath}/logs/react_trace.jsonl", implode("\n", $lines));
    }

    /**
     * Persist the ReAct search log (what each query surfaced, including
     * non-official sources filtered out of the candidate list) for later analysis
     * of whether alternative collection actions are worthwhile.
     *
     * @param array<int, mixed> $log
     */
    public function writeSearchResults(string $basePath, array $log): void
    {
        if ($log === []) {
            return;
        }

        Storage::disk('local')->put(
            "{$basePath}/logs/search_results.json",
            json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Write structured HTML analysis (contacts, social links, company indicators)
     *
     * @param array<string, mixed> $structure
     */
    public function writeStructure(string $basePath, array $structure): void
    {
        Storage::disk('local')->put(
            "{$basePath}/structure.json",
            json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Write the structured company profile as JSON.
     *
     * @param array<string, mixed> $profile
     */
    public function writeProfile(string $basePath, array $profile): void
    {
        Storage::disk('local')->put(
            "{$basePath}/profile.json",
            json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Read a previously persisted company profile, if one exists. Used to
     * rehydrate state on a Step-1 cache hit so a cached run reports the same
     * profile it produced originally instead of a spurious null.
     *
     * @return array<string, mixed>|null
     */
    public function readProfile(string $basePath): ?array
    {
        if (!Storage::disk('local')->exists("{$basePath}/profile.json")) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get("{$basePath}/profile.json"), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write the human-readable markdown summary.
     */
    public function writeSummary(string $basePath, string $markdown): void
    {
        Storage::disk('local')->put("{$basePath}/summary.md", $markdown);
    }

    /**
     * Discover the disk-relative analyze basePath for a session.
     *
     * A CollectionRun stores the session but not the resolved domain, so the
     * analyze directory has to be located by listing the domain directories
     * under the session and returning the first one that owns an `analyze`
     * child. Returns null when the session is blank or nothing is found.
     */
    public function findAnalysisBase(string $session): ?string
    {
        if (blank($session)) {
            return null;
        }

        $sessionDir = "{$this->runsDir}/{$session}";

        foreach (Storage::disk('local')->directories($sessionDir) as $domainDir) {
            $analyze = "{$domainDir}/analyze";

            if (Storage::disk('local')->exists($analyze)) {
                return $analyze;
            }
        }

        return null;
    }

    /**
     * Read the human-readable summary markdown for a run, or null when the file
     * is absent or empty.
     */
    public function readSummary(string $basePath): ?string
    {
        $path = "{$basePath}/summary.md";

        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        $contents = (string) Storage::disk('local')->get($path);

        return $contents === '' ? null : $contents;
    }

    /**
     * Read the persisted web-search log (what each ReAct query surfaced), or
     * null when the file is absent or does not decode to an array.
     *
     * @return array<int, mixed>|null
     */
    public function readSearchResults(string $base): ?array
    {
        $path = "{$base}/logs/search_results.json";

        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Read the captured page markdown (converted by the runner's
     * extract_markdown action) for a run, or null when absent/empty.
     */
    public function readMainMarkdown(string $base): ?string
    {
        $path = "{$base}/main.md";

        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        $contents = (string) Storage::disk('local')->get($path);

        return $contents === '' ? null : $contents;
    }

    /**
     * Read the extracted page structure (navigation, contacts, social links,
     * company indicators), or null when the file is absent or does not
     * decode to an array.
     *
     * @return array<string, mixed>|null
     */
    public function readStructure(string $base): ?array
    {
        $path = "{$base}/structure.json";

        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Parse the ReAct domain-discovery trace (JSON-lines, one decoded step
     * per line) into an array of decoded steps. Blank or invalid lines are
     * skipped; returns null when the file is absent or yields no valid step.
     *
     * @return array<int, mixed>|null
     */
    public function readReactTrace(string $base): ?array
    {
        $path = "{$base}/logs/react_trace.jsonl";

        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        $lines = preg_split('/\r?\n/', (string) Storage::disk('local')->get($path)) ?: [];

        $steps = [];
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if ($decoded !== null) {
                $steps[] = $decoded;
            }
        }

        return $steps === [] ? null : $steps;
    }

    /**
     * List the screenshot basenames captured for a run, sorted alphabetically.
     * Only image files (`*.png` / `*.jpg` / `*.jpeg`) are returned; unknown or
     * missing directories yield an empty list.
     *
     * @return array<int, string>
     */
    public function listScreenshots(string $basePath): array
    {
        $files = Storage::disk('local')->files("{$basePath}/screenshots");

        $names = [];
        foreach ($files as $file) {
            if (preg_match('/\.(png|jpe?g)$/i', $file) === 1) {
                $names[] = basename($file);
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Write iframe HTML files
     */
    public function writeIframes(string $basePath, array $iframesHtml): void
    {
        foreach ($iframesHtml as $i => $iframeData) {
            // Extract HTML content from iframe object structure
            $htmlContent = is_array($iframeData) && isset($iframeData['html'])
                ? $iframeData['html']
                : (string) $iframeData;

            Storage::disk('local')->put(
                "{$basePath}/iframes/iframe-{$i}.html",
                $htmlContent
            );
        }
    }

    /**
     * Append runner logs as JSONL
     */
    public function appendRunnerLogs(string $basePath, array $logs): void
    {
        if (empty($logs)) {
            return;
        }

        $logEntries = [];
        foreach ($logs as $log) {
            $logEntries[] = json_encode($log);
        }

        Storage::disk('local')->append(
            "{$basePath}/logs/runner.jsonl",
            implode("\n", $logEntries)
        );
    }

    /**
     * Create lightweight lock for session/domain to prevent concurrent runs
     */
    public function createLock(string $session, string $domain): bool
    {
        $lockFile = "{$this->runsDir}/.lock_{$session}_{$domain}";

        if (Storage::disk('local')->exists($lockFile)) {
            // Check if lock is stale (older than 10 minutes)
            $lockTime = Storage::disk('local')->lastModified($lockFile);
            if (time() - $lockTime > 600) {
                Storage::disk('local')->delete($lockFile);
            } else {
                return false; // Lock is active
            }
        }

        Storage::disk('local')->put($lockFile, time());
        return true;
    }

    /**
     * Release lock for session/domain
     */
    public function releaseLock(string $session, string $domain): void
    {
        $lockFile = "{$this->runsDir}/.lock_{$session}_{$domain}";

        if (Storage::disk('local')->exists($lockFile)) {
            Storage::disk('local')->delete($lockFile);
        }
    }

    /**
     * Move contents from pending directory to resolved domain directory
     */
    public function movePendingToResolved(string $session, string $resolvedDomain): void
    {
        $pendingPath = "{$this->runsDir}/{$session}/pending/analyze";
        $resolvedPath = $this->getAnalysisPath($session, $resolvedDomain);

        if (!Storage::disk('local')->exists($pendingPath)) {
            return; // Nothing to move
        }

        // Ensure resolved directory exists
        $this->ensureDirectories($resolvedPath);

        // Move all files from pending to resolved
        $files = Storage::disk('local')->allFiles($pendingPath);

        foreach ($files as $file) {
            $relativePath = str_replace($pendingPath . '/', '', $file);
            $newPath = "{$resolvedPath}/{$relativePath}";

            Storage::disk('local')->copy($file, $newPath);
        }

        // Clean up pending directory
        Storage::disk('local')->deleteDirectory("{$this->runsDir}/{$session}/pending");
    }
}