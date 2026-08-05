<?php
/**
 * בדיקות להצפנה ולחתימה של Web Push
 * tests/webpush_test.php
 *
 * הבדיקה המרכזית כאן היא פענוח חוזר: אנחנו מצפינים בדיוק כמו
 * שהשרת מצפין, ואז מפענחים מהצד של הדפדפן - עם המפתח הפרטי של
 * המנוי - ומוודאים שקיבלנו בחזרה את התוכן המקורי. כך נתפסות
 * שגיאות בגזירת המפתחות, בסדר הבתים ובמבנה ההודעה.
 *
 * הוכחת התאימות המלאה לתקן היא הצגת ההתראה בדפדפן אמיתי; כאן
 * מתבצעת גם בדיקה שחתימת ה-VAPID מאומתת בהצלחה מול המפתח הציבורי.
 *
 * הרצה: php tests/webpush_test.php
 */

require_once __DIR__ . '/../includes/WebPush.php';

$pass = 0;
$fail = 0;

function check($label, $condition, $detail = '') {
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** קורא למתודה פרטית, כדי לבדוק את ההצפנה בלי לשלוח לרשת */
function callPrivate($object, $method, array $args) {
    $ref = new ReflectionMethod(get_class($object), $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $args);
}

echo "בדיקות Web Push\n";
echo str_repeat('=', 55) . "\n";

// ------------------------------------------------------------
echo "\n1. יצירת מפתחות\n";
// ------------------------------------------------------------
$keys = WebPush::generateKeys();

check('נוצר מפתח ציבורי באורך 65 בתים',
    strlen(WebPush::b64UrlDecode($keys['publicKey'])) === 65);
check('נוצר מפתח פרטי באורך 32 בתים',
    strlen(WebPush::b64UrlDecode($keys['privateKey'])) === 32);
check('המפתח הציבורי הוא נקודה לא דחוסה',
    WebPush::b64UrlDecode($keys['publicKey'])[0] === "\x04");
check('הקידוד הוא base64url בלי ריפוד',
    strpos($keys['publicKey'], '=') === false
    && strpos($keys['publicKey'], '+') === false
    && strpos($keys['publicKey'], '/') === false);

// ------------------------------------------------------------
echo "\n2. מפתחות לא תקינים נדחים\n";
// ------------------------------------------------------------
try {
    new WebPush(WebPush::b64UrlEncode('קצר מדי'), $keys['privateKey'], 'mailto:a@b.c');
    check('מפתח באורך שגוי נדחה', false, 'לא נזרקה שגיאה');
} catch (InvalidArgumentException $e) {
    check('מפתח באורך שגוי נדחה', true);
}

// ------------------------------------------------------------
echo "\n3. פענוח חוזר של התוכן המוצפן\n";
// ------------------------------------------------------------

// "הדפדפן": זוג מפתחות של המנוי, ו-auth secret באורך 16 בתים
$browser        = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$browserDetails = openssl_pkey_get_details($browser);
$browserPublic  = "\x04"
    . str_pad($browserDetails['ec']['x'], 32, "\0", STR_PAD_LEFT)
    . str_pad($browserDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);
$authSecret     = random_bytes(16);

$push    = new WebPush($keys['publicKey'], $keys['privateKey'], 'mailto:test@example.com');
$message = json_encode(['title' => 'הזמנה לאירוע', 'body' => 'מלכיאל הזמין אותך'], JSON_UNESCAPED_UNICODE);

$body = callPrivate($push, 'encrypt', [$message, $browserPublic, $authSecret]);

// פירוק המבנה: salt(16) | recordSize(4) | idLen(1) | serverPublic | ciphertext
$salt         = substr($body, 0, 16);
$recordSize   = unpack('N', substr($body, 16, 4))[1];
$idLength     = ord($body[20]);
$serverPublic = substr($body, 21, $idLength);
$ciphertext   = substr($body, 21 + $idLength);

check('ה-salt באורך 16 בתים', strlen($salt) === 16);
check('גודל הרשומה סביר', $recordSize === 4096, "התקבל: $recordSize");
check('אורך המפתח המצורף הוא 65', $idLength === 65, "התקבל: $idLength");
check('המפתח המצורף הוא נקודה לא דחוסה', $serverPublic[0] === "\x04");
check('גודל הרשומה גדול מהתוכן המוצפן', $recordSize >= strlen($ciphertext));

// פענוח מהצד של הדפדפן, לפי אותם שלבים בכיוון ההפוך
$spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $serverPublic;
$serverPublicPem = openssl_pkey_get_public(
    "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"
);

$shared = openssl_pkey_derive($serverPublicPem, $browser, 32);
check('ECDH מהצד של הדפדפן הצליח', $shared !== false);

$ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $browserPublic . $serverPublic, $authSecret);
$cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

$tag       = substr($ciphertext, -16);
$encrypted = substr($ciphertext, 0, -16);
$decrypted = openssl_decrypt($encrypted, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

check('הפענוח הצליח', $decrypted !== false);
check('הבית האחרון הוא מפריד הרשומה 0x02',
    $decrypted !== false && substr($decrypted, -1) === "\x02");

$recovered = $decrypted !== false ? substr($decrypted, 0, -1) : '';
check('התקבל בחזרה בדיוק התוכן המקורי', $recovered === $message);

$decodedBack = json_decode($recovered, true);
check('העברית נשמרה כמו שצריך',
    is_array($decodedBack) && $decodedBack['title'] === 'הזמנה לאירוע');

// ------------------------------------------------------------
echo "\n4. כל הודעה מקבלת מפתח ו-salt חדשים\n";
// ------------------------------------------------------------
$second       = callPrivate($push, 'encrypt', [$message, $browserPublic, $authSecret]);
$secondSalt   = substr($second, 0, 16);
$secondPublic = substr($second, 21, 65);

check('ה-salt שונה בין הודעות', $salt !== $secondSalt);
check('המפתח החד-פעמי שונה בין הודעות', $serverPublic !== $secondPublic);

// ------------------------------------------------------------
echo "\n5. חתימת VAPID\n";
// ------------------------------------------------------------
$header = callPrivate($push, 'authorizationHeader', ['https://fcm.googleapis.com/fcm/send/abc123']);

check('הכותרת בפורמט vapid', strpos($header, 'vapid t=') === 0);
check('הכותרת כוללת את המפתח הציבורי', strpos($header, ', k=') !== false);

preg_match('/vapid t=([^,]+), k=(.+)$/', $header, $m);
$jwt = $m[1] ?? '';
check('המפתח בכותרת זהה למפתח שהוגדר', ($m[2] ?? '') === $keys['publicKey']);

$segments = explode('.', $jwt);
check('ה-JWT מורכב משלושה חלקים', count($segments) === 3, 'התקבלו: ' . count($segments));

$jwtHeader  = json_decode(WebPush::b64UrlDecode($segments[0]), true);
$jwtPayload = json_decode(WebPush::b64UrlDecode($segments[1]), true);

check('האלגוריתם הוא ES256', ($jwtHeader['alg'] ?? '') === 'ES256');
check('ה-aud הוא המקור של שירות ה-Push בלבד',
    ($jwtPayload['aud'] ?? '') === 'https://fcm.googleapis.com',
    'התקבל: ' . ($jwtPayload['aud'] ?? ''));
check('ה-sub נשמר', ($jwtPayload['sub'] ?? '') === 'mailto:test@example.com');
check('התוקף בעתיד ואינו עולה על 24 שעות',
    ($jwtPayload['exp'] ?? 0) > time() && ($jwtPayload['exp'] ?? 0) <= time() + 86400);

// אימות החתימה עצמה: המרה מ-64 בתים גולמיים חזרה ל-DER
$rawSignature = WebPush::b64UrlDecode($segments[2]);
check('אורך החתימה 64 בתים', strlen($rawSignature) === 64, 'התקבל: ' . strlen($rawSignature));

$toDerInteger = function ($value) {
    $value = ltrim($value, "\0");
    if (ord($value[0]) > 0x7f) {
        $value = "\0" . $value;   // שמירה על סימן חיובי
    }

    return "\x02" . chr(strlen($value)) . $value;
};

$derSignature = $toDerInteger(substr($rawSignature, 0, 32)) . $toDerInteger(substr($rawSignature, 32));
$derSignature = "\x30" . chr(strlen($derSignature)) . $derSignature;

$spkiVapid = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')
    . WebPush::b64UrlDecode($keys['publicKey']);
$vapidPublicPem = openssl_pkey_get_public(
    "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spkiVapid), 64, "\n") . "-----END PUBLIC KEY-----\n"
);

$verified = openssl_verify($segments[0] . '.' . $segments[1], $derSignature, $vapidPublicPem, OPENSSL_ALGO_SHA256);
check('החתימה מאומתת מול המפתח הציבורי', $verified === 1, "openssl_verify החזיר: $verified");

// חתימה על תוכן שונה חייבת להיכשל
$tampered = openssl_verify($segments[0] . '.' . $segments[1] . 'x', $derSignature, $vapidPublicPem, OPENSSL_ALGO_SHA256);
check('שינוי בתוכן פוסל את החתימה', $tampered !== 1);

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";

exit($fail > 0 ? 1 : 0);
