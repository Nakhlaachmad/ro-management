<x-filament-panels::page class="fi-resource-view-record-page victory-submission-review victory-membership-view">
    @php($canViewDocuments = \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.documents.view'))
    <div class="victory-review-content">
        <x-filament::badge style="align-self:flex-start" :color="match($this->getRecord()->lifecycle_state?->value) { 'ACTIVE' => 'success', 'EXITED' => 'gray', default => 'warning' }">
            {{ $this->getRecord()->lifecycle_state?->value ?? 'Belum terklasifikasi' }}
        </x-filament::badge>
        @unless(\App\Application\FP\OnboardingRevision::enabled())
        <section class="victory-review-profile victory-card" aria-labelledby="profile-heading">
            <h2 id="profile-heading">Foto Profil Financial Planner</h2>
            @include('filament.fp.submission-document', ['type' => 'PROFILE_PHOTO', 'label' => 'Foto Profil', 'unavailableMessage' => 'Foto profil belum tersedia'])
        </section>
        @endunless
        <div class="victory-review-information">{{ $this->infolist }}</div>
        <section class="victory-card victory-review-supporting" aria-labelledby="supporting-heading">
            <h2 id="supporting-heading">Dokumen Pendukung</h2>
            <div class="victory-review-document-grid">
                @include('filament.fp.submission-document', ['type' => 'KTP', 'label' => 'KTP', 'unavailableMessage' => 'Belum tersedia'])
                @include('filament.fp.submission-document', ['type' => 'IJAZAH', 'label' => 'Ijazah', 'unavailableMessage' => 'Belum tersedia'])
            </div>
        </section>
    </div>
    @if ($canViewDocuments)
        @include('filament.fp.private-document-dialog')
    @endif
</x-filament-panels::page>
