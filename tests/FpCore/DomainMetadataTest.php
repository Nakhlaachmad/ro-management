<?php

declare(strict_types=1);

namespace Tests\FpCore;

use App\Domain\FP\NikNormalizer;
use App\Models\FpIdentityClaim;
use App\Models\FpMembership;
use App\Models\FpSubmission;
use App\Models\FpTeam;
use App\Models\NipSequence;
use App\Models\PersonIdentity;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainMetadataTest extends TestCase
{
    public function test_nik_normalization_and_keyed_lookup_preserve_identity(): void
    {
        $input = '0012345678901234';
        self::assertSame($input, NikNormalizer::normalize(" \t".$input."\r\n"));
        self::assertSame(NikNormalizer::lookup($input, 'synthetic-key-A'), NikNormalizer::lookup(' '.$input.' ', 'synthetic-key-A'));
        self::assertNotSame(NikNormalizer::lookup($input, 'synthetic-key-A'), NikNormalizer::lookup($input, 'synthetic-key-B'));
        self::assertNotSame(hash('sha256', $input), NikNormalizer::lookup($input, 'synthetic-key-A'));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', NikNormalizer::lookup($input, 'synthetic-key-A'));
    }

    public static function invalidNiks(): array
    {
        return array_map(fn ($v) => [$v], ['', '123456789012345', '12345678901234567', '1234 567890123456', '1234-567890123456', '１２３４５６７８９０１２３４５６', '١٢٣٤٥٦٧٨٩٠١٢٣٤٥٦', '123456789012345O', "\0".'1234567890123456']);
    }

    #[DataProvider('invalidNiks')]
    public function test_invalid_nik_is_rejected_without_echoing_pii(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NIK must contain exactly 16 ASCII digits.');
        NikNormalizer::normalize($input);
    }

    public function test_lookup_requires_configured_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NikNormalizer::lookup('0012345678901234', '');
    }

    public static function modelMetadata(): array
    {
        return [
            [PersonIdentity::class, 'person_identities', 'id'],
            [FpSubmission::class, 'fp_submissions', 'id'],
            [FpMembership::class, 'financial_planners', 'id'],
            [FpIdentityClaim::class, 'fp_identity_claims', 'identity_id'],
            [FpTeam::class, 'team_profiles', 'team_id'],
            [NipSequence::class, 'nip_sequences', 'id'],
        ];
    }

    #[DataProvider('modelMetadata')]
    public function test_model_mapping_without_connections(string $class, string $table, string $key): void
    {
        $model = new $class;
        self::assertSame($table, $model->getTable());
        self::assertSame($key, $model->getKeyName());
        self::assertSame(0, \FpCoreNoDatabase::$attempts);
    }

    public function test_sensitive_casts_and_generated_column_are_hidden(): void
    {
        $identity = new PersonIdentity;
        self::assertSame('encrypted', $identity->getCasts()['nik_encrypted']);
        foreach (['nik_encrypted', 'nik_lookup_hash', 'lookup_key_id'] as $name) {
            self::assertContains($name, $identity->getHidden());
        }
        foreach ([new FpSubmission, new FpMembership] as $model) {
            self::assertContains('bank_account_encrypted', $model->getHidden());
            self::assertSame('encrypted', $model->getCasts()['bank_account_encrypted']);
        }
        self::assertContains('active_leader_key', (new FpMembership)->getHidden());
        self::assertSame('immutable_date', (new FpMembership)->getCasts()['reference_date']);
    }

    public static function claimMutations(): array
    {
        return [['save'], ['delete']];
    }

    #[DataProvider('claimMutations')]
    public function test_claim_model_refuses_application_mutation(string $method): void
    {
        $this->expectException(LogicException::class);
        (new FpIdentityClaim)->$method();
    }

    public function test_activation_remains_disabled_and_evidence_models_are_now_available(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([['testing', false, false], ['production', true, true], ['local', true, true]] as [$environment, $optIn, $enabled]) {
            $code = 'require '.var_export($root.'/vendor/autoload.php', true).';'
                .'foreach (["APP_ENV","FP_ENABLED","FP_USER_MANAGEMENT_ENABLED","FP_ANNUAL_NIP_EMAIL_ENABLED","FP_USER_ROLES_ENABLED","FP_RECRUITMENT_TEAM_ENABLED","FP_ORGANIZATION_ENABLED"] as $key) { putenv($key); unset($_ENV[$key],$_SERVER[$key]); }'
                .'putenv("APP_ENV='.$environment.'");putenv("FP_ENABLED='.($optIn ? 'true' : 'false').'");'
                .'$config=require '.var_export($root.'/config/fp.php', true).';'
                .'echo json_encode(array_intersect_key($config,array_flip(["enabled","core_schema_only","user_management_enabled","annual_nip_email_enabled","user_roles_enabled","recruitment_team_enabled","organization_enabled"])));';
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $code]);
            $process->mustRun();
            $config = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($enabled, $config['enabled']);
            self::assertSame(! $enabled, $config['core_schema_only']);
            foreach (['user_management_enabled', 'annual_nip_email_enabled', 'user_roles_enabled', 'recruitment_team_enabled', 'organization_enabled'] as $flag) {
                self::assertFalse($config[$flag]);
            }
        }
        foreach (['FpDocument', 'FpExit', 'FpAuditEvent'] as $model) {
            self::assertFileExists(dirname(__DIR__, 2).'/app/Models/'.$model.'.php');
        }
        self::assertSame(0, \FpCoreNoDatabase::$attempts);
    }
}
