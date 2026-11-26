<details wire:ignore.self wire:poll.15s aria-label="Notifikasi pengajuan" class="victory-notification" x-data="{expanded:false}" x-on:toggle="expanded=$el.open" x-on:click.outside="$el.open=false" x-on:keydown.escape.prevent="$el.open=false; $el.querySelector('summary').focus()">
    <summary aria-label="Notifikasi, {{ count($unread) }} belum dibaca" :aria-expanded="expanded" aria-controls="victory-notification-messages" title="Notifikasi pengajuan">
        @svg('heroicon-o-bell', 'victory-bell')
        @if(count($unread))<span class="victory-notification-count">{{ count($unread) }}</span>@endif
    </summary>
    <div id="victory-notification-messages" class="victory-notification-panel" aria-live="polite">
        <strong>Notifikasi pengajuan</strong><small>{{ $pending }} menunggu validasi · status baca pada sesi ini</small>
        @forelse($rows as $row)
            <div wire:key="submission-notification-{{ $row->id }}" class="victory-notification-item {{ isset($unread[$row->id]) ? 'is-unread' : 'is-read' }}">
                <a wire:click.prevent="openSubmission({{ $row->id }})" href="{{ \App\Filament\Resources\FpSubmissionResource::getUrl('view', ['record'=>$row->id], panel:'fp-dev') }}"><strong>@if(isset($unread[$row->id]))<span class="victory-unread-dot" aria-hidden="true"></span>@endif Pengajuan #{{ $row->id }}</strong><span>Status {{ $row->status }}</span><time>{{ \Carbon\CarbonImmutable::parse($row->updated_at)->setTimezone(\App\Application\FP\ReportPeriods::timezone())->translatedFormat('d M Y H:i') }}</time><small>{{ isset($unread[$row->id]) ? 'Belum dibaca' : 'Sudah dibaca' }}</small></a>
                @if(isset($unread[$row->id]))<button type="button" wire:click="markRead({{ $row->id }})" wire:loading.attr="disabled" wire:target="markRead({{ $row->id }})" aria-label="Tandai pengajuan {{ $row->id }} sudah dibaca">Tandai sudah dibaca</button>@endif
            </div>
        @empty <p>Belum ada pengajuan.</p>
        @endforelse
    </div>
</details>
