<?php
/**
 * Минимальная реализация арифметики эллиптической кривой NIST P-256
 * (secp256r1 / prime256v1) на базе расширения GMP.
 *
 * Зачем это вообще нужно: Web Push (RFC 8291) требует ECDH — согласования
 * общего секрета между эфемерным ключом сервера и публичным ключом
 * подписки браузера (p256dh). Штатная функция для этого — openssl_pkey_derive() —
 * появилась в PHP только начиная с 8.1, а хостинг работает на PHP 7.3.
 * Подписи VAPID (ES256/ECDSA) при этом продолжают использовать штатный
 * openssl_sign()/openssl_pkey_new() — там реализация в PHP есть и для 7.3,
 * ECDH-математика с нуля нужна только для согласования ключа шифрования.
 *
 * Требует расширение PHP GMP (обычно включается на хостинге через
 * панель управления / php.ini одной галочкой, "Select PHP Extensions").
 */

if (!extension_loaded('gmp')) {
    // Не бросаем fatal error на этапе подключения файла — вызывающий код
    // (includes/webpush.php) сам решает, что делать при недоступности GMP.
    define('EC_MATH_GMP_MISSING', true);
} else {
    define('EC_MATH_GMP_MISSING', false);

    // ---- Параметры кривой NIST P-256 (общеизвестные, не секретные) ----
    define('P256_P', gmp_init('ffffffff00000001000000000000000000000000ffffffffffffffffffffffff', 16));
    define('P256_A', gmp_sub(P256_P, gmp_init(3)));
    define('P256_B', gmp_init('5ac635d8aa3a93e7b3ebbd55769886bc651d06b0cc53b0f63bce3c3e27d2604b', 16));
    define('P256_GX', gmp_init('6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296', 16));
    define('P256_GY', gmp_init('4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5', 16));
    define('P256_N', gmp_init('ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551', 16));
}

/**
 * Удвоение точки на кривой (в аффинных координатах).
 * $point — array(gmp $x, gmp $y) или null (точка на бесконечности).
 */
function ec_point_double($point) {
    if ($point === null) {
        return null;
    }
    list($x, $y) = $point;
    $p = P256_P;
    if (gmp_cmp(gmp_mod($y, $p), 0) === 0) {
        return null;
    }
    $num = gmp_mod(gmp_add(gmp_mul(3, gmp_mul($x, $x)), P256_A), $p);
    $den = gmp_invert(gmp_mod(gmp_mul(2, $y), $p), $p);
    $lambda = gmp_mod(gmp_mul($num, $den), $p);
    $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_mul($lambda, $lambda), $x), $x), $p);
    $y3 = gmp_mod(gmp_sub(gmp_mul($lambda, gmp_sub($x, $x3)), $y), $p);
    return array($x3, $y3);
}

/**
 * Сложение двух точек на кривой (в аффинных координатах).
 */
function ec_point_add($p1, $p2) {
    if ($p1 === null) {
        return $p2;
    }
    if ($p2 === null) {
        return $p1;
    }
    $p = P256_P;
    list($x1, $y1) = $p1;
    list($x2, $y2) = $p2;
    if (gmp_cmp($x1, $x2) === 0) {
        if (gmp_cmp(gmp_mod(gmp_add($y1, $y2), $p), 0) === 0) {
            return null;
        }
        return ec_point_double($p1);
    }
    $lambda = gmp_mod(gmp_mul(gmp_sub($y2, $y1), gmp_invert(gmp_mod(gmp_sub($x2, $x1), $p), $p)), $p);
    $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_mul($lambda, $lambda), $x1), $x2), $p);
    $y3 = gmp_mod(gmp_sub(gmp_mul($lambda, gmp_sub($x1, $x3)), $y1), $p);
    return array($x3, $y3);
}

/**
 * Скалярное умножение точки (double-and-add). $k — gmp-число или что-то,
 * что понимает gmp_init/gmp_strval.
 */
function ec_scalar_mul($k, $point) {
    $result = null;
    $addend = $point;
    $bin = gmp_strval($k, 2);
    $len = strlen($bin);
    for ($i = $len - 1; $i >= 0; $i--) {
        if ($bin[$i] === '1') {
            $result = ec_point_add($result, $addend);
        }
        $addend = ec_point_double($addend);
    }
    return $result;
}

/**
 * Проверяет, что точка действительно лежит на кривой P-256
 * (y^2 = x^3 + a*x + b mod p) — защита от невалидных/поддельных
 * публичных ключей подписки (инвалидная точка могла бы использоваться
 * для атак на ECDH).
 */
function ec_point_is_on_curve($point) {
    if ($point === null) {
        return false;
    }
    list($x, $y) = $point;
    $p = P256_P;
    $lhs = gmp_mod(gmp_mul($y, $y), $p);
    $rhs = gmp_mod(gmp_add(gmp_add(gmp_mul(gmp_mul($x, $x), $x), gmp_mul(P256_A, $x)), P256_B), $p);
    return gmp_cmp($lhs, $rhs) === 0;
}

/**
 * Разбирает несжатую точку (0x04 || X(32) || Y(32), как отдаёт браузер
 * в PushSubscription.getKey('p256dh')) в array(gmp $x, gmp $y).
 * Возвращает null, если формат не распознан или точка не на кривой.
 */
function ec_point_from_uncompressed($raw) {
    if (strlen($raw) !== 65 || ord($raw[0]) !== 0x04) {
        return null;
    }
    $x = gmp_init(bin2hex(substr($raw, 1, 32)), 16);
    $y = gmp_init(bin2hex(substr($raw, 33, 32)), 16);
    $point = array($x, $y);
    if (!ec_point_is_on_curve($point)) {
        return null;
    }
    return $point;
}

/**
 * Сериализует точку обратно в несжатый формат (0x04 || X(32) || Y(32)).
 */
function ec_point_to_uncompressed($point) {
    list($x, $y) = $point;
    return "\x04" . ec_gmp_to_fixed_bytes($x, 32) . ec_gmp_to_fixed_bytes($y, 32);
}

/**
 * gmp-число -> бинарная строка фиксированной длины (big-endian, с
 * дополнением нулями слева).
 */
function ec_gmp_to_fixed_bytes($num, $len) {
    $hex = gmp_strval($num, 16);
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    $bytes = hex2bin($hex);
    if (strlen($bytes) < $len) {
        $bytes = str_repeat("\x00", $len - strlen($bytes)) . $bytes;
    } elseif (strlen($bytes) > $len) {
        $bytes = substr($bytes, strlen($bytes) - $len);
    }
    return $bytes;
}
