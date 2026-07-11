<?php

declare(strict_types=1);

namespace Tests\Feature;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

/**
 * ContractSchemaTest loads the two frozen JSON Schemas from
 * docs/contracts/ and asserts the in-doc example payloads (reproduced as
 * fixtures) pass validation, and that deliberately broken payloads fail.
 */
class ContractSchemaTest extends TestCase
{
    private const INGEST_SCHEMA = 'docs/contracts/tower.ingest.v1.json';
    private const HERDR_SCHEMA = 'docs/contracts/tower.herdr.v1.json';

    private Validator $validator;
    private ErrorFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new Validator();
        $this->validator->resolver()->registerPrefix('https://tower.dev', base_path());
        $this->formatter = new ErrorFormatter();
    }

    /**
     * The generic ingest envelope example from ARCHITECTURE.md §1.3, lifted
     * verbatim and used as a fixture to prove the schema accepts the spec's
     * own example.
     */
    public function test_ingest_example_payload_passes_schema(): void
    {
        $payload = $this->asJsonObject($this->ingestExamplePayload());
        $schema = $this->loadSchema(self::INGEST_SCHEMA);

        $result = $this->validator->validate($payload, $schema);
        $this->assertValidationPassed($result, 'ingest example payload');
    }

    public function test_ingest_rejects_missing_required_event_fields(): void
    {
        $payload = $this->ingestExamplePayload();
        $payload['events'][0]['type'] = 'run.state_changed';
        unset($payload['events'][0]['run'], $payload['events'][0]['to']);

        $result = $this->validator->validate(
            $this->asJsonObject($payload),
            $this->loadSchema(self::INGEST_SCHEMA)
        );
        $this->assertFalse(
            $result->isValid(),
            'run.state_changed without run/to should fail schema validation'
        );
    }

    public function test_ingest_rejects_unknown_event_type(): void
    {
        $payload = $this->ingestExamplePayload();
        $payload['events'][0]['type'] = 'bogus.event';

        $result = $this->validator->validate(
            $this->asJsonObject($payload),
            $this->loadSchema(self::INGEST_SCHEMA)
        );
        $this->assertFalse(
            $result->isValid(),
            'unknown event.type should fail schema validation'
        );
    }

    public function test_herdr_example_payload_passes_schema(): void
    {
        $payload = $this->asJsonObject($this->herdrExamplePayload());
        $result = $this->validator->validate($payload, $this->loadSchema(self::HERDR_SCHEMA));
        $this->assertValidationPassed($result, 'herdr example payload');
    }

    public function test_herdr_rejects_snapshot_without_workspaces(): void
    {
        $payload = $this->herdrExamplePayload();
        $payload['batch'][0] = ['kind' => 'snapshot']; // missing required `workspaces`

        $result = $this->validator->validate(
            $this->asJsonObject($payload),
            $this->loadSchema(self::HERDR_SCHEMA)
        );
        $this->assertFalse(
            $result->isValid(),
            'snapshot batch item without `workspaces` must fail'
        );
    }

    public function test_herdr_rejects_status_change_without_required_keys(): void
    {
        $payload = $this->herdrExamplePayload();
        $payload['batch'][1] = [
            'kind' => 'pane.agent_status_changed',
            // missing: workspace, pane, to, dedupe_key
        ];

        $result = $this->validator->validate(
            $this->asJsonObject($payload),
            $this->loadSchema(self::HERDR_SCHEMA)
        );
        $this->assertFalse(
            $result->isValid(),
            'pane.agent_status_changed without required fields must fail'
        );
    }

    public function test_herdr_rejects_unknown_tier(): void
    {
        $payload = $this->herdrExamplePayload();
        $payload['tier'] = 7;

        $result = $this->validator->validate(
            $this->asJsonObject($payload),
            $this->loadSchema(self::HERDR_SCHEMA)
        );
        $this->assertFalse(
            $result->isValid(),
            'tier outside {0,1} must fail'
        );
    }

    /**
     * @param  \Opis\JsonSchema\ValidationResult  $result
     */
    private function assertValidationPassed(object $result, string $label): void
    {
        if ($result->isValid()) {
            $this->assertTrue(true);
            return;
        }

        $error = $result->error();
        $formatted = $error ? json_encode(
            $this->formatter->format($error),
            JSON_UNESCAPED_SLASHES
        ) : '(no error info)';
        $this->fail("{$label} should validate. Errors: {$formatted}");
    }

    /**
     * opis/json-schema requires its data to be a stdClass (object) tree, not
     * a PHP array — round-trip through json_encode/json_decode to convert.
     *
     * @param  array<string, mixed>  $data
     * @return object
     */
    private function asJsonObject(array $data): object
    {
        return json_decode(json_encode($data, JSON_UNESCAPED_SLASHES), false);
    }

    /**
     * The example envelope as it appears in ARCHITECTURE.md §1.3.
     *
     * @return array<string, mixed>
     */
    private function ingestExamplePayload(): array
    {
        return [
            'schema' => 'tower.ingest.v1',
            'sent_at' => '2026-07-11T14:02:11Z',
            'events' => [
                [
                    'type' => 'run.state_changed',
                    'run' => [
                        'external_id' => 'omp:acme:2026-07-11T13:55',
                        'title' => 'ACME-241 checkout fix',
                    ],
                    'from' => 'running',
                    'to' => 'blocked',
                    'occurred_at' => '2026-07-11T14:02:09Z',
                    'dedupe_key' => 'omp:acme:2026-07-11T13:55:evt-00042',
                    'payload' => [
                        'reason' => 'awaiting approval',
                        'tools_used' => ['bash', 'edit'],
                    ],
                ],
                [
                    'type' => 'agent.heartbeat',
                    'occurred_at' => '2026-07-11T14:02:10Z',
                ],
            ],
        ];
    }

    /**
     * The herdr envelope example as it appears in ARCHITECTURE.md §1.6.
     *
     * @return array<string, mixed>
     */
    private function herdrExamplePayload(): array
    {
        return [
            'schema' => 'tower.herdr.v1',
            'bridge_version' => '0.1.0',
            'herdr_version' => '0.4.2',
            'host' => 'claude-a',
            'tier' => 0,
            'sent_at' => '2026-07-12T09:15:04Z',
            'batch' => [
                [
                    'kind' => 'snapshot',
                    'workspaces' => [
                        [
                            'name' => 'tower',
                            'panes' => [
                                [
                                    'pane' => '%3',
                                    'agent_kind' => 'claude-code',
                                    'state' => 'working',
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'kind' => 'pane.agent_status_changed',
                    'workspace' => 'tower',
                    'pane' => '%3',
                    'agent_kind' => 'claude-code',
                    'from' => 'working',
                    'to' => 'blocked',
                    'at' => '2026-07-12T09:15:02Z',
                    'dedupe_key' => 'claude-a:%3:8842',
                ],
                [
                    'kind' => 'pane.agent_detected',
                    'workspace' => 'tower',
                    'pane' => '%5',
                    'agent_kind' => 'codex',
                    'at' => '2026-07-12T09:15:03Z',
                    'dedupe_key' => 'claude-a:%5:8843',
                ],
                [
                    'kind' => 'pane.closed',
                    'workspace' => 'tower',
                    'pane' => '%3',
                    'at' => '2026-07-12T09:15:04Z',
                    'dedupe_key' => 'claude-a:%3:8844',
                ],
            ],
            'tails' => [
                [
                    'pane' => '%3',
                    'dedupe_key' => 'claude-a:%3:8842:tail',
                    'content_redacted' => '...last 40 lines, post-redaction...',
                    'redactions_applied' => 4,
                ],
            ],
        ];
    }

    /**
     * @return object
     */
    private function loadSchema(string $relativePath)
    {
        $absolute = base_path($relativePath);
        $this->assertFileExists($absolute, "missing schema at {$absolute}");

        return json_decode(file_get_contents($absolute), false);
    }
}
