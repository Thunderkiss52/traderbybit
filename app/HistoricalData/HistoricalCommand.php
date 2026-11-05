<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Console\Command;

final class HistoricalCommand extends Command
{
    protected $signature = 'historical:data {action : plan|create|status|start|pause|resume|cancel|retry|validate|verify|recover|research|run}
        {id? : Batch, pin or run identifier} {--input= : Local JSON input file; plan/research only}
        {--key= : Stable planning idempotency key} {--reference= : Owner acknowledgement reference}
        {--terms-version= : Recorded source terms version}';

    protected $description = 'Plan or explicitly control bounded non-commercial historical research; help and plan never download';

    public function handle(DownloadPlanner $planner, BatchRepository $batches, BatchControl $control, ReadModel $view, CoverageValidator $coverage, AcquisitionDataset $pins, Recovery $recovery, ResearchService $research): int
    {
        try {
            $action = (string) $this->argument('action');
            $id = (string) $this->argument('id');
            $input = [];
            if (in_array($action, ['plan', 'create', 'research'], true)) {
                $file = $this->option('input');
                if (! is_string($file) || ! is_file($file) || filesize($file) > 65536) {
                    throw new \RuntimeException('Provide --input with a local JSON file no larger than 64 KiB.');
                }
                $input = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            }
            $result = match ($action) {
                'plan' => $planner->plan($input),
                'create' => $this->create($batches, $view, $input),
                'status' => $view->batch($id),
                'validate' => $coverage->validate($id),
                'verify' => ['pin' => $pins->verify($id)->id, 'verified' => true],
                'recover' => $recovery->dispatch(),
                'run' => $view->run($id),
                'research' => $this->research($id, $input, $research, $view),
                'start', 'resume', 'pause', 'cancel', 'retry' => $this->control($id, $action, $control, $view),
                default => throw new \RuntimeException('Unsupported historical action.'),
            };
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof AcquisitionFailure ? $error->reason : $error->getMessage());

            return self::FAILURE;
        }
    }

    private function create(BatchRepository $batches, ReadModel $view, array $input): array
    {
        $key = $this->option('key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $key)) {
            throw new \RuntimeException('Explicit stable --key is required.');
        }

        return $view->batch($batches->create($input, $key)->id);
    }

    private function control(string $id, string $action, BatchControl $control, ReadModel $view): array
    {
        $batch = $control->change($id, $action, $this->option('reference'), $this->option('terms-version'));
        if ($batch->status === 'ACTIVE') {
            AcquisitionJob::dispatch($id)->afterCommit();
        }

        return $view->batch($id);
    }

    private function research(string $id, array $input, ResearchService $research, ReadModel $view): array
    {
        $run = $research->create($id, $input);
        if ($run->status === 'QUEUED') {
            ResearchReplayJob::dispatch($run->id)->afterCommit();
        }

        return $view->run($run->id);
    }
}
