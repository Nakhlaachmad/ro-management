<x-filament-panels::page>
@php($report = $this->report())
<section class="victory-card victory-report-filter-card" aria-labelledby="fp-report-filter-heading">
<span class="victory-eyebrow">VICTORY INTERNATIONAL FUTURES</span><h2 id="fp-report-filter-heading">Filter Laporan</h2>
<p>Data membership canonical; data legacy tidak digabung otomatis. NORMAL ≥60 hari, ABNORMAL &lt;60 hari.</p>
<form wire:submit="applyFilters" class="victory-report-filters">
<label>Status<select wire:model="filters.status">@foreach(\App\Application\FP\ReportFilters::STATUSES as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
<label>Team<select wire:model="filters.team_id"><option value="">Semua Team</option>@foreach($report['teams'] as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
<label>Sumber Rekrutmen<select wire:model="filters.{{ config('fp.recruitment_team_enabled') ? 'recruitment_team_id' : 'path_id' }}"><option value="">Semua / termasuk historis</option>@foreach($report['paths'] as $path)<option value="{{ $path->id }}">{{ $path->name }}{{ $path->is_active ? '' : ' (nonaktif)' }}</option>@endforeach</select></label>
<fieldset class="victory-report-periods"><legend>Rentang waktu</legend><div class="victory-period-grid">
@foreach(\App\Application\FP\ReportPeriods::LABELS as $value=>$label)
<label for="fp-period-{{ $value }}"><input id="fp-period-{{ $value }}" type="radio" wire:model.live="filters.period" value="{{ $value }}">{{ $label }}</label>
@endforeach
</div></fieldset>
@if(in_array($this->filters['period'], ['month','year'], true))
@if($this->filters['period'] === 'month')
<label>Bulan<select wire:model="filters.month"><option value="">Pilih bulan</option>@for($month=1;$month<=12;$month++)<option value="{{ $month }}">{{ \Carbon\CarbonImmutable::create(2000,$month,1)->locale('id')->monthName }}</option>@endfor</select></label>
@endif
<label>Tahun<input type="number" min="1900" max="9999" wire:model="filters.year" placeholder="{{ now(\App\Application\FP\ReportPeriods::timezone())->year }}"></label>
@endif
{{ $this->form }}
<div class="victory-filter-submit"><button class="victory-button victory-report-apply" type="submit" wire:loading.attr="disabled" wire:target="applyFilters">@svg('heroicon-o-funnel', 'victory-filter-icon')<span wire:loading.remove wire:target="applyFilters">Terapkan filter</span><span wire:loading wire:target="applyFilters" role="status">Menerapkan…</span></button></div>
</form>
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<p class="victory-note">Periode memakai tanggal referensi pendaftaran canonical (reference_date), bukan tanggal keluar. Tanggal keluar tetap tersedia di hasil dan ekspor. Tanggal inklusif, timezone {{ \App\Application\FP\ReportPeriods::timezone() }}. Untuk satu tanggal, isi Dari dan Sampai dengan tanggal yang sama. Histori Sumber Rekrutmen memakai snapshot approval.</p>
<p class="victory-note">Filter BM/cabang menunggu relasi canonical yang disepakati.</p>
</section>
@if($report['export'])
<section class="victory-card victory-report-columns-card" aria-labelledby="fp-report-columns-heading">
<h2 id="fp-report-columns-heading">Pilih Kolom Ekspor</h2>
<fieldset class="victory-export-columns"><legend class="sr-only">Kolom data Financial Planner</legend><div class="victory-column-grid">
@foreach(\App\Application\FP\ReportColumns::AVAILABLE as $key=>$label)
<label wire:key="fp-report-column-{{ $key }}" for="fp-report-column-{{ $key }}"><input id="fp-report-column-{{ $key }}" type="checkbox" wire:model.live="columns" value="{{ $key }}">{{ $label }}</label>
@endforeach
</div></fieldset>
<div class="victory-report-actions victory-column-actions"><button type="button" class="victory-button victory-button-light" wire:click="selectAllColumns">Pilih Semua</button><button type="button" class="victory-button victory-button-light" wire:click="resetColumns">Reset Pilihan</button></div><p class="victory-column-count" aria-live="polite">{{ count($this->columns) }} dari 16 kolom dipilih</p>
@if(!count($this->columns))<p role="alert">Pilih minimal satu kolom ekspor.</p>@endif
<p class="victory-note">Admin, HRD, RO, dan BM dapat memilih data lengkap. Pilih hanya data yang diperlukan dan simpan hasil ekspor dengan aman.</p>
</section>
@endif
<section class="victory-card victory-report-results-card"><h2>Hasil Laporan</h2><h3>Hasil filter: {{ $report['counts']['filtered'] }} membership</h3>
<div class="victory-report-table"><table><thead><tr>@foreach(\App\Application\FP\ReportExport::HEADERS as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($report['rows'] as $fp)<tr>@foreach(\App\Application\FP\FpReports::row($fp) as $value)<td>{{ $value === '' ? '—' : $value }}</td>@endforeach</tr>
@empty<tr><td colspan="{{ count(\App\Application\FP\ReportExport::HEADERS) }}">Tidak ada membership sesuai filter.</td></tr>@endforelse
</tbody></table></div>{{ $report['rows']->links() }}
@if($report['export'] && count($this->columns))<div class="victory-report-actions" aria-label="Ekspor hasil laporan">
@foreach(['csv'=>'Unduh CSV','xlsx'=>'Unduh Excel (.xlsx)'] as $format=>$label)
<a class="victory-button" href="{{ route('fp.report.export', $report['filters'] + ['format'=>$format, 'columns'=>$this->columns]) }}">{{ $label }}</a>
@endforeach
</div>@endif
<p class="victory-note">Maksimal 5.000 baris / 8 MiB. Ekspor mengikuti filter dan pilihan kolom; berkas dokumen privat tidak disertakan.</p>
</section>
</x-filament-panels::page>
