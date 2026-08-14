@props([
    'kicker' => null,
    'title',
    'titleId' => null,
    'description' => null,
])

<section {{ $attributes->class(['report-ui-card', 'report-ui-empty-state']) }}>
    @if (filled($kicker))
        <span class="report-ui-badge">{{ $kicker }}</span>
    @endif
    <h2 @if (filled($titleId)) id="{{ $titleId }}" @endif class="report-ui-empty-state__title">{{ $title }}</h2>
    @if (filled($description))
        <p class="report-ui-empty-state__description">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="report-ui-empty-state__action">{{ $action }}</div>
    @endisset
</section>
