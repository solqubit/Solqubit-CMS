@php($showHeading = $section['show_heading'])
@php($showIntro = $section['show_intro'] && filled($section['intro']))
@if($showHeading || $showIntro)
    <section class="journal-intro {{ !$showHeading ? 'intro-note-only' : (!$showIntro ? 'intro-heading-only' : '') }}" @if($showHeading) aria-labelledby="home-heading" @else aria-label="Bienvenida" @endif data-home-section="{{ $index }}">
        @if($showHeading)
            <div><h1 id="home-heading">{{ $section['heading'] ?: $page->title }}@if($section['accent_heading'])<br><em>{{ $section['accent_heading'] }}</em>@endif</h1></div>
        @endif
        @if($showIntro)
            <div class="journal-intro-note">
                @if($section['icon'] !== 'none')
                    <span class="intro-spark" aria-hidden="true"><x-blog-icon :name="$section['icon']"/></span>
                @endif
                <p class="page-intro-text">{{ $section['intro'] }}</p>
            </div>
        @endif
    </section>
@endif
