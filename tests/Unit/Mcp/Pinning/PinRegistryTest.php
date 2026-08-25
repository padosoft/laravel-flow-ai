<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Tests\Unit\Mcp\Pinning;

use InvalidArgumentException;
use Padosoft\LaravelFlowAI\Mcp\Pinning\PinRegistry;
use Padosoft\LaravelFlowAI\Mcp\Pinning\ToolPins;
use PHPUnit\Framework\TestCase;

final class PinRegistryTest extends TestCase
{
    public function test_the_server_id_is_the_command_line(): void
    {
        $this->assertSame('npx -y @scope/server /srv/docs', PinRegistry::serverId('npx', ['-y', '@scope/server', '/srv/docs']));
    }

    public function test_stray_whitespace_in_a_wired_input_still_resolves_to_the_same_server(): void
    {
        $this->assertSame(
            PinRegistry::serverId('npx', ['-y', 'server']),
            PinRegistry::serverId('  npx ', [' -y', '', 'server  ']),
        );
    }

    public function test_pinning_off_hands_back_no_verifier_at_all(): void
    {
        // Not "a verifier that always passes": null is what keeps an
        // unpinned session from paying for a tools/list it does not need.
        $registry = new PinRegistry(ToolPins::MODE_OFF, servers: ['npx server' => ['search' => 'sha256:x']]);

        $this->assertNull($registry->forServer('npx', ['server']));
    }

    public function test_a_configured_server_gets_its_own_pinset_bound_to_its_id(): void
    {
        $registry = new PinRegistry(ToolPins::MODE_ENFORCE, servers: ['npx server' => ['search' => 'sha256:x']]);

        $pins = $registry->forServer('npx', ['server']);

        $this->assertNotNull($pins);
        $this->assertSame('npx server', $pins->serverId);
        $this->assertTrue($pins->isEnforcing());
    }

    public function test_an_unknown_server_is_unpinned_unless_pins_are_required(): void
    {
        $lenient = new PinRegistry(ToolPins::MODE_ENFORCE, servers: ['npx server' => ['search' => 'sha256:x']]);
        $strict = new PinRegistry(ToolPins::MODE_ENFORCE, requirePins: true, servers: ['npx server' => ['search' => 'sha256:x']]);

        $this->assertSame([], $lenient->forServer('npx', ['other'])?->violations([['name' => 'anything']]));
        $this->assertCount(1, (array) $strict->forServer('npx', ['other'])?->violations([['name' => 'anything']]));
    }

    public function test_an_unrecognised_mode_throws_rather_than_degrading_to_off(): void
    {
        // A typo in a security setting must be loud: silently reading
        // "enfroce" as "off" is the one failure a control like this cannot
        // afford.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not one of: off, warn, enforce');

        new PinRegistry('enfroce');
    }
}
