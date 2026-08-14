@props([
    'title',
    'detail' => null,
    'meta' => null,
])

<div {{ $attributes->class('report-ui-source-status') }}>
    <div class="report-ui-source-status__copy">
        <div class="report-ui-source-status__title">{{ $title }}</div>
        @if (filled($detail))
            <div class="report-ui-source-status__detail">{{ $detail }}</div>
        @endif
        @if (filled($meta))
            <div class="report-ui-source-status__meta">{{ $meta }}</div>
        @endif
    </div>

    @isset($indicator)
        <div class="report-ui-source-status__indicator">{{ $indicator }}</div>
    @endisset
</div>
