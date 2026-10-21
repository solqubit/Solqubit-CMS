<?php

namespace Tests\Unit;

use App\Services\UpdatePackage;
use PHPUnit\Framework\TestCase;

class PublicFilesTest extends TestCase
{
    /**
     * Installed sites reject a package with any public file outside the paths their updater allows, so a new
     * public file must live in an allowed folder: the allowed list of an older updater cannot be changed.
     */
    public function test_every_public_file_is_in_a_path_installed_sites_can_update(): void
    {
        $site = dirname(__DIR__, 3).'/site';
        $rejected = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = 'site/'.str_replace('\\', '/', substr($file->getPathname(), strlen($site) + 1));
            if ($file->isFile() && ! in_array($relative, ['site/index.php', 'site/.htaccess'], true) && ! UpdatePackage::allowedPath($relative)) {
                $rejected[] = $relative;
            }
        }

        $this->assertSame([], $rejected);
    }
}
