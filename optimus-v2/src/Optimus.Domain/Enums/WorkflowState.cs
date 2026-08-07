namespace Optimus.Domain.Enums;

public enum WorkflowState
{
    ManifestUploaded = 0,
    NoaGenerated = 1,
    BlGenerated = 2,
    BlUploaded = 3,
    BillingGenerated = 4,
    PaymentSubmitted = 5,
    PaymentVerified = 6,
    EdoGenerated = 7,
    EdoReleased = 8
}

public enum PaymentType
{
    ManifestAccess = 0,
    FinalPayment = 1
}

public enum PaymentStatus
{
    PendingValidation = 0,
    Verified = 1,
    Rejected = 2
}

public static class WorkflowTransitions
{
    private static readonly Dictionary<WorkflowState, WorkflowState[]> Allowed = new()
    {
        [WorkflowState.ManifestUploaded] = new[] { WorkflowState.NoaGenerated },
        [WorkflowState.NoaGenerated] = new[] { WorkflowState.BlGenerated, WorkflowState.BlUploaded },
        [WorkflowState.BlGenerated] = new[] { WorkflowState.BlUploaded },
        [WorkflowState.BlUploaded] = new[] { WorkflowState.BillingGenerated },
        [WorkflowState.BillingGenerated] = new[] { WorkflowState.PaymentSubmitted },
        [WorkflowState.PaymentSubmitted] = new[] { WorkflowState.PaymentVerified, WorkflowState.BillingGenerated },
        [WorkflowState.PaymentVerified] = new[] { WorkflowState.EdoGenerated },
        [WorkflowState.EdoGenerated] = new[] { WorkflowState.EdoReleased },
        [WorkflowState.EdoReleased] = Array.Empty<WorkflowState>()
    };

    public static bool CanTransition(WorkflowState from, WorkflowState to) =>
        Allowed.TryGetValue(from, out var next) && next.Contains(to);
}
