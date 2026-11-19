<?php
namespace Aio;
use PDO;
use PDOException;

final class DB {
    private static ?PDO $pdo=null;
    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;
        $c=$GLOBALS['config']['db'];
        $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$c['host'],$c['port'],$c['database'],$c['charset']);
        self::$pdo=new PDO($dsn,$c['username'],$c['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_STRINGIFY_FETCHES=>false,
        ]);
        // MySQL 8 enables ONLY_FULL_GROUP_BY by default (Railway); the app's
        // GROUP BY queries were written for MariaDB. Relax it per-session so
        // behaviour matches across both engines.
        try { self::$pdo->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))"); } catch (\Throwable $e) {}
        // Collation pin: literals/params hamesha unicode_ci se compare hon (mix se bachao).
        try { self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (\Throwable $e) {}
        /* V206 — DATABASE KA WAQT = APP KA WAQT.
           App (PHP) Pakistan ka waqt chalata hai; cloud ka MySQL aam taur par
           UTC par hota hai aur connection par koi waqt set nahi hota tha.
           Raat 12 se 5 baje ke beech: account aur kharcha KAL ki tareekh
           (CURDATE) par, bill AAJ ki tareekh par — aaj ki Closing History
           mein account ghayab, aaj ki Sales Summary khali (test se pakka).
           Ab har connection PHP wala offset leta hai, to NOW()/CURDATE()
           aur date() hamesha ek hi din batate hain. Shop PC par pehle se
           dono ek the — wahan kuch nahi badalta. */
        try { self::$pdo->exec("SET time_zone = " . self::$pdo->quote(date('P'))); } catch (\Throwable $e) {}
        return self::$pdo;
    }
    public static function tx(callable $fn): mixed {
        $pdo=self::pdo(); $pdo->beginTransaction();
        try { $r=$fn($pdo); $pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}

// build: V206 build 2026-11-18
