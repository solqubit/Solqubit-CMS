@if($featured)
    <section id="home-feature" aria-label="Entrada destacada" data-home-section="{{ $index }}">
        @include('themes.solqubit.card', ['post' => $featured, 'featuredCard' => true])
    </section>
@endif
