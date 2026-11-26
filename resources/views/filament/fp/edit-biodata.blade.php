<x-filament-panels::page>
    <p>Perubahan biodata dicatat dalam riwayat. NIP, identitas, organisasi, perekrutan dan dokumen asli tidak diubah.</p>
    <form wire:submit="submit" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="submit">Simpan Perubahan Biodata</x-filament::button>
    </form>
</x-filament-panels::page>
