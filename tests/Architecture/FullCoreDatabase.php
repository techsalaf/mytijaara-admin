<?php
namespace Tests\Architecture;

use Illuminate\Support\Facades\DB;

/** Disposable loopback schema. Imports DDL only, never bundled customer/admin data. */
final class FullCoreDatabase
{
    private \PDO $server;
    private string $name;

    public function create(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') throw new \LogicException('Explicit local integration gate required');
        $port = (int) (getenv('ISOLATION_MYSQL_PORT') ?: 3306);
        $password = getenv('ISOLATION_MYSQL_PASSWORD') ?: '';
        $this->server = new \PDO("mysql:host=127.0.0.1;port=$port", 'root', $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->name = 'mytijaara_isolation_'.bin2hex(random_bytes(8));
        $this->server->exec('CREATE DATABASE `'.$this->name.'`');
        config(['database.connections.core_fixture' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => $this->name,
            'username' => 'root', 'password' => $password, 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ], 'database.default' => 'core_fixture']);
        $source = file_get_contents(base_path('installation/backup/database.sql'));
        preg_match_all('/^(?:CREATE TABLE|ALTER TABLE)\s+`[^`]+`.*?;(?=\s|$)/ms', $source, $matches);
        if (count($matches[0]) < 100) throw new \RuntimeException('Incomplete installation DDL');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($matches[0] as $statement) {
            // The bundled MySQL 9 dump uses a collation unavailable in local MariaDB.
            DB::unprepared(str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $statement));
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function destroy(): void
    {
        if (isset($this->name) && preg_match('/^mytijaara_isolation_[a-f0-9]{16}$/D', $this->name)) {
            DB::disconnect('core_fixture');
            $this->server->exec('DROP DATABASE `'.$this->name.'`');
        }
    }
}
