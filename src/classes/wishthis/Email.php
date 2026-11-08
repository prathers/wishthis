<?php

/**
 * Send an email
 *
 * Requires MJML input.
 *
 * @see https://mjml.io
 *
 * @author Jay Trees <github.jay@grandel.anonaddy.me>
 */

namespace wishthis;

class Email
{
    private string $mjml = '';
    private string $contentsTemplate;
    private string $contentsPart;

    public function __construct(
        private string $to,
        private string $subject,
        private string $template,
        private string $part
    ) {
        $this->contentsTemplate = \file_get_contents(ROOT . '/src/mjml/' . $this->template . '.mjml');
        $this->contentsPart     = \file_get_contents(ROOT . '/src/mjml/parts/' . $this->part . '.mjml');

        $this->mjml = \str_replace('<mj-include path="MJML_PART" />', $this->contentsPart, $this->contentsTemplate);

        /** Set Locale */
        global $locale;

        $this->mjml = \preg_replace('/<mjml lang="(.+?)">/', '<mjml lang="' . $locale . '">', $this->mjml);
    }

    public function setPlaceholder(string $placeholder, string $replacement): void
    {
        $this->mjml = \str_replace($placeholder, $replacement, $this->mjml);
    }

    public function send(): bool
    {
        global $options;

        $api      = new \Qferrer\Mjml\Http\CurlApi(
            $options->getOption('mjml_api_application_id'),
            $options->getOption('mjml_api_secret_key')
        );
        $renderer = new \Qferrer\Mjml\Renderer\ApiRenderer($api);

        $html = $this->mjml;
        $html = $renderer->render($this->mjml);

        $to      = $this->to;
        /**
         * Email headers must be ASCII. Decode any HTML entities (e. g. from
         * translations) and MIME-encode the subject so characters such as
         * æ, ø and å are displayed correctly.
         */
        $subject = \html_entity_decode($this->subject, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $subject = \mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        $message = $html;
        $headers = [
            'From'         => 'no-reply@' . $_SERVER['HTTP_HOST'],
            'Content-type' => 'text/html; charset=utf-8',
        ];

        $success = \mail($to, $subject, $message, $headers);

        return $success;
    }
}
