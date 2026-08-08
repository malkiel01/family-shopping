<?php
/**
 * לקוח SMTP עצמאי
 * includes/Smtp.php
 *
 * האפליקציה שלחה מיילים דרך mail() של PHP, שמוסר את ההודעה
 * ל-sendmail המקומי ומחזיר true. זה לא אומר שמישהו קיבל אותה:
 * מול Gmail הודעות כאלה נוחתות בספאם או נבלעות בשקט, כי אין
 * להן SPF/DKIM שמתאימים לשולח.
 *
 * שליחה דרך שרת ה-SMTP של הדומיין פותרת את זה. ההגדרות כבר היו
 * ב-.env - הן פשוט מעולם לא היו בשימוש, כי המחלקה שנועדה להשתמש
 * בהן דורשת ספריית PHPMailer שאינה מותקנת ואין composer בשרת.
 *
 * לכן זהו מימוש ישיר של הפרוטוקול: מספיק לשליחה, בלי תלויות.
 */

class Smtp
{
    private $host;
    private $port;
    private $secure;    // tls | ssl | ''
    private $username;
    private $password;
    private $timeout;

    private $socket;
    private $lastError = '';

    public function __construct(array $settings)
    {
        $this->host     = $settings['host'] ?? '';
        $this->port     = (int)($settings['port'] ?? 587);
        $this->secure   = strtolower($settings['secure'] ?? 'tls');
        $this->username = $settings['username'] ?? '';

        // סיסמאות אפליקציה של Google מוצגות בארבע קבוצות עם
        // רווחים. הרווחים אינם חלק מהסיסמה.
        $this->password = str_replace(' ', '', $settings['password'] ?? '');
        $this->timeout  = (int)($settings['timeout'] ?? 15);
    }

    public function lastError()
    {
        return $this->lastError;
    }

    /**
     * שולח הודעה אחת.
     *
     * @param array  $from ['email' => ..., 'name' => ...]
     * @param string $to   כתובת הנמען
     *
     * @return bool
     */
    public function send(array $from, $to, $subject, $htmlBody, $textBody = null)
    {
        if ($this->host === '') {
            $this->lastError = 'לא הוגדר שרת SMTP';

            return false;
        }

        try {
            $this->connect();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $from['email'] . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250);
            $this->command('DATA', 354);

            $this->write($this->buildMessage($from, $to, $subject, $htmlBody, $textBody));
            $this->command('.', 250);
            $this->command('QUIT', 221);

            $this->disconnect();

            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('SMTP send failed: ' . $e->getMessage());
            $this->disconnect();

            return false;
        }
    }

    // ============================================================
    // הפרוטוקול
    // ============================================================

    private function connect()
    {
        $transport = ($this->secure === 'ssl') ? 'ssl://' : 'tcp://';

        $this->socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            throw new RuntimeException("חיבור ל-{$this->host}:{$this->port} נכשל: $errstr");
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->expect(220);

        $hostname = $_SERVER['SERVER_NAME'] ?? php_uname('n');
        $this->command('EHLO ' . $hostname, 250);

        // STARTTLS מצפין חיבור שנפתח כטקסט גלוי. אחריו חובה
        // לחזור על EHLO, כי השרת מציג יכולות אחרות בערוץ מוצפן.
        if ($this->secure === 'tls') {
            $this->command('STARTTLS', 220);

            $ok = @stream_socket_enable_crypto(
                $this->socket, true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if (!$ok) {
                throw new RuntimeException('המעבר להצפנה נכשל');
            }

            $this->command('EHLO ' . $hostname, 250);
        }
    }

    private function authenticate()
    {
        if ($this->username === '') {
            return;
        }

        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334);
        $this->command(base64_encode($this->password), 235);
    }

    private function disconnect()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    private function write($data)
    {
        if (fwrite($this->socket, $data) === false) {
            throw new RuntimeException('הכתיבה לשרת נכשלה');
        }
    }

    private function command($command, $expectedCode)
    {
        $this->write($command . "\r\n");
        $this->expect($expectedCode, $command);
    }

    /**
     * קורא תשובה ומוודא את קוד המצב.
     * תשובות SMTP יכולות להשתרע על כמה שורות; המקף אחרי הקוד
     * מסמן שיש המשך.
     */
    private function expect($expectedCode, $context = '')
    {
        $response = '';

        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;

            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);

        if ($code !== $expectedCode) {
            $where = $context !== '' ? " (אחרי $context)" : '';
            throw new RuntimeException(
                "השרת החזיר $code במקום $expectedCode$where: " . trim($response)
            );
        }

        return $response;
    }

    // ============================================================
    // בניית ההודעה
    // ============================================================

    /** מקודד כותרת שאינה ASCII, כדי שעברית תוצג נכון */
    private function encodeHeader($value)
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function buildMessage(array $from, $to, $subject, $htmlBody, $textBody)
    {
        $boundary = 'bnd_' . bin2hex(random_bytes(12));
        $fromName = $this->encodeHeader($from['name'] ?? '');

        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->host . '>',
            'From: ' . ($fromName !== '' ? $fromName . ' ' : '') . '<' . $from['email'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if ($textBody === null) {
            $textBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8'));
        }

        // base64 לגוף ההודעה פותר בבת אחת שלוש בעיות: עברית,
        // שורות ארוכות מדי, ושורה שמתחילה בנקודה - שבפרוטוקול
        // הזה מסמנת סוף הודעה.
        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody), 76, "\r\n")
            . "\r\n--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody), 76, "\r\n")
            . "\r\n--$boundary--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * בונה לקוח מתוך הגדרות ה-.env, או null אם אינן מוגדרות.
     */
    public static function fromEnvironment()
    {
        $host = $_ENV['SMTP_HOST'] ?? '';

        if ($host === '') {
            return null;
        }

        return new self([
            'host'     => $host,
            'port'     => $_ENV['SMTP_PORT'] ?? 587,
            'secure'   => $_ENV['SMTP_SECURE'] ?? 'tls',
            'username' => $_ENV['SMTP_USER'] ?? '',
            'password' => $_ENV['SMTP_PASS'] ?? '',
        ]);
    }
}
