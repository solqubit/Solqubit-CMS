<?php

namespace Tests\Feature;

use App\Filament\Pages\NavigationSettings;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Series;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Services\SiteNavigation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminControlsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-admin-controls');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'timezone' => 'UTC']);
        Setting::write('theme', 'solqubit');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_admin_cannot_change_own_role_or_active(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->id])
            ->assertFormFieldDisabled('role')->assertFormFieldDisabled('is_active');
        $other = User::factory()->create(['role' => 'editor']);
        Livewire::test(EditUser::class, ['record' => $other->id])
            ->assertFormFieldEnabled('role')->assertFormFieldEnabled('is_active');
    }

    public function test_last_admin_demotion_shows_error_on_role_field(): void
    {
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $component = Livewire::test(EditUser::class, ['record' => $other->id]);
        // Another session deactivates the current administrator meanwhile, so the edited one is the last active administrator.
        User::whereKey($this->admin->id)->update(['is_active' => false]);
        $component->fillForm(['role' => 'editor'])->call('save')->assertHasFormErrors(['role']);
        $this->assertSame('admin', $other->fresh()->role);
    }

    public function test_admin_changing_own_password_keeps_session(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->id])->fillForm(['password' => 'NuevaClave12345'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($this->admin->fresh()->getAuthPassword(), session('password_hash_'.Filament::getAuthGuard()));
    }

    public function test_users_table_shows_posts_and_two_factor(): void
    {
        Post::create(['user_id' => $this->admin->id, 'title' => 'Una', 'slug' => 'una', 'content' => [], 'status' => 'draft']);
        Livewire::test(ListUsers::class)->assertTableColumnExists('posts_count')->assertTableColumnExists('two_factor')
            ->assertTableColumnStateSet('posts_count', 1, $this->admin);
    }

    public function test_footer_menu_starts_empty(): void
    {
        $navigation = app(SiteNavigation::class);
        $this->assertNotSame([], $navigation->entries());
        $this->assertSame([], $navigation->entries('footer'));
        $navigation->save([]);
        $this->assertSame([], $navigation->entries('footer'));
        $this->assertSame([], $navigation->menus()['footer']);
    }

    public function test_menu_label_autofills_from_page(): void
    {
        $page = Page::factory()->create(['title' => 'Contacto', 'slug' => 'contacto', 'published' => true]);
        Livewire::test(NavigationSettings::class)
            ->set('data.items', [['type' => 'page', 'label' => '', 'page_id' => null, 'enabled' => true, 'new_tab' => false]])
            ->set('data.items.0.page_id', $page->id)
            ->assertSet('data.items.0.label', 'Contacto');
    }

    public function test_category_delete_modal_mentions_post_count(): void
    {
        $category = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        Post::create(['user_id' => $this->admin->id, 'title' => 'Una', 'slug' => 'una', 'content' => [], 'status' => 'draft', 'category_ids' => [$category->id]]);
        Livewire::test(ListCategories::class)->mountAction(TestAction::make('delete')->table($category))
            ->assertMountedActionModalSee('1 entrada perderán esta categoría');
    }

    public function test_tag_slug_generated_automatically(): void
    {
        Tag::create(['name' => 'Linux', 'slug' => 'linux']);
        Livewire::test(CreateTag::class)->assertFormFieldDoesNotExist('slug')->fillForm(['name' => 'Linux'])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('tags', ['slug' => 'linux-2']);
    }

    public function test_editor_classification_counts_and_series_hide_other_drafts(): void
    {
        $category = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        $series = Series::create(['name' => 'Curso', 'slug' => 'curso']);
        $other = User::factory()->create(['role' => 'editor']);
        Post::create(['user_id' => $other->id, 'title' => 'Borrador secreto de Ana', 'slug' => 'secreto', 'content' => [], 'status' => 'draft', 'category_ids' => [$category->id], 'series_id' => $series->id]);
        Post::create(['user_id' => $other->id, 'title' => 'Publicada de Ana', 'slug' => 'publicada', 'content' => [], 'status' => 'published', 'published_at' => now()->subMinute(), 'category_ids' => [$category->id], 'series_id' => $series->id]);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);
        Livewire::test(ListCategories::class)->assertTableColumnStateSet('posts_count', 1, $category);
        Livewire::test(ListSeries::class)->assertTableColumnStateSet('posts_count', 1, $series);
        Livewire::test(EditSeries::class, ['record' => $series->id])->assertDontSee('Borrador secreto de Ana')->assertSee('Entrada de otro autor (sin publicar)');
        $this->actingAs($this->admin);
        Livewire::test(ListCategories::class)->assertTableColumnStateSet('posts_count', 2, $category);
    }
}
