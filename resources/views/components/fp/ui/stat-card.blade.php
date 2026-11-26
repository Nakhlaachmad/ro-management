@props(['label', 'value', 'description', 'href', 'tone' => 'green', 'icon' => 'heroicon-o-users'])
<a {{ $attributes->class(['victory-card', 'victory-stat', 'victory-'.$tone]) }} href="{{ $href }}" aria-label="{{ $label }}: {{ $value }}. Lihat direktori">
    <div class="victory-stat-head"><span class="victory-stat-label">{{ $label }}</span><span class="victory-stat-icon" aria-hidden="true">@svg($icon)</span></div>
    <strong>{{ number_format($value) }}</strong>
    <small>{{ $description }}</small>
    <span class="victory-card-link">Lihat direktori <span aria-hidden="true">↗</span></span>
</a>
