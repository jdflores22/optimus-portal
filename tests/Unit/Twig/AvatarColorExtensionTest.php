<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\Avatar\AvatarColorServiceInterface;
use App\Twig\AvatarColorExtension;
use App\ValueObject\AvatarColorResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\TwigFunction;

/**
 * Unit tests for AvatarColorExtension.
 */
class AvatarColorExtensionTest extends TestCase
{
    private AvatarColorExtension $extension;
    private AvatarColorServiceInterface|MockObject $avatarColorService;

    protected function setUp(): void
    {
        $this->avatarColorService = $this->createMock(AvatarColorServiceInterface::class);
        $this->extension = new AvatarColorExtension($this->avatarColorService);
    }

    public function testGetFunctionsReturnsAvatarColorsFunction(): void
    {
        $functions = $this->extension->getFunctions();
        
        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertEquals('avatar_colors', $functions[0]->getName());
    }

    public function testAvatarColorsWithValidUserReturnsColorClasses(): void
    {
        $user = new StaffUser();
        $user->setEmail('test@example.com');
        $user->setFirstName('John');
        $user->setLastName('Doe');
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setDepartment('IT');

        $colorResult = new AvatarColorResult(
            backgroundClass: 'bg-blue-500',
            textClass: 'text-white',
            backgroundColor: '#3B82F6',
            textColor: '#FFFFFF',
            contrastRatio: 8.59
        );

        $this->avatarColorService
            ->expects($this->once())
            ->method('getAvatarColors')
            ->with($user, [])
            ->willReturn($colorResult);

        $result = $this->extension->avatarColors($user);

        $this->assertEquals('bg-blue-500 text-white', $result);
    }

    public function testAvatarColorsWithNullUserReturnsDefaultColors(): void
    {
        $this->avatarColorService
            ->expects($this->never())
            ->method('getAvatarColors');

        $result = $this->extension->avatarColors(null);

        $this->assertEquals('bg-meta-blue text-white', $result);
    }

    public function testAvatarColorsWithServiceExceptionReturnsDefaultColors(): void
    {
        $user = new StaffUser();
        $user->setEmail('test@example.com');
        $user->setFirstName('John');
        $user->setLastName('Doe');
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setDepartment('IT');

        $this->avatarColorService
            ->expects($this->once())
            ->method('getAvatarColors')
            ->with($user, [])
            ->willThrowException(new \Exception('Service error'));

        $result = $this->extension->avatarColors($user);

        $this->assertEquals('bg-meta-blue text-white', $result);
    }

    public function testAvatarColorsWithOptionsPassesOptionsToService(): void
    {
        $user = new StaffUser();
        $user->setEmail('test@example.com');
        $user->setFirstName('John');
        $user->setLastName('Doe');
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setDepartment('IT');

        $options = ['size' => 'large'];
        
        $colorResult = new AvatarColorResult(
            backgroundClass: 'bg-green-500',
            textClass: 'text-white',
            backgroundColor: '#10B981',
            textColor: '#FFFFFF',
            contrastRatio: 7.25
        );

        $this->avatarColorService
            ->expects($this->once())
            ->method('getAvatarColors')
            ->with($user, $options)
            ->willReturn($colorResult);

        $result = $this->extension->avatarColors($user, $options);

        $this->assertEquals('bg-green-500 text-white', $result);
    }
}