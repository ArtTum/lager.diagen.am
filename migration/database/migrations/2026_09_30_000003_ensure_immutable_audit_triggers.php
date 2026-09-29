<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') throw new RuntimeException('Immutable audit triggers require MySQL/MariaDB.');

        $triggers = [
            'trg_audit_logs_block_update' => "CREATE TRIGGER trg_audit_logs_block_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit records cannot be changed'",
            'trg_audit_logs_block_delete' => "CREATE TRIGGER trg_audit_logs_block_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit records cannot be deleted'",
            'trg_movement_corrections_block_update' => "CREATE TRIGGER trg_movement_corrections_block_update BEFORE UPDATE ON movement_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Movement corrections cannot be changed'",
            'trg_movement_corrections_block_delete' => "CREATE TRIGGER trg_movement_corrections_block_delete BEFORE DELETE ON movement_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Movement corrections cannot be deleted'",
        ];

        foreach ($triggers as $name => $sql) {
            $exists = DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$name]);
            if ((int) ($exists->aggregate ?? 0) === 0) DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Audit immutability triggers are retained during rollback.');
    }
};
