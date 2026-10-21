@props(['network'])
<svg class="icon social-icon" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false">
@switch($network)
@case('youtube')<path fill-rule="evenodd" d="M5 5h14a4 4 0 0 1 4 4v6a4 4 0 0 1-4 4H5a4 4 0 0 1-4-4V9a4 4 0 0 1 4-4Zm5 3.5v7l6-3.5-6-3.5Z"/>@break
@case('instagram')<g fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5.5"/><circle cx="12" cy="12" r="4.2"/></g><circle cx="17.5" cy="6.5" r="1.2"/>@break
@case('github')<path d="M12 2a10 10 0 0 0-3.16 19.49c.5.09.68-.22.68-.48v-1.86c-2.78.6-3.37-1.18-3.37-1.18-.45-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.61.07-.61 1 .07 1.53 1.03 1.53 1.03.89 1.53 2.34 1.09 2.91.83.09-.65.35-1.09.64-1.34-2.22-.25-4.55-1.11-4.55-4.94 0-1.09.39-1.98 1.03-2.68-.1-.25-.45-1.27.1-2.65 0 0 .84-.27 2.75 1.03a9.59 9.59 0 0 1 5 0c1.91-1.3 2.75-1.03 2.75-1.03.55 1.38.2 2.4.1 2.65.64.7 1.03 1.59 1.03 2.68 0 3.84-2.34 4.69-4.57 4.94.36.31.68.92.68 1.85v2.75c0 .27.18.58.69.48A10 10 0 0 0 12 2Z"/>@break
@case('linkedin')<circle cx="5.3" cy="5" r="2"/><path d="M3.5 8.5H7V21H3.5zM9.5 8.5H13v1.7c.6-1.1 1.9-2 3.7-2 3 0 4.3 1.9 4.3 5.3V21h-3.5v-7c0-1.8-.5-2.8-2-2.8-1.6 0-2.5 1.1-2.5 3V21H9.5Z"/>@break
@case('facebook')<path d="M14 22v-9h3l.5-3.5H14V7.2c0-1 .3-1.7 1.8-1.7H18V2.3c-.4-.1-1.7-.3-3-.3-3 0-5 1.8-5 5.2v2.3H7V13h3v9Z"/>@break
@case('x')<path d="M18.9 2h3l-6.6 7.5L23 22h-6l-4.7-7.6L5.6 22h-3l8.3-9.5L1.5 2h6.1l4.2 6.8L18.9 2ZM6.4 3.9H4.8l13.3 16.2h1.6L6.4 3.9Z" fill-rule="evenodd"/>@break
@case('tiktok')<path class="social-note" d="M14 3v12a5 5 0 1 1-4-4.9v3a2 2 0 1 0 1 1.9V3h3c0 3 2 5 5 5v3a8 8 0 0 1-5-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>@break
@endswitch
</svg>
