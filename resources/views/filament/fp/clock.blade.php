<div class="victory-analog" x-data="victoryClock(@js(\App\Application\FP\ReportPeriods::timezone()))" aria-label="Jam {{ \App\Application\FP\ReportPeriods::timezone() }}">
    <svg viewBox="0 0 200 200" role="img" aria-label="Jam analog waktu operasional">
        <circle cx="100" cy="100" r="94" class="victory-clock-face"/>
        @for($tick=0;$tick<12;$tick++)<line x1="100" y1="17" x2="100" y2="{{ $tick%3===0 ? 30 : 24 }}" transform="rotate({{ $tick*30 }} 100 100)" class="victory-clock-tick"/>@endfor
        <text x="100" y="48" text-anchor="middle" class="victory-clock-mark">VICTORY</text>
        <line x1="100" y1="104" x2="100" y2="61" :transform="`rotate(${hour} 100 100)`" class="victory-clock-hour"/>
        <line x1="100" y1="108" x2="100" y2="37" :transform="`rotate(${minute} 100 100)`" class="victory-clock-minute"/>
        <line x1="100" y1="117" x2="100" y2="27" :transform="`rotate(${second} 100 100)`" class="victory-clock-second"/>
        <circle cx="100" cy="100" r="5" class="victory-clock-pin"/>
    </svg>
    <p x-text="date">{{ now(\App\Application\FP\ReportPeriods::timezone())->locale('id')->translatedFormat('l, d F Y') }}</p><small>{{ \App\Application\FP\ReportPeriods::timezone() }}</small>
</div>
