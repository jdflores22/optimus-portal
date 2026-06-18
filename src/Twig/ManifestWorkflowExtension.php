<?php

namespace App\Twig;

use App\Entity\Manifest;
use App\Entity\User;
use App\Service\ManifestWorkflowDisplayService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ManifestWorkflowExtension extends AbstractExtension
{
    public function __construct(
        private ManifestWorkflowDisplayService $manifestWorkflowDisplayService,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('manifest_workflow_order_text', [$this, 'getOrderText']),
            new TwigFunction('manifest_workflow_current_step', [$this, 'getCurrentStep']),
            new TwigFunction('manifest_workflow_total_steps', [$this, 'getTotalSteps']),
            new TwigFunction('manifest_workflow_is_complete', [$this, 'isComplete']),
            new TwigFunction('manifest_workflow_detail_path', [$this, 'getWorkflowDetailPath']),
            new TwigFunction('manifest_workflow_uses_noa_hub', [$this, 'usesNoaHub']),
        ];
    }

    public function getOrderText(): string
    {
        return ManifestWorkflowDisplayService::ORDER_TEXT;
    }

    public function getCurrentStep(?Manifest $manifest): int
    {
        return $this->manifestWorkflowDisplayService->getCurrentStep($manifest);
    }

    public function getTotalSteps(): int
    {
        return ManifestWorkflowDisplayService::TOTAL_STEPS;
    }

    public function isComplete(?Manifest $manifest): bool
    {
        return $this->manifestWorkflowDisplayService->isComplete($manifest);
    }

    public function getWorkflowDetailPath(?Manifest $manifest): string
    {
        if (!$manifest instanceof Manifest || $manifest->getId() === null) {
            return '#';
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->urlGenerator->generate('manifest_workflow_detail', ['id' => $manifest->getId()]);
        }

        [$route, $params] = $this->manifestWorkflowDisplayService->resolveWorkflowDetailRoute($manifest, $user);

        return $this->urlGenerator->generate($route, $params);
    }

    public function usesNoaHub(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->manifestWorkflowDisplayService->usesNoaDetailAsWorkflowHub($user);
    }
}
