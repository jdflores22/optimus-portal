export type LinkedPartyDto = {
  id: string;
  name: string;
  email: string;
};

export type ShippingAdminConsigneeDto = {
  id: string;
  businessName: string;
  fullName: string;
  email: string;
  status: string;
  isActive: boolean;
  brokerCount: number;
  noaCount: number;
  manifestCount: number;
  containerCount: number;
  linkedBrokers: LinkedPartyDto[];
};

export type ShippingAdminBrokerDto = {
  id: string;
  fullName: string;
  email: string;
  status: string;
  isActive: boolean;
  consigneeCount: number;
  manifestCount: number;
  edoCount: number;
  linkedConsignees: LinkedPartyDto[];
};

export type RecentManifestDto = {
  id: string;
  manifestNumber: string;
  workflowState: string;
  createdAt: string;
};

export type PartnerAccreditationDto = {
  id: string;
  formConfigurationId: string;
  formName: string;
  formType: string;
  formVersion: number;
  fieldsJson: string;
  submittedDataJson: string;
  status: string;
  submittedAt: string;
  approvedAt?: string | null;
  evaluatedAt?: string | null;
};

export type ShippingAdminConsigneeDetailDto = {
  consignee: ShippingAdminConsigneeDto;
  edoCount: number;
  recentManifests: RecentManifestDto[];
  accreditation?: PartnerAccreditationDto | null;
};

export type ShippingAdminBrokerDetailDto = {
  broker: ShippingAdminBrokerDto;
  containerCount: number;
  recentManifests: RecentManifestDto[];
  accreditation?: PartnerAccreditationDto | null;
};
