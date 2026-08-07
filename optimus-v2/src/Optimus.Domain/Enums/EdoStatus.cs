namespace Optimus.Domain.Enums;

public enum EdoStatus
{
    PendingRelease = 0,
    PendingValidation = 1,
    Active = 2,
    Expired = 3,
    Locked = 4,
    Released = 5,
    Rejected = 6,
    Superseded = 7
}

public enum RenewalRequestStatus
{
    PendingReview = 0,
    AwaitingPayment = 1,
    PaymentSubmitted = 2,
    PaymentVerified = 3,
    ReadyForGeneration = 4,
    Completed = 5,
    Cancelled = 6
}

public enum GenerationSessionStatus
{
    InProgress = 0,
    Completed = 1,
    Cancelled = 2,
    Failed = 3
}
