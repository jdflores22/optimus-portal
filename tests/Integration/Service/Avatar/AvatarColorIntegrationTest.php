<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Avatar;

use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\Avatar\AvatarColorServiceInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for the avatar color system.
 */
class AvatarColorIntegrationTest extends KernelTestCase
{
    private AvatarColorServiceInterface $avatarColorService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->avatarColorService = static::getContainer()->get(AvatarColorServiceInterface::class);
    }

    public function testAvatarColorServiceIsProperlyConfigured(): void
    {
        $this->assertInstanceOf(AvatarColorServiceInterface::class, $this->avatarColorService);
    }

    public function testGenerateColorsForStaffUser(): void
    {
        $user = new StaffUser();
        $user->setEmail('john.doe@example.com');
        $user->setFirstName('John');
        $user->setLastName('Doe');
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setDepartment('IT');

        $result = $this->avatarColorService->getAvatarColors($user);

        $this->assertNotEmpty($result->backgroundClass);
        $this->assertNotEmpty($result->textClass);
        $this->assertStringStartsWith('bg-', $result->backgroundClass);
        $this->assertStringStartsWith('text-', $result->textClass);
        $this->assertGreaterThan(0, $result->contrastRatio);
    }

    public function testGenerateColorsFromIdentifier(): void
    {
        $identifier = 'test@example.com';
        $role = 'SL_STAFF';

        $result = $this->avatarColorService->getAvatarColorsFromIdentifier($identifier, $role);

        $this->assertNotEmpty($result->backgroundClass);
        $this->assertNotEmpty($result->textClass);
        $this->assertStringStartsWith('bg-', $result->backgroundClass);
        $this->assertStringStartsWith('text-', $result->textClass);
    }

    public function testConsistentColorGeneration(): void
    {
        $identifier = 'consistent@example.com';

        $result1 = $this->avatarColorService->getAvatarColorsFromIdentifier($identifier);
        $result2 = $this->avatarColorService->getAvatarColorsFromIdentifier($identifier);

        $this->assertEquals($result1->backgroundClass, $result2->backgroundClass);
        $this->assertEquals($result1->textClass, $result2->textClass);
        $this->assertEquals($result1->contrastRatio, $result2->contrastRatio);
    }

    public function testTwigExtensionIsRegistered(): void
    {
        $twig = static::getContainer()->get('twig');
        
        $this->assertTrue($twig->hasExtension(\App\Twig\AvatarColorExtension::class));
        
        // Test that the function exists
        $functions = $twig->getExtension(\App\Twig\AvatarColorExtension::class)->getFunctions();
        $functionNames = array_map(fn($func) => $func->getName(), $functions);
        
        $this->assertContains('avatar_colors', $functionNames);
    }
}