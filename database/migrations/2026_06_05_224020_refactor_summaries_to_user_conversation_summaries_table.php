<?php

use App\Enums\ConversationSummaryTriggerType;
use App\Enums\ConversationSummaryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_TABLE = 'summaries';

    private const NEW_TABLE = 'user_conversation_summaries';

    private const UNIQUE_INDEX = 'user_conversation_summary_unique';

    private const TYPE_TRIGGER_INDEX = 'user_conversation_summary_type_trigger_index';

    private const PERIOD_INDEX = 'user_conversation_summary_period_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable(self::OLD_TABLE) && ! Schema::hasTable(self::NEW_TABLE)) {
            Schema::rename(self::OLD_TABLE, self::NEW_TABLE);
        }

        if (! Schema::hasTable(self::NEW_TABLE)) {
            return;
        }

        $this->renamePostgresArtifactsToNewNames();
        $this->dropUniqueIfExists(self::NEW_TABLE, 'summaries_user_id_month_year_unique');

        $this->addConversationCycleColumns();
        $this->backfillLegacyMonthlyPeriods();
        $this->dropLegacyMonthlyColumns();
        $this->commentConversationSummaryColumns();
        $this->addConversationSummaryIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::NEW_TABLE)) {
            return;
        }

        $this->dropUniqueIfExists(self::NEW_TABLE, self::UNIQUE_INDEX);
        $this->dropIndexIfExists(self::NEW_TABLE, self::TYPE_TRIGGER_INDEX);
        $this->dropIndexIfExists(self::NEW_TABLE, self::PERIOD_INDEX);

        $this->addLegacyMonthlyColumns();
        $this->backfillLegacyMonthAndYear();
        $this->dropConversationCycleColumns();

        $this->addLegacyMonthlyUniqueIndex();

        Schema::rename(self::NEW_TABLE, self::OLD_TABLE);
        $this->renamePostgresArtifactsToOldNames();
    }

    private function addConversationCycleColumns(): void
    {
        Schema::table(self::NEW_TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::NEW_TABLE, 'summary_type')) {
                $table->string('summary_type')
                    ->default(ConversationSummaryType::ConversationCycle->value)
                    ->comment('Classifies which summary domain this record belongs to.');
            }

            if (! Schema::hasColumn(self::NEW_TABLE, 'trigger_type')) {
                $table->string('trigger_type')
                    ->default(ConversationSummaryTriggerType::Weekly->value)
                    ->comment('Identifies which conversation reset trigger produced the summary.');
            }

            if (! Schema::hasColumn(self::NEW_TABLE, 'period_start')) {
                $table->dateTime('period_start')->nullable();
            }

            if (! Schema::hasColumn(self::NEW_TABLE, 'period_end')) {
                $table->dateTime('period_end')->nullable();
            }

            if (! Schema::hasColumn(self::NEW_TABLE, 'conversation_id')) {
                $table->string('conversation_id')->nullable();
            }

            if (! Schema::hasColumn(self::NEW_TABLE, 'message_count')) {
                $table->unsignedInteger('message_count')->nullable();
            }

            if (! Schema::hasColumn(self::NEW_TABLE, 'token_count')) {
                $table->unsignedInteger('token_count')->nullable();
            }
        });
    }

    private function backfillLegacyMonthlyPeriods(): void
    {
        if (! Schema::hasColumn(self::NEW_TABLE, 'month') || ! Schema::hasColumn(self::NEW_TABLE, 'year')) {
            return;
        }

        DB::statement(sprintf(
            <<<'SQL'
            UPDATE %s
            SET
                %s = COALESCE(%s, %s),
                %s = %s,
                %s = COALESCE(%s, make_date(%s::integer, %s::integer, 1)::timestamp),
                %s = COALESCE(%s, (make_date(%s::integer, %s::integer, 1) + interval '1 month' - interval '1 second')::timestamp)
            SQL,
            $this->quoteIdentifier(self::NEW_TABLE),
            $this->quoteIdentifier('summary_type'),
            $this->quoteIdentifier('summary_type'),
            DB::getPdo()->quote(ConversationSummaryType::ConversationCycle->value),
            $this->quoteIdentifier('trigger_type'),
            DB::getPdo()->quote(ConversationSummaryTriggerType::Monthly->value),
            $this->quoteIdentifier('period_start'),
            $this->quoteIdentifier('period_start'),
            $this->quoteIdentifier('year'),
            $this->quoteIdentifier('month'),
            $this->quoteIdentifier('period_end'),
            $this->quoteIdentifier('period_end'),
            $this->quoteIdentifier('year'),
            $this->quoteIdentifier('month'),
        ));
    }

    private function dropLegacyMonthlyColumns(): void
    {
        $columns = array_values(array_filter(
            ['month', 'year'],
            fn (string $column): bool => Schema::hasColumn(self::NEW_TABLE, $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table(self::NEW_TABLE, function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    private function addConversationSummaryIndexes(): void
    {
        Schema::table(self::NEW_TABLE, function (Blueprint $table): void {
            if (! Schema::hasIndex(self::NEW_TABLE, self::UNIQUE_INDEX, 'unique')) {
                $table->unique(
                    ['user_id', 'summary_type', 'trigger_type', 'period_start', 'period_end'],
                    self::UNIQUE_INDEX,
                );
            }

            if (! Schema::hasIndex(self::NEW_TABLE, self::TYPE_TRIGGER_INDEX)) {
                $table->index(['user_id', 'summary_type', 'trigger_type'], self::TYPE_TRIGGER_INDEX);
            }

            if (! Schema::hasIndex(self::NEW_TABLE, self::PERIOD_INDEX)) {
                $table->index(['user_id', 'period_start', 'period_end'], self::PERIOD_INDEX);
            }
        });
    }

    private function addLegacyMonthlyUniqueIndex(): void
    {
        if (Schema::hasIndex(self::NEW_TABLE, 'summaries_user_id_month_year_unique', 'unique')) {
            return;
        }

        Schema::table(self::NEW_TABLE, function (Blueprint $table): void {
            $table->unique(['user_id', 'month', 'year'], 'summaries_user_id_month_year_unique');
        });
    }

    private function commentConversationSummaryColumns(): void
    {
        $this->commentOnColumn('summary_type', 'Classifies which summary domain this record belongs to.');
        $this->commentOnColumn('trigger_type', 'Identifies which conversation reset trigger produced the summary.');
        $this->commentOnColumn('summary', 'Generated human-readable summary for the conversation cycle.');
        $this->commentOnColumn('stats', 'Structured source statistics used to generate the summary.');
    }

    private function addLegacyMonthlyColumns(): void
    {
        Schema::table(self::NEW_TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::NEW_TABLE, 'month')) {
                $table->unsignedTinyInteger('month')->nullable();
            }

            if (! Schema::hasColumn(self::NEW_TABLE, 'year')) {
                $table->unsignedSmallInteger('year')->nullable();
            }
        });
    }

    private function backfillLegacyMonthAndYear(): void
    {
        DB::statement(sprintf(
            <<<'SQL'
            UPDATE %s
            SET
                %s = COALESCE(%s, EXTRACT(MONTH FROM COALESCE(%s, %s, now()))::integer),
                %s = COALESCE(%s, EXTRACT(YEAR FROM COALESCE(%s, %s, now()))::integer)
            SQL,
            $this->quoteIdentifier(self::NEW_TABLE),
            $this->quoteIdentifier('month'),
            $this->quoteIdentifier('month'),
            $this->quoteIdentifier('period_start'),
            $this->quoteIdentifier('created_at'),
            $this->quoteIdentifier('year'),
            $this->quoteIdentifier('year'),
            $this->quoteIdentifier('period_start'),
            $this->quoteIdentifier('created_at'),
        ));

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s SET NOT NULL',
            $this->quoteIdentifier(self::NEW_TABLE),
            $this->quoteIdentifier('month'),
        ));

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s SET NOT NULL',
            $this->quoteIdentifier(self::NEW_TABLE),
            $this->quoteIdentifier('year'),
        ));
    }

    private function dropConversationCycleColumns(): void
    {
        $columns = array_values(array_filter(
            [
                'summary_type',
                'trigger_type',
                'period_start',
                'period_end',
                'conversation_id',
                'message_count',
                'token_count',
            ],
            fn (string $column): bool => Schema::hasColumn(self::NEW_TABLE, $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table(self::NEW_TABLE, function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    private function renamePostgresArtifactsToNewNames(): void
    {
        $this->renameConstraintIfExists(self::NEW_TABLE, 'summaries_pkey', 'user_conversation_summaries_pkey');
        $this->renameConstraintIfExists(self::NEW_TABLE, 'summaries_user_id_foreign', 'user_conversation_summaries_user_id_foreign');
        $this->renameSequenceIfExists('summaries_id_seq', 'user_conversation_summaries_id_seq');
    }

    private function renamePostgresArtifactsToOldNames(): void
    {
        $this->renameConstraintIfExists(self::OLD_TABLE, 'user_conversation_summaries_pkey', 'summaries_pkey');
        $this->renameConstraintIfExists(self::OLD_TABLE, 'user_conversation_summaries_user_id_foreign', 'summaries_user_id_foreign');
        $this->renameSequenceIfExists('user_conversation_summaries_id_seq', 'summaries_id_seq');
    }

    private function dropUniqueIfExists(string $tableName, string $index): void
    {
        if (! Schema::hasIndex($tableName, $index, 'unique')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($index): void {
            $table->dropUnique($index);
        });
    }

    private function dropIndexIfExists(string $tableName, string $index): void
    {
        if (! Schema::hasIndex($tableName, $index)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }

    private function renameConstraintIfExists(string $table, string $from, string $to): void
    {
        if (! $this->constraintExists($table, $from) || $this->constraintExists($table, $to)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s RENAME CONSTRAINT %s TO %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($from),
            $this->quoteIdentifier($to),
        ));
    }

    private function renameSequenceIfExists(string $from, string $to): void
    {
        if (! $this->relationExists($from) || $this->relationExists($to)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER SEQUENCE %s RENAME TO %s',
            $this->quoteIdentifier($from),
            $this->quoteIdentifier($to),
        ));
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $result = DB::selectOne(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = current_schema()
                    AND t.relname = ?
                    AND c.conname = ?
            ) AS exists
            SQL,
            [$table, $constraint],
        );

        return (bool) $result->exists;
    }

    private function relationExists(string $relation): bool
    {
        $result = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', [$relation]);

        return (bool) $result->exists;
    }

    private function commentOnColumn(string $column, string $comment): void
    {
        if (! Schema::hasColumn(self::NEW_TABLE, $column)) {
            return;
        }

        DB::statement(sprintf(
            'COMMENT ON COLUMN %s.%s IS %s',
            $this->quoteIdentifier(self::NEW_TABLE),
            $this->quoteIdentifier($column),
            DB::getPdo()->quote($comment),
        ));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
