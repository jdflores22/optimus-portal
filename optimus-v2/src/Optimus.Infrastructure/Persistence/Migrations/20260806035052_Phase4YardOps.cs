using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace Optimus.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class Phase4YardOps : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "container_sizes",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Name = table.Column<string>(type: "varchar(80)", maxLength: 80, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Code = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    TeuValue = table.Column<decimal>(type: "decimal(8,2)", precision: 8, scale: 2, nullable: false),
                    Description = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    IsActive = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_container_sizes", x => x.Id);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "container_types",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Name = table.Column<string>(type: "varchar(80)", maxLength: 80, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Code = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Description = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    IsActive = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_container_types", x => x.Id);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "dwell_time_configurations",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    NotificationThresholdDays = table.Column<int>(type: "int", nullable: false),
                    AutomaticReturnThresholdDays = table.Column<int>(type: "int", nullable: false),
                    Timezone = table.Column<string>(type: "varchar(80)", maxLength: 80, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    EnableAutomaticReturns = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    EnableNotifications = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    IsActive = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_dwell_time_configurations", x => x.Id);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "in_app_notifications",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    UserId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    Title = table.Column<string>(type: "varchar(200)", maxLength: 200, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Message = table.Column<string>(type: "varchar(2000)", maxLength: 2000, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Category = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SubjectType = table.Column<string>(type: "varchar(80)", maxLength: 80, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SubjectId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    IsRead = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_in_app_notifications", x => x.Id);
                    table.ForeignKey(
                        name: "FK_in_app_notifications_users_UserId",
                        column: x => x.UserId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "terminals",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Name = table.Column<string>(type: "varchar(120)", maxLength: 120, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Code = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Identity = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Kind = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Location = table.Column<string>(type: "varchar(200)", maxLength: 200, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Region = table.Column<string>(type: "varchar(80)", maxLength: 80, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    City = table.Column<string>(type: "varchar(80)", maxLength: 80, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    DailyCapacity = table.Column<int>(type: "int", nullable: false),
                    IsActive = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_terminals", x => x.Id);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "shipping_line_terminal_allocations",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ShippingLineId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    TerminalId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    StaffUserId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    AllocatedCapacityTeu = table.Column<int>(type: "int", nullable: false),
                    Capacity20Ft = table.Column<int>(type: "int", nullable: false),
                    Capacity40Ft = table.Column<int>(type: "int", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_shipping_line_terminal_allocations", x => x.Id);
                    table.ForeignKey(
                        name: "FK_shipping_line_terminal_allocations_shipping_lines_ShippingLi~",
                        column: x => x.ShippingLineId,
                        principalTable: "shipping_lines",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_shipping_line_terminal_allocations_terminals_TerminalId",
                        column: x => x.TerminalId,
                        principalTable: "terminals",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_shipping_line_terminal_allocations_users_StaffUserId",
                        column: x => x.StaffUserId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "terminal_slots",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    TerminalId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Date = table.Column<DateOnly>(type: "date", nullable: false),
                    Capacity = table.Column<int>(type: "int", nullable: false),
                    AssignedCount = table.Column<int>(type: "int", nullable: false),
                    Status = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_terminal_slots", x => x.Id);
                    table.ForeignKey(
                        name: "FK_terminal_slots_terminals_TerminalId",
                        column: x => x.TerminalId,
                        principalTable: "terminals",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "containers",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ContainerNumber = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ShippingLineId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ManifestId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ContainerTypeId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ContainerSizeId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    Status = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CurrentLocation = table.Column<string>(type: "varchar(120)", maxLength: 120, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ExpectedReturnDate = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CyAllocationId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    AllocationStatus = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    AllocatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    AllocationLockedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    TerminalArrivalDate = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CurrentDwellDays = table.Column<int>(type: "int", nullable: false),
                    LastDwellCalculationAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    DwellPausedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    TotalPausedDays = table.Column<int>(type: "int", nullable: false),
                    NextNotificationDate = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    AutomaticReturnDate = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    StackBay = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    StackRow = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    StackTier = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_containers", x => x.Id);
                    table.ForeignKey(
                        name: "FK_containers_container_sizes_ContainerSizeId",
                        column: x => x.ContainerSizeId,
                        principalTable: "container_sizes",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_containers_container_types_ContainerTypeId",
                        column: x => x.ContainerTypeId,
                        principalTable: "container_types",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_containers_manifests_ManifestId",
                        column: x => x.ManifestId,
                        principalTable: "manifests",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_containers_shipping_line_terminal_allocations_CyAllocationId",
                        column: x => x.CyAllocationId,
                        principalTable: "shipping_line_terminal_allocations",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_containers_shipping_lines_ShippingLineId",
                        column: x => x.ShippingLineId,
                        principalTable: "shipping_lines",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "container_allocation_audits",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ContainerId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    PreviousAllocationId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    NewAllocationId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ChangedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ChangedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    ChangeType = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Reason = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    MetadataJson = table.Column<string>(type: "longtext", nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_container_allocation_audits", x => x.Id);
                    table.ForeignKey(
                        name: "FK_container_allocation_audits_containers_ContainerId",
                        column: x => x.ContainerId,
                        principalTable: "containers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_container_allocation_audits_users_ChangedById",
                        column: x => x.ChangedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "dwell_time_events",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ContainerId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    EventType = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    EventDate = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    DwellDaysAtEvent = table.Column<int>(type: "int", nullable: false),
                    Reason = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    MetadataJson = table.Column<string>(type: "longtext", nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    TriggeredById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_dwell_time_events", x => x.Id);
                    table.ForeignKey(
                        name: "FK_dwell_time_events_containers_ContainerId",
                        column: x => x.ContainerId,
                        principalTable: "containers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_dwell_time_events_users_TriggeredById",
                        column: x => x.TriggeredById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "pre_advice_requests",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    TruckerId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ContainerId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    TerminalId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    AssignedSlotId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ShippingLineId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    Status = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    VerifiedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    VerifiedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    RejectionReason = table.Column<string>(type: "varchar(1000)", maxLength: 1000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    PaymentReference = table.Column<string>(type: "varchar(100)", maxLength: 100, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    PaymentVerified = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    QrCodePath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    PackagePdfPath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    EdoNumber = table.Column<string>(type: "varchar(100)", maxLength: 100, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    VerificationToken = table.Column<string>(type: "varchar(64)", maxLength: 64, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_pre_advice_requests", x => x.Id);
                    table.ForeignKey(
                        name: "FK_pre_advice_requests_containers_ContainerId",
                        column: x => x.ContainerId,
                        principalTable: "containers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_pre_advice_requests_shipping_lines_ShippingLineId",
                        column: x => x.ShippingLineId,
                        principalTable: "shipping_lines",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_pre_advice_requests_terminal_slots_AssignedSlotId",
                        column: x => x.AssignedSlotId,
                        principalTable: "terminal_slots",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_pre_advice_requests_terminals_TerminalId",
                        column: x => x.TerminalId,
                        principalTable: "terminals",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_pre_advice_requests_users_TruckerId",
                        column: x => x.TruckerId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_pre_advice_requests_users_VerifiedById",
                        column: x => x.VerifiedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "geotag_photos",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    PreAdviceRequestId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    FilePath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    OriginalName = table.Column<string>(type: "varchar(255)", maxLength: 255, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Latitude = table.Column<double>(type: "double", nullable: true),
                    Longitude = table.Column<double>(type: "double", nullable: true),
                    CapturedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    IsVerified = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    VerificationNotes = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_geotag_photos", x => x.Id);
                    table.ForeignKey(
                        name: "FK_geotag_photos_pre_advice_requests_PreAdviceRequestId",
                        column: x => x.PreAdviceRequestId,
                        principalTable: "pre_advice_requests",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateIndex(
                name: "IX_container_allocation_audits_ChangedById",
                table: "container_allocation_audits",
                column: "ChangedById");

            migrationBuilder.CreateIndex(
                name: "IX_container_allocation_audits_ContainerId",
                table: "container_allocation_audits",
                column: "ContainerId");

            migrationBuilder.CreateIndex(
                name: "IX_container_sizes_Code",
                table: "container_sizes",
                column: "Code",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_container_types_Code",
                table: "container_types",
                column: "Code",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_containers_ContainerNumber",
                table: "containers",
                column: "ContainerNumber",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_containers_ContainerSizeId",
                table: "containers",
                column: "ContainerSizeId");

            migrationBuilder.CreateIndex(
                name: "IX_containers_ContainerTypeId",
                table: "containers",
                column: "ContainerTypeId");

            migrationBuilder.CreateIndex(
                name: "IX_containers_CyAllocationId",
                table: "containers",
                column: "CyAllocationId");

            migrationBuilder.CreateIndex(
                name: "IX_containers_ManifestId",
                table: "containers",
                column: "ManifestId");

            migrationBuilder.CreateIndex(
                name: "IX_containers_ShippingLineId",
                table: "containers",
                column: "ShippingLineId");

            migrationBuilder.CreateIndex(
                name: "IX_dwell_time_events_ContainerId",
                table: "dwell_time_events",
                column: "ContainerId");

            migrationBuilder.CreateIndex(
                name: "IX_dwell_time_events_TriggeredById",
                table: "dwell_time_events",
                column: "TriggeredById");

            migrationBuilder.CreateIndex(
                name: "IX_geotag_photos_PreAdviceRequestId",
                table: "geotag_photos",
                column: "PreAdviceRequestId");

            migrationBuilder.CreateIndex(
                name: "IX_in_app_notifications_UserId",
                table: "in_app_notifications",
                column: "UserId");

            migrationBuilder.CreateIndex(
                name: "IX_pre_advice_requests_AssignedSlotId",
                table: "pre_advice_requests",
                column: "AssignedSlotId");

            migrationBuilder.CreateIndex(
                name: "IX_pre_advice_requests_ContainerId",
                table: "pre_advice_requests",
                column: "ContainerId");

            migrationBuilder.CreateIndex(
                name: "IX_pre_advice_requests_ShippingLineId",
                table: "pre_advice_requests",
                column: "ShippingLineId");

            migrationBuilder.CreateIndex(
                name: "IX_pre_advice_requests_TerminalId",
                table: "pre_advice_requests",
                column: "TerminalId");

            migrationBuilder.CreateIndex(
                name: "IX_pre_advice_requests_TruckerId",
                table: "pre_advice_requests",
                column: "TruckerId");

            migrationBuilder.CreateIndex(
                name: "IX_pre_advice_requests_VerifiedById",
                table: "pre_advice_requests",
                column: "VerifiedById");

            migrationBuilder.CreateIndex(
                name: "IX_shipping_line_terminal_allocations_ShippingLineId_TerminalId~",
                table: "shipping_line_terminal_allocations",
                columns: new[] { "ShippingLineId", "TerminalId", "StaffUserId" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_shipping_line_terminal_allocations_StaffUserId",
                table: "shipping_line_terminal_allocations",
                column: "StaffUserId");

            migrationBuilder.CreateIndex(
                name: "IX_shipping_line_terminal_allocations_TerminalId",
                table: "shipping_line_terminal_allocations",
                column: "TerminalId");

            migrationBuilder.CreateIndex(
                name: "IX_terminal_slots_TerminalId_Date",
                table: "terminal_slots",
                columns: new[] { "TerminalId", "Date" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_terminals_Code",
                table: "terminals",
                column: "Code",
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "container_allocation_audits");

            migrationBuilder.DropTable(
                name: "dwell_time_configurations");

            migrationBuilder.DropTable(
                name: "dwell_time_events");

            migrationBuilder.DropTable(
                name: "geotag_photos");

            migrationBuilder.DropTable(
                name: "in_app_notifications");

            migrationBuilder.DropTable(
                name: "pre_advice_requests");

            migrationBuilder.DropTable(
                name: "containers");

            migrationBuilder.DropTable(
                name: "terminal_slots");

            migrationBuilder.DropTable(
                name: "container_sizes");

            migrationBuilder.DropTable(
                name: "container_types");

            migrationBuilder.DropTable(
                name: "shipping_line_terminal_allocations");

            migrationBuilder.DropTable(
                name: "terminals");
        }
    }
}
