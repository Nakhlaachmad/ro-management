@php($analytics=$this->analytics())
<div class="victory-analytics-grid" wire:poll.60s>
    <section class="victory-card"><span class="victory-eyebrow">TEAM · {{ $analytics['period'] }}</span><h3>Ringkasan Team</h3><p>{{ $analytics['team_count'] }} Team aktif · refresh 60 detik</p>
        <div class="victory-report-table" role="region" aria-label="Ringkasan Team, gulir untuk seluruh Team" tabindex="0"><table><thead><tr><th>Team</th><th>FP aktif</th><th>Masuk bulan ini</th><th>Keluar bulan ini</th></tr></thead><tbody>@forelse($analytics['teams'] as $team)<tr><td>{{ $team->display_name }}</td><td>{{ $team->active_count }}</td><td>{{ $team->joined_count }}</td><td>{{ $team->exited_count }}</td></tr>@empty<tr><td colspan="4">Belum ada Team aktif.</td></tr>@endforelse</tbody></table></div>
        @if($analytics['team_count']>50)<p class="victory-note">50 Team pertama; buka menu Team untuk seluruh daftar.</p>@endif
    </section>
    <section class="victory-card"><span class="victory-eyebrow">SUMBER REKRUTMEN · {{ $analytics['period'] }}</span><h3>FP masuk bulan ini</h3><strong class="victory-number">{{ $analytics['joined'] }}</strong><div class="victory-source-breakdown">@forelse($analytics['sources'] as $source=>$total)<div><span>{{ $source }}</span><strong>{{ $total }}</strong></div>@empty<p>Belum ada FP masuk pada bulan ini.</p>@endforelse</div><p class="victory-note">Tanggal masuk = reference_date, {{ $analytics['timezone'] }}. Periode berganti otomatis setiap bulan. Data historis tidak direset.</p></section>
</div>
@livewire(\App\Filament\Widgets\FpFlowChart::class)
