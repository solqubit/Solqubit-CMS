<?php

namespace Tests\Feature;

use App\Filament\Pages\Statistics;
use App\Filament\Widgets\Visits\TopCountries;
use App\Filament\Widgets\Visits\TopPages;
use App\Filament\Widgets\Visits\TopSources;
use App\Filament\Widgets\Visits\VisitTotals;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\CountryDatabase;
use App\Services\VisitStatistics;
use App\Services\VisitTracker;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class VisitStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';

    private string $marker;

    private string $countryFile;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-test-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        $this->countryFile = sys_get_temp_dir().'/solqubit-countries-'.bin2hex(random_bytes(8)).'.bin';
        config(['solqubit.installed_file' => $this->marker, 'solqubit.country_database_file' => $this->countryFile]);
        Setting::write('site', ['name' => 'Solqubit', 'description' => 'Material de apoyo', 'timezone' => 'America/Guayaquil', 'about' => '<p>Sobre el blog.</p>']);
        Setting::write('theme', 'solqubit');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        @unlink($this->countryFile);
        parent::tearDown();
    }

    private function createPost(string $title = 'Mi entrada Linux'): Post
    {
        return Post::create(['user_id' => $this->admin->id, 'title' => $title, 'slug' => 'linux-'.bin2hex(random_bytes(4)), 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Contenido.</p>']]], 'status' => 'published', 'published_at' => now()->subMinute()]);
    }

    private function path(Post $post): string
    {
        return (string) parse_url($post->url(), PHP_URL_PATH);
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, string>  $headers
     */
    private function visit(array $data, array $headers = [], string $ip = '203.0.113.10'): void
    {
        $this->withHeaders($headers + ['User-Agent' => self::CHROME])->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/visita', $data)->assertNoContent();
    }

    private function statistics(): VisitStatistics
    {
        return app(VisitStatistics::class);
    }

    public function test_a_page_view_is_recorded_with_its_details_and_without_the_address(): void
    {
        $post = $this->createPost();
        $this->visit(['p' => $this->path($post), 'r' => 'https://www.google.com.ec/', 'n' => '0'], ['CF-IPCountry' => 'EC']);
        $this->visit(['p' => '/', 'r' => 'https://news.example.org/hoy', 'n' => '0'], ['User-Agent' => self::IPHONE]);
        $this->visit(['p' => '/', 'r' => 'http://localhost/otra', 'u' => 'Boletin']);

        $visits = DB::table('visits')->orderBy('id')->get();
        $this->assertCount(3, $visits);
        $this->assertSame([$this->path($post), 'EC', 'Google', 'desktop', 'Chrome', 'Windows'], [$visits[0]->path, $visits[0]->country, $visits[0]->source, $visits[0]->device, $visits[0]->browser, $visits[0]->os]);
        $this->assertSame(['news.example.org', 'mobile', 'Safari', 'iOS'], [$visits[1]->source, $visits[1]->device, $visits[1]->browser, $visits[1]->os]);
        $this->assertSame('boletin', $visits[2]->source);
        $this->assertSame(16, strlen($visits[0]->visitor));
        $this->assertStringNotContainsString('203.0.113.10', json_encode($visits));
    }

    public function test_robots_unknown_pages_and_paused_tracking_are_not_counted(): void
    {
        $this->visit(['p' => '/'], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
        $this->visit(['p' => '/'], ['User-Agent' => '']);
        foreach (['/admin', '/install', '/visita', '/entrada/no-existe', 'https://otro.test/', '/entrada/../admin', '', '/'.str_repeat('a', 300)] as $path) {
            $this->visit(['p' => $path]);
        }
        Setting::write('visits', ['enabled' => false, 'returning' => true]);
        $this->visit(['p' => '/']);

        $this->assertSame(0, DB::table('visits')->count());
    }

    public function test_the_same_visitor_counts_once_a_day_and_returning_visitors_are_recognised(): void
    {
        $post = $this->createPost();
        $this->visit(['p' => '/', 'n' => '1']);
        $this->visit(['p' => $this->path($post), 'n' => '1']);
        $this->visit(['p' => '/', 'n' => '0'], [], '198.51.100.7');
        Setting::write('visits', ['enabled' => true, 'returning' => false]);
        $this->visit(['p' => '/', 'n' => '1'], [], '198.51.100.8');

        $today = app(VisitTracker::class)->day();
        $this->assertSame(['views' => 4, 'visitors' => 3, 'returning' => 1], $this->statistics()->totals($today, $today));
    }

    public function test_visits_from_one_address_are_limited_per_minute(): void
    {
        for ($attempt = 0; $attempt < 62; $attempt++) {
            $this->visit(['p' => '/']);
        }

        $this->assertSame(60, DB::table('visits')->count());
    }

    public function test_closed_days_are_summarised_and_their_raw_visits_removed(): void
    {
        $post = $this->createPost('Guía de Docker');
        $path = $this->path($post);
        $today = app(VisitTracker::class)->day();
        $yesterday = now()->setTimezone('America/Guayaquil')->subDay()->toDateString();
        $row = fn (string $day, string $visitor, bool $returning = false): array => ['day' => $day, 'path' => $path, 'visitor' => $visitor, 'is_returning' => $returning, 'country' => 'EC', 'source' => '', 'device' => 'mobile', 'browser' => 'Chrome', 'os' => 'Android', 'created_at' => now()];
        DB::table('visits')->insert([$row($yesterday, 'aaaaaaaaaaaaaaaa', true), $row($yesterday, 'aaaaaaaaaaaaaaaa', true), $row($yesterday, 'bbbbbbbbbbbbbbbb'), $row($today, 'cccccccccccccccc')]);

        $this->statistics()->compact();

        $this->assertSame([$today], DB::table('visits')->pluck('day')->map(fn ($day): string => substr((string) $day, 0, 10))->all());
        $total = DB::table('visit_stats')->where('dimension', 'total')->first();
        $this->assertSame([3, 2, 1], [(int) $total->views, (int) $total->visitors, (int) $total->returning_visitors]);
        $this->assertSame(['views' => 4, 'visitors' => 3, 'returning' => 1], $this->statistics()->totals($yesterday, $today));
        $this->assertSame([['value' => $path, 'label' => 'Guía de Docker', 'views' => 4, 'visitors' => 3]], $this->statistics()->top('path', $yesterday, $today));
        $this->assertSame('Ecuador', $this->statistics()->top('country', $yesterday, $today)[0]['label']);
    }

    public function test_the_blog_loads_the_counter_only_for_visitors_and_while_it_is_enabled(): void
    {
        $this->createPost();
        $this->get('/')->assertOk()->assertSee('visitas.js', false)->assertSee('data-returning="1"', false)->assertDontSee('data-ignore', false);
        $this->get('/no-existe-esta-pagina')->assertNotFound()->assertDontSee('visitas.js', false);
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('data-ignore', false);
        Setting::write('visits', ['enabled' => false, 'returning' => true]);
        $this->get('/')->assertOk()->assertDontSee('visitas.js', false);
    }

    public function test_the_country_list_is_downloaded_converted_and_used(): void
    {
        $csv = "0.0.0.0,0.255.255.255,ZZ\n1.0.0.0,1.0.0.255,AU\n1.0.1.0,1.0.3.255,CN\n1.0.4.0,1.0.7.255,CN\n8.8.8.0,8.8.8.255,US\n2001:db8::,2001:db8::ffff,DE\n";
        $previousMonth = now()->subMonthNoOverflow()->format('Y-m');
        Http::fake([
            'download.db-ip.com/free/dbip-country-lite-'.now()->format('Y-m').'.csv.gz' => Http::response('', 404),
            'download.db-ip.com/free/dbip-country-lite-'.$previousMonth.'.csv.gz' => Http::response(gzencode($csv)),
        ]);

        $this->actingAs($this->admin);
        Livewire::test(Statistics::class)->callAction('countries')->assertNotified('Lista de países instalada');

        $countries = new CountryDatabase;
        $this->assertSame($previousMonth, $countries->month());
        $this->assertSame(['AU', 'CN', 'CN', 'US', 'DE'], array_map($countries->country(...), ['1.0.0.9', '1.0.2.1', '1.0.7.255', '8.8.8.8', '2001:db8::1']));
        $this->assertSame([null, null, null, null, null], array_map($countries->country(...), ['0.1.2.3', '1.0.8.0', '9.9.9.9', '2001:db9::1', 'no-es-una-ip']));

        $this->visit(['p' => '/'], [], '8.8.8.8');
        $this->assertSame('US', DB::table('visits')->value('country'));
    }

    public function test_the_statistics_page_shows_the_figures_to_administrators_only(): void
    {
        $post = $this->createPost('Guía de Docker');
        $this->visit(['p' => $this->path($post), 'r' => 'https://duckduckgo.com/'], ['CF-IPCountry' => 'MX']);
        $this->visit(['p' => '/'], [], '198.51.100.7');

        $this->actingAs($this->admin)->get(Statistics::getUrl())->assertOk()->assertSee('Estadísticas de visitas');
        $this->get(Filament::getPanel('admin')->getUrl())->assertOk();
        Livewire::test(VisitTotals::class, ['pageFilters' => ['range' => '7']])->assertSee('Visitas únicas')->assertSee('Páginas vistas');
        Livewire::test(TopPages::class, ['pageFilters' => ['range' => '7']])->assertSee('Guía de Docker')->assertSee($this->path($post));
        Livewire::test(TopSources::class, ['pageFilters' => ['range' => '30']])->assertSee('DuckDuckGo')->assertSee('Directo o desconocido');
        Livewire::test(TopCountries::class, ['pageFilters' => ['range' => '30']])->assertSee('México')->assertSee('Activar países');

        Livewire::test(Statistics::class)->callAction('settings', data: ['enabled' => false, 'returning' => false])->assertNotified('Ajustes guardados');
        $this->assertSame(['enabled' => false, 'returning' => false], app(VisitTracker::class)->settings());

        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $this->actingAs($editor)->get(Statistics::getUrl())->assertForbidden();
    }
}
