<?php
// 1. Simular la interfaz de caché PSR que exige PhpSpreadsheet
namespace Psr\SimpleCache {
    if (!interface_exists('Psr\SimpleCache\CacheInterface')) {
        interface CacheInterface {
            public function get($key, $default = null);
            public function set($key, $value, $ttl = null);
            public function delete($key);
            public function clear();
            public function getMultiple($keys, $default = null);
            public function setMultiple($values, $ttl = null);
            public function deleteMultiple($keys);
            public function has($key);
        }
    }
}

// 2. Simular la clase Preg de Composer que usa PhpSpreadsheet para validar imágenes
namespace Composer\Pcre {
    if (!class_exists('Composer\Pcre\Preg')) {
        class Preg {
            public static function isMatch($pattern, $subject, &$matches = null, $flags = 0, $offset = 0) {
                return preg_match($pattern, $subject, $matches, $flags, $offset);
            }
            public static function match($pattern, $subject, &$matches = null, $flags = 0, $offset = 0) {
                return preg_match($pattern, $subject, $matches, $flags, $offset);
            }
            public static function matchAll($pattern, $subject, &$matches = null, $flags = 0, $offset = 0) {
                return preg_match_all($pattern, $subject, $matches, $flags, $offset);
            }
            public static function replace($pattern, $replacement, $subject, $limit = -1, &$count = null) {
                return preg_replace($pattern, $replacement, $subject, $limit, $count);
            }
            public static function replaceCallback($pattern, $callback, $subject, $limit = -1, &$count = null) {
                return preg_replace_callback($pattern, $callback, $subject, $limit, $count);
            }
            public static function split($pattern, $subject, $limit = -1, $flags = 0) {
                return preg_split($pattern, $subject, $limit, $flags);
            }
        }
    }
}

// 3. Simular el detector de metarchivos WMF y EMF
namespace PhpOffice\WMF\Reader {
    if (!class_exists('PhpOffice\WMF\Reader\Detector')) {
        class Detector {
            public static function isWMF($filename) {
                return false;
            }
            public static function isEMF($filename) {
                return false;
            }
        }
    }
}

// 4. Autolink manual para las clases de PhpSpreadsheet
namespace {
    spl_autoload_register(function ($class) {
        $prefix = 'PhpOffice\\PhpSpreadsheet\\';
        $base_dir = __DIR__ . '/PhpSpreadsheet/src/PhpSpreadsheet/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });
}