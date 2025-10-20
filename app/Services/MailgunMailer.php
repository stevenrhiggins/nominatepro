<?php
namespace App\Services;

use App\Contracts\MailerInterface;

final class MailgunMailer implements MailerInterface
{
    private string $domain;
    private string $apiKey;
    private string $endpoint; // api.mailgun.net or api.eu.mailgun.net
    private string $fromEmail;
    private string $fromName;
    private ?string $replyTo;

    public function __construct(private \Base $f3)
    {
        $this->domain   = (string)$f3->get('MAILGUN_DOMAIN');   // e.g. mg.example.com
        $this->apiKey   = (string)$f3->get('MAILGUN_API_KEY');  // key-xxxxxxxx
        $this->region         = 'US';
        $this->endpoint = 'api.mailgun.net';

        $this->fromEmail = 'no-reply@nominatepro.com';
        $this->fromName  = 'NominatePRO';
        $this->replyTo   = $f3->get('MAIL_REPLY_TO') ?: null;

        if (!$this->domain || !$this->apiKey) {
            throw new \InvalidArgumentException('Mailgun config missing: set MAILGUN_DOMAIN and MAILGUN_API_KEY.');
        }
    }

    public function send(string $toEmail, string $subject, string $html, ?string $text = null): void
    {
        $url = "https://{$this->endpoint}/v3/{$this->domain}/messages";

        $data = [
            'from'    => sprintf('"%s" <%s>', addslashes($this->fromName), $this->fromEmail),
            'to'      => $toEmail,
            'subject' => $subject,
            'html'    => $html,
        ];
        if ($text !== null) { $data['text'] = $text; }
        if ($this->replyTo) { $data['h:Reply-To'] = $this->replyTo; }

        // Optional toggles via F3 config
        if ($this->f3->get('MAILGUN_TRACKING') !== null) {
            $data['o:tracking'] = $this->f3->get('MAILGUN_TRACKING') ? 'yes' : 'no';
        }
        if ($this->f3->get('MAILGUN_CLICK_TRACKING') !== null) {
            $data['o:tracking-clicks'] = $this->f3->get('MAILGUN_CLICK_TRACKING') ? 'yes' : 'no';
        }
        if ($tag = $this->f3->get('MAILGUN_TAG')) {
            $data['o:tag'] = $tag; // e.g. "password-reset"
        }
        if ($this->f3->get('MAILGUN_TESTMODE')) {
            $data['o:testmode'] = 'yes'; // doesn’t actually send
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPAUTH      => CURLAUTH_BASIC,
            CURLOPT_USERPWD       => 'api:' . $this->apiKey,
            CURLOPT_RETURNTRANSFER=> true,
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $data, // multipart/form-data
            CURLOPT_TIMEOUT       => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 202) {
            error_log('Mailgun send failed: HTTP ' . $httpCode . ' ' . $response . ($curlErr ? ' cURL: ' . $curlErr : ''));
        }
    }
}
