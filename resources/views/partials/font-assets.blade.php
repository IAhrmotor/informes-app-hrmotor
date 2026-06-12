@php
    $fontAssetHref = collect(glob(public_path('build/assets/fonts-*.css')))
        ->map(fn (string $path): string => asset('build/assets/' . basename($path)))
        ->first();
@endphp

@if ($fontAssetHref)
    <link rel="stylesheet" href="{{ $fontAssetHref }}">
@endif
