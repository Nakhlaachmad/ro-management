@php
    $document = $this->getInlineDocument($type);
    $canUpload = $this->canManageInlineDocuments();
    $field = 'documentFiles.'.$type;
@endphp
<article class="victory-review-document" data-document-type="{{ $type }}" x-data="{ previewFailed: false }" x-init="$nextTick(() => { const image = $el.querySelector('img'); if (image?.complete && image.naturalWidth === 0) previewFailed = true; })">
    <h3>{{ $label }}</h3>
    @if ($document)
        <button type="button" class="open-document victory-review-document-preview"
            data-url="{{ route('fp.document', ['uuid' => $document->uuid]) }}" data-label="{{ $label }}"
            aria-label="Lihat {{ $label }} dalam viewer privat">
            <img src="{{ route('fp.document', ['uuid' => $document->uuid]) }}" alt="Pratinjau {{ $label }}"
                onerror="this.hidden = true" loading="{{ $type === 'PROFILE_PHOTO' ? 'eager' : 'lazy' }}" x-show="!previewFailed" x-on:error="previewFailed = true; $el.hidden = true">
            <span class="victory-review-preview-fallback" x-show="previewFailed" x-cloak>Preview tidak tersedia. Buka viewer untuk mencoba kembali.</span>
            <span class="victory-review-document-caption">Lihat {{ $label }}</span>
        </button>
    @else
        <div class="victory-review-document-placeholder" role="status">
            {{ $canViewDocuments ? (\App\Application\FP\OnboardingRevision::enabled() ? 'Dokumen belum diunggah.' : ($unavailableMessage ?? 'Dokumen belum tersedia untuk preview atau belum READY.')) : 'Preview dokumen tidak tersedia untuk akses Anda.' }}
        </div>
    @endif

    @if ($canUpload)
        <div class="victory-review-document-upload" wire:key="inline-upload-{{ $type }}">
            <label for="inline-upload-{{ strtolower($type) }}">{{ $document ? 'Ganti' : 'Unggah' }} {{ $label }}</label>
            <input id="inline-upload-{{ strtolower($type) }}" type="file" accept="image/jpeg,image/png"
                wire:model="{{ $field }}" aria-describedby="inline-error-{{ strtolower($type) }}">
            @error($field)
                <p id="inline-error-{{ strtolower($type) }}" class="victory-review-document-upload-error" role="alert">{{ $message }}</p>
            @enderror
            <button type="button" wire:click="uploadInlineDocument('{{ $type }}')" wire:loading.attr="disabled" wire:target="uploadInlineDocument">
                Simpan {{ $label }}
                <span wire:loading wire:target="uploadInlineDocument">…</span>
            </button>
            <p class="victory-review-document-upload-help">JPEG/PNG maksimal 2 MB. Berkas diperiksa ulang di server dan riwayat lama tetap tersimpan.</p>
        </div>
    @endif
</article>
