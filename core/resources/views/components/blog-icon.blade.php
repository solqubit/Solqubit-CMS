@props(['name' => 'file'])
<svg class="icon" data-icon="{{ $name }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! config('icons.'.$name, config('icons.file', '')) !!}</svg>
