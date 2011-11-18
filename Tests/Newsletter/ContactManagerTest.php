<?php

namespace Wowo\Bundle\NewsletterBundle\Tests\Newsletter;

use Wowo\Bundle\NewsletterBundle\Newsletter\Model\ContactManager;
use Wowo\Bundle\NewsletterBundle\Entity\Contact;
use lapistano\ProxyObject\ProxyObject;

class ContactManagerTest extends AbstractManagerBase
{
    public function testFindContactToChooseForMailing()
    {
        $this->assertEquals(array(null => 'john  ()'),
            $this->getContactManager()->findContactToChooseForMailing());
    }

    public function testFindChoosenContactIdForMailing()
    {
        $mock = \Mockery::mock('Symfony\Component\Form\AbstractType',
            array('getData' => array('contacts' => array(2 => 'john'))));

        $this->assertEquals(array(2 => 'john'),
            $this->getContactManager()->findChoosenContactIdForMailing($mock));
    }

    protected function getContactManager()
    {
        return new ContactManager($this->getEmMock(), $this->getContainerMock(), 'aClass');
    }
}
