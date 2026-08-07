namespace Optimus.Shared.Constants;

public static class AppRoles
{
    public const string SystemAdmin = "SystemAdmin";
    public const string ShippingLinesAdmin = "ShippingLinesAdmin";
    public const string SlStaff = "SlStaff";
    public const string Evaluator = "Evaluator";
    public const string Accounting = "Accounting";
    public const string TerminalTeam = "TerminalTeam";
    public const string Broker = "Broker";
    public const string Consignee = "Consignee";
    public const string Trucker = "Trucker";

    public static readonly string[] All =
    {
        SystemAdmin,
        ShippingLinesAdmin,
        SlStaff,
        Evaluator,
        Accounting,
        TerminalTeam,
        Broker,
        Consignee,
        Trucker
    };
}
