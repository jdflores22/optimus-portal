<?php

namespace App\Controller\Api;

use App\Repository\CityRepository;
use App\Repository\RegionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/locations')]
#[IsGranted('ROLE_USER')]
class LocationController extends AbstractController
{
    public function __construct(
        private RegionRepository $regionRepository,
        private CityRepository $cityRepository
    ) {}

    #[Route('/regions', name: 'api_locations_regions', methods: ['GET'])]
    public function getRegions(): JsonResponse
    {
        $regions = $this->regionRepository->findAllOrdered();
        
        $data = array_map(function($region) {
            return [
                'id' => $region->getId(),
                'name' => $region->getName(),
                'code' => $region->getCode()
            ];
        }, $regions);

        return new JsonResponse([
            'success' => true,
            'regions' => $data
        ]);
    }

    #[Route('/cities/{regionId}', name: 'api_locations_cities', methods: ['GET'], requirements: ['regionId' => '\d+'])]
    public function getCitiesByRegion(int $regionId): JsonResponse
    {
        $cities = $this->cityRepository->findByRegionId($regionId);
        
        $data = array_map(function($city) {
            return [
                'id' => $city->getId(),
                'name' => $city->getName(),
                'type' => $city->getType()
            ];
        }, $cities);

        return new JsonResponse([
            'success' => true,
            'cities' => $data
        ]);
    }
}
