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
    private static string $cache = '';

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
        /* ============================================================
           WARM CACHE — offline POS ki sab se bari sust raftaari.

           Pehle HAR request par yeh sab hota tha:
             blob parhna (705 KB) -> HMAC -> AES-256-GCM decrypt ->
             gzinflate -> unserialize (224 files)
           Ek fast Linux machine par bhi 15.5 ms; counter ke aam Windows
           PC par kahin zyada. Aur yeh har request par lagta tha — har
           CSS, JS aur image par bhi, kyunke sab router se guzarti hain.

           Uske upar: `require 'sealed://...'` ko OPcache cache NAHI kar
           sakta, is liye har request par saara PHP dobara compile hota
           tha (~30 ms).

           Ab: pehli dafa decrypt kar ke files runtime/.cache mein
           nikal di jati hain. Us ke baad har request seedha asli file
           require karti hai — OPcache lag jata hai aur decrypt ka
           kharcha SIFAR ho jata hai.

           Package badalte hi stamp badal jata hai aur cache khud
           dobara banti hai.
           ============================================================ */
        $stamp = SEALED_INTEGRITY . '|' . (int)@filesize($blobPath);
        $dir   = self::cacheDir($root);
        if (@file_get_contents($dir.'/.stamp') === $stamp) {
            self::$cache = $dir;
            self::$ready = true;
            stream_wrapper_register('sealed', SealedStream::class);
            return;                      // <- decrypt bilkul nahi hua
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

        /* Warm cache likh do — agli request ko yeh sab dobara nahi karna
           parega. Files asli disk par aati hain, is liye OPcache unhein
           cache kar leta hai (sealed:// stream ko OPcache cache NAHI kar
           sakta — wahi sab se bara kharcha tha). */
        self::warm($root, $stamp);

        stream_wrapper_register('sealed', SealedStream::class);
        $GLOBALS['__sealed_files'] = self::$files;
    }

    private static function rmrf(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $d.'/'.$f;
            is_dir($p) ? self::rmrf($p) : @unlink($p);
        }
        @rmdir($d);
    }

    /** Cache folder — package ke andar, install ke waqt banta hai. */
    private static function cacheDir(string $root): string { return $root.'/runtime/.cache'; }

    /** Sealed files ko ek dafa asli disk par nikal do. */
    private static function warm(string $root, string $stamp): void
    {
        $dir = self::cacheDir($root);
        /* Naya build -> purani cache poori tarah saaf. Warna hataayi hui
           file cache mein reh jati hai aur update ke baad purana code
           chalta rehta hai. */
        if (is_dir($dir) && @file_get_contents($dir.'/.stamp') !== $stamp) self::rmrf($dir);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach (self::$files as $rel => $code) {
            $t = $dir.'/'.$rel;
            $d = dirname($t);
            if (!is_dir($d)) @mkdir($d, 0775, true);
            if (!is_file($t) || filesize($t) !== strlen($code)) @file_put_contents($t, $code);
        }
        @file_put_contents($dir.'/.stamp', $stamp);
        self::$cache = $dir;
    }

    public static function has(string $rel): bool { return self::exists($rel); }

    /** return value propagate hoti hai — PHP dev server ka router `false`
     *  return karke static files khud serve karta hai. */
    public static function run(string $rel)
    {
        /* Asli file se require -> OPcache kaam karta hai. */
        if (self::$cache !== '' && is_file(self::$cache.'/'.$rel)) {
            return require self::$cache.'/'.$rel;
        }
        if (!isset(self::$files[$rel])) { http_response_code(404); exit('Not found.'); }
        return require 'sealed://'.$rel;
    }

    /** Stream wrapper ke liye — cache se ya memory se. */
    public static function read(string $rel): ?string
    {
        if (self::$cache !== '') {
            $f = self::$cache.'/'.$rel;
            if (is_file($f)) return (string)file_get_contents($f);
        }
        return self::$files[$rel] ?? null;
    }
    public static function exists(string $rel): bool
    {
        if (self::$cache !== '' && is_file(self::$cache.'/'.$rel)) return true;
        return isset(self::$files[$rel]);
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
        /* Warm-cache mode mein memory wali list khali hoti hai — content
           SealedApp::read() se aata hai (cache folder se). */
        $d = SealedApp::read($rel);
        if ($d === null) return false;
        $this->data = $d;
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
    public function url_stat($path, $flags)
    {
        $rel = substr($path, strlen('sealed://'));
        $d = SealedApp::read($rel);
        if ($d === null) return false;
        return ['dev'=>0,'ino'=>0,'mode'=>0100444,'nlink'=>1,'uid'=>0,'gid'=>0,'rdev'=>0,
                'size'=>strlen($d),'atime'=>0,'mtime'=>0,'ctime'=>0,'blksize'=>-1,'blocks'=>-1];
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
            "\$file = 'sealed://src/' . str_replace('\\\\', '/', \$relative) . '.php';\n    if (SealedApp::has(substr(\$file,9))) require \$file;",
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
