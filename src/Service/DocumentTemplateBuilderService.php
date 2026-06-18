<?php

namespace App\Service;

use App\Entity\DocumentTemplateConfiguration;
use App\Entity\Enum\DocumentTemplateType;
use App\Entity\Enum\FormStatus;
use App\Entity\User;
use App\Form\DocumentBlockTypes;
use Doctrine\ORM\EntityManagerInterface;

class DocumentTemplateBuilderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function createTemplate(string $name, DocumentTemplateType $type, ?User $createdBy = null): DocumentTemplateConfiguration
    {
        $template = new DocumentTemplateConfiguration();
        $template->setName($name);
        $template->setDocumentType($type);
        $template->setStatus(FormStatus::DRAFT);
        $template->setVersion(1);
        $template->setLayout(DocumentBlockTypes::defaultLayout($type));
        $template->setCreatedBy($createdBy);

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    public function updateLayout(int $templateId, array $layout): void
    {
        $template = $this->findTemplate($templateId);
        if (isset($layout['elements']) && is_array($layout['elements'])) {
            foreach ($layout['elements'] as $index => $element) {
                if (is_array($element)) {
                    $layout['elements'][$index] = DocumentBlockTypes::normalizeTableElement($element);
                }
            }
        }
        $template->setLayout($layout);

        $canvas = $layout['canvas'] ?? [];
        if (!empty($canvas['paperSize']) && is_string($canvas['paperSize'])) {
            $template->setPaperSize($canvas['paperSize']);
        }
        if (!empty($canvas['orientation']) && is_string($canvas['orientation'])) {
            $template->setOrientation($canvas['orientation']);
        }

        $this->entityManager->flush();
    }

    public function publishTemplate(int $templateId): void
    {
        $template = $this->findTemplate($templateId);

        if ($template->isPublished()) {
            $template->incrementVersion();
        }

        $template->publish();
        $this->entityManager->flush();
    }

    public function activateTemplate(int $templateId): void
    {
        $template = $this->findTemplate($templateId);

        if (!$template->isPublished()) {
            throw new \InvalidArgumentException('Only published templates can be activated');
        }

        $this->entityManager->createQueryBuilder()
            ->update(DocumentTemplateConfiguration::class, 't')
            ->set('t.status', ':inactive')
            ->where('t.documentType = :type')
            ->andWhere('t.id != :currentId')
            ->andWhere('t.status = :active')
            ->setParameter('inactive', FormStatus::INACTIVE)
            ->setParameter('type', $template->getDocumentType())
            ->setParameter('currentId', $template->getId())
            ->setParameter('active', FormStatus::ACTIVE)
            ->getQuery()
            ->execute();

        $template->activate();
        $this->entityManager->flush();
    }

    public function deactivateTemplate(int $templateId): void
    {
        $template = $this->findTemplate($templateId);
        $template->deactivate();
        $this->entityManager->flush();
    }

    public function unpublishTemplate(int $templateId): void
    {
        $template = $this->findTemplate($templateId);

        if ($template->getStatus() !== FormStatus::PUBLISHED) {
            throw new \InvalidArgumentException('Only published templates can be unpublished. Deactivate active templates first.');
        }

        $template->unpublish();
        $this->entityManager->flush();
    }

    public function createNewVersion(int $templateId): DocumentTemplateConfiguration
    {
        $source = $this->findTemplate($templateId);

        $newVersion = new DocumentTemplateConfiguration();
        $newVersion->setName($source->getName());
        $newVersion->setDocumentType($source->getDocumentType());
        $newVersion->setStatus(FormStatus::DRAFT);
        $newVersion->setVersion($source->getVersion() + 1);
        $newVersion->setLayout($source->getLayout());
        $newVersion->setPaperSize($source->getPaperSize());
        $newVersion->setOrientation($source->getOrientation());
        $newVersion->setCreatedBy($source->getCreatedBy());

        $this->entityManager->persist($newVersion);
        $this->entityManager->flush();

        return $newVersion;
    }

    public function getActiveTemplate(DocumentTemplateType $type): ?DocumentTemplateConfiguration
    {
        return $this->entityManager->getRepository(DocumentTemplateConfiguration::class)
            ->findOneBy([
                'documentType' => $type,
                'status' => FormStatus::ACTIVE,
            ]);
    }

    private function findTemplate(int $templateId): DocumentTemplateConfiguration
    {
        $template = $this->entityManager->getRepository(DocumentTemplateConfiguration::class)->find($templateId);

        if (!$template) {
            throw new \InvalidArgumentException('Document template not found');
        }

        return $template;
    }
}
