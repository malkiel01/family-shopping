<?php
// includes/upload.php
// העלאת תמונות קבלה בצורה בטוחה

/**
 * שומר תמונת קבלה שהועלתה.
 *
 * הקובץ נשמר תמיד בשם אקראי עם סיומת שנגזרת מסוג התמונה
 * שזוהה בפועל - לא מהשם או מה-Content-Type שהדפדפן שלח.
 * כך אי אפשר להעלות קובץ .php או .htaccess דרך הטופס.
 *
 * @return array{ok: bool, path?: string, message?: string}
 */
function saveReceiptImage(array $file) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'message' => 'העלאת הקובץ נכשלה'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => true, 'path' => null];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'message' => 'הקובץ גדול מדי'];
        default:
            return ['ok' => false, 'message' => 'העלאת הקובץ נכשלה'];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        $maxMb = round(MAX_FILE_SIZE / 1048576, 1);
        return ['ok' => false, 'message' => "הקובץ גדול מדי (מקסימום {$maxMb}MB)"];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'קובץ לא תקין'];
    }

    // זיהוי סוג התמונה מהתוכן עצמו
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return ['ok' => false, 'message' => 'הקובץ אינו תמונה תקינה'];
    }

    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    if (!isset($allowed[$imageInfo[2]])) {
        return ['ok' => false, 'message' => 'סוג תמונה לא נתמך (מותר: JPG, PNG, GIF, WEBP)'];
    }

    $uploadDir = dirname(__DIR__) . '/' . rtrim(UPLOAD_DIR, '/');
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        return ['ok' => false, 'message' => 'לא ניתן ליצור את תיקיית ההעלאות'];
    }

    $filename = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$imageInfo[2]];

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
        return ['ok' => false, 'message' => 'שמירת הקובץ נכשלה'];
    }

    @chmod($uploadDir . '/' . $filename, 0644);

    return ['ok' => true, 'path' => rtrim(UPLOAD_DIR, '/') . '/' . $filename];
}

/**
 * מוחק תמונת קבלה. מתעלם מנתיבים שחורגים מתיקיית ההעלאות.
 */
function deleteReceiptImage($path) {
    if (empty($path)) {
        return;
    }

    $uploadDir = realpath(dirname(__DIR__) . '/' . rtrim(UPLOAD_DIR, '/'));
    $fullPath  = realpath(dirname(__DIR__) . '/' . ltrim($path, '/'));

    if ($uploadDir === false || $fullPath === false) {
        return;
    }

    // ודא שהקובץ באמת יושב בתוך תיקיית ההעלאות
    if (strpos($fullPath, $uploadDir . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }

    @unlink($fullPath);
}
