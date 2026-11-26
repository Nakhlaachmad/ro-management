<?php

declare(strict_types=1);

namespace App\Filament\FP;

use App\Application\FP\FeatureAccess;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

final class SubmissionUpdates extends \Livewire\Component
{
    public static function changes(array $previous, array $current): array
    {
        return array_filter($current, fn ($item, $id) => ! isset($previous[$id]) || $previous[$id] !== $item, ARRAY_FILTER_USE_BOTH);
    }

    private function rows(): \Illuminate\Support\Collection
    {
        abort_unless(FeatureAccess::allowed(auth()->user(), 'f2.view'), 403);

        return DB::table('fp_submissions')->orderByDesc('updated_at')->orderByDesc('id')->limit(30)->get(['id', 'status', 'updated_at', 'biodata_revision', 'document_revision', 'biodata_verified_revision', 'documents_verified_revision']);
    }

    private function states($rows): array
    {
        return $rows->mapWithKeys(fn ($row) => [$row->id => [$row->status, $row->biodata_revision, $row->document_revision, $row->biodata_verified_revision, $row->documents_verified_revision]])->all();
    }

    public function markRead(?int $id = null): void
    {
        $current = $this->states($this->rows());
        abort_unless($id !== null && isset($current[$id]), 404);
        $key = 'fp_submission_read.'.auth()->id();
        $read = session($key, []);
        $read[$id] = $current[$id];
        session()->put($key, $read);
    }

    public function openSubmission(int $id): void
    {
        $this->markRead($id);
        $this->redirect(\App\Filament\Resources\FpSubmissionResource::getUrl('view', ['record' => $id], panel: 'fp-dev'));
    }

    public function render(): \Illuminate\View\View
    {
        $rows = $this->rows();
        $current = $this->states($rows);
        $key = 'fp_submission_updates.'.auth()->id();
        $previous = session($key);
        if (is_array($previous)) {
            foreach (self::changes($previous, $current) as $id => $state) {
                $title = match ($state[0]) {
                    'APPROVED' => 'Pengajuan disetujui','REJECTED' => 'Pengajuan ditolak',default => isset($previous[$id]) ? 'Status pemeriksaan pengajuan berubah' : 'Pengajuan baru menunggu validasi'
                };
                Notification::make('fp-submission-'.$id)->title($title)->send();
            }
        }
        session()->put($key, $current);
        $unread = self::changes(session('fp_submission_read.'.auth()->id(), []), $current);

        return view('filament.fp.submission-updates', ['rows' => $rows, 'unread' => $unread, 'pending' => DB::table('fp_submissions')->where('status', 'PENDING')->count()]);
    }
}
