<nav class="toc" aria-label="Índice de la entrada">@foreach($toc as $entry)<a href="#{{ $entry['id'] }}" data-scroll="{{ $entry['id'] }}">{{ $entry['title'] }}</a>@endforeach</nav>
