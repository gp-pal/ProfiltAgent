<?php

namespace Tests\Unit;

use App\Tools\SessionFs;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The runs tree is shared between two containers that run as different users:
 * php-fpm as www-data (uid 33) creates the session directories, and the Playwright
 * runner as pwuser (uid 1000) then writes screenshots into them. Neither the uid nor
 * the gid match, so only the "other" permission bits let the runner in — with
 * Laravel's default private visibility (0700) every capture died with
 * "EACCES: permission denied, mkdir .../analyze/screenshots".
 */
class SessionFsRunnerAccessTest extends TestCase
{
    private string $session = '';

    /**
     * fileperms() reads PHP's stat cache, which still holds the 0700 recorded when
     * Flysystem created the directory — without clearing it the assertion measures
     * the cache instead of the filesystem. (Irrelevant in production: the runner is
     * a separate process in a separate container.)
     */
    private function permissionsOf(string $absolute): string
    {
        clearstatcache(true, $absolute);

        return substr(sprintf('%o', fileperms($absolute)), -4);
    }

    protected function tearDown(): void
    {
        if ($this->session !== '') {
            Storage::disk('local')->deleteDirectory("runs/{$this->session}");
        }
        parent::tearDown();
    }

    public function test_session_directories_are_writable_by_the_runner_container(): void
    {
        $this->session = 'test-perm-' . uniqid();
        $base = "runs/{$this->session}/example.lt/analyze";

        (new SessionFs())->ensureDirectories($base);

        $root = config('filesystems.disks.local.root');
        foreach (['', '/screenshots', '/plans', '/logs', '/iframes'] as $sub) {
            $abs = "{$root}/{$base}{$sub}";
            $this->assertDirectoryExists($abs);
            $this->assertSame(
                '0777',
                $this->permissionsOf($abs),
                "{$base}{$sub} must be writable by the runner's uid",
            );
        }
    }

    public function test_parent_session_directory_is_traversable_by_the_runner(): void
    {
        $this->session = 'test-perm-' . uniqid();
        (new SessionFs())->ensureDirectories("runs/{$this->session}/example.lt/analyze");

        // The runner fails on the first inaccessible component, so the intermediate
        // levels matter as much as the leaf.
        $root = config('filesystems.disks.local.root');
        foreach (["runs/{$this->session}", "runs/{$this->session}/example.lt"] as $dir) {
            $this->assertSame(
                '0777',
                $this->permissionsOf("{$root}/{$dir}"),
                "{$dir} must be traversable by the runner's uid",
            );
        }
    }
}
