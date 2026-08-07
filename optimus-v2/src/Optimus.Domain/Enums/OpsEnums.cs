namespace Optimus.Domain.Enums;

public enum FormConfigType
{
    Consignee = 0,
    Broker = 1
}

public enum FormConfigStatus
{
    Draft = 0,
    Published = 1,
    Active = 2,
    Inactive = 3
}

public enum AccreditationStatus
{
    Pending = 0,
    AwaitingFinalApproval = 1,
    ComplianceRequired = 2,
    Approved = 3,
    Denied = 4,
    Rejected = 5
}

public enum TransferRequestStatus
{
    Pending = 0,
    Approved = 1,
    Rejected = 2
}

public enum AppealStatus
{
    Pending = 0,
    Approved = 1,
    Rejected = 2
}

public enum RepositioningRequestType
{
    Export = 0,
    Repositioning = 1
}

public enum RepositioningStatus
{
    Pending = 0,
    Approved = 1,
    Rejected = 2,
    InTransit = 3,
    Completed = 4,
    Cancelled = 5
}
