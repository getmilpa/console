<?php

/**
 * This file is part of Milpa Console — the projection layer that turns one declared Operation into the shape each surface speaks.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/console
 */

declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationPermissionPolicy;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * An operation typed by `permission` is judged on the MCP surface, the way HTTP judges it.
 *
 * Up to 0.22.1 the projector carried `scopes` into the tool registry and dropped `permission`: the
 * registry's PolicyGate saw a tool with no scopes and let every caller through, so a read typed by
 * permission was served over MCP to anyone who could reach the server. Every call here goes through
 * the real {@see ToolRegistry} — the same `call()` an MCP `tools/call` reaches — so what these tests
 * see is what a client sees.
 */
final class McpPermissionTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    private function permissionedRead(): Operation
    {
        return new Operation(
            name: 'grades.read',
            description: 'Read the grades of a group',
            handler: function (array $input): array {
                $this->ran[] = 'grades.read';

                return ['group' => $input['group'] ?? null, 'grades' => [10, 9]];
            },
            inputSchema: ['type' => 'object', 'properties' => ['group' => ['type' => 'string']]],
            permission: 'school.grades:read',
            effects: EffectProfile::readOnly(),
        );
    }

    private function scopedRead(): Operation
    {
        return new Operation(
            name: 'notes.read',
            description: 'Read notes',
            handler: function (): string {
                $this->ran[] = 'notes.read';

                return 'notes';
            },
            scopes: ['notes:read'],
            effects: EffectProfile::readOnly(),
        );
    }

    /** @param list<Operation> $operations */
    private function served(array $operations, DIContainer $container, ?McpProjector $projector = null): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        ($projector ?? new McpProjector())->projectAll($operations, $registry, $container);

        return $registry;
    }

    private function withPolicy(OperationPermissionPolicy $policy): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(OperationPermissionPolicy::class, $policy);

        return $container;
    }

    /** A process-trusted stdio caller holds `*` — and a wildcard scope is not a permission. */
    private function stdioCaller(): ToolContext
    {
        return ToolContext::stdio('req-1');
    }

    public function test_a_permission_typed_read_is_refused_to_a_caller_the_policy_denies(): void
    {
        $registry = $this->served([$this->permissionedRead()], $this->withPolicy(new JudgeDouble(allow: false)));

        $result = $registry->call('grades_read', ['group' => '3B'], $this->stdioCaller());

        self::assertFalse($result->success, 'a caller without the permission must not be served');
        self::assertSame(ToolResult::FORBIDDEN, $result->meta['code'] ?? null);
        self::assertStringContainsString('school.grades:read', (string) $result->error);
        self::assertSame([], $this->ran, 'the handler must not run for a refused caller');
    }

    public function test_a_permission_typed_read_is_served_to_a_caller_the_policy_admits(): void
    {
        $judge = new JudgeDouble(allow: true);
        $registry = $this->served([$this->permissionedRead()], $this->withPolicy($judge));

        $result = $registry->call('grades_read', ['group' => '3B'], $this->stdioCaller());

        self::assertTrue($result->success, (string) $result->error);
        self::assertSame(['group' => '3B', 'grades' => [10, 9]], $result->data);
        self::assertSame(['grades.read'], $this->ran);
        self::assertNotEmpty($judge->asked);
        foreach ($judge->asked as $asked) {
            self::assertSame('school.grades:read', $asked['operation']->permission);
            self::assertSame('stdio', $asked['caller']->principal);
            self::assertSame(['group' => '3B'], $asked['arguments']);
        }
    }

    /**
     * The registry lets a `tool.executing` listener (a cache) answer for a tool without running it,
     * and requires every check to have run before that. A permission judged only inside the
     * callback would be skipped by such a listener.
     */
    public function test_a_listener_that_answers_for_the_tool_cannot_serve_a_refused_caller(): void
    {
        $dispatcher = new \Milpa\Eventing\EventDispatcher(new NullLogger());
        $dispatcher->subscribe(\Milpa\ToolRuntime\Events\ToolRuntimeEvents::TOOL_EXECUTING, static function (string $event, array $payload): void {
            $payload['slot']->shortCircuit(['grades' => ['cached']]);
        });
        $judge = new JudgeDouble(allow: true);
        $registry = new ToolRegistry(new NullLogger(), $dispatcher);
        (new McpProjector())->projectAll([$this->permissionedRead()], $registry, $this->withPolicy($judge));

        // Positive control: the listener really answers — an admitted caller gets the cached value
        // and the handler never runs.
        $admitted = $registry->call('grades_read', [], $this->stdioCaller());
        self::assertSame(['grades' => ['cached']], $admitted->data);
        self::assertSame([], $this->ran);

        $judge->allow = false;
        $result = $registry->call('grades_read', [], $this->stdioCaller());

        self::assertFalse($result->success, 'a cached answer must not reach a caller without the permission');
        self::assertSame(ToolResult::FORBIDDEN, $result->meta['code'] ?? null);
    }

    public function test_a_refused_caller_is_not_asked_to_confirm_first(): void
    {
        $mutation = new Operation(
            name: 'grades.write',
            description: 'Change a grade',
            handler: function (): string {
                $this->ran[] = 'grades.write';

                return 'written';
            },
            mutating: true,
            requiresConfirmation: true,
            permission: 'school.grades:write',
        );
        $registry = $this->served([$mutation], $this->withPolicy(new JudgeDouble(allow: false)));

        $result = $registry->call('grades_write', [], $this->stdioCaller());

        self::assertFalse($result->success);
        self::assertSame(ToolResult::FORBIDDEN, $result->meta['code'] ?? null, 'authority is judged before consent');
        self::assertSame([], $this->ran);
    }

    public function test_the_host_call_policy_still_judges_behind_the_permission(): void
    {
        $host = new HostPolicyDouble();
        $container = $this->withPolicy(new JudgeDouble(allow: true));
        $container->registerService(\Milpa\ToolRuntime\Contracts\CallPolicy::class, $host);
        $registry = new ToolRegistry(new NullLogger());
        $projector = new McpProjector();

        $projector->materialize($projector->project($this->permissionedRead()), $registry, $container);
        // A later tool on the same registry must not evict the permission judge from the gate.
        $projector->materialize($projector->project($this->scopedRead()), $registry, $container);

        self::assertInstanceOf(\Milpa\Console\PermissionCallPolicy::class, $registry->getPolicyGate()->getCallPolicy());
        $host->allow = false;
        self::assertFalse($registry->call('grades_read', [], $this->stdioCaller())->success, 'the host may still refuse an admitted caller');
        self::assertFalse($registry->call('notes_read', [], $this->stdioCaller())->success, 'the host still judges unguarded tools');
        $host->allow = true;
        self::assertTrue($registry->call('grades_read', [], $this->stdioCaller())->success);
        self::assertContains('grades_read', $host->asked);
        self::assertContains('notes_read', $host->asked);
    }

    public function test_the_guarded_callable_refuses_a_call_with_no_caller(): void
    {
        $handler = new \Milpa\Console\PermissionGuardedHandler(
            new JudgeDouble(allow: true),
            $this->permissionedRead(),
            new \Milpa\Console\OperationToolHandler(new \Milpa\Console\OperationRunner(new DIContainer()), $this->permissionedRead()),
        );

        $result = $handler([]);

        self::assertInstanceOf(ToolResult::class, $result);
        self::assertSame(ToolResult::FORBIDDEN, $result->meta['code'] ?? null);
    }

    public function test_the_guarded_callable_refuses_on_its_own_when_the_gate_is_bypassed(): void
    {
        $judge = new JudgeDouble(allow: false);
        $spy = new GatelessRegistry();
        (new McpProjector())->projectAll([$this->permissionedRead()], $spy, $this->withPolicy($judge));

        $result = ($spy->callbacks[0])(['group' => '3B'], $this->stdioCaller());

        self::assertInstanceOf(ToolResult::class, $result);
        self::assertSame(ToolResult::FORBIDDEN, $result->meta['code'] ?? null);
        self::assertSame([], $this->ran, 'a registry without a gate still never runs an unjudged call');
    }

    public function test_the_policy_is_asked_on_every_call_not_once_per_projection(): void
    {
        $judge = new JudgeDouble(allow: true);
        $registry = $this->served([$this->permissionedRead()], $this->withPolicy($judge));

        $registry->call('grades_read', [], $this->stdioCaller());
        $judge->allow = false;
        $second = $registry->call('grades_read', [], $this->stdioCaller());

        self::assertFalse($second->success, 'a verdict is for a call, never cached for the tool');
        self::assertSame(['grades.read'], $this->ran);
    }

    public function test_a_policy_that_throws_refuses_the_call(): void
    {
        $judge = new JudgeDouble(allow: true);
        $judge->throws = true;
        $registry = $this->served([$this->permissionedRead()], $this->withPolicy($judge));

        $result = $registry->call('grades_read', [], $this->stdioCaller());

        self::assertFalse($result->success, 'a judge that cannot answer is not a yes');
        self::assertSame([], $this->ran);
    }

    public function test_without_a_policy_a_permission_typed_operation_is_withheld_not_served(): void
    {
        $logger = new LoggerDouble();
        $container = new DIContainer();
        $container->registerService(LoggerInterface::class, $logger);
        $projector = new McpProjector();

        $registry = $this->served([$this->permissionedRead(), $this->scopedRead()], $container, $projector);

        self::assertFalse($registry->has('grades_read'), 'no judge means no tool, never an unjudged one');
        self::assertTrue($registry->has('notes_read'), 'the rest of the catalogue is still served');
        self::assertSame(['grades_read'], array_keys($projector->withheld()));
        self::assertStringContainsString(OperationPermissionPolicy::class, $projector->withheld()['grades_read']);
        self::assertSame(ToolResult::TOOL_NOT_FOUND, $registry->call('grades_read', [], $this->stdioCaller())->meta['code'] ?? null);
        self::assertCount(1, $logger->warnings, 'withholding is said out loud, never silent');
        self::assertStringContainsString('grades.read', $logger->warnings[0]);
        self::assertSame([], $this->ran);
    }

    /**
     * A fresh house's kernel puts a NullLogger in the container when the host passes none; a
     * warning sent there is silence. Both shapes of «no logger» must reach the SAPI log.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noLogger')]
    public function test_without_a_real_logger_the_withholding_goes_to_the_sapi_log(bool $nullLogger): void
    {
        $container = new DIContainer();
        if ($nullLogger) {
            $container->registerService(LoggerInterface::class, new NullLogger());
        }
        $log = tempnam(sys_get_temp_dir(), 'withheld');
        $previous = ini_set('error_log', (string) $log);

        try {
            $registry = $this->served([$this->permissionedRead()], $container);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $said = (string) file_get_contents((string) $log);
        @unlink((string) $log);
        self::assertFalse($registry->has('grades_read'));
        self::assertStringContainsString('grades.read', $said);
        self::assertStringContainsString('school.grades:read', $said);
    }

    /** @return array<string, array{bool}> */
    public static function noLogger(): array
    {
        return ['no logger at all' => [false], 'the kernel default NullLogger' => [true]];
    }

    public function test_a_scopes_typed_operation_never_asks_the_permission_policy(): void
    {
        $judge = new JudgeDouble(allow: false);
        $registry = $this->served([$this->scopedRead()], $this->withPolicy($judge));

        $denied = $registry->call('notes_read', [], ToolContext::stdio('req-2', 'agent', ['other:read']));
        $served = $registry->call('notes_read', [], ToolContext::stdio('req-3', 'agent', ['notes:read']));

        self::assertFalse($denied->success, 'scopes are still judged by the PolicyGate');
        self::assertTrue($served->success, (string) $served->error);
        self::assertSame([], $judge->asked, 'the permission policy is not the judge of scopes');
    }

    public function test_the_projected_model_carries_the_permission(): void
    {
        $model = (new McpProjector())->project($this->permissionedRead());

        self::assertSame('school.grades:read', $model->permission);
        self::assertSame('school.grades:read', $model->toArray()['permission']);
        self::assertNull((new McpProjector())->project($this->scopedRead())->permission);
    }

    public function test_a_hand_built_model_with_a_permission_and_no_operation_is_withheld(): void
    {
        $projector = new McpProjector();
        $registry = new ToolRegistry(new NullLogger());
        $model = new \Milpa\Console\Model\McpToolModel(
            name: 'hand_read',
            description: 'Built by hand',
            inputSchema: [],
            handler: static fn (): string => 'secret',
            permission: 'school.grades:read',
        );

        $projector->materialize($model, $registry, $this->withPolicy(new JudgeDouble(allow: true)));

        self::assertFalse($registry->has('hand_read'), 'a permission with no operation to judge is not served');
        self::assertArrayHasKey('hand_read', $projector->withheld());
    }
}

/** Keeps the warnings a projector says out loud. */
final class LoggerDouble extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }
}

/** The host's own call policy, which must keep judging behind the permission judge. */
final class HostPolicyDouble implements \Milpa\ToolRuntime\Contracts\CallPolicy
{
    /** @var list<string> */
    public array $asked = [];

    public bool $allow = true;

    public function authorize(ToolContext $context, \Milpa\ToolRuntime\ToolDefinition $tool, array $arguments): AuthorizationResult
    {
        $this->asked[] = $tool->name;

        return $this->allow ? AuthorizationResult::allowed() : AuthorizationResult::denied('the host says no');
    }
}

/** A registry that is not tool-runtime's: no PolicyGate, so only the guarded callable stands. */
final class GatelessRegistry implements \Milpa\Interfaces\Tooling\ToolRegistryInterface
{
    /** @var list<callable> */
    public array $callbacks = [];

    /** @param array<string, mixed> $inputSchema */
    public function register(string $name, string $description, array $inputSchema, callable $callback, ?\Milpa\ValueObjects\Tooling\ToolOptions $options = null): void
    {
        $this->callbacks[] = $callback;
    }
}
