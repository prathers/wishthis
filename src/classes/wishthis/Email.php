<?php

/**
 * Send an email
 *
 * Emails are built from simple HTML templates in `src/email/` and sent with
 * PHP's `mail()` as multipart/alternative (HTML and plain text). No external
 * service (such as the MJML API) is needed.
 *
 * The sender defaults to `no-reply@<host>`. Define `EMAIL_FROM` in
 * `src/config/config.php` to use an address whose domain has SPF/DKIM set up,
 * which greatly improves deliverability:
 *
 *     \define('EMAIL_FROM', 'no-reply@example.com');
 *     \define('EMAIL_FROM_NAME', 'wishthis'); // optional
 *
 * @author Jay Trees <github.jay@grandel.anonaddy.me>
 */

namespace wishthis;

class Email
{
    private string $html = '';

    /**
     * The sender address: `EMAIL_FROM` if defined, otherwise `no-reply@<host>`.
     */
    public static function getFrom(): string
    {
        if (\defined('EMAIL_FROM')) {
            return EMAIL_FROM;
        }

        $host = \preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');

        return 'no-reply@' . $host;
    }

    public function __construct(
        private string $to,
        private string $subject,
        private string $template,
        private string $part
    ) {
        $contentsTemplate = \file_get_contents(ROOT . '/src/email/' . $this->template . '.html');
        $contentsPart     = \file_get_contents(ROOT . '/src/email/parts/' . $this->part . '.html');

        $this->html = \str_replace('EMAIL_PART', $contentsPart, $contentsTemplate);

        /** Set Locale */
        global $locale;

        $language   = \str_replace('_', '-', $locale ?? DEFAULT_LOCALE);
        $this->html = \str_replace('EMAIL_LANGUAGE', \htmlspecialchars($language), $this->html);
        $this->html = \str_replace('EMAIL_TITLE', \htmlspecialchars($this->subject), $this->html);
    }

    public function setPlaceholder(string $placeholder, string $replacement): void
    {
        /** Leftovers from the MJML templates */
        $replacement = \str_replace(['<mj-raw>', '</mj-raw>'], '', $replacement);

        $this->html = \str_replace($placeholder, $replacement, $this->html);
    }

    public function send(): bool
    {
        $to = \trim($this->to);

        /** Also prevents header injection via the recipient */
        if (false === \filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $from     = self::getFrom();
        $fromName = \defined('EMAIL_FROM_NAME') ? EMAIL_FROM_NAME : 'wishthis';

        /**
         * Email headers must be ASCII. Decode any HTML entities (e. g. from
         * translations) and MIME-encode the subject so characters such as
         * æ, ø and å are displayed correctly.
         */
        $subject = \html_entity_decode($this->subject, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $subject = \mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

        $boundary = 'wishthis-' . \bin2hex(\random_bytes(12));
        $html     = $this->html;
        $text     = $this->getPlainText($html);

        $message  = 'This is a multi-part message in MIME format.' . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $message .= \chunk_split(\base64_encode($text)) . "\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $message .= \chunk_split(\base64_encode($html)) . "\r\n";
        $message .= '--' . $boundary . '--' . "\r\n";

        $headers = [
            'From'         => \mb_encode_mimeheader($fromName, 'UTF-8', 'B') . ' <' . $from . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer'     => 'wishthis',
        ];

        /** Envelope sender (Return-Path), so bounces and SPF use the same address */
        $success = \mail($to, $subject, $message, $headers, '-f' . $from);

        if (!$success) {
            \error_log('wishthis: sending email to ' . $to . ' failed.');
        }

        return $success;
    }

    /**
     * A plain text version of the HTML email.
     */
    private function getPlainText(string $html): string
    {
        $body = \preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $matches) ? $matches[1] : $html;

        /** Links: "Text (https://...)" */
        $body = \preg_replace_callback(
            '/<a\s[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/si',
            function (array $link): string {
                $text = \trim(\strip_tags($link[2]));

                return $text === $link[1] ? $link[1] : $text . ' (' . $link[1] . ')';
            },
            $body
        );

        $body = \preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/tr)[^>]*>/i', "\n", $body);
        $body = \strip_tags($body);
        $body = \html_entity_decode($body, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $body = \preg_replace('/[ \t]+/', ' ', $body);
        $body = \preg_replace('/ *\n */', "\n", $body);
        $body = \preg_replace('/\n{3,}/', "\n\n", $body);

        return \trim($body) . "\n";
    }
}
