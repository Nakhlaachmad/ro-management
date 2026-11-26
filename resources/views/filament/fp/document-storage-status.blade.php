<x-filament-panels::page>
    <x-filament::section heading="Nextcloud">
        <p role="status" aria-live="polite">{{ match ($connectionStatus) {
            'CONNECTED' => 'Terhubung',
            'DISABLED' => 'Integrasi belum diaktifkan',
            'AUTHENTICATION_FAILED' => 'Autentikasi gagal',
            'CONFIGURATION_REQUIRED' => 'Konfigurasi diperlukan',
            'STORAGE_UNAVAILABLE' => 'Penyimpanan tidak tersedia',
            'RATE_LIMITED' => 'Tunggu satu menit sebelum memeriksa kembali',
            default => 'Koneksi belum diperiksa',
        } }}</p>
        <x-filament::button wire:click="checkConnection" wire:loading.attr="disabled" class="mt-4">
            Periksa koneksi
        </x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
