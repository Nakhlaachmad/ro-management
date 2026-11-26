<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\FP\SubmissionUpdates;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class NotificationReadStateTest extends TestCase
{
    private function actor(string $role = 'hrd', bool $active = true): User
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        $actor = new User(['is_active' => $active]);
        $actor->id = 99;
        $actor->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([new \Spatie\Permission\Models\Role(['name' => $role, 'guard_name' => 'web'])]));
        auth()->setUser($actor);
        $panel = \Filament\Panel::make()->id('fp-dev')->path('fp-dev')->authGuard('web');
        \Filament\Facades\Filament::registerPanel($panel);
        \Filament\Facades\Filament::setCurrentPanel($panel);
        \Illuminate\Support\Facades\Route::get('/test/submissions/{record}', fn () => 'test')->name('filament.fp-dev.resources.fp-submissions.view');

        return $actor;
    }

    private function rows(): void
    {
        Schema::create('fp_submissions', function ($t) {
            $t->id();
            $t->string('status');
            $t->integer('biodata_revision')->default(1);
            $t->integer('document_revision')->default(1);
            $t->integer('biodata_verified_revision')->nullable();
            $t->integer('documents_verified_revision')->nullable();
            $t->timestamp('updated_at');
        });
        DB::table('fp_submissions')->insert([
            ['id' => 1, 'status' => 'PENDING', 'updated_at' => '2026-10-21 00:00:00'],
            ['id' => 2, 'status' => 'PENDING', 'updated_at' => '2026-10-21 00:00:01'],
        ]);
    }

    public function test_read_one_does_not_clear_other_and_polling_preserves_read_state(): void
    {
        $this->actor();
        $this->rows();
        $c = Livewire::test(SubmissionUpdates::class)->assertSee('Notifikasi, 2 belum dibaca')->assertSeeHtml('is-unread');
        self::assertStringNotContainsString('<summary wire:click', $c->html());
        $c->call('markRead', 1)->assertSee('Notifikasi, 1 belum dibaca')->assertSee('Sudah dibaca')->assertSeeHtml('is-read');
        self::assertSame([1], array_keys(session('fp_submission_read.99')));
        $c->call('$refresh')->assertSee('Notifikasi, 1 belum dibaca');
        $c->call('markRead', 2)->assertSee('Notifikasi, 0 belum dibaca')->assertDontSeeHtml('class="victory-notification-count"');
        $c->call('$refresh')->assertSee('Notifikasi, 0 belum dibaca');
        DB::table('fp_submissions')->where('id', 1)->update(['biodata_verified_revision' => 1]);
        $c->call('$refresh')->assertSee('Notifikasi, 1 belum dibaca');
        self::assertCount(2, session('fp_submission_read.99'));
        self::assertSame('PENDING', DB::table('fp_submissions')->where('id', 1)->value('status'));
    }

    public function test_opening_one_submission_marks_only_that_item(): void
    {
        $this->actor('bm');
        $this->rows();
        Livewire::test(SubmissionUpdates::class)->call('openSubmission', 2)->assertRedirect(\App\Filament\Resources\FpSubmissionResource::getUrl('view', ['record' => 2], panel: 'fp-dev'));
        self::assertSame([2], array_keys(session('fp_submission_read.99')));
    }

    public function test_unknown_item_cannot_clear_state(): void
    {
        $this->actor();
        $this->rows();
        Livewire::test(SubmissionUpdates::class)->call('markRead', 999)->assertNotFound();
        self::assertNull(session('fp_submission_read.99'));
    }

    public function test_guest_inactive_and_unknown_role_cannot_read_notifications(): void
    {
        Livewire::test(SubmissionUpdates::class)->assertForbidden();
        $this->actor('hrd', false);
        Livewire::test(SubmissionUpdates::class)->assertForbidden();
        $this->actor('unrecognized');
        Livewire::test(SubmissionUpdates::class)->assertForbidden();
    }

    public function test_read_state_is_separate_for_each_authenticated_actor(): void
    {
        $actor = $this->actor();
        $this->rows();
        Livewire::test(SubmissionUpdates::class)->call('markRead', 1);
        $actor->id = 100;
        auth()->setUser($actor);
        Livewire::test(SubmissionUpdates::class)->assertSee('Notifikasi, 2 belum dibaca');
        self::assertNull(session('fp_submission_read.100'));
    }
}
