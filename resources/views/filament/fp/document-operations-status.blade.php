<x-filament-panels::page>
    @php($operations = $this->operations())

    @if ($operations['status'] !== 'AVAILABLE')
        <x-filament::section>
            <p role="status">Data operasi dokumen belum tersedia.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b dark:border-gray-700">
                            <th scope="col" class="p-3">Referensi</th>
                            <th scope="col" class="p-3">Status</th>
                            <th scope="col" class="p-3">Integritas</th>
                            <th scope="col" class="p-3">Ukuran</th>
                            <th scope="col" class="p-3">Diagnostik</th>
                            <th scope="col" class="p-3">Rekonsiliasi terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($operations['rows'] as $operation)
                            <tr class="border-b align-top dark:border-gray-700">
                                <td class="p-3">
                                    <dl class="space-y-2">
                                        <div><dt>Operasi</dt><dd class="font-mono text-xs">{{ $operation['operation_uuid'] ?? '—' }}</dd></div>
                                        <div><dt>Dokumen</dt><dd class="font-mono text-xs">{{ $operation['document_uuid'] ?? '—' }}</dd></div>
                                        <div><dt>Revisi</dt><dd class="font-mono text-xs">{{ $operation['revision_uuid'] ?? '—' }}</dd></div>
                                    </dl>
                                </td>
                                <td class="p-3">
                                    <p>{{ $operation['state'] }}</p>
                                    @if ($operation['stale'])
                                        <p class="text-warning-600 dark:text-warning-400">Melewati batas waktu</p>
                                    @endif
                                    @if ($operation['quarantined'])
                                        <p class="text-danger-600 dark:text-danger-400">Karantina</p>
                                    @endif
                                </td>
                                <td class="p-3">{{ $operation['integrity_state'] }}</td>
                                <td class="p-3">
                                    <p>Diharapkan {{ number_format($operation['expected_size']) }} byte</p>
                                    <p>Terverifikasi {{ $operation['verified_size'] === null ? '—' : number_format($operation['verified_size']).' byte' }}</p>
                                </td>
                                <td class="p-3">{{ $operation['error_code'] ?? '—' }}</td>
                                <td class="p-3 whitespace-nowrap">{{ $operation['last_reconciled_at'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-3">Belum ada operasi dokumen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $operations['rows']->links() }}</div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
