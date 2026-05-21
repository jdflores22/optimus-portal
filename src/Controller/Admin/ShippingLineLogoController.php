<?php

namespace App\Controller\Admin;

use App\Entity\ShippingLine;
use App\Service\ShippingLineLogoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/shipping-line/logo')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class ShippingLineLogoController extends AbstractController
{
    public function __construct(
        private ShippingLineLogoService $logoService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Upload logo for a shipping line
     */
    #[Route('/upload/{id}', name: 'app_admin_shipping_line_logo_upload', methods: ['POST'])]
    public function upload(int $id, Request $request): Response
    {
        $shippingLine = $this->entityManager
            ->getRepository(ShippingLine::class)
            ->find($id);
        
        if (!$shippingLine) {
            $this->addFlash('error', 'Shipping line not found.');
            return $this->redirectToRoute('app_admin_shipping_lines');
        }
        
        /** @var UploadedFile|null $logoFile */
        $logoFile = $request->files->get('logo');
        
        if (!$logoFile) {
            $this->addFlash('error', 'No logo file provided.');
            return $this->redirectToRoute('app_admin_shipping_line_edit', ['id' => $id]);
        }
        
        try {
            $this->logoService->uploadLogo($shippingLine, $logoFile);
            $this->addFlash('success', 'Logo uploaded successfully.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Failed to upload logo: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_admin_shipping_line_edit', ['id' => $id]);
    }

    /**
     * Delete logo for a shipping line
     */
    #[Route('/delete/{id}', name: 'app_admin_shipping_line_logo_delete', methods: ['POST'])]
    public function delete(int $id): Response
    {
        $shippingLine = $this->entityManager
            ->getRepository(ShippingLine::class)
            ->find($id);
        
        if (!$shippingLine) {
            $this->addFlash('error', 'Shipping line not found.');
            return $this->redirectToRoute('app_admin_shipping_lines');
        }
        
        try {
            $this->logoService->deleteLogo($shippingLine);
            $this->addFlash('success', 'Logo deleted successfully.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Failed to delete logo: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_admin_shipping_line_edit', ['id' => $id]);
    }
}
