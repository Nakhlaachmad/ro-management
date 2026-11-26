<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
\Tests\FpPostgres\PostgresHarness::connect();
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$actor = new \Tests\FpPostgres\TestActor;
$actor->forceFill(['id' => $input['actor'], 'is_active' => true]);
fwrite(STDOUT, "READY\n");
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(3);
}
try {
    $engine = new \App\Application\FP\FpEngine;
    if (($input['operation'] ?? 'approve') === 'reject') {
        $engine->rejectFpSubmission($actor, $input['submission'], 'Synthetic race rejection');
        fwrite(STDOUT, json_encode(['rejected' => true]));
    } else {
        $id = $engine->approveFpSubmission($actor, $input['submission'], ['source_type' => 'HRD', 'recruitment_path_id' => (int) \Illuminate\Support\Facades\DB::table('fp_recruitment_paths')->where('code', 'A')->value('id')]);
        fwrite(STDOUT, json_encode(['membership' => $id]));
    }
} catch (\Throwable) {
    fwrite(STDOUT, json_encode(['conflict' => true]));
    exit(2);
}
