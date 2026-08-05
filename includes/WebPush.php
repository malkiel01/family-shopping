<?php
/**
 * שליחת Web Push אמיתי
 * includes/WebPush.php
 *
 * מימוש עצמאי של שני התקנים שנדרשים כדי ששרת ישלח התראה לדפדפן:
 *
 *   RFC 8291 - הצפנת התוכן (aes128gcm). מפתח ההצפנה נגזר מ-ECDH
 *              בין זוג מפתחות חד-פעמי שלנו לבין המפתח הציבורי של
 *              הדפדפן, יחד עם ה-auth secret של המנוי.
 *   RFC 8292 - זיהוי השולח (VAPID). כותרת Authorization עם JWT
 *              חתום ב-ES256, שמוכיח לשירות ה-Push מי שלח.
 *
 * הכל נשען על openssl ו-hash_hkdf שקיימים בשרת, בלי תלות חיצונית,
 * כי אין composer בסביבת האחסון.
 */

class WebPush
{
    /** תחילית DER קבועה של מפתח ציבורי P-256 בפורמט SubjectPublicKeyInfo */
    const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private $publicKey;   // 65 בתים, נקודה לא דחוסה
    private $privateKey;  // 32 בתים
    private $subject;     // mailto: או כתובת האתר

    public function __construct($publicKeyB64, $privateKeyB64, $subject)
    {
        $this->publicKey  = self::b64UrlDecode($publicKeyB64);
        $this->privateKey = self::b64UrlDecode($privateKeyB64);
        $this->subject    = $subject;

        if (strlen($this->publicKey) !== 65 || strlen($this->privateKey) !== 32) {
            throw new InvalidArgumentException('מפתחות VAPID בפורמט לא תקין');
        }
    }

    /**
     * יוצר זוג מפתחות VAPID חדש.
     *
     * @return array{publicKey: string, privateKey: string} מקודדים ב-base64url
     */
    public static function generateKeys()
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('יצירת מפתחות נכשלה: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        return [
            'publicKey'  => self::b64UrlEncode(self::rawPublicKey($details)),
            'privateKey' => self::b64UrlEncode(str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * שולח התראה אחת למנוי אחד.
     *
     * @param array  $subscription מכיל endpoint, p256dh ו-auth
     * @param string $payload      גוף ההתראה, בדרך כלל JSON
     * @param int    $ttl          כמה שניות שירות ה-Push ישמור אם המכשיר כבוי
     *
     * @return array{ok: bool, status: int, expired: bool, error: string}
     */
    public function send(array $subscription, $payload, $ttl = 86400)
    {
        $result = ['ok' => false, 'status' => 0, 'expired' => false, 'error' => ''];

        try {
            $body = $this->encrypt(
                $payload,
                self::b64UrlDecode($subscription['p256dh']),
                self::b64UrlDecode($subscription['auth'])
            );
        } catch (Exception $e) {
            $result['error'] = 'הצפנה נכשלה: ' . $e->getMessage();

            return $result;
        }

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . (int)$ttl,
            'Urgency: normal',
            'Authorization: ' . $this->authorizationHeader($subscription['endpoint']),
        ];

        $ch = curl_init($subscription['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $result['status'] = $status;

        if ($response === false) {
            $result['error'] = 'שגיאת רשת: ' . $curlErr;

            return $result;
        }

        // 404 ו-410 אומרים שהמנוי כבר לא קיים אצל שירות ה-Push,
        // ואין טעם לנסות אותו שוב לעולם
        if ($status === 404 || $status === 410) {
            $result['expired'] = true;
            $result['error']   = 'המנוי אינו תקף יותר';

            return $result;
        }

        if ($status >= 200 && $status < 300) {
            $result['ok'] = true;

            return $result;
        }

        $result['error'] = 'שירות ה-Push החזיר ' . $status . ': ' . substr((string)$response, 0, 200);

        return $result;
    }

    // ============================================================
    // הצפנת התוכן - RFC 8291
    // ============================================================

    /**
     * @param string $payload     התוכן המקורי
     * @param string $uaPublic    המפתח הציבורי של הדפדפן, 65 בתים
     * @param string $authSecret  ה-auth secret של המנוי, 16 בתים
     */
    private function encrypt($payload, $uaPublic, $authSecret)
    {
        if (strlen($uaPublic) !== 65) {
            throw new InvalidArgumentException('p256dh באורך לא צפוי');
        }

        $salt = random_bytes(16);

        // זוג מפתחות חד-פעמי לכל הודעה
        $local        = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $localDetails = openssl_pkey_get_details($local);
        $asPublic     = self::rawPublicKey($localDetails);

        // סוד משותף מ-ECDH מול המפתח של הדפדפן
        $shared = openssl_pkey_derive(self::publicKeyResource($uaPublic), $local, 32);

        if ($shared === false) {
            throw new RuntimeException('ECDH נכשל: ' . openssl_error_string());
        }

        // ה-info כאן חייב לכלול את שני המפתחות הציבוריים, בסדר הזה
        $ikm = hash_hkdf(
            'sha256',
            $shared,
            32,
            "WebPush: info\0" . $uaPublic . $asPublic,
            $authSecret
        );

        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // 0x02 מסמן שזו הרשומה האחרונה
        $plaintext = $payload . "\x02";
        $tag       = '';
        $cipher    = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($cipher === false) {
            throw new RuntimeException('הצפנת AES נכשלה: ' . openssl_error_string());
        }

        $recordSize = 4096;

        // המבנה: salt | גודל רשומה | אורך המפתח | המפתח | התוכן המוצפן
        return $salt
            . pack('N', $recordSize)
            . chr(strlen($asPublic))
            . $asPublic
            . $cipher . $tag;
    }

    // ============================================================
    // זיהוי השולח - RFC 8292
    // ============================================================

    private function authorizationHeader($endpoint)
    {
        $parts    = parse_url($endpoint);
        $audience = $parts['scheme'] . '://' . $parts['host'];

        $header  = ['typ' => 'JWT', 'alg' => 'ES256'];
        $payload = [
            'aud' => $audience,
            'exp' => time() + 43200, // 12 שעות, המקסימום שהתקן מתיר
            'sub' => $this->subject,
        ];

        $signingInput = self::b64UrlEncode(json_encode($header))
            . '.' . self::b64UrlEncode(json_encode($payload));

        $signature = '';
        $ok = openssl_sign(
            $signingInput,
            $signature,
            self::privateKeyResource($this->privateKey, $this->publicKey),
            OPENSSL_ALGO_SHA256
        );

        if (!$ok) {
            throw new RuntimeException('חתימת JWT נכשלה: ' . openssl_error_string());
        }

        $jwt = $signingInput . '.' . self::b64UrlEncode(self::derSignatureToRaw($signature));

        return 'vapid t=' . $jwt . ', k=' . self::b64UrlEncode($this->publicKey);
    }

    // ============================================================
    // עזרי מפתחות ו-DER
    // ============================================================

    /** בונה נקודה לא דחוסה (0x04 + x + y) מפרטי מפתח של openssl */
    private static function rawPublicKey(array $details)
    {
        return "\x04"
            . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** עוטף נקודה גולמית ל-PEM שאפשר להעביר ל-openssl */
    private static function publicKeyResource($rawPoint)
    {
        $der = hex2bin(self::SPKI_PREFIX) . $rawPoint;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new RuntimeException('מפתח ציבורי לא תקין: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * בונה מפתח פרטי לפי RFC 5915 מתוך הסקלר הגולמי והנקודה הציבורית.
     */
    private static function privateKeyResource($rawPrivate, $rawPublic)
    {
        $hex = '3077'                             // SEQUENCE, 119 בתים
            . '020101'                            // version = 1
            . '0420' . bin2hex($rawPrivate)       // OCTET STRING, 32 בתים
            . 'a00a06082a8648ce3d030107'          // [0] prime256v1
            . 'a14403420004' . bin2hex(substr($rawPublic, 1)); // [1] BIT STRING

        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode(hex2bin($hex)), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";

        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new RuntimeException('מפתח פרטי לא תקין: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * openssl מחזיר חתימה בקידוד DER, ו-JWT דורש 64 בתים גולמיים
     * של r ואחריו s, כל אחד מרופד ל-32 בתים.
     */
    private static function derSignatureToRaw($der)
    {
        $offset = 0;

        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('חתימה בפורמט לא צפוי');
        }

        // דילוג על אורך הרצף, כולל צורת האורך הארוכה
        $lengthByte = ord($der[$offset++]);
        if ($lengthByte > 0x80) {
            $offset += $lengthByte - 0x80;
        }

        $readInteger = function () use ($der, &$offset) {
            if (ord($der[$offset++]) !== 0x02) {
                throw new RuntimeException('חתימה בפורמט לא צפוי');
            }

            $length = ord($der[$offset++]);
            $value  = substr($der, $offset, $length);
            $offset += $length;

            // DER מוסיף בית אפס כדי לשמור על סימן חיובי
            return str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
        };

        return $readInteger() . $readInteger();
    }

    public static function b64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
