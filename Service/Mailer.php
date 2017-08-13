<?php

namespace Bvisonl\EmailBundle\Service;

use Bvisonl\EmailBundle\Entity\Email;
use Bvisonl\EmailBundle\Entity\Smtp;
use Doctrine\ORM\EntityManagerInterface;
use Swift_Message;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


class Mailer {

    /** @var ContainerInterface $container */
    private $container;

    /** @var EngineInterface $templating */
    private $templating;

    public function __construct(ContainerInterface $container, EngineInterface $templating) {
        $this->container = $container;
        $this->templating = $templating;
    }

    /**
     * Send an email with a twig template
     *
     * @param $from
     * @param $to
     * @param $bcc
     * @param $subject
     * @param $template
     * @param array $parameters
     * @param null $attachment
     * @return bool
     */
    public function sendFromTemplate($from, $to, $bcc, $subject, $template, $parameters = array(), $attachment = null) {

        // If is a test environment prepend TEST - to the subject
        if($this->container->hasParameter('environment') && $this->container->getParameter('environment') == "test") {
            $subject = "TEST - ".$subject;
        }

        /** @var Swift_Message $message */

        $message = \Swift_Message::newInstance("sendmail -bs")
            ->setSubject($subject)
            ->setFrom($from);

        $html = $this->templating->render($template, $parameters);
        $body = $this->embedBase64Images($message, $html);

        $message->setBody($body, 'text/html');

        $message->setContentType("text/html");

        // Check if we are in development
        if($this->container->hasParameter('environment') && $this->container->getParameter('environment') == "dev") {
            $to = $this->container->hasParameter('event_development_email')  ? $this->container->getParameter('event_development_email') : $to;
        }

        $message->setTo($to);

        if(is_array($bcc)) {
            foreach($bcc as $recipient) {
                $message->addBcc($recipient);
            }
        }

        if($attachment && file_exists($attachment)) {
            $message->attach(\Swift_Attachment::fromPath($attachment));
        }

        if($this->container->hasParameter("app.mailer.master_bcc")) {
            $message->addBcc($this->container->getParameter("app.mailer.master_bcc"));
        }

        try {
            /** @var EntityManagerInterface $em */
            $em = $this->container->get('doctrine')->getManager();

            /** @var Smtp $smtp */
            $smtp = $em->getRepository('BvisonlEmailBundle:Smtp')->findOneBy(array("environment" => $this->container->getParameter('app_env')));

            if (!$smtp) {
                if ($this->container->has('nti.logger')) {
                    $this->container->get('nti.logger')->logError("Unable to find an SMTP configuration for this environment.");
                }
                return false;
            }


            // Create a new temporary spool
            $hash = md5(uniqid(time()));
            $tempSpoolPath = $this->container->getParameter('swiftmailer.spool.default.file.path')."/".$hash."/";
            $tempSpool = new \Swift_FileSpool($tempSpoolPath);

            /** @var \Swift_Mailer $mailer */
            $mailer = \Swift_Mailer::newInstance(\Swift_SpoolTransport::newInstance($tempSpool));

            $transport = $mailer->getTransport();
            $transport->setSpool($tempSpool);

            // Send the email to generate the file
            $mailer->send($message);

            // Read the temporary spool path
            $files = scandir($tempSpoolPath, SORT_ASC);
            if(count($files) <= 0) {
                if($this->container->has('nti.logger')){
                    $this->container->get('nti.logger')->logError("Unable to find file in temporary spool...");
                }
            }
            $filename = null;

            foreach($files as $file) {
                if ($file == "." || $file == "..") continue;
                $filename = $file;
                break;
            }

            // Copy the file
            try {
                copy($tempSpoolPath.$filename, $tempSpoolPath."../".$filename);
            } catch (\Exception $ex) {
                // Log the error and proceed with the process, the check command will take care of moving
                // the file if the $mailer->send() still hasn't created the file
                if($this->container->has('nti.logger')) {
                    $this->container->get('nti.logger')->logException($ex);
                    $this->container->get('nti.logger')->logError("An error occured copying the file $filename to the main spool folder...");
                }
            }

            // Save the email and delete the hash directory
            $em = $this->container->get('doctrine')->getManager();
            $email = new Email();

            $from = (is_array($message->getFrom())) ? join(', ', array_keys($message->getFrom())) : $message->getFrom();
            $recipients = (is_array($message->getTo())) ? join(', ', array_keys($message->getTo())) : $message->getTo();
            $email->setFilename($filename);
            $email->setPath($this->container->getParameter('swiftmailer.spool.default.file.path')."/");
            $email->setHash($hash);
            $email->setMessageFrom($from);
            $email->setMessageTo($recipients);
            $email->setMessageSubject($message->getSubject());
            $email->setMessageBody($message->getBody());
            if(!$filename == null) {
                $email->setFileContent(base64_encode(file_get_contents($tempSpoolPath."/".$filename)));
            } else {
                $email->setStatus(Email::STATUS_FAILURE);
            }


            $em->persist($email);
            $em->flush();

            @unlink($tempSpoolPath."/".$filename);
            @rmdir($tempSpoolPath);

            return true;

        } catch (\Exception $ex) {
            if($this->container->has('nti.logger')) {
                $this->container->get('nti.logger')->logException($ex);
                $this->container->get('nti.logger')->logError("An error occurred sending an email, see above exception for more");
            }
        }
        return false;
    }


    /**
     * Adds the file to the spool folder so that the
     * cron job resends it again.
     *
     * @param Email $email
     * @return $this
     */
    public function resend(Email $email) {
        $path = $this->container->getParameter('swiftmailer.spool.default.file.path');
        if(!fwrite(fopen($path."/".$email->getFilename(), "w+"), base64_decode($email->getFileContent()))) {
            if($this->container->has('nti.logger')) {
                $this->container->get('nti.logger')->logError("An error occurred creating the email file in the spool for resending.");
            }
        }
        $email->setStatus(Email::STATUS_QUEUE);
        $em = $this->container->get('doctrine')->getManager();
        try {
            $em->flush();
        } catch (\Exception $ex) {
            if($this->container->has('nti.logger')) {
                $this->container->get('nti.logger')->logException($ex);
                $this->container->get('nti.logger')->logError("An error occurred changing the status for resending...");
            }
        }

        return $this;
    }

    /**
     * @param Swift_Message $message
     * @param $body
     * @return mixed
     */
    private function embedBase64Images(Swift_Message $message, $body)
    {
        // Temporary directory to save the images
        $tempDir = $this->container->getParameter('kernel.root_dir')."/../web/tmp";
        if(!file_exists($tempDir)) {
            if(!mkdir($tempDir, 0777, true)) {
                throw new FileNotFoundException("Unable to create temporary directory for images.");
            }
        }

        $arrSrc = array();
        if (!empty($body))
        {
            preg_match_all('/<img[^>]+>/i', stripcslashes($body), $imgTags);

            //All img tags
            for ($i=0; $i < count($imgTags[0]); $i++)
            {
                preg_match('/src="([^"]+)/i', $imgTags[0][$i], $withSrc);

                //Remove src
                $withoutSrc = str_ireplace('src="', '', $withSrc[0]);
                $srcContent = $withoutSrc; // Save the previous content to replace with the cid

                //data:image/png;base64,
                if (strpos($withoutSrc, ";base64,"))
                {
                    //data:image/png;base64,.....
                    list($type, $data) = explode(";base64,", $withoutSrc);
                    //data:image/png
                    list($part, $ext) = explode("/", $type);
                    //Paste in temp file
                    $withoutSrc = $tempDir."/".uniqid("temp_").".".$ext;
                    @file_put_contents($withoutSrc, base64_decode($data));
                    $cid = $message->embed((\Swift_Image::fromPath($withoutSrc)));
                    $body = str_replace($srcContent, $cid, $body);
                }

                //Set to array
                $arrSrc[] = $withoutSrc;
            }
        }
        return $body;
    }
}