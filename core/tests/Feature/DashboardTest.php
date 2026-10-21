<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Widgets\ContentStats;
use App\Filament\Widgets\SiteGuide;
use App\Filament\Widgets\Visits\VisitTotals;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-dashboard');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'timezone' => 'UTC']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_dashboard_guide_cards(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(SiteGuide::class)->assertSee('Escribir una entrada')->assertSee('Mis entradas')->assertSee('Portada del blog')
            ->assertDontSee('Identidad del sitio')->assertDontSee('Páginas del sitio')->assertDontSee('Menú de navegación')->assertSee('Ver mi sitio');
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        Livewire::test(SiteGuide::class)->assertSee('Escribir una entrada')->assertSee('Mis entradas')->assertDontSee('Portada del blog');
    }

    public function test_content_stats_scoped_to_editor(): void
    {
        $other = User::factory()->create(['role' => 'editor']);
        Post::create(['user_id' => $other->id, 'title' => 'Ajeno', 'slug' => 'ajeno', 'content' => [], 'status' => 'draft']);
        $editor = User::factory()->create(['role' => 'editor']);
        Post::create(['user_id' => $editor->id, 'title' => 'Mío', 'slug' => 'mio', 'content' => [], 'status' => 'draft']);
        Post::create(['user_id' => $editor->id, 'title' => 'Viejo', 'slug' => 'viejo', 'content' => [], 'status' => 'archived']);
        $this->actingAs($editor);
        $stats = collect((fn (): array => $this->getStats())->call(Livewire::test(ContentStats::class)->instance()))->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => (string) $stat->getValue()]);
        $this->assertSame('1', $stats['Borradores']);
        $this->assertSame('1', $stats['Archivadas']);
    }

    public function test_content_stats_link_to_filtered_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $stats = collect((fn (): array => $this->getStats())->call(Livewire::test(ContentStats::class)->instance()))->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => $stat->getUrl()]);
        $this->assertStringContainsString('tableFilters[status][value]=draft', urldecode($stats['Borradores']));
        $this->assertStringContainsString('tableFilters[scheduled][isActive]=1', urldecode($stats['Programadas']));
    }

    public function test_editor_dashboard_links_open_only_own_posts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $stats = collect((fn (): array => $this->getStats())->call(Livewire::test(ContentStats::class)->instance()))->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => urldecode((string) $stat->getUrl())]);
        foreach (['Entradas publicadas', 'Borradores', 'Programadas', 'Archivadas'] as $label) {
            $this->assertStringContainsString('tableFilters[mine][isActive]=1', $stats[$label], $label);
        }
        $this->assertStringContainsString('tableFilters[published][isActive]=1', $stats['Entradas publicadas']);
        $this->assertStringNotContainsString('tableFilters[status]', $stats['Entradas publicadas']);
        Livewire::test(SiteGuide::class)->assertSee('tableFilters%5Bmine%5D%5BisActive%5D=1', false);
    }

    public function test_admin_dashboard_links_do_not_filter_by_author(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $stats = collect((fn (): array => $this->getStats())->call(Livewire::test(ContentStats::class)->instance()))->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => urldecode((string) $stat->getUrl())]);
        $this->assertStringNotContainsString('tableFilters[mine]', $stats['Borradores']);
        $this->assertStringContainsString('tableFilters[published][isActive]=1', $stats['Entradas publicadas']);
    }

    public function test_mine_and_published_filters_match_dashboard_counts(): void
    {
        $other = User::factory()->create(['role' => 'editor']);
        $editor = User::factory()->create(['role' => 'editor']);
        $foreign = Post::create(['user_id' => $other->id, 'title' => 'Ajena', 'slug' => 'ajena', 'content' => [], 'status' => 'published', 'published_at' => now()->subDay()]);
        $own = Post::create(['user_id' => $editor->id, 'title' => 'Propia', 'slug' => 'propia', 'content' => [], 'status' => 'published', 'published_at' => now()->subDay()]);
        $scheduled = Post::create(['user_id' => $editor->id, 'title' => 'Futura', 'slug' => 'futura', 'content' => [], 'status' => 'published', 'published_at' => now()->addDay()]);
        $this->actingAs($editor);
        Livewire::test(ListPosts::class)->filterTable('mine')->filterTable('published')
            ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign, $scheduled]);
    }

    public function test_unique_visitors_label_not_misleading(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(VisitTotals::class, ['pageFilters' => ['range' => '30']])->assertDontSee('Visitantes únicos')->assertSee('Visitas únicas')
            ->assertSee('Cada persona cuenta una vez por día');
    }
}
