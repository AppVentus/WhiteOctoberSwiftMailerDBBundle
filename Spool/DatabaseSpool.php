<?php

namespace WhiteOctober\SwiftMailerDBBundle\Spool;

use Doctrine\ORM\EntityManager;
use WhiteOctober\SwiftMailerDBBundle\EmailInterface;

class DatabaseSpool extends \Swift_ConfigurableSpool
{
    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var string
     */
    protected $entityClass;
    /**
     * @var sms sender service
     */
    protected $smsSender;

    public function __construct(EntityManager $em, $entityClass, $smsSender = null)
    {
        $this->em = $em;

        $obj = new $entityClass;
        if (!$obj instanceof EmailInterface) {
            throw new \InvalidArgumentException("The entity class '{$entityClass}'' does not extend from EmailInterface");
        }

        $this->entityClass = $entityClass;
        $this->smsSender = $smsSender;
    }

    /**
     * Starts this Spool mechanism.
     */
    public function start()
    {
    }

    /**
     * Stops this Spool mechanism.
     */
    public function stop()
    {
    }

    /**
     * Tests if this Spool mechanism has started.
     *
     * @return boolean
     */
    public function isStarted()
    {
        return true;
    }

    /**
     * Queues a sms.
     *
     * @param $sms The sms to store
     * @return boolean Whether the operation has succeeded
     * @throws \Swift_IoException if the persist fails
     */
    public function queueSms($sms)
    {
        $mailObject = new $this->entityClass;
        $mailObject->setBody($sms['body']);
        $mailObject->setTo($sms['recipient']);
        $mailObject->setType('sms');
        $mailObject->setStatus(EmailInterface::STATUS_READY);
        try {
            $this->em->persist($mailObject);
            $this->em->flush();
        } catch (\Exception $e) {
            throw new \Swift_IoException("Unable to persist object for enqueuing message".$e);
        }

        return true;
    }
    /**
     * Queues a message.
     *
     * @param \Swift_Mime_Message $message The message to store
     * @return boolean Whether the operation has succeeded
     * @throws \Swift_IoException if the persist fails
     */
    public function queueMessage(\Swift_Mime_Message $message)
    {
        $mailObject = new $this->entityClass;
        $mailObject->setMessage($message);
        $mailObject->setStatus(EmailInterface::STATUS_READY);
        try {
            $this->em->persist($mailObject);
            $this->em->flush();
        } catch (\Exception $e) {
            throw new \Swift_IoException("Unable to persist object for enqueuing message".$e);
        }

        return true;
    }

    /**
     * Sends messages using the given transport instance.
     *
     * @param \Swift_Transport $transport         A transport instance
     * @param string[]        &$failedRecipients An array of failures by-reference
     *
     * @return int The number of sent emails
     */
    public function flushQueue(\Swift_Transport $transport, &$failedRecipients = null)
    {
        if (!$transport->isStarted())
        {
            $transport->start();
        }

        $repoClass = $this->em->getRepository($this->entityClass);
        $emails = $repoClass->findBy(array("status" => EmailInterface::STATUS_READY));
        if (!count($emails)) {
            return 0;
        }

        $failedRecipients = (array) $failedRecipients;
        $count = 0;
        $countSms = 0;
        $smsInSpool = false;
        $time = time();
        foreach ($emails as $email) {
            if($email->getType()!='sms'){
                $email->setStatus(EmailInterface::STATUS_PROCESSING);
                $this->em->persist($email);
                $this->em->flush();
                $message = $email->getMessage();
                $count += $transport->send($message, $failedRecipients);
                $email->setStatus(EmailInterface::STATUS_COMPLETE);
                $email->setSendDate(new \DateTime());
                $this->em->persist($email);
                $this->em->flush();
                
                if ($this->getMessageLimit() && $count >= $this->getMessageLimit()) {
                    break;
                }

                if ($this->getTimeLimit() && (time() - $time) >= $this->getTimeLimit()) {
                    break;
                }
            }else{
                $smsInSpool = true;
            }
        }
        if($smsInSpool){

            $this->smsSender->login();
            foreach ($emails as $email) {
                if($email->getType()=='sms'){
                    $recipient = $email->getTo();
                    $recipient = preg_replace("[^0-9]","",$recipient);
                    if(preg_match('`^0[67][0-9]{8}$`',$recipient)){
                        $recipient = "+33".substr($recipient, 1);
                        $this->smsSender->sendMessage($recipient, $email->getBody(), array());
                        $countSms++;
                        $email->setStatus(EmailInterface::STATUS_COMPLETE);
                        $email->setSendDate(new \DateTime());
                        $this->em->persist($email);
                        $this->em->flush();
                    }
                }
                if ($this->getMessageLimit() && $countSms >= $this->getMessageLimit()) {
                    break;
                }
            }
            $this->smsSender->logout();
        }
        return $count+$countSms;
    }
}
