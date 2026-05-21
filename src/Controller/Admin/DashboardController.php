<?php

namespace App\Controller\Admin;

use App\Repository\EDOPaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class DashboardController extends AbstractController
{
    public function __construct(
        private EDOPaymentRepository $edoPaymentRepository
    ) {
    }

    #[Route('/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        // Get EDO Payment Statistics
        $edoPaymentStats = $this->edoPaymentRepository->getPaymentStatistics();
        
        // System Health Data
        $systemHealth = [
            'total_files' => 1247,
            'database_status' => 'connected',
            'cache_status' => 'healthy',
            'storage_status' => 'healthy'
        ];

        // Main Statistics
        $stats = [
            'total_users' => 156,
            'active_users' => 142,
            'pending_users' => 8,
            'locked_users' => 6,
            'total_shipments' => 2847,
            'shipments_today' => 23,
            'shipments_this_week' => 156,
            'total_payments' => 1923,
            'verified_payments' => 1845,
            'pending_payments' => 78,
            'total_accreditations' => 89,
            'approved_accreditations' => 76,
            'pending_accreditations' => 13,
            'denied_accreditations' => 0
        ];

        // User Distribution by Role
        $usersByRole = [
            ['role' => 'CONSIGNEE', 'count' => 78],
            ['role' => 'BROKER', 'count' => 45],
            ['role' => 'SHIPPING_LINES_ADMIN', 'count' => 12],
            ['role' => 'SL_STAFF', 'count' => 8],
            ['role' => 'EVALUATOR', 'count' => 6],
            ['role' => 'ACCOUNTING', 'count' => 4],
            ['role' => 'TERMINAL_TEAM', 'count' => 3]
        ];

        // Recent Users
        $recentUsers = [
            (object) [
                'email' => 'john.doe@example.com',
                'role' => (object) ['value' => 'CONSIGNEE'],
                'status' => (object) ['value' => 'APPROVED'],
                'createdAt' => new \DateTime('-2 hours')
            ],
            (object) [
                'email' => 'sarah.smith@broker.com',
                'role' => (object) ['value' => 'BROKER'],
                'status' => (object) ['value' => 'PENDING'],
                'createdAt' => new \DateTime('-5 hours')
            ],
            (object) [
                'email' => 'mike.wilson@shipping.com',
                'role' => (object) ['value' => 'SL_STAFF'],
                'status' => (object) ['value' => 'APPROVED'],
                'createdAt' => new \DateTime('-1 day')
            ],
            (object) [
                'email' => 'admin@maersk.com',
                'role' => (object) ['value' => 'SHIPPING_LINES_ADMIN'],
                'status' => (object) ['value' => 'APPROVED'],
                'createdAt' => new \DateTime('-2 days')
            ],
            (object) [
                'email' => 'evaluator@system.com',
                'role' => (object) ['value' => 'EVALUATOR'],
                'status' => (object) ['value' => 'LOCKED'],
                'createdAt' => new \DateTime('-3 days')
            ]
        ];

        // Applications Requiring Attention
        $applications = [
            (object) [
                'id' => 1,
                'applicant' => (object) [
                    'email' => 'pending.user@example.com',
                    'role' => (object) ['value' => 'BROKER']
                ],
                'status' => (object) ['value' => 'PENDING'],
                'submittedAt' => new \DateTime('-1 day'),
                'evaluatedAt' => null
            ],
            (object) [
                'id' => 2,
                'applicant' => (object) [
                    'email' => 'another.broker@company.com',
                    'role' => (object) ['value' => 'CONSIGNEE']
                ],
                'status' => (object) ['value' => 'PENDING'],
                'submittedAt' => new \DateTime('-3 days'),
                'evaluatedAt' => null
            ],
            (object) [
                'id' => 3,
                'applicant' => (object) [
                    'email' => 'shipping.admin@line.com',
                    'role' => (object) ['value' => 'SHIPPING_LINES_ADMIN']
                ],
                'status' => (object) ['value' => 'PENDING'],
                'submittedAt' => new \DateTime('-5 days'),
                'evaluatedAt' => null
            ]
        ];

        return $this->render('admin/dashboard.html.twig', [
            'systemHealth' => $systemHealth,
            'stats' => $stats,
            'usersByRole' => $usersByRole,
            'recentUsers' => $recentUsers,
            'applications' => $applications,
            'edoPaymentStats' => $edoPaymentStats
        ]);
    }

    #[Route('', name: 'admin_index', methods: ['GET'])]
    public function index(): Response
    {
        // Redirect to dashboard
        return $this->redirectToRoute('admin_dashboard');
    }
}