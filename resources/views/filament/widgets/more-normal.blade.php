<x-filament-widgets::widget>
    <x-filament::section>
        {{ $this->table }}

        <div class="px-4 py-3 border-t flex justify-center">
            <a href="{{ route('filament.admin.resources.fps.keluar-normal') }}"
               class="px-4 py-2 text-sm font-semibold rounded-lg border border-warning-600 text-warning-600 hover:bg-warning-600 hover:text-white transition">
                More
            </a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>