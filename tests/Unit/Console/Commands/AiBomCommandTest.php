<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Console\Commands;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlowAI\Bom\AiBom;
use Padosoft\LaravelFlowAI\LaravelFlowAIServiceProvider;

final class AiBomCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class, LaravelFlowAIServiceProvider::class];
    }

    public function test_it_writes_the_document_to_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-bom-').'.json';

        try {
            $this->artisan('flow:ai-bom', ['--output' => $path])->assertSuccessful();

            $contents = file_get_contents($path);
            $this->assertIsString($contents);

            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(AiBom::FORMAT, $document['bomFormat']);
            $this->assertArrayHasKey('controls', $document);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_digest_flag_prints_only_a_digest(): void
    {
        // Small enough that
        // `test "$(php artisan flow:ai-bom --digest)" = "$(cat ai-bom.sha)"`
        // is the whole CI gate.
        $this->artisan('flow:ai-bom', ['--digest' => true])
            ->expectsOutputToContain('sha256:')
            ->assertSuccessful();
    }

    public function test_writing_to_an_unwritable_path_fails_loudly(): void
    {
        $this->artisan('flow:ai-bom', ['--output' => '/nonexistent-directory-'.uniqid().'/ai-bom.json'])
            ->assertFailed();
    }
}
