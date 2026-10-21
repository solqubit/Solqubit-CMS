<?php

namespace Tests\Feature;

use App\Services\HostingLayout;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HostingLayoutTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/sq-layout-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory.'/site/solqubit-core/bootstrap');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_contained_engine_requires_verification_for_this_directory_and_deny_rules(): void
    {
        $public = $this->directory.'/site';
        $core = $public.'/solqubit-core';
        $this->assertFalse(HostingLayout::isProtected($core, $public));
        file_put_contents($core.'/.htaccess', HostingLayout::DENY_RULES);
        $this->assertFalse(HostingLayout::isProtected($core, $public));
        file_put_contents($core.'/bootstrap/hosting-protection.json', json_encode(['layout' => 'domain-contained-v1', 'core' => $core, 'public' => $public]));
        $this->assertTrue(HostingLayout::isProtected($core, $public));
        file_put_contents($core.'/.htaccess', 'Require all granted');
        $this->assertFalse(HostingLayout::isProtected($core, $public));
        file_put_contents($core.'/.htaccess', HostingLayout::DENY_RULES);
        file_put_contents($core.'/bootstrap/hosting-protection.json', json_encode(['layout' => 'domain-contained-v1', 'core' => $core, 'public' => '/another-site']));
        $this->assertFalse(HostingLayout::isProtected($core, $public));
    }

    public function test_panel_private_folder_is_preferred_outside_the_web_root(): void
    {
        $public = $this->directory.'/site';
        File::ensureDirectoryExists($this->directory.'/private');
        $locations = HostingLayout::privateLocations($public);
        $this->assertSame(realpath($this->directory).'/private/solqubit-core', $locations[0]);
        $this->assertSame(realpath($this->directory).'/solqubit-core-site', $locations[1]);
        File::ensureDirectoryExists($this->directory.'/private/solqubit-core');
        $this->assertNotContains(realpath($this->directory).'/private/solqubit-core', HostingLayout::privateLocations($public));
    }

    public function test_parent_folder_is_not_used_when_it_is_another_web_root(): void
    {
        file_put_contents($this->directory.'/index.php', '<?php');
        $this->assertSame([], HostingLayout::privateLocations($this->directory.'/site'));
        File::ensureDirectoryExists($this->directory.'/public_html/addon');
        $this->assertSame([], HostingLayout::privateLocations($this->directory.'/public_html/addon'));
    }

    public function test_open_basedir_limits_where_the_engine_can_be_created(): void
    {
        $this->assertTrue(HostingLayout::withinOpenBasedir('/home/blog/web/example.com/private', ''));
        $restriction = implode(PATH_SEPARATOR, ['/home/blog/web/example.com/public_html', '/home/blog/web/example.com/private/', '/tmp']);
        $this->assertTrue(HostingLayout::withinOpenBasedir('/home/blog/web/example.com/private', $restriction));
        $this->assertFalse(HostingLayout::withinOpenBasedir('/home/blog/web/example.com', $restriction));
        $this->assertFalse(HostingLayout::withinOpenBasedir('/home/blog/web/example.com/public_html2', $restriction));
    }

    public function test_existing_separate_engines_remain_supported_but_public_root_is_rejected(): void
    {
        File::ensureDirectoryExists($this->directory.'/legacy-core');
        $this->assertTrue(HostingLayout::isProtected($this->directory.'/legacy-core', $this->directory.'/site'));
        $this->assertFalse(HostingLayout::isProtected($this->directory.'/site', $this->directory.'/site'));
        $this->assertFalse(HostingLayout::isProtected($this->directory.'/missing', $this->directory.'/site'));
    }

    public function test_invalid_host_is_rejected_and_probe_files_are_removed(): void
    {
        $public = $this->directory.'/site';
        $core = $public.'/solqubit-core';
        try {
            HostingLayout::verifyWebProtection($core, $public, ['HTTP_HOST' => "example.com\r\nInjected: value"]);
            $this->fail('An invalid host was accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('dirección', $error->getMessage());
        }
        $this->assertSame([], glob($public.'/solqubit-check-*'));
        $this->assertSame([], glob($core.'/solqubit-check-*'));
        $this->assertFileDoesNotExist($core.'/bootstrap/hosting-protection.json');
    }
}
