<?php

namespace App\Controller\Api;

use App\Repository\BarangayRepository;
use App\Repository\CityRepository;
use App\Repository\ProvinceRepository;
use App\Repository\RegionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/locations')]
class LocationController extends AbstractController
{
    public function __construct(
        private RegionRepository $regionRepository,
        private ProvinceRepository $provinceRepository,
        private CityRepository $cityRepository,
        private BarangayRepository $barangayRepository
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

    #[Route('/provinces/{regionId}', name: 'api_locations_provinces', methods: ['GET'], requirements: ['regionId' => '\d+'])]
    public function getProvincesByRegion(int $regionId): JsonResponse
    {
        $provinces = $this->provinceRepository->findByRegionId($regionId);

        $data = array_map(static function ($province) {
            return [
                'id' => $province->getId(),
                'name' => $province->getName(),
                'code' => $province->getCode(),
            ];
        }, $provinces);

        return new JsonResponse([
            'success' => true,
            'provinces' => $data,
        ]);
    }

    #[Route('/cities/by-province/{provinceId}', name: 'api_locations_cities_by_province', methods: ['GET'], requirements: ['provinceId' => '\d+'])]
    public function getCitiesByProvince(int $provinceId): JsonResponse
    {
        $cities = $this->cityRepository->findByProvinceId($provinceId);

        $data = array_map(static function ($city) {
            return [
                'id' => $city->getId(),
                'name' => $city->getName(),
                'type' => $city->getType(),
            ];
        }, $cities);

        return new JsonResponse([
            'success' => true,
            'cities' => $data,
        ]);
    }

    #[Route('/barangays/{cityId}', name: 'api_locations_barangays', methods: ['GET'], requirements: ['cityId' => '\d+'])]
    public function getBarangaysByCity(int $cityId): JsonResponse
    {
        $barangays = $this->barangayRepository->findByCityId($cityId);

        $data = array_map(static function ($barangay) {
            return [
                'id' => $barangay->getId(),
                'name' => $barangay->getName(),
                'code' => $barangay->getCode(),
            ];
        }, $barangays);

        return new JsonResponse([
            'success' => true,
            'barangays' => $data,
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
