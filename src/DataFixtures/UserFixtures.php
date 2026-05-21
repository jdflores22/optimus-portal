<?php

namespace App\DataFixtures;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\StaffUser;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public const SYSTEM_ADMIN_REFERENCE = 'user-system-admin';
    public const EVALUATOR_REFERENCE = 'user-evaluator';
    public const SHIPPING_LINES_ADMIN_REFERENCE = 'user-shipping-lines-admin';
    public const SL_STAFF_REFERENCE = 'user-sl-staff';
    public const ACCOUNTING_REFERENCE = 'user-accounting';
    public const BROKER_1_REFERENCE = 'user-broker-1';
    public const BROKER_2_REFERENCE = 'user-broker-2';
    public const CONSIGNEE_1_REFERENCE = 'user-consignee-1';
    public const CONSIGNEE_2_REFERENCE = 'user-consignee-2';
    public const CONSIGNEE_3_REFERENCE = 'user-consignee-3';

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Create System Admin user
        $systemAdmin = new StaffUser();
        $systemAdmin->setEmail('admin@optimus.com');
        $systemAdmin->setRole(UserRole::SYSTEM_ADMIN);
        $systemAdmin->setStatus(AccountStatus::APPROVED);
        $systemAdmin->setFirstName('System');
        $systemAdmin->setLastName('Administrator');
        $systemAdmin->setDepartment('IT');
        $systemAdmin->setPasswordHash($this->passwordHasher->hashPassword($systemAdmin, 'admin123'));
        $manager->persist($systemAdmin);
        $this->addReference(self::SYSTEM_ADMIN_REFERENCE, $systemAdmin);

        // Create Evaluator user
        $evaluator = new StaffUser();
        $evaluator->setEmail('evaluator@optimus.com');
        $evaluator->setRole(UserRole::EVALUATOR);
        $evaluator->setStatus(AccountStatus::APPROVED);
        $evaluator->setFirstName('Jane');
        $evaluator->setLastName('Evaluator');
        $evaluator->setDepartment('Compliance');
        $evaluator->setPasswordHash($this->passwordHasher->hashPassword($evaluator, 'eval123'));
        $manager->persist($evaluator);
        $this->addReference(self::EVALUATOR_REFERENCE, $evaluator);

        // Create Shipping Lines Admin user
        $shippingAdmin = new StaffUser();
        $shippingAdmin->setEmail('shipping.admin@optimus.com');
        $shippingAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $shippingAdmin->setStatus(AccountStatus::APPROVED);
        $shippingAdmin->setFirstName('Robert');
        $shippingAdmin->setLastName('Shipping');
        $shippingAdmin->setDepartment('Operations');
        $shippingAdmin->setPasswordHash($this->passwordHasher->hashPassword($shippingAdmin, 'ship123'));
        $manager->persist($shippingAdmin);
        $this->addReference(self::SHIPPING_LINES_ADMIN_REFERENCE, $shippingAdmin);

        // Create SL-Staff user
        $slStaff = new StaffUser();
        $slStaff->setEmail('staff@optimus.com');
        $slStaff->setRole(UserRole::SL_STAFF);
        $slStaff->setStatus(AccountStatus::APPROVED);
        $slStaff->setFirstName('Michael');
        $slStaff->setLastName('Staff');
        $slStaff->setDepartment('Operations');
        $slStaff->setPasswordHash($this->passwordHasher->hashPassword($slStaff, 'staff123'));
        $manager->persist($slStaff);
        $this->addReference(self::SL_STAFF_REFERENCE, $slStaff);

        // Create Accounting user
        $accounting = new StaffUser();
        $accounting->setEmail('accounting@optimus.com');
        $accounting->setRole(UserRole::ACCOUNTING);
        $accounting->setStatus(AccountStatus::APPROVED);
        $accounting->setFirstName('Sarah');
        $accounting->setLastName('Accountant');
        $accounting->setDepartment('Finance');
        $accounting->setPasswordHash($this->passwordHasher->hashPassword($accounting, 'acc123'));
        $manager->persist($accounting);
        $this->addReference(self::ACCOUNTING_REFERENCE, $accounting);

        // Create sample Broker users
        $broker1 = new Broker();
        $broker1->setEmail('broker1@example.com');
        $broker1->setRole(UserRole::BROKER);
        $broker1->setStatus(AccountStatus::APPROVED);
        $broker1->setFullName('John Smith');
        $broker1->setPasswordHash($this->passwordHasher->hashPassword($broker1, 'broker123'));
        $manager->persist($broker1);
        $this->addReference(self::BROKER_1_REFERENCE, $broker1);

        $broker2 = new Broker();
        $broker2->setEmail('broker2@example.com');
        $broker2->setRole(UserRole::BROKER);
        $broker2->setStatus(AccountStatus::APPROVED);
        $broker2->setFullName('Sarah Johnson');
        $broker2->setPasswordHash($this->passwordHasher->hashPassword($broker2, 'broker123'));
        $manager->persist($broker2);
        $this->addReference(self::BROKER_2_REFERENCE, $broker2);

        // Create sample Consignee users
        $consignee1 = new Consignee();
        $consignee1->setEmail('consignee1@example.com');
        $consignee1->setRole(UserRole::CONSIGNEE);
        $consignee1->setStatus(AccountStatus::APPROVED);
        $consignee1->setBusinessName('ABC Import Company');
        $consignee1->setLinkedBroker($broker1);
        $consignee1->setPasswordHash($this->passwordHasher->hashPassword($consignee1, 'consignee123'));
        $manager->persist($consignee1);
        $this->addReference(self::CONSIGNEE_1_REFERENCE, $consignee1);

        $consignee2 = new Consignee();
        $consignee2->setEmail('consignee2@example.com');
        $consignee2->setRole(UserRole::CONSIGNEE);
        $consignee2->setStatus(AccountStatus::APPROVED);
        $consignee2->setBusinessName('XYZ Trading Corp');
        $consignee2->setLinkedBroker($broker1);
        $consignee2->setPasswordHash($this->passwordHasher->hashPassword($consignee2, 'consignee123'));
        $manager->persist($consignee2);
        $this->addReference(self::CONSIGNEE_2_REFERENCE, $consignee2);

        $consignee3 = new Consignee();
        $consignee3->setEmail('consignee3@example.com');
        $consignee3->setRole(UserRole::CONSIGNEE);
        $consignee3->setStatus(AccountStatus::PENDING);
        $consignee3->setBusinessName('DEF Enterprises');
        $consignee3->setLinkedBroker($broker2);
        $consignee3->setPasswordHash($this->passwordHasher->hashPassword($consignee3, 'consignee123'));
        $manager->persist($consignee3);
        $this->addReference(self::CONSIGNEE_3_REFERENCE, $consignee3);

        $manager->flush();
    }
}