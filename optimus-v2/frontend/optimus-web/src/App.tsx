import { Navigate, Route, Routes } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { LoginPage } from './features/auth/LoginPage';
import {
  ForgotPasswordPage,
  RegisterBrokerPage,
  RegisterConsigneePage,
  RoleAcceptancePage,
  VerifyEmailPage,
} from './features/auth/AuthPages';
import { DashboardPage } from './features/dashboard/DashboardPage';
import { ConsigneeDashboardPage } from './features/dashboard/ConsigneeDashboardPage';
import { BrokerDashboardPage } from './features/dashboard/BrokerDashboardPage';
import { AppShell } from './features/layout/AppShell';
import { ShippingLinesPage } from './features/admin/ShippingLinesPage';
import { HierarchyPage } from './features/admin/HierarchyPage';
import { ShippingAdminConsigneesPage } from './features/admin/ShippingAdminConsigneesPage';
import { ShippingAdminConsigneeDetailPage } from './features/admin/ShippingAdminConsigneeDetailPage';
import { ShippingAdminBrokersPage } from './features/admin/ShippingAdminBrokersPage';
import { ShippingAdminBrokerDetailPage } from './features/admin/ShippingAdminBrokerDetailPage';
import { ManifestsPage } from './features/cargo/ManifestsPage';
import { ManifestCreatePage } from './features/cargo/ManifestCreatePage';
import { ManifestBulkImportNoasPage } from './features/cargo/ManifestBulkImportNoasPage';
import { ManifestBulkImportManifestsPage } from './features/cargo/ManifestBulkImportManifestsPage';
import { ManifestDetailPage } from './features/cargo/ManifestDetailPage';
import { ManifestGeneratePage } from './features/cargo/ManifestGeneratePage';
import { ManifestUploadBlPage } from './features/cargo/ManifestUploadBlPage';
import { ManifestGenerateBillingPage } from './features/cargo/ManifestGenerateBillingPage';
import { ManifestFinalPaymentPage } from './features/cargo/ManifestFinalPaymentPage';
import { ManifestEdoPaymentPage } from './features/cargo/ManifestEdoPaymentPage';
import { ManifestPaymentHistoryPage } from './features/cargo/ManifestPaymentHistoryPage';
import { PaymentsPage } from './features/cargo/PaymentsPage';
import { AccountingPaymentReviewPage } from './features/cargo/AccountingPaymentReviewPage';
import { EdosPage } from './features/edo/EdosPage';
import { EdoReleasePage } from './features/edo/EdoReleasePage';
import { EdoPaymentReviewPage } from './features/edo/EdoPaymentReviewPage';
import { EdoPaymentValidationPage } from './features/edo/EdoPaymentValidationPage';
import { EdoDetailPage } from './features/edo/EdoDetailPage';
import { EdoRenewalsPage } from './features/edo/EdoRenewalsPage';
import { VerifyDocumentPage } from './features/edo/VerifyDocumentPage';
import { YardAdminPage } from './features/yard/YardAdminPage';
import { ContainerInventoryPage } from './features/yard/ContainerInventoryPage';
import { ContainerDetailPage } from './features/yard/ContainerDetailPage';
import { DwellPage } from './features/yard/DwellPage';
import { PreAdvicePage } from './features/yard/PreAdvicePage';
import { UtilizationReportPage } from './features/yard/UtilizationReportPage';
import { SasPage } from './features/ops/SasPage';
import { ApprovalsPage } from './features/ops/ApprovalsPage';
import { EvaluatorApplicationPage } from './features/ops/EvaluatorApplicationPage';
import { TransfersPage } from './features/ops/TransfersPage';
import { AppealsReferralsPage } from './features/ops/AppealsReferralsPage';
import { BrokersPage } from './features/ops/BrokersPage';
import { RepositioningPage } from './features/ops/RepositioningPage';
import { RepositioningCreatePage } from './features/ops/RepositioningCreatePage';
import { RepositioningDetailPage } from './features/ops/RepositioningDetailPage';
import { NotificationsPage } from './features/platform/NotificationsPage';
import { NotificationDetailPage } from './features/platform/NotificationDetailPage';
import { ProfilePage } from './features/profile/ProfilePage';
import { WorkspaceSelectorPage } from './features/workspace/WorkspaceSelectorPage';
import { ApplyReferralPage } from './features/workspace/ApplyReferralPage';
import { WorkspaceGateLayout } from './features/workspace/WorkspaceGateLayout';
import { BrokerWorkspaceGate } from './features/workspace/BrokerWorkspaceGate';
import { ReportsAuditPage } from './features/platform/ReportsAuditPage';
import { AdminPlatformPage } from './features/platform/AdminPlatformPage';
import type { RootState } from './app/store';

function Protected({
  children,
  roles,
}: {
  children: React.ReactNode;
  roles?: string[];
}) {
  const { accessToken, user } = useSelector((state: RootState) => state.auth);
  if (!accessToken) {
    return <Navigate to="/login" replace />;
  }
  if (roles && user && !roles.includes(user.role)) {
    return <Navigate to={user.role === 'Broker' ? '/workspace' : '/'} replace />;
  }
  return <>{children}</>;
}

function HomePage() {
  const { user } = useSelector((state: RootState) => state.auth);
  // Brokers land on workspace first; gate also enforces this for deep links.
  if (user?.role === 'Broker' && !user.activeWorkspaceConsigneeId) {
    return <Navigate to="/workspace" replace />;
  }
  if (user?.role === 'Consignee') return <ConsigneeDashboardPage />;
  if (user?.role === 'Broker') return <BrokerDashboardPage />;
  return <DashboardPage />;
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register/broker" element={<RegisterBrokerPage />} />
      <Route path="/register/consignee" element={<RegisterConsigneePage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/verify-email" element={<VerifyEmailPage />} />
      <Route path="/role-acceptance/:token" element={<RoleAcceptancePage />} />
      <Route path="/verify/:token" element={<VerifyDocumentPage />} />

      <Route
        element={
          <Protected>
            <WorkspaceGateLayout />
          </Protected>
        }
      >
        <Route path="/workspace" element={<WorkspaceSelectorPage />} />
        <Route path="/workspace/referral" element={<ApplyReferralPage />} />
      </Route>

      <Route
        element={
          <Protected>
            <AppShell />
          </Protected>
        }
      >
        <Route element={<BrokerWorkspaceGate />}>
          <Route path="/" element={<HomePage />} />
          <Route path="/profile" element={<ProfilePage />} />
          <Route path="/manifests" element={<ManifestsPage />} />
          <Route path="/manifests/create" element={<ManifestCreatePage />} />
          <Route path="/manifests/bulk-import" element={<ManifestBulkImportNoasPage />} />
          <Route path="/manifests/bulk-import-manifests" element={<ManifestBulkImportManifestsPage />} />
          <Route path="/manifests/:id/generate-manifest" element={<ManifestGeneratePage />} />
          <Route path="/manifests/:id/upload-bl" element={<ManifestUploadBlPage />} />
          <Route path="/manifests/:id/generate-billing" element={<ManifestGenerateBillingPage />} />
          <Route path="/manifests/:id/final-payment" element={<ManifestFinalPaymentPage />} />
          <Route path="/manifests/:id/edo-payment/:edoId" element={<ManifestEdoPaymentPage />} />
          <Route path="/manifests/:id/payment-history" element={<ManifestPaymentHistoryPage />} />
          <Route path="/manifests/:id" element={<ManifestDetailPage />} />
          <Route path="/payments" element={<PaymentsPage />} />
          <Route path="/payments/final/:id" element={<AccountingPaymentReviewPage />} />
          <Route path="/edo" element={<EdosPage />} />
          <Route path="/edo/renewals" element={<EdoRenewalsPage />} />
          <Route
            path="/edo/payment-validation"
            element={
              <Protected roles={['SystemAdmin']}>
                <EdoPaymentValidationPage />
              </Protected>
            }
          />
          <Route
            path="/edo/payment-validation/:id"
            element={
              <Protected roles={['SystemAdmin']}>
                <EdoPaymentReviewPage />
              </Protected>
            }
          />
          <Route
            path="/edo/release"
            element={
              <Protected roles={['SlStaff', 'ShippingLinesAdmin', 'TerminalTeam', 'SystemAdmin']}>
                <EdoReleasePage />
              </Protected>
            }
          />
          <Route
            path="/edo/release/payments/:id"
            element={<Navigate to="/edo/payment-validation" replace />}
          />
          <Route path="/admin/edo-release/queue" element={<Navigate to="/edo/release" replace />} />
          <Route path="/edo/:id" element={<EdoDetailPage />} />
          <Route path="/yard" element={<YardAdminPage />} />
          <Route
            path="/container-inventory"
            element={
              <Protected
                roles={['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Accounting', 'TerminalTeam']}
              >
                <ContainerInventoryPage />
              </Protected>
            }
          />
          <Route
            path="/container/:containerNumber/details"
            element={
              <Protected
                roles={['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Accounting', 'TerminalTeam']}
              >
                <ContainerDetailPage />
              </Protected>
            }
          />
          <Route path="/dwell" element={<DwellPage />} />
          <Route path="/pre-advice" element={<PreAdvicePage />} />
          <Route path="/reports/utilization" element={<UtilizationReportPage />} />
          <Route path="/sas" element={<SasPage />} />
          <Route
            path="/approvals"
            element={
              <Protected roles={['ShippingLinesAdmin']}>
                <ApprovalsPage />
              </Protected>
            }
          />
          <Route
            path="/evaluator/application/:id"
            element={
              <Protected roles={['Evaluator', 'SystemAdmin', 'ShippingLinesAdmin']}>
                <EvaluatorApplicationPage />
              </Protected>
            }
          />
          <Route
            path="/brokers"
            element={
              <Protected roles={['Consignee', 'SystemAdmin']}>
                <BrokersPage />
              </Protected>
            }
          />
          <Route path="/transfers" element={<TransfersPage />} />
          <Route path="/appeals" element={<AppealsReferralsPage />} />
          <Route path="/repositioning" element={<RepositioningPage />} />
          <Route
            path="/repositioning/new"
            element={
              <Protected roles={['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff']}>
                <RepositioningCreatePage />
              </Protected>
            }
          />
          <Route path="/repositioning/:id" element={<RepositioningDetailPage />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/notifications/:id" element={<NotificationDetailPage />} />
          <Route
            path="/reports/audit"
            element={
              <Protected
                roles={['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Evaluator', 'Accounting']}
              >
                <ReportsAuditPage />
              </Protected>
            }
          />
          <Route
            path="/admin/platform"
            element={
              <Protected roles={['SystemAdmin', 'ShippingLinesAdmin']}>
                <AdminPlatformPage />
              </Protected>
            }
          />
          <Route
            path="/admin/shipping-lines"
            element={
              <Protected roles={['SystemAdmin', 'ShippingLinesAdmin']}>
                <ShippingLinesPage />
              </Protected>
            }
          />
          <Route
            path="/shipping-admin/consignees"
            element={
              <Protected roles={['ShippingLinesAdmin']}>
                <ShippingAdminConsigneesPage />
              </Protected>
            }
          />
          <Route
            path="/shipping-admin/consignees/:id"
            element={
              <Protected roles={['ShippingLinesAdmin']}>
                <ShippingAdminConsigneeDetailPage />
              </Protected>
            }
          />
          <Route
            path="/shipping-admin/brokers"
            element={
              <Protected roles={['ShippingLinesAdmin']}>
                <ShippingAdminBrokersPage />
              </Protected>
            }
          />
          <Route
            path="/shipping-admin/brokers/:id"
            element={
              <Protected roles={['ShippingLinesAdmin']}>
                <ShippingAdminBrokerDetailPage />
              </Protected>
            }
          />
          <Route
            path="/admin/hierarchy"
            element={
              <Protected
                roles={['SystemAdmin', 'ShippingLinesAdmin', 'SlStaff', 'Evaluator', 'Accounting']}
              >
                <HierarchyPage />
              </Protected>
            }
          />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
