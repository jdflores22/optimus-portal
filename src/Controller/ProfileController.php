<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private SluggerInterface $slugger
    ) {
    }

    /**
     * Profile Settings Page
     */
    #[Route('/settings', name: 'profile_settings', methods: ['GET', 'POST'])]
    public function profileSettings(Request $request): Response
    {
        $user = $this->getUser();
        
        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            
            if ($action === 'upload_photo') {
                /** @var UploadedFile $photoFile */
                $photoFile = $request->files->get('profile_photo');
                
                if ($photoFile) {
                    // Validate file type
                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($photoFile->getMimeType(), $allowedMimeTypes)) {
                        $this->addFlash('error', 'Please upload a valid image file (JPEG, PNG, GIF, or WebP).');
                        return $this->redirectToRoute('profile_settings');
                    }
                    
                    // Validate file size (max 5MB)
                    if ($photoFile->getSize() > 5 * 1024 * 1024) {
                        $this->addFlash('error', 'File size must be less than 5MB.');
                        return $this->redirectToRoute('profile_settings');
                    }
                    
                    $originalFilename = pathinfo($photoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $this->slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$photoFile->guessExtension();
                    
                    try {
                        // Create role-based uploads directory structure
                        $userRole = strtolower($user->getRole()->value);
                        $uploadsDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/'.$userRole.'/profile-picture';
                        if (!is_dir($uploadsDirectory)) {
                            mkdir($uploadsDirectory, 0755, true);
                        }
                        
                        // Delete old photo if exists
                        if ($user->getProfilePhoto()) {
                            $oldPhotoPath = $uploadsDirectory.'/'.$user->getProfilePhoto();
                            if (file_exists($oldPhotoPath)) {
                                unlink($oldPhotoPath);
                            }
                        }
                        
                        // Create filename with user ID
                        $newFilename = $user->getId().'-'.$safeFilename.'-'.uniqid().'.'.$photoFile->guessExtension();
                        
                        $photoFile->move($uploadsDirectory, $newFilename);
                        
                        $user->setProfilePhoto($newFilename);
                        $this->entityManager->flush();
                        
                        $this->addFlash('success', 'Profile photo updated successfully!');
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Failed to upload photo. Please try again.');
                    }
                }
                
                return $this->redirectToRoute('profile_settings');
            }
            
            if ($action === 'remove_photo') {
                if ($user->getProfilePhoto()) {
                    // Delete the photo file using role-based path
                    $userRole = strtolower($user->getRole()->value);
                    $uploadsDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/'.$userRole.'/profile-picture';
                    $photoPath = $uploadsDirectory.'/'.$user->getProfilePhoto();
                    if (file_exists($photoPath)) {
                        unlink($photoPath);
                    }
                    
                    // Remove from database
                    $user->setProfilePhoto(null);
                    $this->entityManager->flush();
                    
                    $this->addFlash('success', 'Profile photo removed successfully!');
                } else {
                    $this->addFlash('error', 'No profile photo to remove.');
                }
                
                return $this->redirectToRoute('profile_settings');
            }
            
            if ($action === 'save_profile') {
                // Handle photo upload if a file was selected
                /** @var UploadedFile $photoFile */
                $photoFile = $request->files->get('profile_photo');
                
                if ($photoFile) {
                    // Validate file type
                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($photoFile->getMimeType(), $allowedMimeTypes)) {
                        $this->addFlash('error', 'Please upload a valid image file (JPEG, PNG, GIF, or WebP).');
                        return $this->redirectToRoute('profile_settings');
                    }
                    
                    // Validate file size (max 5MB)
                    if ($photoFile->getSize() > 5 * 1024 * 1024) {
                        $this->addFlash('error', 'File size must be less than 5MB.');
                        return $this->redirectToRoute('profile_settings');
                    }
                    
                    $originalFilename = pathinfo($photoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $this->slugger->slug($originalFilename);
                    
                    try {
                        // Create role-based uploads directory structure
                        $userRole = strtolower($user->getRole()->value);
                        $uploadsDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/'.$userRole.'/profile-picture';
                        if (!is_dir($uploadsDirectory)) {
                            mkdir($uploadsDirectory, 0755, true);
                        }
                        
                        // Delete old photo if exists
                        if ($user->getProfilePhoto()) {
                            $oldPhotoPath = $uploadsDirectory.'/'.$user->getProfilePhoto();
                            if (file_exists($oldPhotoPath)) {
                                unlink($oldPhotoPath);
                            }
                        }
                        
                        // Create filename with user ID
                        $newFilename = $user->getId().'-'.$safeFilename.'-'.uniqid().'.'.$photoFile->guessExtension();
                        
                        $photoFile->move($uploadsDirectory, $newFilename);
                        
                        $user->setProfilePhoto($newFilename);
                        $this->entityManager->flush();
                        
                        $this->addFlash('success', 'Profile photo updated successfully!');
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Failed to upload photo. Please try again.');
                    }
                } else {
                    // Handle general profile updates here if needed
                    $this->addFlash('success', 'Profile settings saved successfully!');
                }
                
                return $this->redirectToRoute('profile_settings');
            }
        }

        return $this->render('profile/settings.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * Account Settings Page
     */
    #[Route('/account', name: 'account_settings', methods: ['GET', 'POST'])]
    public function accountSettings(Request $request): Response
    {
        $user = $this->getUser();
        
        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            
            if ($action === 'change_password') {
                $currentPassword = $request->request->get('current_password');
                $newPassword = $request->request->get('new_password');
                $confirmPassword = $request->request->get('confirm_password');
                
                // Validate current password
                if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $this->addFlash('error', 'Current password is incorrect.');
                    return $this->redirectToRoute('account_settings');
                }
                
                // Validate new password
                if ($newPassword !== $confirmPassword) {
                    $this->addFlash('error', 'New passwords do not match.');
                    return $this->redirectToRoute('account_settings');
                }
                
                if (strlen($newPassword) < 8) {
                    $this->addFlash('error', 'Password must be at least 8 characters long.');
                    return $this->redirectToRoute('account_settings');
                }
                
                // Update password
                $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
                $user->setPasswordHash($hashedPassword);
                $this->entityManager->flush();
                
                $this->addFlash('success', 'Password changed successfully!');
                return $this->redirectToRoute('account_settings');
            }
        }

        // Get or create notification preferences
        $preferences = $this->entityManager
            ->getRepository(\App\Entity\NotificationPreferences::class)
            ->findOneBy(['user' => $user]);

        if (!$preferences) {
            $preferences = new \App\Entity\NotificationPreferences();
            $preferences->setUser($user);
            $this->entityManager->persist($preferences);
            $this->entityManager->flush();
        }

        // Get user's active push subscriptions
        $subscriptions = $this->entityManager
            ->getRepository(\App\Entity\PushSubscription::class)
            ->findBy(['user' => $user, 'isActive' => true]);

        return $this->render('profile/account.html.twig', [
            'user' => $user,
            'preferences' => $preferences,
            'subscriptions' => $subscriptions,
            'notificationTypes' => $this->getNotificationTypes(),
        ]);
    }

    private function getNotificationTypes(): array
    {
        return [
            'manifest_payment_required' => 'Manifest Payment Required',
            'manifest_consignee_declared' => 'Manifest Consignee Declared',
            'manifest_access_granted' => 'Manifest Access Granted',
            'noa_generated' => 'NOA Generated',
            'billing_generated' => 'Billing Generated',
            'payment_rejected' => 'Payment Rejected',
            'payment_submitted' => 'Payment Submitted',
            'payment_approved' => 'Payment Approved',
            'payment_validated' => 'Payment Validated',
            'bl_uploaded' => 'Bill of Lading Uploaded',
            'edo_generated' => 'EDO Generated',
        ];
    }

    /**
     * Serve profile photos securely
     */
    #[Route('/photo/{userId}/{filename}', name: 'profile_photo', methods: ['GET'])]
    public function serveProfilePhoto(int $userId, string $filename): Response
    {
        // Get user to determine role-based path
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }
        
        $userRole = strtolower($user->getRole()->value);
        $uploadsDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/'.$userRole.'/profile-picture';
        $filePath = $uploadsDirectory.'/'.$filename;
        
        // Security check: ensure file exists and is in the correct directory
        if (!file_exists($filePath) || !is_file($filePath)) {
            throw $this->createNotFoundException('Photo not found');
        }
        
        // Additional security: ensure the resolved path is within uploads directory
        $realPath = realpath($filePath);
        $realUploadsDir = realpath($uploadsDirectory);
        if (!$realPath || !str_starts_with($realPath, $realUploadsDir)) {
            throw $this->createNotFoundException('Invalid photo path');
        }
        
        // Get mime type
        $mimeType = mime_content_type($filePath);
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            throw $this->createNotFoundException('Invalid file type');
        }
        
        return new Response(
            file_get_contents($filePath),
            200,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'public, max-age=3600',
                'Expires' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT',
            ]
        );
    }
}