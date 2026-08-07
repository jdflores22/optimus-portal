namespace Optimus.Domain.Enums;

public enum TerminalIdentity
{
    Terminal = 0,
    ContainerYard = 1
}

public enum TerminalKind
{
    Cy = 0,
    Ati = 1,
    Ictsi = 2
}

public enum SlotStatus
{
    Available = 0,
    Full = 1,
    Blocked = 2
}

public enum ContainerStatus
{
    Pending = 0,
    AvailableForReturn = 1,
    PaApproved = 2,
    InTransit = 3,
    AtTerminal = 4,
    Returned = 5,
    Maintenance = 6,
    Alert = 7
}

public enum AllocationStatus
{
    None = 0,
    PreForecast = 1,
    Allocated = 2,
    Released = 3
}

public enum PreAdviceStatus
{
    Pending = 0,
    Verified = 1,
    Rejected = 2,
    Cancelled = 3,
    Completed = 4
}

public enum DwellEventType
{
    Arrival = 0,
    Pause = 1,
    Resume = 2,
    Notification60Day = 3,
    AutomaticReturn = 4,
    ManualCalculation = 5,
    StatusChange = 6
}
