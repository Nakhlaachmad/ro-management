<?php

declare(strict_types=1);

namespace Tests\FpCore;

use PHPUnit\Framework\TestCase;

final class RecruitmentPathTest extends TestCase
{
    public function test_disabled_master_access_fails_closed_without_database(): void
    {
        $app = \Illuminate\Container\Container::getInstance();
        $app->instance('config', new \Illuminate\Config\Repository(['fp' => ['enabled' => false, 'core_schema_only' => true]]));
        $user = new \App\Models\User;
        $user->is_active = true;
        self::assertFalse(\App\Application\FP\FeatureAccess::allowed($user, 'f3.jalur.view'));
        self::assertFalse(\App\Application\FP\FeatureAccess::allowed($user, 'f3.jalur.manage'));
    }

    public function test_missing_path_rejected_before_database(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $app = \Illuminate\Container\Container::getInstance();
        $app->instance('translator', new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'));
        $app->instance('validator', new \Illuminate\Validation\Factory($app->make('translator'), $app));
        $old = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        try {
            \App\Application\FP\RecruitmentPaths::activeForApproval(null);
        } finally {
            \Illuminate\Support\Facades\Facade::setFacadeApplication($old);
        }
    }

    public function test_public_receipt_contains_neither_jalur_nor_nip(): void
    {
        $receipt = \App\Domain\FP\PublicReceipt::make('b86fda15-3bc1-4791-a77a-628c09d63563');
        self::assertSame(['receipt', 'message'], array_keys($receipt));
        self::assertDoesNotMatchRegularExpression('/jalur|nip|recruitment_path/i', json_encode($receipt));
    }
}
