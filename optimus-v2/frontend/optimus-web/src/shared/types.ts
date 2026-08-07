export type UserDto = {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  fullName: string;
  role: string;
  status: string;
  userType: string;
  managedShippingLineId?: string | null;
  activeShippingLineId?: string | null;
  activeWorkspaceConsigneeId?: string | null;
  businessName?: string | null;
};

export type WorkspaceDto = {
  id: string;
  email: string;
  fullName: string;
  businessName?: string | null;
  manifestCount: number;
  source: string;
};

export type AuthResponse = {
  accessToken: string;
  refreshToken: string;
  accessTokenExpiresAt: string;
  user: UserDto;
};

export type HelloResponse = {
  message: string;
  user: UserDto;
  serverTimeUtc: string;
};

export type ShippingLineDto = {
  id: string;
  brandName: string;
  logoPath?: string | null;
  brandColor?: string | null;
  isActive: boolean;
  assignedAdminUserId?: string | null;
};

export type PendingUserDto = {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  role: string;
  status: string;
  tokenExpiresAt: string;
  shippingLineId?: string | null;
  acceptanceToken: string;
};

export type ManifestDto = {
  id: string;
  manifestNumber: string;
  shippingLineId: string;
  shippingLineName?: string | null;
  consigneeId?: string | null;
  consigneeName?: string | null;
  brokerId?: string | null;
  brokerName?: string | null;
  vesselName?: string | null;
  voyageNumber?: string | null;
  arrivalDate?: string | null;
  blNumber?: string | null;
  blFilePath?: string | null;
  blPdfPath?: string | null;
  manifestFilePath?: string | null;
  workflowState: string;
  noaId?: string | null;
  noaNumber?: string | null;
  noaPdfPath?: string | null;
  portLocation?: string | null;
  billingId?: string | null;
  billingTotal?: number | null;
  billingCurrency?: string | null;
  billingPdfPath?: string | null;
  billingFreightCharges?: number | null;
  billingThcCharges?: number | null;
  billingAdditionalCharges?: number | null;
  billingExchangeRate?: number | null;
  billingTotalPhp?: number | null;
  createdAt: string;
};

export type PaymentDto = {
  id: string;
  manifestId: string;
  manifestNumber: string;
  paymentType: string;
  amount: number;
  currency: string;
  status: string;
  receiptFilePath?: string | null;
  officialReceiptPath?: string | null;
  rejectionReason?: string | null;
  submittedById: string;
  submittedByName: string;
  createdAt: string;
  validatedAt?: string | null;
  version?: number;
  validatedByName?: string | null;
};

export type FinalPaymentListItemDto = {
  id: string;
  manifestId: string;
  manifestNumber: string;
  consigneeName?: string | null;
  amount: number;
  currency: string;
  billingAmount?: number | null;
  billingCurrency?: string | null;
  status: string;
  submittedByName: string;
  submittedByEmail?: string | null;
  createdAt: string;
  validatedAt?: string | null;
  validatedByName?: string | null;
  version: number;
};

export type FinalPaymentStatsDto = {
  pending: number;
  approved: number;
  rejected: number;
  total: number;
  discrepancies: number;
};

export type FinalPaymentListResponse = {
  items: FinalPaymentListItemDto[];
  stats: FinalPaymentStatsDto;
  page: number;
  limit: number;
  total: number;
  pages: number;
  start: number;
  end: number;
};

export type PaymentFeeDto = {
  id: string;
  feeType: string;
  amount: number;
  isActive: boolean;
  qrCodePath?: string | null;
};

export type BulkImportResultDto = {
  jobId: string;
  status: string;
  totalRows: number;
  successCount: number;
  errorCount: number;
  errorLog?: string | null;
};

export type ExchangeRateDto = {
  base: string;
  quote: string;
  rate: number;
  retrievedAtUtc: string;
  fromCache: boolean;
};

export type WorkflowHistoryDto = {
  fromState: string;
  toState: string;
  actorRole: string;
  reason?: string | null;
  createdAt: string;
};

export type EdoDto = {
  id: string;
  edoNumber: string;
  manifestId: string;
  manifestNumber: string;
  shippingLineId: string;
  containerNumber?: string | null;
  status: string;
  feeAmount?: number | null;
  pdfPath?: string | null;
  qrImagePath?: string | null;
  verificationToken?: string | null;
  generatedAt: string;
  releasedAt?: string | null;
  expiresAt?: string | null;
  cyLocation?: string | null;
  rejectionReason?: string | null;
  version: number;
  currentPaymentStatus?: string | null;
  paymentSubmittedAt?: string | null;
  releasedByName?: string | null;
  paymentValidatedAt?: string | null;
  paymentValidatedByName?: string | null;
};

export type GenerationSessionDto = {
  id: string;
  sessionId: string;
  manifestId: string;
  status: string;
  totalItems: number;
  completedItems: number;
  failedItems: number;
  currentItem?: string | null;
  startedAt: string;
  completedAt?: string | null;
};

export type EdoGenerationContainerDto = {
  containerId?: string | null;
  containerNumber: string;
  containerSize?: string | null;
  containerType?: string | null;
  manifestId: string;
  manifestNumber: string;
  brokerName?: string | null;
  consigneeName?: string | null;
  shippingLineName?: string | null;
  paymentVerifiedAt?: string | null;
  hasEdo: boolean;
  edoId?: string | null;
  edoNumber?: string | null;
  edoStatus?: string | null;
  edoGeneratedAt?: string | null;
  edoExpiresAt?: string | null;
};

export type EdoGenerationGroupDto = {
  manifestId: string;
  manifestNumber: string;
  brokerName: string;
  consigneeName: string;
  shippingLineName?: string | null;
  pendingCount: number;
  edoCountInManifest: number;
  totalInManifest: number;
  pendingContainers: EdoGenerationContainerDto[];
};

export type EdoGenerationQueueDto = {
  pendingGroups: EdoGenerationGroupDto[];
  generated: EdoGenerationContainerDto[];
  totalEligible: number;
  pendingCount: number;
  generatedCount: number;
};

export type EdoPaymentDto = {
  id: string;
  manifestId: string;
  edoId?: string | null;
  edoNumber?: string | null;
  amount: number;
  currency: string;
  status: string;
  receiptFilePath?: string | null;
  officialReceiptPath?: string | null;
  rejectionReason?: string | null;
  createdAt: string;
  manifestNumber?: string | null;
  containerNumber?: string | null;
  edoStatus?: string | null;
  submittedByName?: string | null;
  validatedAt?: string | null;
  validatedByName?: string | null;
};

export type EdoReleaseQueueItemDto = {
  edoId: string;
  edoNumber: string;
  status: string;
  manifestId: string;
  manifestNumber: string;
  containerNumber?: string | null;
  brokerName?: string | null;
  consigneeName?: string | null;
  generatedAt: string;
  paymentId?: string | null;
  paymentStatus?: string | null;
  paymentAmount?: number | null;
  paymentCurrency?: string | null;
  paymentSubmittedAt?: string | null;
  submittedByName?: string | null;
};

export type EdoReleaseQueueDto = {
  items: EdoReleaseQueueItemDto[];
  total: number;
  pendingValidation: number;
  readyToRelease: number;
  awaitingPayment: number;
};

export type EdoReleaseRecordDto = {
  edoId: string;
  edoNumber: string;
  status: string;
  manifestId: string;
  manifestNumber: string;
  containerNumber?: string | null;
  brokerName?: string | null;
  consigneeName?: string | null;
  releasedAt?: string | null;
  releasedByName?: string | null;
  paymentAmount?: number | null;
  paymentCurrency?: string | null;
  paymentValidatedAt?: string | null;
  paymentValidatedByName?: string | null;
};

export type RenewalDto = {
  id: string;
  expiredEdoId: string;
  expiredEdoNumber: string;
  newEdoId?: string | null;
  status: string;
  overdueDays: number;
  detentionChargeAmount: number;
  paymentVerified: boolean;
  requestedAt: string;
  completedAt?: string | null;
};

export type DocumentVerifyDto = {
  valid: boolean;
  documentType?: string | null;
  documentNumber?: string | null;
  status?: string | null;
  manifestNumber?: string | null;
  generatedAt?: string | null;
  expiresAt?: string | null;
  message?: string | null;
};

export type TerminalDto = {
  id: string;
  name: string;
  code: string;
  identity: string;
  kind: string;
  location?: string | null;
  region?: string | null;
  city?: string | null;
  dailyCapacity: number;
  isActive: boolean;
};

export type TerminalSlotDto = {
  id: string;
  terminalId: string;
  terminalName: string;
  date: string;
  capacity: number;
  assignedCount: number;
  status: string;
};

export type ContainerTypeDto = {
  id: string;
  name: string;
  code: string;
  description?: string | null;
  isActive: boolean;
};

export type ContainerSizeDto = {
  id: string;
  name: string;
  code: string;
  teuValue: number;
  description?: string | null;
  isActive: boolean;
};

export type CyAllocationDto = {
  id: string;
  shippingLineId: string;
  shippingLineName: string;
  terminalId: string;
  terminalName: string;
  staffUserId?: string | null;
  allocatedCapacityTeu: number;
  capacity20Ft: number;
  capacity40Ft: number;
  usedTeu: number;
};

export type ContainerDto = {
  id: string;
  containerNumber: string;
  shippingLineId: string;
  shippingLineName: string;
  manifestId?: string | null;
  typeCode?: string | null;
  sizeCode?: string | null;
  status: string;
  allocationStatus: string;
  currentLocation?: string | null;
  cyAllocationId?: string | null;
  cyTerminalName?: string | null;
  currentDwellDays: number;
  terminalArrivalDate?: string | null;
  dwellPausedAt?: string | null;
  stackBay?: string | null;
  stackRow?: string | null;
  stackTier?: string | null;
  createdAt: string;
};

export type ContainerInventoryItemDto = {
  id: string;
  containerNumber: string;
  shippingLineId: string;
  shippingLineName: string;
  typeCode?: string | null;
  sizeCode?: string | null;
  sizeTypeLabel: string;
  depotName: string;
  gateInDate?: string | null;
  currentDwellDays: number;
  totalPausedDays: number;
  isDwellPaused: boolean;
  displayStatus: string;
  condition: string;
  allocationStatus: string;
  status: string;
  teuValue: number;
  stackBay?: string | null;
  stackRow?: string | null;
  stackTier?: string | null;
  currentLocation?: string | null;
  createdAt: string;
};

export type ContainerInventoryStatsDto = {
  totalContainers: number;
  totalTeus: number;
  total20Ft: number;
  total40Ft: number;
  overallCapacityTeu: number;
  overallCapacity20Ft: number;
  overallCapacity40Ft: number;
  terminalCount: number;
  terminalCapacityTeu: number;
  terminalCapacity20Ft: number;
  terminalCapacity40Ft: number;
  yardCount: number;
  yardCapacityTeu: number;
  yardCapacity20Ft: number;
  yardCapacity40Ft: number;
};

export type ContainerInventoryPageDto = {
  items: ContainerInventoryItemDto[];
  totalCount: number;
  page: number;
  pageSize: number;
  totalPages: number;
  shippingLineName: string;
  stats: ContainerInventoryStatsDto;
};

export type ContainerDetailDto = {
  id: string;
  basicInfo: {
    containerNumber: string;
    sizeType: string;
    teuCount: number;
    location: string;
    gateInDate?: string | null;
    dwellTime: number;
    condition: string;
    status: string;
    shippingLineName: string;
    stackPosition?: string | null;
    operationalStatus?: string | null;
  };
  specifications: {
    manufacturer: string;
    yearBuilt: string;
    isoCode: string;
    cscPlate: string;
    maxGrossWeight: string;
    tareWeight: string;
    maxPayload: string;
    length: string;
    width: string;
    height: string;
  };
  movement: {
    lastMovement: string;
    movementType: string;
    fromLocation: string;
    toLocation: string;
    operator: string;
    equipment: string;
    remarks: string;
  };
  documentation: {
    billOfLading: string;
    manifest: string;
    customsDeclaration: string;
    deliveryOrder?: string | null;
    gatePass: string;
  };
  charges: {
    storageCharges: number;
    handlingCharges: number;
    documentationFee: number;
  };
  history: Array<{
    date: string;
    type: string;
    fromLocation: string;
    toLocation: string;
    operator: string;
    equipment: string;
    remarks: string;
  }>;
  inspections: Array<{
    date: string;
    type: string;
    inspector: string;
    result: string;
    photos: string;
    remarks: string;
  }>;
};

export type DwellConfigDto = {
  id: string;
  notificationThresholdDays: number;
  automaticReturnThresholdDays: number;
  timezone: string;
  enableAutomaticReturns: boolean;
  enableNotifications: boolean;
  isActive: boolean;
};

export type PreAdviceDto = {
  id: string;
  containerId: string;
  containerNumber: string;
  terminalId: string;
  terminalName: string;
  truckerId: string;
  truckerName: string;
  status: string;
  assignedSlotId?: string | null;
  paymentReference?: string | null;
  paymentVerified: boolean;
  rejectionReason?: string | null;
  qrCodePath?: string | null;
  packagePdfPath?: string | null;
  edoNumber?: string | null;
  verificationToken?: string | null;
  createdAt: string;
};

export type TruckerTokenDto = {
  apiToken: string;
  expiresAt: string;
};

export type UtilizationReportDto = {
  terminalId: string;
  terminalName: string;
  allocatedTeu: number;
  usedTeu: number;
  utilizationPercent: number;
  availableForReturn: number;
  atTerminal: number;
  pendingPreAdvice: number;
};

export type FormConfigurationDto = {
  id: string;
  name: string;
  type: string;
  version: number;
  status: string;
  fieldsJson: string;
  publishedAt?: string | null;
  createdAt: string;
};

export type SasFormFieldType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'email'
  | 'phone'
  | 'date'
  | 'url'
  | 'currency'
  | 'file'
  | 'image'
  | 'multi_file'
  | 'signature'
  | 'dropdown'
  | 'multi_select'
  | 'checkbox'
  | 'radio'
  | 'toggle'
  | 'terms'
  | 'geolocation'
  | 'address'
  | 'section_heading'
  | 'divider';

export type SasFieldShowWhen = {
  field: string;
  operator?: 'equals';
  value: string;
};

export type SasFieldValidation = {
  options?: Record<string, string>;
  showWhen?: SasFieldShowWhen;
  unique?: boolean;
  pattern?: string;
  message?: string;
  inputRestriction?: 'none' | 'numeric' | 'alpha' | 'alphanumeric' | string;
  lengthMode?: 'none' | 'exact' | 'max' | 'range' | 'min' | string;
  minLength?: number;
  maxLength?: number;
  min?: number;
  max?: number;
  allowedTypes?: string[];
  maxSize?: number;
  maxFiles?: number;
  preview?: boolean;
  declaration?: string;
  subtitle?: string;
  defaultLat?: number;
  defaultLng?: number;
  defaultZoom?: number;
};

export type SasFormField = {
  id: string;
  label: string;
  type: SasFormFieldType | string;
  required: boolean;
  order: number;
  placeholder?: string;
  helpText?: string;
  /**
   * Layout width on a 12-column grid (sm+).
   * 12 = full row, 6 = half (2/row), 4 = third (3/row), 3 = quarter (4/row).
   * Always full width on mobile (xs).
   */
  columnSpan?: 12 | 6 | 4 | 3 | number;
  /** Convenience mirror of validation.options for choice fields */
  options?: Record<string, string>;
  validation?: SasFieldValidation;
};

export type AccreditationDto = {
  id: string;
  applicantId: string;
  applicantName: string;
  applicantRole: string;
  shippingLineId: string;
  shippingLineName: string;
  formConfigurationId: string;
  status: string;
  submittedDataJson: string;
  denialReason?: string | null;
  complianceNotes?: string | null;
  complianceFieldIdsJson?: string | null;
  submittedAt: string;
  evaluatedAt?: string | null;
  approvedAt?: string | null;
};

export type TransferDto = {
  id: string;
  manifestId: string;
  manifestNumber: string;
  consigneeId: string;
  consigneeName: string;
  oldBrokerId: string;
  oldBrokerName: string;
  newBrokerId: string;
  newBrokerName: string;
  reason: string;
  status: string;
  transferLetterPath?: string | null;
  requestedAt: string;
  reviewNotes?: string | null;
};

export type AppealDto = {
  id: string;
  userId: string;
  userName: string;
  appealLetter: string;
  attachmentsJson?: string | null;
  status: string;
  submittedAt: string;
  reviewNotes?: string | null;
};

export type RepositioningDto = {
  id: string;
  requestNumber: string;
  shippingLineId: string;
  shippingLineName: string;
  requestType: string;
  sourceTerminalId: string;
  sourceTerminalName: string;
  sourceTerminalCode?: string | null;
  destinationTerminalId: string;
  destinationTerminalName: string;
  destinationTerminalCode?: string | null;
  purpose: string;
  requestLetterPath?: string | null;
  containerCount: number;
  status: string;
  requestedAt: string;
  requestedByEmail?: string | null;
  reviewedAt?: string | null;
  completedAt?: string | null;
  reviewNotes?: string | null;
  items: RepositioningItemDto[];
};

export type RepositioningItemDto = {
  containerId: string;
  containerNumber: string;
  dwellTimeDays: number;
  dischargeDate?: string | null;
  currentStatus: string;
};

export type RepositioningEligibleContainerDto = {
  id: string;
  containerNumber: string;
  sizeCode?: string | null;
  typeCode?: string | null;
  depotName: string;
  currentDwellDays: number;
  dischargeDate?: string | null;
};

export type ReferralCodeDto = {
  id: string;
  consigneeId: string;
  consigneeName: string;
  code: string;
  isActive: boolean;
  maxUses?: number | null;
  currentUses: number;
  expiresAt?: string | null;
};

export type RelationshipDto = {
  id: string;
  consigneeId: string;
  consigneeName: string;
  brokerId: string;
  brokerName: string;
  status: string;
  suspendedAt?: string | null;
  suspensionReason?: string | null;
  brokerEmail?: string | null;
  brokerBusinessAddress?: string | null;
  linkedAt?: string | null;
  brokerIsActive?: boolean;
};

export type WelcomeContentDto = {
  id: string;
  audience: string;
  title: string;
  bodyMarkdown: string;
  stepsJson: string;
  completedSteps: string[];
};

export type NotificationDto = {
  id: string;
  title: string;
  message: string;
  category: string;
  isRead: boolean;
  createdAt: string;
  subjectType?: string | null;
  subjectId?: string | null;
};

export type NotificationPreferenceDto = {
  id: string;
  inAppEnabled: boolean;
  emailEnabled: boolean;
  smsEnabled: boolean;
  pushEnabled: boolean;
  mutedCategoriesJson: string;
};

export type NotificationMetricsDto = {
  sent: number;
  failed: number;
  skipped: number;
  inAppUnread: number;
  recent: {
    id: string;
    userId?: string;
    channel: string;
    category: string;
    title: string;
    status: string;
    error?: string;
    createdAt: string;
  }[];
};

export type SystemSettingDto = { id: string; key: string; value: string; description?: string };
export type RateLimitRuleDto = {
  id: string;
  name: string;
  pathPrefix: string;
  role?: string;
  permitLimit: number;
  windowSeconds: number;
  isActive: boolean;
};
export type MessageTemplateDto = {
  id: string;
  key: string;
  channel: string;
  name: string;
  subject?: string;
  body: string;
  isActive: boolean;
};
export type DocumentTemplateDto = {
  id: string;
  documentType: string;
  name: string;
  version: number;
  bodyHtml: string;
  isActive: boolean;
};
export type ScheduledReportDto = {
  id: string;
  reportType: string;
  cronExpression: string;
  recipientsJson: string;
  isActive: boolean;
  lastRunAt?: string;
  lastResultPath?: string;
  lastError?: string;
};
export type EdoReleaseMetricsDto = {
  totalGenerated: number;
  totalReleased: number;
  totalRejected: number;
  totalExpired: number;
  releasedLast7Days: number;
  releasedLast30Days: number;
  avgHoursToRelease: number;
};
export type AuditTrailDto = {
  source: string;
  event: string;
  from?: string;
  to?: string;
  actor?: string;
  at: string;
  notes?: string;
};
export type ActivityLogDto = {
  id: string;
  action: string;
  entityType: string;
  entityId?: string;
  details?: string;
  createdAt: string;
  actorName?: string;
};

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:5080';
