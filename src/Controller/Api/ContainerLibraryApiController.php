<?php

namespace App\Controller\Api;

use App\Repository\ContainerTypeRepository;
use App\Repository\ContainerSizeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api')]
class ContainerLibraryApiController extends AbstractController
{
    public function __construct(
        private ContainerTypeRepository $containerTypeRepository,
        private ContainerSizeRepository $containerSizeRepository,
        private CacheInterface $cache
    ) {}

    #[Route('/container-types/active', name: 'api_container_types_active', methods: ['GET'])]
    public function getActiveContainerTypes(): JsonResponse
    {
        $containerTypes = $this->cache->get('container_types.active', function (ItemInterface $item) {
            $item->expiresAfter(300); // 5 minutes

            $types = $this->containerTypeRepository->findAllActive();

            return array_map(function ($type) {
                return [
                    'id' => $type->getId(),
                    'name' => $type->getName(),
                    'code' => $type->getCode(),
                    'description' => $type->getDescription()
                ];
            }, $types);
        });

        return new JsonResponse(['containerTypes' => $containerTypes]);
    }

    #[Route('/container-sizes/active', name: 'api_container_sizes_active', methods: ['GET'])]
    public function getActiveContainerSizes(): JsonResponse
    {
        $containerSizes = $this->cache->get('container_sizes.active', function (ItemInterface $item) {
            $item->expiresAfter(300); // 5 minutes

            $sizes = $this->containerSizeRepository->findAllActive();

            return array_map(function ($size) {
                return [
                    'id' => $size->getId(),
                    'name' => $size->getName(),
                    'code' => $size->getCode(),
                    'description' => $size->getDescription()
                ];
            }, $sizes);
        });

        return new JsonResponse(['containerSizes' => $containerSizes]);
    }
}
