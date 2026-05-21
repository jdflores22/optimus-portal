<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserHierarchyAccessControlTest extends WebTestCase
{
    public function testSystemAdminCanAccessStatistics(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        
        // Create a system admin user
        $systemAdmin = $this->createSystemAdmin($entityManager);
        
        // Login as system admin
        $client->loginUser($systemAdmin);
        
        // Access statistics page
        $client->request('GET', '/admin/user-hierarchy/statistics');
        
        // Should be successful
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'User Hierarchy Statistics');
        
        // Clean up
        $this->cleanupTestData($entityManager);
    }

    public function testShippingLineAdminCannotAccessStatistics(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        
        // Create a shipping line admin user
        $shippingLineAdmin = $this->createShippingLineAdmin($entityManager);
        
        // Login as shipping line admin
        $client->loginUser($shippingLineAdmin);
        
        // Try to access statistics page
        $client->request('GET', '/admin/user-hierarchy/statistics');
        
        // Should be forbidden
        $this->assertResponseStatusCodeSame(403);
        
        // Clean up
        $this->cleanupTestData($entityManager);
    }

    private function createSystemAdmin(EntityManagerInterface $entityManager): User
    {
        // Clean up any existing test user
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'sysadmin@test.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

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

        return $systemAdmin;
    }

    private function createShippingLineAdmin(EntityManagerInterface $entityManager): User
    {
        // Clean up any existing test user
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'sladmin@test.com']);
        if ($existingUser) {
            $entityManager->remove($existingUser);
            $entityManager->flush();
        }

        // Create a shipping line first
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');

        $entityManager->persist($shippingLine);

        $shippingLineAdmin = new StaffUser();
        $shippingLineAdmin->setEmail('sladmin@test.com');
        $shippingLineAdmin->setPasswordHash(password_hash('password', PASSWORD_DEFAULT));
        $shippingLineAdmin->setFirstName('Shipping');
        $shippingLineAdmin->setLastName('Admin');
        $shippingLineAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $shippingLineAdmin->setStatus(AccountStatus::APPROVED);
        $shippingLineAdmin->setDepartment('Shipping');
        $shippingLineAdmin->setManagedShippingLine($shippingLine);

        $entityManager->persist($shippingLineAdmin);
        $entityManager->flush();

        return $shippingLineAdmin;
    }

    private function cleanupTestData(EntityManagerInterface $entityManager): void
    {
        // Clean up test data
        $testUsers = $entityManager->getRepository(User::class)->findBy([
            'email' => ['sysadmin@test.com', 'sladmin@test.com']
        ]);
        
        foreach ($testUsers as $user) {
            $entityManager->remove($user);
        }

        $testShippingLines = $entityManager->getRepository(ShippingLine::class)->findBy([
            'brandName' => 'Test Shipping Line'
        ]);
        
        foreach ($testShippingLines as $shippingLine) {
            $entityManager->remove($shippingLine);
        }

        $entityManager->flush();
    }
}