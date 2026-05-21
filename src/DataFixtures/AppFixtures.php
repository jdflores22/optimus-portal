<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // This fixture serves as a main loader that ensures all other fixtures are loaded
        // The actual data loading is done by the individual fixture classes
        
        // All fixtures are loaded through dependencies
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            FormConfigurationFixtures::class,
            ShipmentFixtures::class,
        ];
    }
}