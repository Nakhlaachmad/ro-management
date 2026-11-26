<?php

declare(strict_types=1);

namespace App\Filament\FP;

trait GuardsVictoryActions
{
    private function guardVictoryAction(?string $name): void
    {
        abort_if(\App\Application\FP\OnboardingRevision::enabled() && in_array($name, ['verifyBiodata', 'verifyDocuments'], true), 403);
        if ($name === 'amendDocument') {
            abort_unless(\App\Application\FP\OnboardingRevision::enabled() && \App\Application\FP\VictoryAccess::canManageDocuments(auth()->user()), 403);

            return;
        }
        if (in_array($name, ['view', 'documents'], true)) {
            \App\Application\FP\VictoryAccess::require(auth()->user(), $name === 'documents' ? 'f2.documents.view' : 'f2.view');

            return;
        }
        $operation = match ($name) {
            'correct','verifyBiodata' => 'f2.biodata','verifyDocuments' => 'f2.documents',
            'approve' => 'f2.approve','reject' => 'f2.reject','exit' => 'f2.exit',
            'profile','leader','newTeam','dissolveTeam' => 'f2.teams','createPath','editPath' => 'f3.jalur.manage',
            'registerFp','editFp' => 'f2.biodata',
            'exportDirectory' => 'f3.reports.export',
            'createLocal' => 'f3.users.create','editLocal' => 'f3.users.edit','permissionsLocal' => 'f3.users.permissions',
            default => null
        };
        abort_unless($operation !== null, 403);
        \App\Application\FP\VictoryAccess::require(auth()->user(), $operation);
    }

    public function mountTableAction(string $name, ?string $record = null, array $arguments = []): mixed
    {
        $this->guardVictoryAction($name);

        return parent::mountTableAction($name, $record, $arguments);
    }

    public function callMountedTableAction(array $arguments = []): mixed
    {
        $names = $this->mountedTableActions;
        $this->guardVictoryAction(end($names) ?: null);

        return parent::callMountedTableAction($arguments);
    }

    public function mountAction(string $name, array $arguments = []): mixed
    {
        $this->guardVictoryAction($name);

        return parent::mountAction($name, $arguments);
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $names = $this->mountedActions;
        $this->guardVictoryAction(end($names) ?: null);

        return parent::callMountedAction($arguments);
    }
}
