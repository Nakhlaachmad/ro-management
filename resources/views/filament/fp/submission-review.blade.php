<x-filament-panels::page class="fi-resource-view-record-page victory-submission-review">
    @php
        $approvalActions = $this->getApprovalBarActions();
        $canViewDocuments = \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.documents.view');
    @endphp

    <div class="victory-review-content" x-data="{ barHeight: 0, contentLeft: 0, observer: null, init() { this.$nextTick(() => { const bar = this.$refs.approvalBar; if (bar) { this.observer = new ResizeObserver(() => { this.barHeight = bar.getBoundingClientRect().height; this.contentLeft = this.$el.closest('.fi-main-ctn').getBoundingClientRect().left; }); this.observer.observe(bar); this.observer.observe(this.$el.closest('.fi-main-ctn')); } }); }, destroy() { this.observer?.disconnect(); } }"
        :style="{ paddingBottom: barHeight ? (barHeight + 24) + 'px' : '0px', '--victory-review-left': contentLeft + 'px' }">
        @unless(\App\Application\FP\OnboardingRevision::enabled())
        <section class="victory-review-profile victory-card" aria-labelledby="profile-heading">
            <h2 id="profile-heading">Foto Profil Pendaftar</h2>
            @include('filament.fp.submission-document', ['type' => 'PROFILE_PHOTO', 'label' => 'Foto Profil'])
        </section>
        @endunless

        <div class="victory-review-information">
            {{ $this->infolist }}
        </div>

        <section class="victory-card victory-review-supporting" aria-labelledby="supporting-heading">
            <h2 id="supporting-heading">Dokumen Pendukung</h2>
            <p class="victory-review-help">Periksa, unggah, atau ganti KTP dan ijazah langsung pada kartu ini. Semua dokumen tetap tersimpan privat dan riwayat lama tidak ditimpa.</p>
            <div class="victory-review-document-grid">
                @include('filament.fp.submission-document', ['type' => 'KTP', 'label' => 'KTP'])
                @include('filament.fp.submission-document', ['type' => 'IJAZAH', 'label' => 'Ijazah'])
            </div>
        </section>

        @if (count($approvalActions))
            <div x-ref="approvalBar" class="victory-review-approval-bar" role="region" aria-label="Keputusan pendaftaran">
                <div class="victory-review-approval-inner">
                    @foreach ($approvalActions as $action)
                        {{ $action }}
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if ($canViewDocuments)
        @include('filament.fp.private-document-dialog')
    @endif
</x-filament-panels::page>
