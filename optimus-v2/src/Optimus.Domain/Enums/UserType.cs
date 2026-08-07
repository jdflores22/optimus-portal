namespace Optimus.Domain.Enums;

public enum UserType
{
    SystemAdmin = 0,
    Staff = 1,
    Broker = 2,
    Consignee = 3,
    TerminalTeam = 4,
    Trucker = 5
}

public enum PendingUserStatus
{
    Pending = 0,
    Expired = 1,
    Accepted = 2,
    Declined = 3,
    TemporarilyDisabled = 4
}

public enum RelationshipStatus
{
    Active = 0,
    Suspended = 1,
    Terminated = 2
}
