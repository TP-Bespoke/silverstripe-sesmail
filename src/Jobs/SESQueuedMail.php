<?php

namespace Symbiote\SilverStripeSESMailer\Jobs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\SilverStripeSESMailer\Mail\SESMailer; // Updated namespace for SESMailer
use Exception;
use SilverStripe\Core\Injector\Injector;


if (class_exists(QueuedJob::class)) {
    /**
     * SESQueuedMail
     *
     * @author Stephen McMahon <stephen@symbiote.com.au>
     */
    class SESQueuedMail extends AbstractQueuedJob implements QueuedJob {

        /**
         * @var array
         */
        protected $To;

        /**
         * @var string
         */
        protected $Subject;

        /**
         * @var string
         */
        protected $RawMessageText;


        public function __construct($destinations = [], $subject = '', $rawMessageText = '') {
            // Check if arguments are provided to avoid initialisation errors from QueuedJobService
            if (!$destinations && !$subject && !$rawMessageText) {
                return;
            }
            $this->To = $destinations;
            $this->Subject = $subject;
            $this->RawMessageText = $rawMessageText;
        }

        /**
         * Get the title for this job, displayed in the QueuedJobs admin.
         *
         * @return string
         */
        public function getTitle() {
            return 'Email To: ' . implode(', ', (array) $this->To) . ' Subject: ' . $this->Subject;
        }

        /**
         * Get a unique signature for this job, used for checking duplicate jobs.
         *
         * @return string
         */
        public function getSignature() {
            // Ensure $this->To is treated as an array for implode
            return md5($this->Subject) . ' ' . implode(', ', (array) $this->To);
        }

        /**
         * Define the job type.
         *
         * @return string
         */
        public function getJobType() {
            $this->totalSteps = 1; // It's a single step process

            return QueuedJob::QUEUED;
        }

        /**
         * Process the email sending. We try this only once and break as soon as something goes wrong
         * to avoid sending multiple emails or overwhelming the job queue with slow processes.
         */
        public function process() {
            if ($this->isComplete) {
                return;
            }

            $this->currentStep = 1; // Increment the step (though it's only one step)

            // Detect issues with data
            $isCorrupt = false;
            $to = $this->To;
            $rawMessageText = $this->RawMessageText;

            if (empty($to)) { // Use empty for better check
                $this->addMessage('$this->To should not be empty.');
                $isCorrupt = true;
            }
            if (empty($rawMessageText)) { // Use empty for better check
                $this->addMessage('$this->RawMessageText should not be empty.');
                $isCorrupt = true;
            }

            if ($isCorrupt) {
                // Better error message and code for clarity
                throw new Exception('Corrupted SESQueuedMail job: Missing "To" or "RawMessageText".');
            }

            try {
                /** @var SESMailer $mailer */
                $mailer = Injector::inst()->get(SESMailer::class);
                $response = $mailer->sendSESClient($to, $rawMessageText);

                $this->addMessage('SES Response: '.print_r($response->toArray(), true)); // Ensure response is converted to array for print_r

                // Check for successful response
                if (isset($response['MessageId']) && !empty($response['MessageId']) &&
                    (isset($response['@metadata']['statusCode']) && $response['@metadata']['statusCode'] === 200)) {
                    $this->RawMessageText = 'Email Sent Successfully. Message body deleted'; // Clear sensitive data
                    $this->isComplete = true; // Mark job as complete
                } else {
                    // If response indicates failure but no exception was thrown by SESMailer
                    throw new Exception('SESMailer::sendSESClient() returned an unsuccessful response: ' . json_encode($response->toArray()));
                }
            } catch (Exception $ex) {
                // Log the exception and re-throw to mark the job as failed by QueuedJobs
                $this->addMessage('Error sending email via SES: ' . $ex->getMessage());
                throw $ex; // Re-throw to indicate job failure
            }
        }
    }
}