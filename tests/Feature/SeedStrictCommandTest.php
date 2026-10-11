<?php

namespace Tests\Feature;

use Illuminate\Database\Seeder;
use Modules\Warehouse\Support\SeedStrictCommand;
use RuntimeException;
use Tests\TestCase;

final class SeedStrictCommandTest extends TestCase
{
    public function test_unclassified_warnings_and_errors_stop_without_disclosing_raw_text(): void
    {
        $command = new SeedStrictCommand;
        foreach (['warn' => 'WARNING', 'error' => 'ERROR'] as $method => $kind) {
            $privateText = 'Do-not-export-'.bin2hex(random_bytes(16));
            try {
                $command->{$method}($privateText);
                $this->fail('Unclassified seed output must stop the full chain.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('SEED_'.$kind.'_REQUIRES_REVIEW', $error->getMessage());
                $this->assertStringNotContainsString($privateText, $error->getMessage());
                $this->assertStringNotContainsString($privateText, $error->getTraceAsString());
                $this->assertStringContainsString('earlier writes may have committed', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
    }

    public function test_informational_replay_output_does_not_fail(): void
    {
        $command = new SeedStrictCommand;
        $command->info('REPLAY_VERIFIED: fixture evidence checked; no new effect.');
        $this->assertSame([], $command->option());
        $this->assertFalse($command->hasOption('unknown'));
        $this->assertSame([], $command->argument());
        $this->assertFalse($command->hasArgument('unknown'));
    }

    public function test_seeder_warning_prevents_following_phase_body_from_running(): void
    {
        $seeder = new class extends Seeder
        {
            public bool $completed = false;

            public function run(): void
            {
                $this->command->warn('Simulated required phase failure');
                $this->completed = true;
            }
        };
        $seeder->setCommand(new SeedStrictCommand);
        try {
            $seeder->run();
            $this->fail('Required phase cannot complete after its warning.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('SEED_WARNING_REQUIRES_REVIEW', $error->getMessage());
            $this->assertFalse($seeder->completed);
        }
    }
}
