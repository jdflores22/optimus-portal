<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(__DIR__.'/.env');

$kernel = new Kernel($_ENV['APP_ENV'], (bool) $_ENV['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();

$em = $container->get('doctrine')->getManager();

echo "=== Adding Pre-Forecast Containers ===\n\n";

try {
    // Get CMA CGM shipping line
    $shippingLine = $em->getRepository(\App\Entity\ShippingLine::class)
        ->findOneBy(['brandName' => 'CMA CGM']);
    
    if (!$shippingLine) {
        echo "❌ CMA CGM shipping line not found\n";
        exit(1);
    }
    
    // Get an allocation
    $allocation = $em->getRepository(\App\Entity\ShippingLineTerminalAllocation::class)
        ->findOneBy(['shippingLine' => $shippingLine]);
    
    if (!$allocation) {
        echo "❌ No allocation found for CMA CGM\n";
        exit(1);
    }
    
    echo "Using allocation ID: {$allocation->getId()}\n";
    echo "Terminal: {$allocation->getTerminal()->getName()}\n\n";
    
    // Get container sizes
    $size20ft = $em->getRepository(\App\Entity\ContainerSize::class)
        ->findOneBy(['name' => '20 Feet']);
    $size40ft = $em->getRepository(\App\Entity\ContainerSize::class)
        ->findOneBy(['name' => '40 Feet']);
    
    // Get container type
    $containerType = $em->getRepository(\App\Entity\ContainerType::class)
        ->findOneBy(['code' => 'HC']);
    
    if (!$containerType) {
        $containerType = $em->getRepository(\App\Entity\ContainerType::class)->findAll()[0];
    }
    
    // Create 5 pre-forecast containers
    $preForecastContainers = [
        ['number' => 'PREFC' . rand(1000000, 9999999), 'size' => $size20ft],
        ['number' => 'PREFC' . rand(1000000, 9999999), 'size' => $size40ft],
        ['number' => 'PREFC' . rand(1000000, 9999999), 'size' => $size20ft],
        ['number' => 'PREFC' . rand(1000000, 9999999), 'size' => $size40ft],
        ['number' => 'PREFC' . rand(1000000, 9999999), 'size' => $size20ft],
    ];
    
    $totalPreForecastTeu = 0;
    
    foreach ($preForecastContainers as $data) {
        $container = new \App\Entity\Container();
        $container->setContainerNumber($data['number']);
        $container->setContainerType($containerType);
        $container->setContainerSize($data['size']);
        $container->setStatus(\App\Entity\Enum\ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setShippingLine($shippingLine);
        $container->setCyAllocation($allocation);
        $container->setAllocationStatus(\App\Entity\Enum\AllocationStatus::PRE_FORECAST);
        $container->setAllocatedAt(new \DateTime());
        
        $em->persist($container);
        
        $teu = $data['size']->getTeuValue();
        $totalPreForecastTeu += $teu;
        
        echo "Created pre-forecast container: {$data['number']} ({$teu} TEU)\n";
    }
    
    $em->flush();
    
    echo "\n✓ Successfully created " . count($preForecastContainers) . " pre-forecast containers\n";
    echo "Total Pre-Forecast TEU: {$totalPreForecastTeu}\n\n";
    
    // Show updated allocation status
    echo "=== Updated Allocation Status ===\n";
    
    $allocatedContainers = $em->getRepository(\App\Entity\Container::class)
        ->createQueryBuilder('c')
        ->where('c.cyAllocation = :allocation')
        ->andWhere('c.allocationStatus = :status')
        ->setParameter('allocation', $allocation)
        ->setParameter('status', \App\Entity\Enum\AllocationStatus::ALLOCATED)
        ->getQuery()
        ->getResult();
    
    $allocatedTeu = 0;
    foreach ($allocatedContainers as $c) {
        $allocatedTeu += $c->getContainerSize()->getTeuValue();
    }
    
    $preForecastContainersDb = $em->getRepository(\App\Entity\Container::class)
        ->createQueryBuilder('c')
        ->where('c.cyAllocation = :allocation')
        ->andWhere('c.allocationStatus = :status')
        ->setParameter('allocation', $allocation)
        ->setParameter('status', \App\Entity\Enum\AllocationStatus::PRE_FORECAST)
        ->getQuery()
        ->getResult();
    
    $preForecastTeu = 0;
    foreach ($preForecastContainersDb as $c) {
        $preForecastTeu += $c->getContainerSize()->getTeuValue();
    }
    
    $totalCapacity = $allocation->getAllocatedCapacity();
    $totalUsed = $allocatedTeu + $preForecastTeu;
    $available = $totalCapacity - $totalUsed;
    
    echo "Total Capacity: {$totalCapacity} TEU\n";
    echo "Allocated (at CY): {$allocatedTeu} TEU\n";
    echo "Pre-Forecast (announced): {$preForecastTeu} TEU\n";
    echo "Total Used: {$totalUsed} TEU\n";
    echo "Available: {$available} TEU\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}
