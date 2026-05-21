<?php

namespace App\Service;

use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Trucker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TruckerRegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator
    ) {
    }

    public function registerTrucker(array $data): Trucker
    {
        $trucker = new Trucker();
        
        // Set basic user information
        $trucker->setEmail($data['email']);
        $trucker->setRole(UserRole::TRUCKER);
        $trucker->setStatus(AccountStatus::EMAIL_UNVERIFIED);
        
        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($trucker, $data['password']);
        $trucker->setPasswordHash($hashedPassword);
        
        // Set trucker-specific information
        $trucker->setFirstName($data['firstName']);
        $trucker->setLastName($data['lastName']);
        
        if (isset($data['phoneNumber'])) {
            $trucker->setPhoneNumber($data['phoneNumber']);
        }
        
        if (isset($data['licenseNumber'])) {
            $trucker->setLicenseNumber($data['licenseNumber']);
        }
        
        if (isset($data['companyName'])) {
            $trucker->setCompanyName($data['companyName']);
        }
        
        if (isset($data['truckPlateNumber'])) {
            $trucker->setTruckPlateNumber($data['truckPlateNumber']);
        }
        
        // Validate the trucker entity
        $violations = $this->validator->validate($trucker);
        if (count($violations) > 0) {
            throw new \InvalidArgumentException('Validation failed: ' . (string) $violations);
        }
        
        // Generate email verification token
        $trucker->setEmailVerificationToken(bin2hex(random_bytes(32)));
        $trucker->setEmailVerificationTokenExpiresAt(new \DateTime('+24 hours'));
        
        // Persist to database
        $this->entityManager->persist($trucker);
        $this->entityManager->flush();
        
        return $trucker;
    }

    public function verifyEmail(string $token): bool
    {
        $trucker = $this->entityManager->getRepository(Trucker::class)
            ->findOneBy(['emailVerificationToken' => $token]);
        
        if (!$trucker || !$trucker->isEmailVerificationTokenValid()) {
            return false;
        }
        
        $trucker->setEmailVerifiedAt(new \DateTime());
        $trucker->setEmailVerificationToken(null);
        $trucker->setEmailVerificationTokenExpiresAt(null);
        $trucker->setStatus(AccountStatus::APPROVED);
        
        $this->entityManager->flush();
        
        return true;
    }

    public function resendVerificationEmail(string $email): ?Trucker
    {
        $trucker = $this->entityManager->getRepository(Trucker::class)
            ->findOneBy(['email' => $email]);
        
        if (!$trucker || $trucker->isEmailVerified()) {
            return null;
        }
        
        // Generate new verification token
        $trucker->setEmailVerificationToken(bin2hex(random_bytes(32)));
        $trucker->setEmailVerificationTokenExpiresAt(new \DateTime('+24 hours'));
        
        $this->entityManager->flush();
        
        return $trucker;
    }

    public function updateProfile(Trucker $trucker, array $data): Trucker
    {
        if (isset($data['firstName'])) {
            $trucker->setFirstName($data['firstName']);
        }
        
        if (isset($data['lastName'])) {
            $trucker->setLastName($data['lastName']);
        }
        
        if (isset($data['phoneNumber'])) {
            $trucker->setPhoneNumber($data['phoneNumber']);
        }
        
        if (isset($data['licenseNumber'])) {
            $trucker->setLicenseNumber($data['licenseNumber']);
        }
        
        if (isset($data['companyName'])) {
            $trucker->setCompanyName($data['companyName']);
        }
        
        if (isset($data['truckPlateNumber'])) {
            $trucker->setTruckPlateNumber($data['truckPlateNumber']);
        }
        
        // Validate the updated trucker entity
        $violations = $this->validator->validate($trucker);
        if (count($violations) > 0) {
            throw new \InvalidArgumentException('Validation failed: ' . (string) $violations);
        }
        
        $this->entityManager->flush();
        
        return $trucker;
    }
}