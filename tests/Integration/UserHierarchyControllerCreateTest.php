<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\PendingUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserHierarchyControllerCreateTest extends WebTestCase
{
    public function testCreateUserFormIsAccessible(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Clean up any existing test users first
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'sysadmin@test.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        // Create a system admin user for authentication
        $systemAdmin = new StaffUser();
        $systemAdmin->setEmail('sysadmin@test.com');
        $systemAdmin->setPasswordHash(password_hash('password', PASSWORD_DEFAULT));
        $systemAdmin->setFirstName('System');
        $systemAdmin->setLastName('Admin');
        $systemAdmin->setRole(UserRole::SYSTEM_ADMIN);
        $systemAdmin->setStatus(AccountStatus::APPROVED);
        $systemAdmin->setDepartment('Administration');

        $entityManager->persist($systemAdmin);
        $entityManager->flush();

        // Login as system admin
        $client->loginUser($systemAdmin);

        // Access the create user form
        $crawler = $client->request('GET', '/admin/user-hierarchy/create');

        // Check that the page loads successfully
        $this->assertResponseIsSuccessful();
        
        // Check that the form contains expected elements
        $this->assertSelectorExists('form');
        $this->assertSelectorExists('input[name="email"]');
        $this->assertSelectorExists('input[name="firstName"]');
        $this->assertSelectorExists('input[name="lastName"]');
        $this->assertSelectorExists('select[name="role"]');
        
        // Check that system admin bypass option is present
        $this->assertSelectorExists('input[name="skipEmailNotification"]');
        
        // Check that the password field is present
        $this->assertSelectorExists('input[name="password"]');

        // Clean up
        $entityManager->remove($systemAdmin);
        $entityManager->flush();
    }

    public function testShippingLineAdminDoesNotSeeBypassOption(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Clean up any existing test users first
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'sladmin@test.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        // Create a shipping line admin user for authentication
        $shippingLineAdmin = new StaffUser();
        $shippingLineAdmin->setEmail('sladmin@test.com');
        $shippingLineAdmin->setPasswordHash(password_hash('password', PASSWORD_DEFAULT));
        $shippingLineAdmin->setFirstName('SL');
        $shippingLineAdmin->setLastName('Admin');
        $shippingLineAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $shippingLineAdmin->setStatus(AccountStatus::APPROVED);
        $shippingLineAdmin->setDepartment('Administration');

        $entityManager->persist($shippingLineAdmin);
        $entityManager->flush();

        // Login as shipping line admin
        $client->loginUser($shippingLineAdmin);

        // Access the create user form
        $client->request('GET', '/admin/user-hierarchy/create');

        // Check that the page loads successfully
        $this->assertResponseIsSuccessful();
        
        // Check that system admin bypass option is NOT present for shipping line admins
        $this->assertSelectorNotExists('input[name="skipEmailNotification"]');

        // Clean up
        $entityManager->remove($shippingLineAdmin);
        $entityManager->flush();
    }

    public function testFormValidationWorks(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Clean up any existing test users first
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'sysadmin2@test.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        // Create a system admin user for authentication
        $systemAdmin = new StaffUser();
        $systemAdmin->setEmail('sysadmin2@test.com');
        $systemAdmin->setPasswordHash(password_hash('password', PASSWORD_DEFAULT));
        $systemAdmin->setFirstName('System');
        $systemAdmin->setLastName('Admin');
        $systemAdmin->setRole(UserRole::SYSTEM_ADMIN);
        $systemAdmin->setStatus(AccountStatus::APPROVED);
        $systemAdmin->setDepartment('Administration');

        $entityManager->persist($systemAdmin);
        $entityManager->flush();

        // Login as system admin
        $client->loginUser($systemAdmin);

        // Submit form with missing required fields
        $client->request('POST', '/admin/user-hierarchy/create', [
            'email' => '', // Missing email
            'firstName' => 'Test',
            'lastName' => 'User',
            'role' => 'SL_STAFF'
        ]);

        // Should redirect back to form with error
        $this->assertResponseRedirects('/admin/user-hierarchy/create');

        // Clean up
        $entityManager->remove($systemAdmin);
        $entityManager->flush();
    }
}