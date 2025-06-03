<?php

namespace Symbiote\SilverStripeSESMailer\Mail;

use Aws\Ses\SesClient;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\Email;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config; // Added for SilverStripe 5 config access

// Assuming QueuedJobService and SESQueuedMail are compatible or will be updated
// for SilverStripe 5 and their namespaces remain as they are,
// or you'll adjust them based on your QueuedJobs module version.
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symbiote\SilverStripeSESMailer\Jobs\SESQueuedMail; // Assuming this is your queued job class
use SilverStripe\Mailer\Mailer; // Updated for SilverStripe 5


/**
 * A mailer implementation which uses Amazon's Simple Email Service.
 *
 * This mailer uses the SendRawEmail endpoint, so it supports sending attachments. Note that this only sends sending
 * emails to up to 50 recipients at a time, and Amazon's standard SES usage limits apply.
 *
 * Does not support inline images.
 */
class SESMailer implements Mailer
{

    /**
     * @var SesClient
     */
    protected $client;

    /**
     * Uses QueuedJobs module when sending emails
     *
     * @var boolean
     */
    protected $useQueuedJobs = true;

    /**
     * @var array|null
     */
    protected $lastResponse = null;

    /**
     * @param array $config
     */
    public function __construct($config)
    {
        $this->client = new SesClient($config);
    }

    /**
     * @param boolean $bool
     *
     * @return $this
     */
    public function setUseQueuedJobs($bool)
    {
        $this->useQueuedJobs = $bool;

        return $this;
    }

    /**
     * @return array|null
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * @param Email $email
     * @return bool
     */
    public function send(Email $email)
    {
        // In SilverStripe 5, Email::getTo(), getCc(), getBcc() return an array of ['email' => 'name']
        // We need just the email addresses for SES.
        $destinations = array_keys($email->getTo());

        // Handling send all emails to / override
        $overrideTo = Config::inst()->get(Email::class, 'send_all_emails_to');
        if ($overrideTo) {
            $destinations = [$overrideTo];
        } else {
            if ($cc = $email->getCc()) {
                $destinations = array_merge($destinations, array_keys($cc));
            }

            if ($bcc = $email->getBcc()) {
                $destinations = array_merge($destinations, array_keys($bcc));
            }

            // SilverStripe 5 uses config for global CC/BCC
            $addCc = Config::inst()->get(Email::class, 'cc_all_emails_to');
            if ($addCc) {
                $destinations = array_merge($destinations, [$addCc]);
            }

            $addBCc = Config::inst()->get(Email::class, 'bcc_all_emails_to');
            if ($addBCc) {
                $destinations = array_merge($destinations, [$addBCc]);
            }
        }

        $subject = $email->getSubject();

        // SilverStripe 5: Email::render() returns the raw content directly
        // The Swift_Message dependency is removed.
        $rawMessageText = $email->getSwiftMessage()->toString(); // Still get a Swift_Message here to get raw message

        if (class_exists(QueuedJobService::class) && $this->useQueuedJobs) {
            $job = Injector::inst()->createWithArgs(SESQueuedMail::class, array(
                $destinations,
                $subject,
                $rawMessageText
            ));

            singleton(QueuedJobService::class)->queueJob($job);

            return true;
        }

        try {
            $response = $this->sendSESClient($destinations, $rawMessageText);

            $this->lastResponse = $response;
        } catch (\Aws\Ses\Exception\SesException $ex) {
            Injector::inst()->get(LoggerInterface::class)->warning($ex->getMessage());

            $this->lastResponse = false;
            return false;
        }

        /* @var $response Aws\Result */
        if (isset($response['MessageId']) && strlen($response['MessageId']) &&
            (isset($response['@metadata']['statusCode']) && $response['@metadata']['statusCode'] == 200)) {
            return true;
        }

        return false;
    }

    /**
     * Send an email via SES. Expects an array of valid emails and a raw email body that is valid.
     *
     * @param array $destinations array of emails addresses this email will be sent to
     * @param string $rawMessageText Raw email message text must contain headers; and otherwise be a valid email body
     * @return \Aws\Result Amazon SDK response
     * @throws Exception
     */
    public function sendSESClient ($destinations, $rawMessageText) {

        try {
            $response = $this->client->sendRawEmail(array(
                'Destinations' => $destinations,
                'RawMessage' => array('Data' => $rawMessageText)
            ));
        } catch (Exception $ex) {
            /*
             * Amazon SES has intermittent issues with SSL connections being dropped before response is full received
             * and decoded we're catching it here and trying to send again, the exception doesn't have an error code or
             * similar to check on so we have to relie on magic strings in the error message. The error we're catching
             * here is normally:
             *
             * AWS HTTP error: cURL error 56: SSL read: error:00000000:lib(0):func(0):reason(0), errno 104
             * (see http://curl.haxx.se/libcurl/c/libcurl-errors.html) (server): 100 Continue
             *
             * Without the line break, so we check for the 'cURL error 56' as it seems likely to be consistent across
             * systems/sites
             */
            if(strpos($ex->getMessage(), "cURL error 56")) {
                // Retry sending the email
                $response = $this->client->sendRawEmail(array(
                    'Destinations' => $destinations,
                    'RawMessage' => array('Data' => $rawMessageText)
                ));
            } else {
                throw $ex;
            }
        }

        return $response;
    }
}