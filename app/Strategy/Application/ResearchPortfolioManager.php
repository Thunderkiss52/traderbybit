<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\PaperTrading\Application\TradingJournalService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final readonly class ResearchPortfolioManager
{
    public function __construct(private TradingJournalService $journal) {}

    public function approve(string $experimentId): PaperAccountRecord
    {
        return DB::transaction(function () use ($experimentId): PaperAccountRecord {
            $experiment = ResearchExperimentRecord::query()->lockForUpdate()->findOrFail($experimentId);
            if ($experiment->status !== 'COMPLETED') {
                throw new \DomainException('Only completed experiments can be promoted to forward paper.');
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:research-paper:'.$experiment->id)->toString();
            $starting = (string) config('paper.default_account.starting_balance');
            $parameters = $experiment->selected_parameters ?? $experiment->parameters;
            $account = PaperAccountRecord::query()->firstOrCreate(['id' => $id], ['name' => 'Research '.$experiment->strategy_id.' '.$experiment->strategy_version, 'status' => 'ACTIVE', 'starting_balance' => $starting, 'current_equity' => $starting, 'peak_equity' => $starting, 'realized_pnl' => '0', 'unrealized_pnl' => '0', 'reserved_risk' => '0', 'maximum_drawdown_percent' => '0', 'current_drawdown_percent' => '0', 'mode' => 'RESEARCH_FORWARD_PAPER', 'strategy_id' => $experiment->strategy_id, 'strategy_version' => $experiment->strategy_version, 'research_experiment_id' => $experiment->id, 'configuration_hash' => $this->hash($parameters), 'strategy_parameters' => $parameters]);
            EquitySnapshotRecord::query()->firstOrCreate(['paper_account_id' => $id, 'source_event_key' => 'research-portfolio-created'], ['recorded_at' => $account->created_at, 'equity' => $starting, 'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0']);
            $this->journal->append($id, null, null, 'RESEARCH_PORTFOLIO_APPROVED', $account->created_at->toDateTimeImmutable(), ['strategy_id' => $experiment->strategy_id, 'strategy_version' => $experiment->strategy_version, 'experiment_id' => $experiment->id], 'research-portfolio-approved:'.$id);

            return $account;
        });
    }

    private function hash(array $parameters): string
    {
        ksort($parameters);

        return hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }
}
