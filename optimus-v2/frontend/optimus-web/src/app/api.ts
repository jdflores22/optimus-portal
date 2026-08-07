import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';
import type {
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError,
} from '@reduxjs/toolkit/query';
import type {
  AuthResponse,
  BulkImportResultDto,
  ContainerDto,
  ContainerDetailDto,
  ContainerInventoryItemDto,
  ContainerInventoryPageDto,
  ContainerSizeDto,
  ContainerTypeDto,
  CyAllocationDto,
  DocumentVerifyDto,
  DwellConfigDto,
  EdoDto,
  EdoGenerationQueueDto,
  EdoPaymentDto,
  EdoReleaseQueueDto,
  EdoReleaseRecordDto,
  ExchangeRateDto,
  FinalPaymentListResponse,
  GenerationSessionDto,
  HelloResponse,
  ManifestDto,
  PaymentDto,
  PaymentFeeDto,
  PendingUserDto,
  PreAdviceDto,
  ReferralCodeDto,
  RelationshipDto,
  RenewalDto,
  RepositioningDto,
  RepositioningEligibleContainerDto,
  ShippingLineDto,
  TerminalDto,
  TerminalSlotDto,
  TransferDto,
  TruckerTokenDto,
  UserDto,
  WorkspaceDto,
  UtilizationReportDto,
  WelcomeContentDto,
  WorkflowHistoryDto,
  AccreditationDto,
  AppealDto,
  FormConfigurationDto,
  NotificationDto,
  NotificationPreferenceDto,
  NotificationMetricsDto,
  SystemSettingDto,
  RateLimitRuleDto,
  MessageTemplateDto,
  DocumentTemplateDto,
  ScheduledReportDto,
  EdoReleaseMetricsDto,
  AuditTrailDto,
  ActivityLogDto,
} from '../shared/types';
import { API_BASE_URL } from '../shared/types';
import type { RootState } from './store';
import { logout, setCredentials } from './authSlice';

const rawBaseQuery = fetchBaseQuery({
  baseUrl: API_BASE_URL,
  prepareHeaders: (headers, { getState }) => {
    const token = (getState() as RootState).auth.accessToken;
    if (token) {
      headers.set('Authorization', `Bearer ${token}`);
    }
    return headers;
  },
});

const baseQueryWithReauth: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = async (
  args,
  api,
  extraOptions,
) => {
  let result = await rawBaseQuery(args, api, extraOptions);

  if (result.error && result.error.status === 401) {
    const refreshToken = (api.getState() as RootState).auth.refreshToken;
    const url = typeof args === 'string' ? args : args.url;
    // Avoid infinite loop on the refresh call itself
    if (refreshToken && !url.includes('/api/auth/refresh') && !url.includes('/api/auth/login')) {
      const refreshResult = await rawBaseQuery(
        { url: '/api/auth/refresh', method: 'POST', body: { refreshToken } },
        api,
        extraOptions,
      );
      if (refreshResult.data) {
        const data = refreshResult.data as AuthResponse;
        api.dispatch(
          setCredentials({
            accessToken: data.accessToken,
            refreshToken: data.refreshToken,
            user: data.user,
          }),
        );
        result = await rawBaseQuery(args, api, extraOptions);
      } else {
        api.dispatch(logout());
      }
    } else if (!url.includes('/api/auth/login')) {
      api.dispatch(logout());
    }
  }

  return result;
};

export const api = createApi({
  reducerPath: 'api',
  baseQuery: baseQueryWithReauth,
  tagTypes: [
    'ShippingLines',
    'Users',
    'Workspaces',
    'Manifests',
    'Payments',
    'PaymentFees',
    'Edos',
    'EdoPayments',
    'EdoRenewals',
    'Terminals',
    'Containers',
    'CyAllocations',
    'Dwell',
    'PreAdvice',
    'Forms',
    'Accreditation',
    'Transfers',
    'Appeals',
    'Repositioning',
    'Referrals',
    'Relationships',
    'Notifications',
    'PlatformSettings',
    'RateLimits',
    'MessageTemplates',
    'DocumentTemplates',
    'ScheduledReports',
    'ShippingAdminPartners',
  ],
  endpoints: (builder) => ({
    login: builder.mutation<AuthResponse, { email: string; password: string }>({
      query: (body) => ({ url: '/api/auth/login', method: 'POST', body }),
    }),
    refreshAuth: builder.mutation<AuthResponse, { refreshToken: string }>({
      query: (body) => ({ url: '/api/auth/refresh', method: 'POST', body }),
    }),
    registerBroker: builder.mutation<
      { message: string },
      {
        email: string;
        password: string;
        firstName: string;
        lastName: string;
        businessAddress?: string;
        referralCode?: string;
      }
    >({
      query: (body) => ({ url: '/api/auth/register/broker', method: 'POST', body }),
    }),
    registerConsignee: builder.mutation<
      { message: string },
      {
        email: string;
        password: string;
        firstName: string;
        lastName: string;
        businessName: string;
      }
    >({
      query: (body) => ({ url: '/api/auth/register/consignee', method: 'POST', body }),
    }),
    requestOtp: builder.mutation<{ message: string }, { email: string }>({
      query: (body) => ({ url: '/api/auth/password/request-otp', method: 'POST', body }),
    }),
    resetPassword: builder.mutation<
      { message: string },
      { email: string; otp: string; newPassword: string }
    >({
      query: (body) => ({ url: '/api/auth/password/reset', method: 'POST', body }),
    }),
    verifyEmail: builder.mutation<{ message: string }, { token: string }>({
      query: (body) => ({ url: '/api/auth/verify-email', method: 'POST', body }),
    }),
    getInvitation: builder.query<PendingUserDto, string>({
      query: (token) => `/api/auth/role-acceptance/${token}`,
    }),
    acceptInvitation: builder.mutation<AuthResponse, { token: string; password: string }>({
      query: (body) => ({ url: '/api/auth/role-acceptance/accept', method: 'POST', body }),
    }),
    hello: builder.query<HelloResponse, void>({
      query: () => '/api/hello',
    }),
    getShippingLines: builder.query<ShippingLineDto[], void>({
      query: () => '/api/shipping-lines',
      providesTags: ['ShippingLines'],
    }),
    createShippingLine: builder.mutation<
      ShippingLineDto,
      { brandName: string; brandColor?: string }
    >({
      query: (body) => ({ url: '/api/shipping-lines', method: 'POST', body }),
      invalidatesTags: ['ShippingLines'],
    }),
    updateShippingLine: builder.mutation<
      ShippingLineDto,
      { id: string; brandName: string; brandColor?: string; isActive: boolean; assignedAdminUserId?: string }
    >({
      query: ({ id, ...body }) => ({ url: `/api/shipping-lines/${id}`, method: 'PUT', body }),
      invalidatesTags: ['ShippingLines'],
    }),
    setShippingLineActive: builder.mutation<void, { id: string; active: boolean }>({
      query: ({ id, active }) => ({
        url: `/api/shipping-lines/${id}/${active ? 'activate' : 'deactivate'}`,
        method: 'POST',
      }),
      invalidatesTags: ['ShippingLines'],
    }),
    switchShippingLine: builder.mutation<AuthResponse, { shippingLineId: string }>({
      query: (body) => ({ url: '/api/shipping-lines/switch', method: 'POST', body }),
    }),
    getHierarchyUsers: builder.query<UserDto[], void>({
      query: () => '/api/hierarchy/users',
      providesTags: ['Users'],
    }),
    inviteUser: builder.mutation<
      PendingUserDto,
      {
        email: string;
        firstName: string;
        lastName: string;
        role: string;
        shippingLineId?: string;
        shippingLineAdminId?: string;
      }
    >({
      query: (body) => ({ url: '/api/hierarchy/invitations', method: 'POST', body }),
      invalidatesTags: ['Users'],
    }),
    unlockUser: builder.mutation<void, string>({
      query: (id) => ({ url: `/api/hierarchy/users/${id}/unlock`, method: 'POST' }),
      invalidatesTags: ['Users'],
    }),
    getShippingAdminConsignees: builder.query<
      import('../shared/shippingAdminTypes').ShippingAdminConsigneeDto[],
      void
    >({
      query: () => '/api/shipping-admin/consignees',
      providesTags: ['ShippingAdminPartners'],
    }),
    getShippingAdminConsignee: builder.query<
      import('../shared/shippingAdminTypes').ShippingAdminConsigneeDetailDto,
      string
    >({
      query: (id) => `/api/shipping-admin/consignees/${id}`,
      providesTags: (_r, _e, id) => [{ type: 'ShippingAdminPartners', id: `consignee-${id}` }],
    }),
    getShippingAdminBrokers: builder.query<
      import('../shared/shippingAdminTypes').ShippingAdminBrokerDto[],
      void
    >({
      query: () => '/api/shipping-admin/brokers',
      providesTags: ['ShippingAdminPartners'],
    }),
    getShippingAdminBroker: builder.query<
      import('../shared/shippingAdminTypes').ShippingAdminBrokerDetailDto,
      string
    >({
      query: (id) => `/api/shipping-admin/brokers/${id}`,
      providesTags: (_r, _e, id) => [{ type: 'ShippingAdminPartners', id: `broker-${id}` }],
    }),
    getWorkspaces: builder.query<WorkspaceDto[], void>({
      query: () => '/api/workspace',
      providesTags: ['Workspaces'],
    }),
    switchWorkspace: builder.mutation<AuthResponse, { consigneeId: string }>({
      query: (body) => ({ url: '/api/workspace/switch', method: 'POST', body }),
      invalidatesTags: ['Workspaces'],
    }),
    getManifests: builder.query<ManifestDto[], void>({
      query: () => '/api/manifests',
      providesTags: ['Manifests'],
    }),
    getAccreditedConsignees: builder.query<
      { id: string; businessName: string; fullName: string; email: string }[],
      void
    >({
      query: () => '/api/manifests/accredited-consignees',
      providesTags: ['Manifests'],
    }),
    getManifest: builder.query<ManifestDto, string>({
      query: (id) => `/api/manifests/${id}`,
      providesTags: ['Manifests'],
    }),
    getManifestHistory: builder.query<WorkflowHistoryDto[], string>({
      query: (id) => `/api/manifests/${id}/history`,
    }),
    createManifest: builder.mutation<
      ManifestDto,
      {
        manifestNumber: string;
        shippingLineId: string;
        vesselName?: string;
        voyageNumber?: string;
        arrivalDate?: string;
        blNumber?: string;
        consigneeId?: string;
        brokerId?: string;
        portLocation?: string;
      }
    >({
      query: (body) => ({ url: '/api/manifests', method: 'POST', body }),
      invalidatesTags: ['Manifests'],
    }),
    getConsigneeBrokers: builder.query<
      Array<{ id: string; fullName: string; email: string; businessAddress?: string | null }>,
      string
    >({
      query: (consigneeId) => `/api/manifests/consignees/${consigneeId}/brokers`,
    }),
    declareConsignee: builder.mutation<
      ManifestDto,
      { id: string; consigneeId: string; brokerId?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/manifests/${id}/declare-consignee`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Manifests'],
    }),
    assignBroker: builder.mutation<ManifestDto, { id: string; brokerId: string }>({
      query: ({ id, brokerId }) => ({
        url: `/api/manifests/${id}/assign-broker`,
        method: 'POST',
        body: { brokerId },
      }),
      invalidatesTags: ['Manifests'],
    }),
    generateNoa: builder.mutation<ManifestDto, string>({
      query: (id) => ({ url: `/api/manifests/${id}/noa`, method: 'POST' }),
      invalidatesTags: ['Manifests'],
    }),
    generateBl: builder.mutation<
      ManifestDto,
      { id: string; manifestBlNumber: string; actualArrivalDate: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/manifests/${id}/bl/generate`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Manifests'],
    }),
    uploadBl: builder.mutation<ManifestDto, { id: string; file: File; blNumber?: string }>({
      query: ({ id, file, blNumber }) => {
        const form = new FormData();
        form.append('file', file);
        if (blNumber) form.append('blNumber', blNumber);
        return { url: `/api/manifests/${id}/bl/upload`, method: 'POST', body: form };
      },
      invalidatesTags: ['Manifests'],
    }),
    generateBilling: builder.mutation<
      ManifestDto,
      {
        manifestId: string;
        freightCharges: number;
        thcCharges: number;
        additionalCharges: number;
        currency: string;
        exchangeRate?: number;
        additionalChargeLines?: Array<{ description: string; amount: number }>;
      }
    >({
      query: ({ manifestId, ...body }) => ({
        url: `/api/manifests/${manifestId}/billing`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Manifests'],
    }),
    bulkImportManifests: builder.mutation<
      BulkImportResultDto,
      { shippingLineId: string; file: File }
    >({
      query: ({ shippingLineId, file }) => {
        const form = new FormData();
        form.append('shippingLineId', shippingLineId);
        form.append('file', file);
        return { url: '/api/manifests/bulk-import', method: 'POST', body: form };
      },
      invalidatesTags: ['Manifests'],
    }),
    getPendingPayments: builder.query<PaymentDto[], void>({
      query: () => '/api/payments/pending',
      providesTags: ['Payments'],
    }),
    getFinalPayments: builder.query<
      FinalPaymentListResponse,
      { status?: string; page?: number; limit?: number }
    >({
      query: ({ status = 'pending_validation', page = 1, limit = 20 }) => {
        const qs = new URLSearchParams();
        qs.set('status', status);
        qs.set('page', String(page));
        qs.set('limit', String(limit));
        return `/api/payments/final?${qs.toString()}`;
      },
      providesTags: ['Payments'],
    }),
    getPayment: builder.query<PaymentDto, string>({
      query: (id) => `/api/payments/${id}`,
      providesTags: (_r, _e, id) => [{ type: 'Payments', id }],
    }),
    getPaymentsByManifest: builder.query<PaymentDto[], string>({
      query: (manifestId) => `/api/payments/by-manifest/${manifestId}`,
      providesTags: ['Payments'],
    }),
    submitPayment: builder.mutation<
      PaymentDto,
      {
        manifestId: string;
        paymentType: string;
        amount: number;
        currency: string;
        receipt?: File;
      }
    >({
      query: ({ manifestId, paymentType, amount, currency, receipt }) => {
        const form = new FormData();
        form.append('paymentType', paymentType);
        form.append('amount', String(amount));
        form.append('currency', currency);
        if (receipt) form.append('receipt', receipt);
        return { url: `/api/payments/manifest/${manifestId}`, method: 'POST', body: form };
      },
      invalidatesTags: ['Payments', 'Manifests'],
    }),
    validatePayment: builder.mutation<
      PaymentDto,
      { id: string; approve: boolean; rejectionReason?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/payments/${id}/validate`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Payments', 'Manifests'],
    }),
    getActivePaymentFee: builder.query<PaymentFeeDto, string>({
      query: (feeType) => `/api/payment-fees/active/${feeType}`,
      providesTags: ['PaymentFees'],
    }),
    upsertPaymentFee: builder.mutation<
      PaymentFeeDto,
      { feeType: string; amount: number; qrCode?: File }
    >({
      query: ({ feeType, amount, qrCode }) => {
        const form = new FormData();
        form.append('feeType', feeType);
        form.append('amount', String(amount));
        if (qrCode) form.append('qrCode', qrCode);
        return { url: '/api/payment-fees', method: 'POST', body: form };
      },
      invalidatesTags: ['PaymentFees'],
    }),
    getExchangeRate: builder.query<ExchangeRateDto, void>({
      query: () => '/api/exchange-rate/usd-php',
    }),
    getEdos: builder.query<EdoDto[], { manifestId?: string; status?: string } | void>({
      query: (params) => {
        const qs = new URLSearchParams();
        if (params?.manifestId) qs.set('manifestId', params.manifestId);
        if (params?.status) qs.set('status', params.status);
        const q = qs.toString();
        return `/api/edo${q ? `?${q}` : ''}`;
      },
      providesTags: ['Edos'],
    }),
    getEdo: builder.query<EdoDto, string>({
      query: (id) => `/api/edo/${id}`,
      providesTags: (_r, _e, id) => [{ type: 'Edos', id }],
    }),
    getEdoReleaseQueue: builder.query<EdoReleaseQueueDto, void>({
      query: () => '/api/edo/release-queue',
      providesTags: ['Edos'],
    }),
    getEdoReleaseRecords: builder.query<EdoReleaseRecordDto[], void>({
      query: () => '/api/edo/release-records',
      providesTags: ['Edos'],
    }),
    getEdoGenerationQueue: builder.query<EdoGenerationQueueDto, void>({
      query: () => '/api/edo/generation-queue',
      providesTags: ['Edos'],
    }),
    generateEdo: builder.mutation<
      EdoDto,
      {
        manifestId: string;
        containerNumber?: string;
        expiresAt?: string;
        cyLocation?: string;
        additionalNotes?: string;
        requirePayment?: boolean;
      }
    >({
      query: (body) => ({ url: '/api/edo/generate', method: 'POST', body }),
      invalidatesTags: ['Edos', 'Manifests'],
    }),
    batchGenerateEdo: builder.mutation<
      GenerationSessionDto,
      {
        manifestId: string;
        containerNumbers: string[];
        expiresAt?: string;
        cyLocation?: string;
      }
    >({
      query: (body) => ({ url: '/api/edo/batch', method: 'POST', body }),
      invalidatesTags: ['Edos', 'Manifests'],
    }),
    releaseEdo: builder.mutation<EdoDto, { id: string; approve: boolean; rejectionReason?: string }>({
      query: ({ id, ...body }) => ({ url: `/api/edo/${id}/release`, method: 'POST', body }),
      invalidatesTags: ['Edos', 'Manifests'],
    }),
    unlockEdo: builder.mutation<EdoDto, { id: string; newExpiresAt?: string; notes?: string }>({
      query: ({ id, ...body }) => ({ url: `/api/edo/${id}/unlock`, method: 'POST', body }),
      invalidatesTags: ['Edos'],
    }),
    submitEdoPayment: builder.mutation<
      EdoPaymentDto,
      { edoId: string; amount: number; currency: string; receipt?: File }
    >({
      query: ({ edoId, amount, currency, receipt }) => {
        const form = new FormData();
        form.append('amount', String(amount));
        form.append('currency', currency);
        if (receipt) form.append('receipt', receipt);
        return { url: `/api/edo/${edoId}/payments`, method: 'POST', body: form };
      },
      invalidatesTags: ['Edos', 'EdoPayments'],
    }),
    getPendingEdoPayments: builder.query<EdoPaymentDto[], void>({
      query: () => '/api/edo-payments/pending',
      providesTags: ['EdoPayments'],
    }),
    getReviewedEdoPayments: builder.query<EdoPaymentDto[], void>({
      query: () => '/api/edo-payments/reviewed',
      providesTags: ['EdoPayments'],
    }),
    getEdoPayment: builder.query<EdoPaymentDto, string>({
      query: (id) => `/api/edo-payments/${id}`,
      providesTags: (_r, _e, id) => [{ type: 'EdoPayments', id }],
    }),
    validateEdoPayment: builder.mutation<
      EdoPaymentDto,
      { id: string; approve: boolean; rejectionReason?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/edo-payments/${id}/validate`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['EdoPayments', 'Edos'],
    }),
    getEdoRenewals: builder.query<RenewalDto[], void>({
      query: () => '/api/edo-renewals',
      providesTags: ['EdoRenewals'],
    }),
    createEdoRenewal: builder.mutation<
      RenewalDto,
      { expiredEdoId: string; emptyContainerReturnDate: string; additionalNotes?: string }
    >({
      query: (body) => ({ url: '/api/edo-renewals', method: 'POST', body }),
      invalidatesTags: ['EdoRenewals'],
    }),
    reviewEdoRenewal: builder.mutation<RenewalDto, { id: string; approve: boolean; notes?: string }>({
      query: ({ id, ...body }) => ({
        url: `/api/edo-renewals/${id}/review`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['EdoRenewals'],
    }),
    verifyEdoRenewalPayment: builder.mutation<RenewalDto, string>({
      query: (id) => ({ url: `/api/edo-renewals/${id}/verify-payment`, method: 'POST' }),
      invalidatesTags: ['EdoRenewals'],
    }),
    generateRenewedEdo: builder.mutation<EdoDto, string>({
      query: (id) => ({ url: `/api/edo-renewals/${id}/generate`, method: 'POST' }),
      invalidatesTags: ['EdoRenewals', 'Edos', 'Manifests'],
    }),
    verifyDocument: builder.query<DocumentVerifyDto, string>({
      query: (token) => `/api/verify/document/${token}`,
    }),
    getTerminals: builder.query<TerminalDto[], void>({
      query: () => '/api/terminals',
      providesTags: ['Terminals'],
    }),
    upsertTerminal: builder.mutation<
      TerminalDto,
      {
        name: string;
        code: string;
        identity: string;
        kind: string;
        dailyCapacity: number;
        location?: string;
        region?: string;
        city?: string;
        isActive?: boolean;
      }
    >({
      query: (body) => ({ url: '/api/terminals', method: 'POST', body }),
      invalidatesTags: ['Terminals'],
    }),
    getTerminalSlots: builder.query<TerminalSlotDto[], string>({
      query: (terminalId) => `/api/terminals/${terminalId}/slots`,
      providesTags: ['Terminals'],
    }),
    getContainerCatalog: builder.query<
      { types: ContainerTypeDto[]; sizes: ContainerSizeDto[] },
      void
    >({
      async queryFn(_arg, _api, _extra, baseQuery) {
        const types = await baseQuery('/api/container-catalog/types');
        const sizes = await baseQuery('/api/container-catalog/sizes');
        if (types.error) return { error: types.error };
        if (sizes.error) return { error: sizes.error };
        return {
          data: {
            types: types.data as ContainerTypeDto[],
            sizes: sizes.data as ContainerSizeDto[],
          },
        };
      },
    }),
    getCyAllocations: builder.query<CyAllocationDto[], void>({
      query: () => '/api/cy-allocations',
      providesTags: ['CyAllocations'],
    }),
    getContainers: builder.query<ContainerDto[], void>({
      query: () => '/api/containers',
      providesTags: ['Containers'],
    }),
    getContainerInventory: builder.query<
      ContainerInventoryPageDto,
      { depot?: string; search?: string; page?: number; pageSize?: number }
    >({
      query: ({ depot, search, page = 1, pageSize = 50 }) => {
        const params = new URLSearchParams();
        params.set('page', String(page));
        params.set('pageSize', String(pageSize));
        if (depot) params.set('depot', depot);
        if (search) params.set('search', search);
        return `/api/containers/inventory?${params.toString()}`;
      },
      providesTags: ['Containers'],
    }),
    getContainerInventoryDepots: builder.query<string[], void>({
      query: () => '/api/containers/inventory/depots',
      providesTags: ['Containers'],
    }),
    getContainerInventoryItem: builder.query<ContainerInventoryItemDto, string>({
      query: (id) => `/api/containers/inventory/${id}`,
      providesTags: ['Containers'],
    }),
    getContainerDetailsByNumber: builder.query<ContainerDetailDto, string>({
      query: (containerNumber) => `/api/containers/by-number/${encodeURIComponent(containerNumber)}/details`,
      providesTags: ['Containers'],
    }),
    searchReturnContainers: builder.query<ContainerDto[], string>({
      query: (q) => `/api/containers/search-return?q=${encodeURIComponent(q)}`,
    }),
    createContainer: builder.mutation<
      ContainerDto,
      {
        containerNumber: string;
        shippingLineId: string;
        manifestId?: string;
        containerTypeId?: string;
        containerSizeId?: string;
        currentLocation?: string;
      }
    >({
      query: (body) => ({ url: '/api/containers', method: 'POST', body }),
      invalidatesTags: ['Containers'],
    }),
    allocateContainer: builder.mutation<
      ContainerDto,
      { id: string; cyAllocationId: string; reason?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/containers/${id}/allocate`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Containers', 'CyAllocations'],
    }),
    markAvailableForReturn: builder.mutation<ContainerDto, string>({
      query: (id) => ({ url: `/api/containers/${id}/available-for-return`, method: 'POST' }),
      invalidatesTags: ['Containers'],
    }),
    getUtilization: builder.query<UtilizationReportDto[], void>({
      query: () => '/api/containers/utilization',
    }),
    exportUtilization: builder.mutation<{ csv: string; pdfPath: string }, void>({
      query: () => ({ url: '/api/containers/utilization/export', method: 'GET' }),
    }),
    getDwellConfig: builder.query<DwellConfigDto, void>({
      query: () => '/api/dwell/config',
      providesTags: ['Dwell'],
    }),
    upsertDwellConfig: builder.mutation<
      DwellConfigDto,
      {
        notificationThresholdDays: number;
        automaticReturnThresholdDays: number;
        timezone: string;
        enableAutomaticReturns: boolean;
        enableNotifications: boolean;
      }
    >({
      query: (body) => ({ url: '/api/dwell/config', method: 'PUT', body }),
      invalidatesTags: ['Dwell'],
    }),
    getDwellMonitor: builder.query<ContainerDto[], void>({
      query: () => '/api/dwell/monitor',
      providesTags: ['Dwell', 'Containers'],
    }),
    recordArrival: builder.mutation<ContainerDto, string>({
      query: (id) => ({ url: `/api/dwell/containers/${id}/arrival`, method: 'POST', body: null }),
      invalidatesTags: ['Dwell', 'Containers'],
    }),
    pauseDwell: builder.mutation<ContainerDto, { id: string; reason?: string }>({
      query: ({ id, reason }) => ({
        url: `/api/dwell/containers/${id}/pause`,
        method: 'POST',
        body: { reason },
      }),
      invalidatesTags: ['Dwell', 'Containers'],
    }),
    resumeDwell: builder.mutation<ContainerDto, { id: string; reason?: string }>({
      query: ({ id, reason }) => ({
        url: `/api/dwell/containers/${id}/resume`,
        method: 'POST',
        body: { reason },
      }),
      invalidatesTags: ['Dwell', 'Containers'],
    }),
    processDwell: builder.mutation<{ actions: number }, void>({
      query: () => ({ url: '/api/dwell/process', method: 'POST' }),
      invalidatesTags: ['Dwell', 'Containers'],
    }),
    getPreAdvices: builder.query<PreAdviceDto[], { status?: string } | void>({
      query: (params) => {
        const qs = params?.status ? `?status=${encodeURIComponent(params.status)}` : '';
        return `/api/v1/pre-advice${qs}`;
      },
      providesTags: ['PreAdvice'],
    }),
    submitPreAdvice: builder.mutation<
      PreAdviceDto,
      {
        containerId: string;
        terminalId: string;
        slotId?: string;
        paymentReference?: string;
        latitude: number;
        longitude: number;
        photo: File;
      }
    >({
      query: ({ photo, ...fields }) => {
        const form = new FormData();
        form.append('containerId', fields.containerId);
        form.append('terminalId', fields.terminalId);
        if (fields.slotId) form.append('slotId', fields.slotId);
        if (fields.paymentReference) form.append('paymentReference', fields.paymentReference);
        form.append('latitude', String(fields.latitude));
        form.append('longitude', String(fields.longitude));
        form.append('photo', photo);
        return { url: '/api/v1/pre-advice', method: 'POST', body: form };
      },
      invalidatesTags: ['PreAdvice', 'Containers'],
    }),
    verifyPreAdvice: builder.mutation<
      PreAdviceDto,
      { id: string; approve: boolean; rejectionReason?: string; slotId?: string; notes?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/v1/pre-advice/${id}/verify`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['PreAdvice', 'Containers'],
    }),
    completePreAdvice: builder.mutation<PreAdviceDto, { id: string; edoNumber?: string }>({
      query: ({ id, ...body }) => ({
        url: `/api/v1/pre-advice/${id}/complete`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['PreAdvice', 'Containers'],
    }),
    cancelPreAdvice: builder.mutation<PreAdviceDto, string>({
      query: (id) => ({ url: `/api/v1/pre-advice/${id}/cancel`, method: 'POST' }),
      invalidatesTags: ['PreAdvice', 'Containers'],
    }),
    generateTruckerToken: builder.mutation<TruckerTokenDto, void>({
      query: () => ({ url: '/api/v1/token/generate', method: 'POST' }),
    }),
    revokeTruckerToken: builder.mutation<{ message: string }, void>({
      query: () => ({ url: '/api/v1/token/revoke', method: 'POST' }),
    }),
    getForms: builder.query<FormConfigurationDto[], void>({
      query: () => '/api/forms',
      providesTags: ['Forms'],
    }),
    getActiveForm: builder.query<FormConfigurationDto | null, string>({
      async queryFn(type, _api, _extraOptions, baseQuery) {
        const result = await baseQuery(`/api/forms/active/${encodeURIComponent(type)}`);
        if (result.error) {
          // No active form yet — treat as empty, not a hard page failure
          if (result.error.status === 404) return { data: null };
          return { error: result.error as FetchBaseQueryError };
        }
        return { data: (result.data as FormConfigurationDto) ?? null };
      },
      providesTags: (_result, _error, type) => [{ type: 'Forms', id: `active-${type}` }, 'Forms'],
    }),
    createForm: builder.mutation<
      FormConfigurationDto,
      { name: string; type: string; fieldsJson: string }
    >({
      query: (body) => ({ url: '/api/forms', method: 'POST', body }),
      invalidatesTags: ['Forms'],
    }),
    updateFormFields: builder.mutation<FormConfigurationDto, { id: string; fieldsJson: string }>({
      query: ({ id, fieldsJson }) => ({
        url: `/api/forms/${id}/fields`,
        method: 'PUT',
        body: { fieldsJson },
      }),
      invalidatesTags: ['Forms'],
    }),
    publishForm: builder.mutation<FormConfigurationDto, string>({
      query: (id) => ({ url: `/api/forms/${id}/publish`, method: 'POST' }),
      invalidatesTags: ['Forms'],
    }),
    activateForm: builder.mutation<FormConfigurationDto, string>({
      query: (id) => ({ url: `/api/forms/${id}/activate`, method: 'POST' }),
      invalidatesTags: ['Forms'],
    }),
    deleteForm: builder.mutation<{ message: string }, string>({
      query: (id) => ({ url: `/api/forms/${id}`, method: 'DELETE' }),
      invalidatesTags: ['Forms'],
    }),
    getRegions: builder.query<{ success: boolean; regions: { id: string; name: string; code?: string }[] }, void>({
      query: () => '/api/locations/regions',
    }),
    getProvinces: builder.query<
      { success: boolean; provinces: { id: string; name: string; code?: string }[] },
      string
    >({
      query: (regionId) => `/api/locations/provinces/${encodeURIComponent(regionId)}`,
    }),
    getCitiesByProvince: builder.query<
      { success: boolean; cities: { id: string; name: string; code?: string }[] },
      string
    >({
      query: (provinceId) => `/api/locations/cities/by-province/${encodeURIComponent(provinceId)}`,
    }),
    getBarangays: builder.query<
      { success: boolean; barangays: { id: string; name: string; code?: string }[] },
      string
    >({
      query: (cityId) => `/api/locations/barangays/${encodeURIComponent(cityId)}`,
    }),
    uploadFile: builder.mutation<
      { path: string; fileName: string; size: number; contentType?: string },
      { file: File; category?: string; allowedTypes?: string }
    >({
      query: ({ file, category, allowedTypes }) => {
        const form = new FormData();
        form.append('file', file);
        if (category) form.append('category', category);
        if (allowedTypes) form.append('allowedTypes', allowedTypes);
        return { url: '/api/uploads', method: 'POST', body: form };
      },
    }),
    getAccreditations: builder.query<AccreditationDto[], void>({
      query: () => '/api/accreditation',
      providesTags: ['Accreditation'],
    }),
    getAccreditation: builder.query<AccreditationDto, string>({
      query: (id) => `/api/accreditation/${id}`,
      providesTags: (_result, _error, id) => [{ type: 'Accreditation', id }],
    }),
    submitAccreditation: builder.mutation<
      AccreditationDto,
      { submittedDataJson: string; shippingLineId?: string }
    >({
      query: (body) => ({ url: '/api/accreditation', method: 'POST', body }),
      invalidatesTags: ['Accreditation'],
    }),
    evaluatorAccreditation: builder.mutation<
      AccreditationDto,
      { id: string; action: string; notes?: string; complianceFieldIdsJson?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/accreditation/${id}/evaluator`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Accreditation', 'Notifications'],
    }),
    finalAccreditation: builder.mutation<
      AccreditationDto,
      { id: string; approve: boolean; notes?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/accreditation/${id}/final`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Accreditation', 'Notifications'],
    }),
    getTransfers: builder.query<TransferDto[], void>({
      query: () => '/api/transfers',
      providesTags: ['Transfers'],
    }),
    createTransfer: builder.mutation<
      TransferDto,
      { manifestId: string; newBrokerId: string; reason: string }
    >({
      query: (fields) => {
        const form = new FormData();
        form.append('manifestId', fields.manifestId);
        form.append('newBrokerId', fields.newBrokerId);
        form.append('reason', fields.reason);
        return { url: '/api/transfers', method: 'POST', body: form };
      },
      invalidatesTags: ['Transfers', 'Manifests'],
    }),
    reviewTransfer: builder.mutation<TransferDto, { id: string; approve: boolean; notes?: string }>({
      query: ({ id, ...body }) => ({ url: `/api/transfers/${id}/review`, method: 'POST', body }),
      invalidatesTags: ['Transfers', 'Manifests'],
    }),
    getAppeals: builder.query<AppealDto[], void>({
      query: () => '/api/appeals',
      providesTags: ['Appeals'],
    }),
    suspendBroker: builder.mutation<{ message: string }, { brokerId: string; reason: string }>({
      query: ({ brokerId, reason }) => ({
        url: `/api/appeals/suspend/${brokerId}`,
        method: 'POST',
        body: { reason },
      }),
      invalidatesTags: ['Appeals', 'Users'],
    }),
    submitAppeal: builder.mutation<AppealDto, { appealLetter: string }>({
      query: ({ appealLetter }) => {
        const form = new FormData();
        form.append('appealLetter', appealLetter);
        return { url: '/api/appeals', method: 'POST', body: form };
      },
      invalidatesTags: ['Appeals'],
    }),
    reviewAppeal: builder.mutation<AppealDto, { id: string; approve: boolean; notes?: string }>({
      query: ({ id, ...body }) => ({ url: `/api/appeals/${id}/review`, method: 'POST', body }),
      invalidatesTags: ['Appeals'],
    }),
    getRepositioning: builder.query<RepositioningDto[], void>({
      query: () => '/api/repositioning',
      providesTags: ['Repositioning'],
    }),
    getRepositioningById: builder.query<RepositioningDto, string>({
      query: (id) => `/api/repositioning/${id}`,
      providesTags: ['Repositioning'],
    }),
    getRepositioningEligibleContainers: builder.query<
      RepositioningEligibleContainerDto[],
      { sourceTerminalId?: string; search?: string }
    >({
      query: ({ sourceTerminalId, search }) => {
        const params = new URLSearchParams();
        if (sourceTerminalId) params.set('sourceTerminalId', sourceTerminalId);
        if (search) params.set('search', search);
        const qs = params.toString();
        return `/api/repositioning/eligible-containers${qs ? `?${qs}` : ''}`;
      },
    }),
    createRepositioning: builder.mutation<
      RepositioningDto,
      {
        shippingLineId: string;
        requestType: string;
        sourceTerminalId: string;
        destinationTerminalId: string;
        purpose: string;
        containerIds: string[];
        letter?: File | null;
      }
    >({
      query: ({ letter, containerIds, ...fields }) => {
        const form = new FormData();
        form.append('shippingLineId', fields.shippingLineId);
        form.append('requestType', fields.requestType);
        form.append('sourceTerminalId', fields.sourceTerminalId);
        form.append('destinationTerminalId', fields.destinationTerminalId);
        form.append('purpose', fields.purpose);
        containerIds.forEach((id) => form.append('containerIds', id));
        if (letter) form.append('letter', letter);
        return { url: '/api/repositioning', method: 'POST', body: form };
      },
      invalidatesTags: ['Repositioning', 'Containers', 'Notifications'],
    }),
    reviewRepositioning: builder.mutation<
      RepositioningDto,
      { id: string; approve: boolean; notes?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/api/repositioning/${id}/review`,
        method: 'POST',
        body,
      }),
      invalidatesTags: ['Repositioning', 'Containers', 'Notifications'],
    }),
    completeRepositioning: builder.mutation<RepositioningDto, string>({
      query: (id) => ({ url: `/api/repositioning/${id}/complete`, method: 'POST' }),
      invalidatesTags: ['Repositioning', 'Containers'],
    }),
    cancelRepositioning: builder.mutation<RepositioningDto, string>({
      query: (id) => ({ url: `/api/repositioning/${id}/cancel`, method: 'POST' }),
      invalidatesTags: ['Repositioning', 'Containers'],
    }),
    getReferrals: builder.query<ReferralCodeDto[], void>({
      query: () => '/api/referrals',
      providesTags: ['Referrals'],
    }),
    getRelationships: builder.query<RelationshipDto[], void>({
      query: () => '/api/referrals/relationships',
      providesTags: ['Relationships'],
    }),
    generateReferral: builder.mutation<ReferralCodeDto, { maxUses?: number; expiresAt?: string }>({
      query: (body) => ({ url: '/api/referrals', method: 'POST', body }),
      invalidatesTags: ['Referrals'],
    }),
    applyReferral: builder.mutation<{ id: string }, { code: string }>({
      query: (body) => ({ url: '/api/referrals/apply', method: 'POST', body }),
      invalidatesTags: ['Referrals', 'Workspaces', 'Relationships'],
    }),
    deactivateReferral: builder.mutation<{ message: string }, string>({
      query: (id) => ({ url: `/api/referrals/${id}/deactivate`, method: 'POST' }),
      invalidatesTags: ['Referrals'],
    }),
    getWelcome: builder.query<WelcomeContentDto, void>({
      query: () => '/api/onboarding/welcome',
    }),
    completeOnboardingStep: builder.mutation<WelcomeContentDto, { stepId: string }>({
      query: (body) => ({ url: '/api/onboarding/complete-step', method: 'POST', body }),
    }),
    getNotifications: builder.query<NotificationDto[], void>({
      query: () => '/api/notifications',
      providesTags: ['Notifications'],
    }),
    getNotification: builder.query<NotificationDto, string>({
      query: (id) => `/api/notifications/${id}`,
      providesTags: (_r, _e, id) => [{ type: 'Notifications', id }],
    }),
    markNotificationsRead: builder.mutation<{ message: string }, { notificationId?: string }>({
      query: (body) => ({ url: '/api/notifications/read', method: 'POST', body }),
      invalidatesTags: ['Notifications'],
    }),
    getNotificationPreferences: builder.query<NotificationPreferenceDto, void>({
      query: () => '/api/notifications/preferences',
      providesTags: ['Notifications'],
    }),
    upsertNotificationPreferences: builder.mutation<
      NotificationPreferenceDto,
      {
        inAppEnabled: boolean;
        emailEnabled: boolean;
        smsEnabled: boolean;
        pushEnabled: boolean;
        mutedCategoriesJson?: string | null;
      }
    >({
      query: (body) => ({ url: '/api/notifications/preferences', method: 'PUT', body }),
      invalidatesTags: ['Notifications'],
    }),
    subscribePush: builder.mutation<
      { message: string },
      { endpoint: string; p256dh: string; auth: string; userAgent?: string }
    >({
      query: (body) => ({ url: '/api/notifications/push/subscribe', method: 'POST', body }),
    }),
    getNotificationMetrics: builder.query<NotificationMetricsDto, void>({
      query: () => '/api/notifications/metrics',
    }),
    getSystemSettings: builder.query<SystemSettingDto[], void>({
      query: () => '/api/system-settings',
      providesTags: ['PlatformSettings'],
    }),
    upsertSystemSetting: builder.mutation<
      SystemSettingDto,
      { key: string; value: string; description?: string | null }
    >({
      query: (body) => ({ url: '/api/system-settings', method: 'PUT', body }),
      invalidatesTags: ['PlatformSettings'],
    }),
    getRateLimits: builder.query<RateLimitRuleDto[], void>({
      query: () => '/api/rate-limits',
      providesTags: ['RateLimits'],
    }),
    upsertRateLimit: builder.mutation<
      RateLimitRuleDto,
      {
        name: string;
        pathPrefix: string;
        role?: string | null;
        permitLimit: number;
        windowSeconds: number;
        isActive: boolean;
      }
    >({
      query: (body) => ({ url: '/api/rate-limits', method: 'POST', body }),
      invalidatesTags: ['RateLimits'],
    }),
    getMessageTemplates: builder.query<MessageTemplateDto[], void>({
      query: () => '/api/message-templates',
      providesTags: ['MessageTemplates'],
    }),
    upsertMessageTemplate: builder.mutation<
      MessageTemplateDto,
      {
        key: string;
        channel: string;
        name: string;
        subject?: string;
        body: string;
        isActive: boolean;
      }
    >({
      query: (body) => ({ url: '/api/message-templates', method: 'POST', body }),
      invalidatesTags: ['MessageTemplates'],
    }),
    getDocumentTemplates: builder.query<DocumentTemplateDto[], void>({
      query: () => '/api/document-templates',
      providesTags: ['DocumentTemplates'],
    }),
    upsertDocumentTemplate: builder.mutation<
      DocumentTemplateDto,
      { documentType: string; name: string; bodyHtml: string; isActive: boolean }
    >({
      query: (body) => ({ url: '/api/document-templates', method: 'POST', body }),
      invalidatesTags: ['DocumentTemplates'],
    }),
    getScheduledReports: builder.query<ScheduledReportDto[], void>({
      query: () => '/api/scheduled-reports',
      providesTags: ['ScheduledReports'],
    }),
    processScheduledReports: builder.mutation<{ processed: number }, void>({
      query: () => ({ url: '/api/scheduled-reports/process', method: 'POST' }),
      invalidatesTags: ['ScheduledReports'],
    }),
    getEdoReleaseMetrics: builder.query<EdoReleaseMetricsDto, void>({
      query: () => '/api/reports/edo-release',
    }),
    exportEdoReleaseMetrics: builder.mutation<{ csv: string; pdfPath: string }, void>({
      query: () => ({ url: '/api/reports/edo-release/export', method: 'GET' }),
    }),
    getActivityLogs: builder.query<
      ActivityLogDto[],
      { entityType?: string; entityId?: string; take?: number }
    >({
      query: ({ entityType, entityId, take }) => {
        const params = new URLSearchParams();
        if (entityType) params.set('entityType', entityType);
        if (entityId) params.set('entityId', entityId);
        if (take) params.set('take', String(take));
        const q = params.toString();
        return `/api/activity${q ? `?${q}` : ''}`;
      },
    }),
    getManifestAudit: builder.query<AuditTrailDto[], string>({
      query: (id) => `/api/audit/manifest/${id}`,
    }),
    getEdoAudit: builder.query<AuditTrailDto[], string>({
      query: (id) => `/api/audit/edo/${id}`,
    }),
    runMaintenance: builder.mutation<
      {
        refreshTokensRemoved: number;
        notificationsPurged: number;
        deliveriesPurged: number;
        orphanFilesRemoved: number;
      },
      void
    >({
      query: () => ({ url: '/api/maintenance/run', method: 'POST' }),
    }),
  }),
});

export const {
  useLoginMutation,
  useRegisterBrokerMutation,
  useRegisterConsigneeMutation,
  useRequestOtpMutation,
  useResetPasswordMutation,
  useVerifyEmailMutation,
  useGetInvitationQuery,
  useAcceptInvitationMutation,
  useHelloQuery,
  useGetShippingLinesQuery,
  useCreateShippingLineMutation,
  useUpdateShippingLineMutation,
  useSetShippingLineActiveMutation,
  useSwitchShippingLineMutation,
  useGetHierarchyUsersQuery,
  useInviteUserMutation,
  useUnlockUserMutation,
  useGetShippingAdminConsigneesQuery,
  useGetShippingAdminConsigneeQuery,
  useGetShippingAdminBrokersQuery,
  useGetShippingAdminBrokerQuery,
  useGetWorkspacesQuery,
  useSwitchWorkspaceMutation,
  useGetManifestsQuery,
  useGetAccreditedConsigneesQuery,
  useGetConsigneeBrokersQuery,
  useGetManifestQuery,
  useGetManifestHistoryQuery,
  useCreateManifestMutation,
  useDeclareConsigneeMutation,
  useAssignBrokerMutation,
  useGenerateNoaMutation,
  useGenerateBlMutation,
  useUploadBlMutation,
  useGenerateBillingMutation,
  useBulkImportManifestsMutation,
  useGetPendingPaymentsQuery,
  useGetFinalPaymentsQuery,
  useGetPaymentQuery,
  useGetPaymentsByManifestQuery,
  useSubmitPaymentMutation,
  useValidatePaymentMutation,
  useGetActivePaymentFeeQuery,
  useUpsertPaymentFeeMutation,
  useGetExchangeRateQuery,
  useGetEdosQuery,
  useGetEdoQuery,
  useGetEdoReleaseQueueQuery,
  useGetEdoReleaseRecordsQuery,
  useGetEdoGenerationQueueQuery,
  useGenerateEdoMutation,
  useBatchGenerateEdoMutation,
  useReleaseEdoMutation,
  useUnlockEdoMutation,
  useSubmitEdoPaymentMutation,
  useGetPendingEdoPaymentsQuery,
  useGetReviewedEdoPaymentsQuery,
  useGetEdoPaymentQuery,
  useValidateEdoPaymentMutation,
  useGetEdoRenewalsQuery,
  useCreateEdoRenewalMutation,
  useReviewEdoRenewalMutation,
  useVerifyEdoRenewalPaymentMutation,
  useGenerateRenewedEdoMutation,
  useVerifyDocumentQuery,
  useGetTerminalsQuery,
  useUpsertTerminalMutation,
  useGetTerminalSlotsQuery,
  useGetContainerCatalogQuery,
  useGetCyAllocationsQuery,
  useGetContainersQuery,
  useGetContainerInventoryQuery,
  useGetContainerInventoryDepotsQuery,
  useGetContainerInventoryItemQuery,
  useGetContainerDetailsByNumberQuery,
  useSearchReturnContainersQuery,
  useCreateContainerMutation,
  useAllocateContainerMutation,
  useMarkAvailableForReturnMutation,
  useGetUtilizationQuery,
  useExportUtilizationMutation,
  useGetDwellConfigQuery,
  useUpsertDwellConfigMutation,
  useGetDwellMonitorQuery,
  useRecordArrivalMutation,
  usePauseDwellMutation,
  useResumeDwellMutation,
  useProcessDwellMutation,
  useGetPreAdvicesQuery,
  useSubmitPreAdviceMutation,
  useVerifyPreAdviceMutation,
  useCompletePreAdviceMutation,
  useCancelPreAdviceMutation,
  useGenerateTruckerTokenMutation,
  useRevokeTruckerTokenMutation,
  useGetFormsQuery,
  useGetActiveFormQuery,
  useCreateFormMutation,
  useUpdateFormFieldsMutation,
  usePublishFormMutation,
  useActivateFormMutation,
  useDeleteFormMutation,
  useGetRegionsQuery,
  useGetProvincesQuery,
  useGetCitiesByProvinceQuery,
  useGetBarangaysQuery,
  useUploadFileMutation,
  useGetAccreditationsQuery,
  useGetAccreditationQuery,
  useSubmitAccreditationMutation,
  useEvaluatorAccreditationMutation,
  useFinalAccreditationMutation,
  useGetTransfersQuery,
  useCreateTransferMutation,
  useReviewTransferMutation,
  useGetAppealsQuery,
  useSuspendBrokerMutation,
  useSubmitAppealMutation,
  useReviewAppealMutation,
  useGetRepositioningQuery,
  useGetRepositioningByIdQuery,
  useGetRepositioningEligibleContainersQuery,
  useCreateRepositioningMutation,
  useReviewRepositioningMutation,
  useCompleteRepositioningMutation,
  useCancelRepositioningMutation,
  useGetReferralsQuery,
  useGetRelationshipsQuery,
  useGenerateReferralMutation,
  useApplyReferralMutation,
  useDeactivateReferralMutation,
  useGetWelcomeQuery,
  useCompleteOnboardingStepMutation,
  useGetNotificationsQuery,
  useGetNotificationQuery,
  useMarkNotificationsReadMutation,
  useGetNotificationPreferencesQuery,
  useUpsertNotificationPreferencesMutation,
  useSubscribePushMutation,
  useGetNotificationMetricsQuery,
  useGetSystemSettingsQuery,
  useUpsertSystemSettingMutation,
  useGetRateLimitsQuery,
  useUpsertRateLimitMutation,
  useGetMessageTemplatesQuery,
  useUpsertMessageTemplateMutation,
  useGetDocumentTemplatesQuery,
  useUpsertDocumentTemplateMutation,
  useGetScheduledReportsQuery,
  useProcessScheduledReportsMutation,
  useGetEdoReleaseMetricsQuery,
  useExportEdoReleaseMetricsMutation,
  useGetActivityLogsQuery,
  useGetManifestAuditQuery,
  useGetEdoAuditQuery,
  useRunMaintenanceMutation,
} = api;
