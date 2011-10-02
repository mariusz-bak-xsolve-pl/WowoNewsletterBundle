<?php

namespace Wowo\Bundle\NewsletterBundle\Newsletter;

use Doctrine\ORM\EntityManager;
use Wowo\Bundle\NewsletterBundle\Entity\Mailing;
use Wowo\Bundle\NewsletterBundle\Exception\InvalidPlaceholderMappingException;

class NewsletterManager implements NewsletterManagerInterface
{
    protected $em;
    protected $contactClass;
    protected $mailer;
    protected $pheanstalk;
    protected $tube;
    protected $sendingTube;
    protected $placeholders;

    public function __construct()
    {
    }

    public function setEntityManager(EntityManager $em)
    {
        $this->em = $em;
    }

    public function setMailer(\Swift_Mailer $mailer)
    {
        $this->mailer = $mailer;
    }

    public function setPheanstalk(\Pheanstalk $pheanstalk)
    {
        $this->pheanstalk = $pheanstalk;
    }

    public function setContactClass($contactClass)
    {
        if (!class_exists($contactClass)) {
            throw new \InvalidArgumentException(sprintf('Class %s doesn\'t exist!', $contactClass));
        }
        $this->contactClass = $contactClass;
    }

    public function setTube($tube)
    {
        $this->tube = $tube;
    }

    public function setPlaceholders(array $placeholders)
    {
        $this->placeholders = $placeholders;
    }

    public function validateDependencies()
    {
        $dependencies = array(
            'em' => $this->em,
            'mailer' => $this->mailer,
            'pheanstalk' => $this->pheanstalk,
            'contactClass' => $this->contactClass,
            'tube' => $this->tube,
            'placeholders' => $this->placeholders,
        );
        foreach ($dependencies as $name => $dependency) {
            if (null == $dependency) {
                throw new \RuntimeException(sprintf('Dependency "%s" is not set or set to null', $name));
            }
        }
    }

    public function putMailingInQueue(Mailing $mailing, array $contactIds)
    {
        if (null == $this->tube) {
            throw new \InvalidArgumentException("Preparation tube unkonwn!");
        }
        if (count($contactIds) == 0) {
            throw new \InvalidArgumentException('No contacs selected, it need to be at least one contact to send mailing');
        }
        $interval = $mailing->getSendDate()->diff(new \DateTime("now"));
        foreach($contactIds as $contactId) {
              $job = new \StdClass();
              $job->contactId = $contactId;
              $job->mailingId = $mailing->getId();
              $job->contactClass = $this->contactClass;
              $this->pheanstalk->useTube($this->tube)->put(json_encode($job), \Pheanstalk::DEFAULT_PRIORITY,
                  $this->convertDataIntervalToSeconds($interval));
        }
    }

    protected function convertDataIntervalToSeconds(\DateInterval $interval)
    {
        return $interval->format("%days") * 24 * 60 * 60 + $interval->format("%h") * 60 * 60 + $interval->format("%i") * 60 + $interval->format("%s");
    }

    protected function buildMessage($mailingId, $contactId, $contactClass)
    {
        $contact = $this->em
            ->getRepository($contactClass)
            ->find($contactId);
        $mailing = $this->em
            ->getRepository('WowoNewsletterBundle:Mailing')
            ->find($mailingId);
        $body = $this->buildMessageBody($contact, $mailing);
        $message = \Swift_Message::newInstance()
            ->setSubject($mailing->getTitle())
            ->setFrom(array($mailing->getSenderEmail() => $mailing->getSenderName() ?: $mailing->getSenderEmail()))
            ->setTo(array($contact->getEmail() => method_exists($contact, "getFullName") ? $contact->getFullName() : $contact->getEmail()))
            ->setBody($body);
        return $message;
    }

    protected function buildMessageBody($contact, Mailing $mailing)
    {
        return $mailing->getBody();
    }

    public function sendMailing($mailingId, $contactId, $contactClass)
    {
        $message = $this->buildMessage($mailingId, $contactId, $contactClass);
        $this->mailer->send($message);
        return $message;
    }

    public function processMailing(\Closure $logger, $verbose)
    {
        $rawJob = $this->pheanstalk->watch($this->tube)->ignore('default')->reserve();
        if ($rawJob) {
            $job = json_decode($rawJob->getData(), false);
            $time = new \DateTime("now");
            $logger(sprintf("<info>[%s]</info> Processing job with contact id <info>%d</info> "
                . " and mailing id <info>%d</info>", $time->format("Y-m-d h:i:s"), $job->contactId, $job->mailingId));
            
            $message = $this->sendMailing($job->mailingId, $job->contactId, $job->contactClass);
            if ($verbose) {
                $logger(sprintf("Sent message:\n%s", $message->toString()));
            }
            $this->pheanstalk->delete($rawJob);
        }
    }

    public function fillPlaceholders($contact, $body)
    {
        if (null == $this->placeholders) {
            throw new \BadMethodCallException('Placeholders mapping ain\'t configured yet');
        }
        if (get_class($contact) != $this->contactClass) {
            throw new \InvalidArgumentException(sprintf('Contact passed to method isn\'t an instance of contactClass (%s != %s)', get_class($contact), $this->contactClass));
        }
        $this->validatePlaceholders();

        return $body;
    }

    /**
     * It looks firstly for properties, then for method (getter)
     * 
     */
    protected function validatePlaceholders()
    {
        $rc = new \ReflectionClass($this->contactClass);
        foreach ($this->placeholders as $placeholder => $source) {
            if ($rc->hasProperty($source)) {
                $rp = new \ReflectionProperty($this->contactClass, $source);
                if (!$rp->isPublic()) {
                    throw new InvalidPlaceholderMappingException(
                        sprintf('A placeholder %s defines source %s as a property, but it isn\'t public visible', $placeholder, $source),
                        InvalidPlaceholderMappingException::NON_PUBLIC_PROPERTY);
                }
            } elseif($rc->hasMethod($source)) {
                $rm = new \ReflectionMethod($this->contactClass, $source);
                if (!$rm->isPublic()) {
                    throw new InvalidPlaceholderMappingException(
                        sprintf('A placeholder %s defines source %s as a method (getter), but it isn\'t public visible', $placeholder, $source),
                        InvalidPlaceholderMappingException::NON_PUBLIC_METHOD);
                }
            } else {
                throw new InvalidPlaceholderMappingException(
                    sprintf('Unable to map placeholder %s with source %s', $placeholder, $source),
                    InvalidPlaceholderMappingException::UNABLE_TO_MAP);
            }
        }
    }
}
