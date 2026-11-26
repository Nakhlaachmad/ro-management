        <dialog id="viewer" class="victory-review-viewer" wire:ignore aria-label="Pratinjau dokumen privat">
            <div id="toolbar">
                <button type="button" id="prev">Sebelumnya</button><button type="button" id="next">Berikutnya</button>
                <button type="button" id="zoom-in">Zoom +</button><button type="button" id="zoom-out">Zoom −</button>
                <button type="button" id="rotate-left">Putar kiri</button><button type="button" id="rotate-right">Putar kanan</button>
                <button type="button" id="fit">Sesuaikan</button><button type="button" id="reset">Reset</button>
                <button type="button" id="fullscreen">Layar penuh</button><button type="button" id="close">Tutup</button>
            </div>
            <p id="status" aria-live="polite"></p>
            <div id="canvas"><img id="image" alt="Dokumen untuk pemeriksaan"></div>
        </dialog>
        @script
        <script>{!! file_get_contents(resource_path('js/fp/viewer.js')) !!}</script>
        @endscript
