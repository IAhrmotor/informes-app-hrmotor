@props([
    'eyebrow' => null,
    'title',
    'description' => null,
])

<header {{ $attributes->class('report-ui-page-header') }}>
    <div class="report-ui-page-header__copy">
        @if (filled($eyebrow))
            <div class="report-ui-page-header__eyebrow">{{ $eyebrow }}</div>
        @endif
        <h1 class="report-ui-page-header__title">{{ $title }}</h1>
        @if (filled($description))
            <p class="report-ui-page-header__description">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="report-ui-page-header__actions">{{ $actions }}</div>
    @endisset
</header>
