{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">@for($part = 1; $part <= $parts; $part++)<sitemap><loc>{{ route('sitemap.page', ['page' => $part]) }}</loc></sitemap>@endfor</sitemapindex>
