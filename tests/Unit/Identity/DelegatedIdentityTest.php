<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Identity;

use Padosoft\LaravelFlowAI\Identity\DelegatedIdentity;
use PHPUnit\Framework\TestCase;

final class DelegatedIdentityTest extends TestCase
{
    private function identity(): DelegatedIdentity
    {
        return new DelegatedIdentity(
            subject: 'user:42',
            actor: 'agent:01J',
            token: 'tok-secret',
            grantId: 'dgr_1',
            scopes: ['orders:read'],
            audience: 'mcp://crm-tools',
        );
    }

    public function test_environment_is_the_documented_env_var_contract(): void
    {
        $this->assertSame([
            'FLOW_DELEGATED_TOKEN' => 'tok-secret',
            'FLOW_DELEGATED_SUBJECT' => 'user:42',
            'FLOW_DELEGATED_ACTOR' => 'agent:01J',
        ], $this->identity()->environment());
    }

    public function test_the_token_is_reachable_only_through_its_accessor(): void
    {
        $identity = $this->identity();

        $this->assertSame('tok-secret', $identity->token());
        // The property itself is private — (array) casting exposes it only
        // under the mangled private key, never as a public member.
        $this->assertArrayNotHasKey('token', get_object_vars($identity));
    }

    public function test_debug_output_redacts_the_token(): void
    {
        $debug = $this->identity()->__debugInfo();

        $this->assertSame('[redacted]', $debug['token']);
        $this->assertSame('user:42', $debug['subject']);

        ob_start();
        var_dump($this->identity());
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString('tok-secret', $dump);
    }
}
