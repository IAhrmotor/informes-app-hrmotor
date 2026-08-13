@props(['name'])

<svg class="app-nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    @switch($name)
        @case('summary')
            <path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z" />
            @break
        @case('leads')
            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0H5Zm14-9v2h-2v2h2v2h2v-2h2v-2h-2v-2h-2Z" />
            @break
        @case('sales')
            <path d="M3 5h2l2.2 9.2A2 2 0 0 0 9.1 16H18a2 2 0 0 0 1.9-1.4L22 8H7m3 12a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm9 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" />
            @break
        @case('calls')
            <path d="M7.1 3h3l1.3 4-2 1.6a15 15 0 0 0 6 6l1.6-2 4 1.3v3c0 2.2-1.8 4.1-4.1 4.1C9.2 21 3 14.8 3 7.1 3 4.8 4.8 3 7.1 3Z" />
            @break
        @case('campaigns')
            <path d="m3 11 14-6v14L3 13v-2Zm4 4 2 5h4l-2-4.1" />
            @break
        @case('analytics')
            <path d="M4 19V9m6 10V5m6 14v-7m4 7H2" />
            @break
        @case('stock')
            <path d="m3 8 9-5 9 5-9 5-9-5Zm0 5 9 5 9-5M3 17l9 5 9-5" />
            @break
        @case('commissions')
            <path d="M12 2v20m5-16H9a3 3 0 0 0 0 6h6a3 3 0 0 1 0 6H6" />
            @break
        @case('alerts')
            <path d="M12 3 2 21h20L12 3Zm0 6v5m0 3v1" />
            @break
        @case('users')
            <path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0H2Zm14-9a3 3 0 1 0 0-6m2 15h4a6 6 0 0 0-6-6" />
            @break
        @case('permissions')
            <path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Zm-3 9 2 2 4-5" />
            @break
        @case('settings')
            <path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0-5v2m0 14v2m9-9h-2M5 12H3m15.4-6.4L17 7M7 17l-1.4 1.4m12.8 0L17 17M7 7 5.6 5.6" />
            @break
        @case('penalties')
            <path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Zm4 6h6m-6 4h4" />
            @break
        @default
            <path d="M4 4h16v16H4z" />
    @endswitch
</svg>
