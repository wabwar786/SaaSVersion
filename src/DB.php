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
        return self::$pdo;
    }
    public static function tx(callable $fn): mixed {
        $pdo=self::pdo(); $pdo->beginTransaction();
        try { $r=$fn($pdo); $pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
