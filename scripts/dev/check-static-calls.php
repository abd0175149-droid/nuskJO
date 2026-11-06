<?php

/**
 * فحص تطويري سريع (لا يدخل الاختبارات): كل نداء ثابت Class::method( وself::method( في app/ يشير إلى دالة موجودة،
 * وكل ثابت Class::CONST موجود. يكشف بقايا ما حُذف في إعادة البناء قبل أن يكشفها مسار نادر في الإنتاج.
 *   php scripts/dev/check-static-calls.php   (من جذر المستودع، بعد composer install)
 */
require __DIR__.'/../../vendor/autoload.php';

$root = realpath(__DIR__.'/../../app');
$problems = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $code = file_get_contents($file->getPathname());
    $tokens = token_get_all($code);
    $ns = '';
    $uses = [];
    $class = null;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_NAMESPACE) {
            $ns = '';
            for ($j = $i + 1; $j < $n && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                    $ns .= $tokens[$j][1];
                }
            }
        }
        if ($t[0] === T_USE && $class === null) {
            $name = '';
            $alias = null;
            for ($j = $i + 1; $j < $n && $tokens[$j] !== ';'; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    if (is_array($tokens[$j - 2] ?? null) && $tokens[$j - 2][0] === T_AS) {
                        $alias = $tokens[$j][1];
                    } else {
                        $name .= $tokens[$j][1];
                    }
                }
            }
            $name = ltrim($name, '\\');
            if ($name !== '') {
                $uses[$alias ?? substr($name, strrpos('\\'.$name, '\\'))] = $name;
            }
        }
        if (in_array($t[0], [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true) && $class === null && is_array($tokens[$i + 2] ?? null)) {
            $class = ($ns ? $ns.'\\' : '').$tokens[$i + 2][1];
        }
        if ($t[0] === T_DOUBLE_COLON) {
            $left = $tokens[$i - 1] ?? null;
            $right = $tokens[$i + 1] ?? null;
            $after = $tokens[$i + 2] ?? null;
            if (!is_array($left) || !is_array($right) || $right[0] !== T_STRING) {
                continue;
            }
            $ref = $left[1];
            if (in_array(strtolower($ref), ['parent', 'static'], true)) {
                continue;
            }
            if (strtolower($ref) === 'self') {
                $fqcn = $class;
            } elseif ($left[0] === T_NAME_FULLY_QUALIFIED) {
                $fqcn = ltrim($ref, '\\');
            } elseif ($left[0] === T_NAME_QUALIFIED) {
                $first = explode('\\', $ref)[0];
                $fqcn = isset($uses[$first]) ? $uses[$first].substr($ref, strlen($first)) : ($ns ? $ns.'\\' : '').$ref;
            } elseif ($left[0] === T_STRING) {
                $fqcn = $uses[$ref] ?? (($ns ? $ns.'\\' : '').$ref);
            } else {
                continue;
            }
            if (!$fqcn || !(class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn))) {
                if ($fqcn && str_starts_with($fqcn, 'App\\')) {
                    $problems[] = "{$file->getFilename()}:{$right[2]} class {$fqcn} not found";
                }
                continue;
            }
            $member = $right[1];
            if ($member === 'class') {
                continue;
            }
            $isCall = $after === '(';
            if ($isCall) {
                if (!method_exists($fqcn, $member) && !method_exists($fqcn, '__callStatic') && !str_starts_with($fqcn, 'Illuminate')) {
                    $problems[] = substr($file->getPathname(), strlen($root) + 1).":{$right[2]} {$fqcn}::{$member}() missing";
                }
            } elseif (!(new ReflectionClass($fqcn))->hasConstant($member) && !(enum_exists($fqcn) && defined($fqcn.'::'.$member))
                && !str_starts_with($member, '$')) {
                $problems[] = substr($file->getPathname(), strlen($root) + 1).":{$right[2]} {$fqcn}::{$member} constant missing";
            }
        }
    }
}
echo implode("\n", array_unique($problems)), "\n", count(array_unique($problems)), " problems\n";
