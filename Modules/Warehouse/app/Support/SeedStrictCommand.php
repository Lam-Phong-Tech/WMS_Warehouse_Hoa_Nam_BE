<?php

namespace Modules\Warehouse\Support;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** Fixture-only console: an unclassified warning/error is not acceptance success. */
final class SeedStrictCommand extends Command
{
    public function __construct(private ?Command $original = null)
    {
        parent::__construct();
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $this->setInput($input);
        $this->setOutput($original === null ? new OutputStyle($input, new NullOutput) : $original->getOutput());
        $this->setLaravel(app());
    }

    public function option($key = null)
    {
        return $this->original === null ? ($key === null ? [] : null) : $this->original->option($key);
    }

    public function argument($key = null)
    {
        return $this->original === null ? ($key === null ? [] : null) : $this->original->argument($key);
    }

    public function hasOption($name)
    {
        return $this->original?->hasOption($name) ?? false;
    }

    public function hasArgument($name)
    {
        return $this->original?->hasArgument($name) ?? false;
    }

    public function warn(#[SensitiveParameter] $string, $verbosity = null)
    {
        $this->reject('WARNING');
    }

    public function error(#[SensitiveParameter] $string, $verbosity = null)
    {
        $this->reject('ERROR');
    }

    private function reject(string $kind): never
    {
        $phase = 'default-fixture-chain';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $class = $frame['class'] ?? '';
            if (str_ends_with($class, 'Seeder')) {
                $phase = $class.'::'.($frame['function'] ?? 'run');
                break;
            }
        }
        // Do not copy raw warning text, SQL bindings, credentials or tokens into reports.
        throw new RuntimeException('SEED_'.$kind.'_REQUIRES_REVIEW: '.$phase.'; earlier writes may have committed; full-chain rollback is not claimed.');
    }
}
