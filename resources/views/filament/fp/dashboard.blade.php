<x-filament-panels::page>
@php
    $summary = $this->summary();
    $resource = \App\Filament\Resources\FpMembershipResource::class;
    $cards = [['all','Total FP','index','Semua membership canonical','blue','heroicon-o-users'],['active','FP Aktif','active','Membership yang masih aktif','green','heroicon-o-user-circle'],['normal','Keluar Normal','normal','Minimal 60 hari sejak pendaftaran','gold','heroicon-o-arrow-right-on-rectangle'],['abnormal','Keluar Tidak Normal','abnormal','Kurang dari 60 hari','red','heroicon-o-exclamation-circle']];
@endphp
<div class="victory-bento">
    <section class="victory-card victory-hero victory-clock-hero" aria-labelledby="victory-overview-title">
        <div>
            <span class="victory-eyebrow">VICTORY INTERNATIONAL FUTURES</span>
            <h2 id="victory-overview-title">Kelola FP dengan lebih terarah.</h2>
            <p>Ringkasan Financial Planner, pendaftar, dan Team. Informasi penting <br> untuk mengambil langkah berikutnya.</p>
            <a class="victory-button" style="margin-top: 4rem;" href="{{ \App\Filament\Resources\FpSubmissionResource::getUrl('index', panel: 'fp-dev') }}">Lihat pendaftar <span aria-hidden="true">→</span></a>
        </div>
        <div class="victory-hero-brand victory-clock-container">@include('filament.fp.clock')</div>
    </section>
    @foreach($cards as [$key,$label,$page,$description,$color,$icon])
        <x-fp.ui.stat-card :label="$label" :value="$summary[$key]" :description="$description" :href="$resource::getUrl($page, panel: 'fp-dev')" :tone="$color" :icon="$icon" />
    @endforeach
    <section class="victory-card victory-operation" aria-labelledby="victory-pending-title">
        <div class="victory-section-header"><span class="victory-eyebrow">ANTREAN PENDAFTAR</span><span class="victory-pill">PENDING</span></div>
        <h3 id="victory-pending-title">Validasi pendaftar</h3><strong class="victory-number">{{ number_format($summary['pending']) }}</strong>
        <p>Submission menunggu pemeriksaan biodata dan dokumen HRD.</p>
        <a class="victory-button" href="{{ \App\Filament\Resources\FpSubmissionResource::getUrl('index', panel: 'fp-dev') }}">Buka Validasi Pendaftar <span aria-hidden="true">→</span></a>
    </section>
    <section class="victory-card victory-operation" aria-labelledby="victory-team-title">
        <div class="victory-section-header"><span class="victory-eyebrow">TEAM</span><span class="victory-pill">Aktif</span></div>
        <h3 id="victory-team-title">Team aktif</h3><strong class="victory-number">{{ number_format($summary['teams']) }}</strong>
        <p>Team yang tersedia pada form pendaftaran publik.</p>
        @if(\App\Application\FP\FeatureAccess::allowed(auth()->user(),'f2.teams'))<a class="victory-button victory-button-light" href="{{ \App\Filament\Resources\FpTeamResource::getUrl('index', panel: 'fp-dev') }}">Kelola Team <span aria-hidden="true">→</span></a>@endif
    </section>
    <section class="victory-card victory-breakdown" aria-labelledby="victory-composition-title">
        <span class="victory-eyebrow">RINGKASAN CANONICAL</span><h3 id="victory-composition-title">Komposisi membership</h3>
        <div class="victory-bars">
        @foreach([['active','Aktif','green'],['normal','Keluar normal','gold'],['abnormal','Keluar tidak normal','red'],['unresolved','Belum terklasifikasi','gray']] as [$key,$label,$color])
            <div class="victory-bar-row"><span>{{ $label }}</span><strong>{{ number_format($summary[$key]) }}</strong><div class="victory-track" aria-hidden="true"><div class="victory-fill victory-fill-{{ $color }}" style="width:{{ $summary['all'] > 0 ? round($summary[$key] / $summary['all'] * 100, 1) : 0 }}%"></div></div></div>
        @endforeach
        </div>
        <p class="victory-note">Records belum terklasifikasi tetap berada di Semua FP. Tidak otomatis dianggap aktif atau diberi NIP.</p>
    </section>
    <section class="victory-card victory-shortcuts" aria-labelledby="victory-shortcuts-title">
        <span class="victory-eyebrow">AKSES CEPAT</span><h3 id="victory-shortcuts-title">Direktori & pendaftaran</h3>
        <a href="{{ $resource::getUrl('index', panel: 'fp-dev') }}">Semua Financial Planner <span aria-hidden="true">→</span></a>
        <a href="{{ $resource::getUrl('active', panel: 'fp-dev') }}">Financial Planner Aktif <span aria-hidden="true">→</span></a>
        <a href="{{ route('fp.form') }}" target="_blank" rel="noopener">Form pendaftaran publik <span aria-hidden="true">↗</span></a>
        @if(\App\Application\FP\FpReports::allowed(auth()->user()))<a href="{{ \App\Filament\Pages\FP\Reports::getUrl(panel: 'fp-dev') }}">Laporan & ekspor <span aria-hidden="true">→</span></a>@endif
    </section>
</div>
@include('filament.fp.dashboard-widgets')
</x-filament-panels::page>
