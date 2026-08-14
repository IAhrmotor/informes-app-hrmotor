@props([
    'eyebrow' => null,
    'title',
    'description' => null,
])

<header {{ $attributes->class('report-ui-section-header') }}>
    <div class="report-ui-section-header__copy">
        @if (filled($eyebrow))
            <div class="report-ui-section-header__eyebrow">{{ $eyebrow }}</div>
        @endif
        <h2 class="report-ui-section-header__title">{{ $title }}</h2>
        @if (filled($description))
            <p class="report-ui-section-header__description">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="report-ui-section-header__actions">{{ $actions }}</div>
    @endisset
</header>
