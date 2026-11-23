<?php

namespace App\Http\Controllers;

use App\Models\CollectionRun;
use App\Tools\SessionFs;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

readonly class RunArtifactController
{
    public function __construct(private SessionFs $sessionFs) {}

    /**
     * Stream a run screenshot. The client only supplies the run (route-model
     * bound by primary key) and a file basename; the on-disk location is
     * derived server-side from the run's session so no filesystem path is ever
     * accepted from the request.
     */
    public function screenshot(CollectionRun $run, string $name): StreamedResponse
    {
        if (preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g)$/', $name) !== 1) {
            abort(404);
        }

        $base = $this->sessionFs->findAnalysisBase((string) $run->session);

        if ($base === null) {
            abort(404);
        }

        $disk = Storage::disk('local');
        $path = "{$base}/screenshots/{$name}";

        if (!$disk->exists($path)) {
            abort(404);
        }

        // Defense-in-depth on top of the strict basename regex: the resolved
        // absolute path must live inside the runs directory before streaming.
        $fileReal = realpath($disk->path($path));
        $runsReal = realpath($disk->path('runs'));

        if ($fileReal === false
            || $runsReal === false
            || !str_starts_with($fileReal, $runsReal . DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $contentType = str_ends_with(strtolower($name), '.png') ? 'image/png' : 'image/jpeg';

        return $disk->response($path, $name, ['Content-Type' => $contentType]);
    }
}
