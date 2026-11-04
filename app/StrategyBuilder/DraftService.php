<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Strategy\Application\StrategyRegistry;
use Illuminate\Support\Facades\DB;

final readonly class DraftService
{
    public function __construct(private DefinitionStore $versions) {}

    public function save(string $id, ?int $revision, array $definition, ?string $description): object
    {
        $checksum = StrategyRegistry::hash(['definition' => $definition, 'description' => $description]);

        return DB::transaction(function () use ($id, $revision, $definition, $description, $checksum): object {
            $inserted = DB::table('strategy_drafts')->insertOrIgnore(['id' => $id, 'revision' => 1, 'definition' => json_encode($definition, JSON_THROW_ON_ERROR), 'description' => $description, 'checksum' => $checksum, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            if ($inserted) {
                abort_if($revision !== null, 409, 'Draft does not exist at the supplied revision.');
                $this->audit($id, 'DRAFT_CREATED', ['revision' => 1, 'checksum' => $checksum]);
            }
            $row = DB::table('strategy_drafts')->where('id', $id)->lockForUpdate()->first();
            if ($row->checksum === $checksum) {
                return $row;
            }
            abort_if($revision === null || $row->revision !== $revision || $row->status !== 'DRAFT', 409, 'Draft revision conflict; reload before editing.');
            DB::table('strategy_drafts')->where('id', $id)->update(['revision' => $revision + 1, 'definition' => json_encode($definition, JSON_THROW_ON_ERROR), 'description' => $description, 'checksum' => $checksum, 'updated_at' => now('UTC')]);
            $this->audit($id, 'DRAFT_UPDATED', ['revision' => $revision + 1, 'checksum' => $checksum]);

            return DB::table('strategy_drafts')->find($id);
        }, 3);
    }

    public function publish(string $id, int $revision, string $checksum): object
    {
        return DB::transaction(function () use ($id, $revision, $checksum): object {
            $row = DB::table('strategy_drafts')->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_if($row->revision !== $revision || ! hash_equals($row->checksum, $checksum), 409, 'Confirmed draft has changed.');
            if ($row->published_definition_id !== null) {
                return DB::table('strategy_definitions')->find($row->published_definition_id);
            }
            $version = $this->versions->save(json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR), 'configured-research-owner');
            DB::table('strategy_drafts')->where('id', $id)->update(['status' => 'PUBLISHED', 'published_definition_id' => $version->id, 'updated_at' => now('UTC')]);
            $this->audit($id, 'DRAFT_PUBLISHED', ['definition_id' => $version->id, 'revision' => $revision, 'checksum' => $checksum]);

            return $version;
        }, 3);
    }

    private function audit(string $id, string $action, array $details): void
    {
        DB::table('strategy_workflow_events')->insert(['draft_id' => $id, 'action' => $action, 'actor' => 'configured-research-owner', 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }
}
