<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        foreach (['vendors', 'stores'] as $table) {
            $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (strtoupper($engine->engine ?? '') !== 'INNODB') {
                throw new LogicException('Registration owner tables require a reviewed InnoDB conversion before release.');
            }
        }
        foreach (['vendor_security_tokens', 'legal_policy_versions', 'vendor_registration_media', 'vendor_registration_consents'] as $table) {
            $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (! $engine) {
                throw new LogicException('Registration schema is incomplete; preserve existing data and inspect migrations.');
            }
            if (strtoupper($engine->engine) !== 'INNODB') {
                DB::statement("ALTER TABLE `$table` ENGINE=InnoDB");
            }
        }
        foreach ([
            ['vendor_security_tokens', 'vendor_id', 'vendors', 'CASCADE'],
            ['vendor_registration_consents', 'vendor_id', 'vendors', 'RESTRICT'],
            ['vendor_registration_consents', 'store_id', 'stores', 'RESTRICT'],
            ['vendor_registration_consents', 'legal_policy_version_id', 'legal_policy_versions', 'RESTRICT'],
        ] as [$table, $column, $parent, $delete]) {
            $key = DB::selectOne('SELECT k.REFERENCED_TABLE_NAME AS parent_table,k.REFERENCED_COLUMN_NAME AS parent_column,r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.COLUMN_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL', [$table, $column]);
            if ($key) {
                if ($key->parent_table !== $parent || $key->parent_column !== 'id' || $key->delete_rule !== $delete) {
                    throw new LogicException('Existing registration foreign key differs from the required preservation contract.');
                }

                continue;
            }
            $orphans = DB::selectOne("SELECT COUNT(*) AS count FROM `$table` c LEFT JOIN `$parent` p ON c.`$column`=p.id WHERE c.`$column` IS NOT NULL AND p.id IS NULL");
            if ((int) $orphans->count !== 0) {
                throw new LogicException('Orphan registration records require preservation-first reconciliation.');
            }
            Schema::table($table, function (Blueprint $t) use ($table, $column, $parent, $delete) {
                $t->foreign($column, $table.'_'.$column.'_foreign')->references('id')->on($parent)->onDelete($delete);
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Transactional registration evidence must be preserved; destructive rollback is disabled.');
    }
};
