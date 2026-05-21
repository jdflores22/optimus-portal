<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\Avatar\AvatarColorServiceInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Psr\Log\LoggerInterface;

/**
 * Twig extension providing avatar color generation functions.
 * 
 * This extension makes the avatar_colors() function available in Twig templates
 * for generating dynamic background and text colors for user avatars.
 */
class AvatarColorExtension extends AbstractExtension
{
    public function __construct(
        private readonly AvatarColorServiceInterface $avatarColorService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Register Twig functions provided by this extension.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('avatar_colors', [$this, 'avatarColors']),
        ];
    }

    /**
     * Generate avatar colors for use in templates.
     * 
     * @param User|object|null $user The user to generate colors for (User entity or object with role property)
     * @param array $options Optional parameters for color generation
     * @return string CSS classes for background and text colors
     */
    public function avatarColors($user = null, array $options = []): string
    {
        try {
            if ($user === null) {
                $this->logger->debug('Null user provided to avatar_colors function, returning default colors');
                return 'bg-gray-500 text-white';
            }

            // Handle User entities
            if ($user instanceof User) {
                $result = $this->avatarColorService->getAvatarColors($user, $options);
                $cssClasses = $result->getCssClasses();
                
                $this->logger->debug('Avatar colors generated in Twig extension for User entity', [
                    'user_id' => $user->getId(),
                    'css_classes' => $cssClasses,
                    'options' => $options
                ]);
                
                return $cssClasses;
            }

            // Handle plain objects (like stdClass from mock data)
            if (is_object($user) && isset($user->role)) {
                $roleValue = null;
                
                // Extract role value from different possible structures
                if (is_object($user->role) && isset($user->role->value)) {
                    $roleValue = $user->role->value;
                } elseif (is_string($user->role)) {
                    $roleValue = $user->role;
                }
                
                if ($roleValue) {
                    // Use the identifier-based method with a generic identifier
                    $identifier = $user->email ?? 'unknown_user';
                    $result = $this->avatarColorService->getAvatarColorsFromIdentifier($identifier, $roleValue, $options);
                    $cssClasses = $result->getCssClasses();
                    
                    $this->logger->debug('Avatar colors generated in Twig extension for plain object', [
                        'user_email' => $user->email ?? 'unknown',
                        'role' => $roleValue,
                        'css_classes' => $cssClasses,
                        'options' => $options
                    ]);
                    
                    return $cssClasses;
                }
            }
            
            $this->logger->warning('Invalid user object provided to avatar_colors function', [
                'user_type' => gettype($user),
                'user_class' => is_object($user) ? get_class($user) : 'not_object'
            ]);
            
            return 'bg-gray-500 text-white';
        } catch (\Exception $e) {
            $this->logger->error('Avatar color generation failed in Twig extension', [
                'user_type' => gettype($user),
                'user_class' => is_object($user) ? get_class($user) : 'not_object',
                'options' => $options,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Always return a safe fallback to prevent template rendering errors
            return 'bg-gray-500 text-white';
        }
    }
}