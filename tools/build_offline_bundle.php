<?php
/**
 * build_offline_bundle.php — SEALED offline build.
 *
 * Maqsad: jo package customer ko jata hai us mein koi bhi PHP source file
 * padhne/badalne laayak na ho. Saara code (src/ + entry points + config)
 * ek encrypted blob `runtime/app.sealed` mein chala jata hai; disk par
 * sirf chhote loader stubs rehte hain.
 *
 * Kaise:
 *   • Har PHP source file ka code ek manifest mein jama hota hai.
 *   • Blob AES-256-GCM se encrypt hota hai (per-package random key).
 *   • Key do hisson mein bat-ti hai: aadha `runtime/app.key` mein,
 *     aadha loader ke andar; dono ka HMAC blob se bandha hota hai —
 *     kisi bhi file ko chhairne par package chalna band ho jata hai.
 *   • Loader includes ko `sealed://` stream wrapper se serve karta hai,
 *     is liye `require` statements waise ke waise kaam karte hain.
 *
 * Note (imandari se): PHP ek interpreted language hai — is tarah ka
 * sealing casual copying/editing ko rok deta hai aur tampering pakar
 * leta hai, magar determined reverse-engineering ke khilaf sirf
 * ionCube/SourceGuardian jaisa commercial encoder hi guarantee deta hai.
 */
declare(strict_types=1);

final class OfflineBundler
{
    /** @return array{files:array<string,string>,key:string,blob:string} */
    public static function build(string $root, array $configArray): array
    {
        $sources = [];

        // 1) saari class/helper files
        foreach (self::walk($root.'/src') as $abs) {
            if (substr($abs, -4) !== '.php') continue;
            $rel = self::rel($root, $abs);
            $sources[$rel] = self::rewritePaths(self::strip(file_get_contents($abs)));
        }
        // 2) entry points + scripts
        foreach (['public', 'scripts'] as $dir) {
            foreach (self::walk($root.'/'.$dir) as $abs) {
                if (substr($abs, -4) !== '.php') continue;
                $sources[self::rel($root, $abs)] = self::rewritePaths(self::strip(file_get_contents($abs)));
            }
        }
        // 3) approved UI (HTML/JS/CSS) — yeh bhi customer ko raw nahi milti;
        //    router inhe seal ke andar se serve karta hai.
        foreach (self::walk($root.'/approved_ui') as $abs) {
            $sources[self::rel($root, $abs)] = file_get_contents($abs);
        }
        // 3b) VERSION — taake offline node apna build jaan sake aur cloud
        //     ke build se compare kar sake (version mismatch = confusion)
        if (\is_file($root . '/VERSION')) {
            $sources['VERSION'] = \file_get_contents($root . '/VERSION');
        }
        // 4) schema / seed SQL — database structure bhi built-in
        foreach (glob($root.'/docs/*.sql') as $abs) {
            $sources['docs/'.basename($abs)] = file_get_contents($abs);
        }
        // 5) config (sync token yahin hai — plaintext disk par nahi jayega)
        $sources['config/offline.php'] = "<?php return ".var_export($configArray, true).";\n";

        $blobPlain = gzdeflate(serialize($sources), 9);

        $key   = random_bytes(32);
        $nonce = random_bytes(12);
        $tag   = '';
        $enc   = openssl_encrypt($blobPlain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        $blob  = "AIOS1".$nonce.$tag.$enc;

        // key split: aadha file mein, aadha loader mein
        $k1 = substr($key, 0, 16);
        $k2 = substr($key, 16);
        $integrity = hash_hmac('sha256', $blob, $key, true);

        return ['blob' => $blob, 'k1' => $k1, 'k2' => $k2, 'integrity' => $integrity, 'count' => count($sources)];
    }

    /** Loader: sealed:// stream wrapper + entry stubs. */
    public static function loader(string $k2Hex, string $integrityHex): string
    {
        return <<<'PHPCODE'
<?php
/**
 * runtime/boot.php — sealed application loader.
 * Yeh package ka wahid readable PHP hai. App ka asal code
 * runtime/app.sealed mein encrypted hai.
 */
declare(strict_types=1);

final class SealedApp
{
    private static array $files = [];
    private static bool $ready = false;

    public static function boot(string $root): void
    {
        if (self::$ready) return;
        if (!defined('APP_ROOT')) define('APP_ROOT', $root);
        $blobPath = $root.'/runtime/app.sealed';
        $keyPath  = $root.'/runtime/app.key';
        if (!is_file($blobPath) || !is_file($keyPath)) {
            http_response_code(500);
            exit('Installation is damaged. Please download the package again.');
        }
        if (!function_exists('openssl_decrypt')) {
            http_response_code(500);
            exit("PHP 'openssl' extension is required but not enabled.\n"
               . "Delete the runtime\\php folder and run INSTALL_OFFLINE.bat again.");
        }
        $blob = file_get_contents($blobPath);
        $k1   = file_get_contents($keyPath);
        $key  = $k1 . hex2bin(SEALED_K2);

        if (!hash_equals(hex2bin(SEALED_INTEGRITY), hash_hmac('sha256', $blob, $key, true))) {
            http_response_code(500);
            exit('Application files have been modified. Please download the package again.');
        }
        if (substr($blob, 0, 5) !== 'AIOS1') { exit('Bad package.'); }
        $nonce = substr($blob, 5, 12);
        $tag   = substr($blob, 17, 16);
        $enc   = substr($blob, 33);
        $plain = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) { exit('The package could not be opened.'); }

        self::$files = unserialize(gzinflate($plain)) ?: [];
        self::$ready = true;

        stream_wrapper_register('sealed', SealedStream::class);
        $GLOBALS['__sealed_files'] = self::$files;
    }

    public static function has(string $rel): bool { return isset(self::$files[$rel]); }

    /** return value propagate hoti hai — PHP dev server ka router `false`
     *  return karke static files khud serve karta hai. */
    public static function run(string $rel)
    {
        if (!isset(self::$files[$rel])) { http_response_code(404); exit('Not found.'); }
        return require 'sealed://'.$rel;
    }
}

final class SealedStream
{
    private string $data = '';
    private int $pos = 0;
    public $context;

    public function stream_open($path, $mode, $options, &$opened): bool
    {
        $rel = substr($path, strlen('sealed://'));
        $f = $GLOBALS['__sealed_files'] ?? [];
        if (!isset($f[$rel])) return false;
        $this->data = $f[$rel];
        $this->pos = 0;
        $opened = $path;
        return true;
    }
    public function stream_read($n): string { $r = substr($this->data, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_write($d): int { return 0; }
    public function stream_tell(): int { return $this->pos; }
    public function stream_eof(): bool { return $this->pos >= strlen($this->data); }
    public function stream_seek($o, $w = SEEK_SET): bool
    {
        $len = strlen($this->data);
        $this->pos = $w === SEEK_CUR ? $this->pos + $o : ($w === SEEK_END ? $len + $o : $o);
        return $this->pos >= 0 && $this->pos <= $len;
    }
    public function stream_stat(): array { return ['size' => strlen($this->data), 'mode' => 0100444]; }
    public function stream_set_option($o, $a1, $a2): bool { return false; }
    public function url_stat($p, $f)
    {
        $rel = substr($p, strlen('sealed://'));
        if (!isset($GLOBALS['__sealed_files'][$rel])) return false;
        return ['size' => strlen($GLOBALS['__sealed_files'][$rel]), 'mode' => 0100444];
    }
}
PHPCODE
        ;
    }

    private static function walk(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $out[] = $f->getPathname();
        return $out;
    }
    private static function rel(string $root, string $abs): string
    {
        return ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
    }
    /**
     * Sealed bundle ke andar `__DIR__` ka matlab `sealed://<dir>` hota hai aur
     * `dirname()` scheme ka double-slash kha jata hai. Is liye project-relative
     * include paths ko seedha `sealed://` par point kar dete hain.
     */
    private static function rewritePaths(string $code): string
    {
        // bootstrap.php: sealed package mein config aur autoload dono seal ke andar
        $code = str_replace(
            "\$configFile = (\$envConfig && is_file(\$envConfig)) ? \$envConfig : (dirname(__DIR__) . '/config/local.php');",
            "\$configFile = 'sealed://config/offline.php';",
            $code
        );
        $code = str_replace(
            "if (!is_file(\$configFile)) {",
            "if (false) {",
            $code
        );
        $code = str_replace(
            "\$sessionDir = dirname(__DIR__) . '/storage/sessions';",
            "\$sessionDir = APP_ROOT . '/storage/sessions';",
            $code
        );
        // autoloader: sealed:// se classes load karo
        $code = str_replace(
            "\$file = __DIR__ . '/' . str_replace('\\\\', '/', \$relative) . '.php';\n    if (is_file(\$file)) require \$file;",
            "\$file = 'sealed://src/' . str_replace('\\\\', '/', \$relative) . '.php';\n    if (isset(\$GLOBALS['__sealed_files'][substr(\$file,9)])) require \$file;",
            $code
        );
        // router.php: static files disk se (js/css/img aur php stubs)
        $code = str_replace("\$static=__DIR__.'/'.\$name;", "\$static=APP_ROOT.'/public/'.\$name;", $code);
        $map = [
            "dirname(__DIR__).'/src/"        => "'sealed://src/",
            "dirname(__DIR__) . '/src/"      => "'sealed://src/",
            "__DIR__.'/../src/"              => "'sealed://src/",
            "__DIR__ . '/../src/"            => "'sealed://src/",
            "__DIR__ . '/helpers.php'"       => "'sealed://src/helpers.php'",
            "__DIR__.'/helpers.php'"         => "'sealed://src/helpers.php'",
            "dirname(__DIR__).'/config/"     => "'sealed://config/",
            "dirname(__DIR__).'/scripts/"    => "'sealed://scripts/",
            "__DIR__.'/../config/"           => "'sealed://config/",
        ];
        $code = strtr($code, $map);
        // data files (schema/seed) asli disk se aati hain
        $code = str_replace("dirname(__DIR__).'/docs/", "'sealed://docs/", $code);
        $code = str_replace("dirname(__DIR__).'/storage/", "APP_ROOT.'/storage/", $code);
        $code = str_replace("dirname(__DIR__).'/approved_ui/", "'sealed://approved_ui/", $code);
        $code = str_replace("dirname(__DIR__).'/public/", "APP_ROOT.'/public/", $code);
        return $code;
    }

    /** comments/whitespace hata kar size aur readability dono kam */
    private static function strip(string $code): string
    {
        if (!function_exists('token_get_all')) return $code;
        try { return php_strip_whitespace_string($code); } catch (\Throwable $e) { return $code; }
    }
}

if (!function_exists('php_strip_whitespace_string')) {
    function php_strip_whitespace_string(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }
}
