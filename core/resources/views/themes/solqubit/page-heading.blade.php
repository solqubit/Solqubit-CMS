@if($page->option('show_heading', true) || ($page->option('show_intro', false) && $page->option('intro')))
<section class="inner-intro"><h1 @class(['sr-only' => !$page->option('show_heading', true)])>{{ $page->option('heading') ?: $page->title }}</h1>@if($page->option('show_intro', false) && $page->option('intro'))<p class="page-intro-text">{{ $page->option('intro') }}</p>@endif</section>
@else<h1 class="sr-only">{{ $page->title }}</h1>@endif
