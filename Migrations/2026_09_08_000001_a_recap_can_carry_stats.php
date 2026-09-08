<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * When a recap stopped being just the score.
 *
 * 🚨 A recap is posted the moment a game settles, and the box score does not
 * exist yet — the provider publishes it minutes to hours after the final
 * whistle. Waiting for it would delay the one thing everybody in the thread is
 * waiting for; posting twice would be noise. So the recap is posted at once and
 * rewritten in place when the statistics arrive, and this column is how a pass
 * over resolved threads knows which ones are still waiting.
 *
 * Null means "score only, and still worth checking". A timestamp means the
 * statistics went in, and nothing rewrites it again.
 */
return new class () extends Migration {
    public function up(): void
    {
        $table = $this->db->prefixed('gameday_threads');

        if ($this->hasColumn($table, 'stats_at')) {
            return;
        }

        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `stats_at` DATETIME NULL DEFAULT NULL");
    }

    public function down(): void
    {
        $table = $this->db->prefixed('gameday_threads');

        if ($this->hasColumn($table, 'stats_at')) {
            $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `stats_at`");
        }
    }

    /** Checked rather than assumed, so an upgrade can run this twice. */
    private function hasColumn(string $table, string $column): bool
    {
        foreach ($this->db->select("SHOW COLUMNS FROM `{$table}`") as $row) {
            if (($row['Field'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }
};
