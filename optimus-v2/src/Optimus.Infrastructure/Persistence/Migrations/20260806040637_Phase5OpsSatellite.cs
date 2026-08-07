using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace Optimus.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class Phase5OpsSatellite : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "OnboardingCompletedStepsJson",
                table: "users",
                type: "longtext",
                nullable: true)
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "broker_transfer_requests",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ManifestId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ConsigneeId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    OldBrokerId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    NewBrokerId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Reason = table.Column<string>(type: "varchar(2000)", maxLength: 2000, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    TransferLetterPath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Status = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    RequestedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    RequestedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    ReviewedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ReviewedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    ReviewNotes = table.Column<string>(type: "varchar(1000)", maxLength: 1000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_broker_transfer_requests", x => x.Id);
                    table.ForeignKey(
                        name: "FK_broker_transfer_requests_manifests_ManifestId",
                        column: x => x.ManifestId,
                        principalTable: "manifests",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_broker_transfer_requests_users_ConsigneeId",
                        column: x => x.ConsigneeId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_broker_transfer_requests_users_NewBrokerId",
                        column: x => x.NewBrokerId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_broker_transfer_requests_users_OldBrokerId",
                        column: x => x.OldBrokerId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_broker_transfer_requests_users_RequestedById",
                        column: x => x.RequestedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_broker_transfer_requests_users_ReviewedById",
                        column: x => x.ReviewedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "form_configurations",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Name = table.Column<string>(type: "varchar(120)", maxLength: 120, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Type = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Version = table.Column<int>(type: "int", nullable: false),
                    Status = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    FieldsJson = table.Column<string>(type: "longtext", nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    PublishedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CreatedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_form_configurations", x => x.Id);
                    table.ForeignKey(
                        name: "FK_form_configurations_users_CreatedById",
                        column: x => x.CreatedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "repositioning_requests",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    RequestNumber = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ShippingLineId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    RequestType = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SourceTerminalId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    DestinationTerminalId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Purpose = table.Column<string>(type: "varchar(2000)", maxLength: 2000, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    RequestLetterPath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ContainerCount = table.Column<int>(type: "int", nullable: false),
                    Status = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    RequestedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    RequestedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    ReviewedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ReviewedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    ReviewNotes = table.Column<string>(type: "varchar(1000)", maxLength: 1000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CompletedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_repositioning_requests", x => x.Id);
                    table.ForeignKey(
                        name: "FK_repositioning_requests_shipping_lines_ShippingLineId",
                        column: x => x.ShippingLineId,
                        principalTable: "shipping_lines",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_repositioning_requests_terminals_DestinationTerminalId",
                        column: x => x.DestinationTerminalId,
                        principalTable: "terminals",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_repositioning_requests_terminals_SourceTerminalId",
                        column: x => x.SourceTerminalId,
                        principalTable: "terminals",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_repositioning_requests_users_RequestedById",
                        column: x => x.RequestedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_repositioning_requests_users_ReviewedById",
                        column: x => x.ReviewedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "suspension_appeals",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    UserId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    AppealLetter = table.Column<string>(type: "longtext", nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    AttachmentsJson = table.Column<string>(type: "longtext", nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Status = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SubmittedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    ReviewedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ReviewedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    ReviewNotes = table.Column<string>(type: "varchar(1000)", maxLength: 1000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_suspension_appeals", x => x.Id);
                    table.ForeignKey(
                        name: "FK_suspension_appeals_users_ReviewedById",
                        column: x => x.ReviewedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_suspension_appeals_users_UserId",
                        column: x => x.UserId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "welcome_contents",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Audience = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Title = table.Column<string>(type: "varchar(200)", maxLength: 200, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    BodyMarkdown = table.Column<string>(type: "longtext", nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    StepsJson = table.Column<string>(type: "longtext", nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    IsActive = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_welcome_contents", x => x.Id);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "accreditation_submissions",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ApplicantId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ShippingLineId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    FormConfigurationId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    SubmittedDataJson = table.Column<string>(type: "longtext", nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Status = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    EvaluatorId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    FinalApproverId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    SubmittedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    EvaluatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    ApprovedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    DenialReason = table.Column<string>(type: "varchar(1000)", maxLength: 1000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ComplianceNotes = table.Column<string>(type: "varchar(2000)", maxLength: 2000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ComplianceFieldIdsJson = table.Column<string>(type: "longtext", nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_accreditation_submissions", x => x.Id);
                    table.ForeignKey(
                        name: "FK_accreditation_submissions_form_configurations_FormConfigurat~",
                        column: x => x.FormConfigurationId,
                        principalTable: "form_configurations",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_accreditation_submissions_shipping_lines_ShippingLineId",
                        column: x => x.ShippingLineId,
                        principalTable: "shipping_lines",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_accreditation_submissions_users_ApplicantId",
                        column: x => x.ApplicantId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_accreditation_submissions_users_EvaluatorId",
                        column: x => x.EvaluatorId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_accreditation_submissions_users_FinalApproverId",
                        column: x => x.FinalApproverId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "repositioning_request_items",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    RepositioningRequestId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ContainerId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    DwellTimeDays = table.Column<int>(type: "int", nullable: false),
                    DischargeDate = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_repositioning_request_items", x => x.Id);
                    table.ForeignKey(
                        name: "FK_repositioning_request_items_containers_ContainerId",
                        column: x => x.ContainerId,
                        principalTable: "containers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_repositioning_request_items_repositioning_requests_Repositio~",
                        column: x => x.RepositioningRequestId,
                        principalTable: "repositioning_requests",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateIndex(
                name: "IX_accreditation_submissions_ApplicantId_ShippingLineId",
                table: "accreditation_submissions",
                columns: new[] { "ApplicantId", "ShippingLineId" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_accreditation_submissions_EvaluatorId",
                table: "accreditation_submissions",
                column: "EvaluatorId");

            migrationBuilder.CreateIndex(
                name: "IX_accreditation_submissions_FinalApproverId",
                table: "accreditation_submissions",
                column: "FinalApproverId");

            migrationBuilder.CreateIndex(
                name: "IX_accreditation_submissions_FormConfigurationId",
                table: "accreditation_submissions",
                column: "FormConfigurationId");

            migrationBuilder.CreateIndex(
                name: "IX_accreditation_submissions_ShippingLineId",
                table: "accreditation_submissions",
                column: "ShippingLineId");

            migrationBuilder.CreateIndex(
                name: "IX_broker_transfer_requests_ConsigneeId",
                table: "broker_transfer_requests",
                column: "ConsigneeId");

            migrationBuilder.CreateIndex(
                name: "IX_broker_transfer_requests_ManifestId",
                table: "broker_transfer_requests",
                column: "ManifestId");

            migrationBuilder.CreateIndex(
                name: "IX_broker_transfer_requests_NewBrokerId",
                table: "broker_transfer_requests",
                column: "NewBrokerId");

            migrationBuilder.CreateIndex(
                name: "IX_broker_transfer_requests_OldBrokerId",
                table: "broker_transfer_requests",
                column: "OldBrokerId");

            migrationBuilder.CreateIndex(
                name: "IX_broker_transfer_requests_RequestedById",
                table: "broker_transfer_requests",
                column: "RequestedById");

            migrationBuilder.CreateIndex(
                name: "IX_broker_transfer_requests_ReviewedById",
                table: "broker_transfer_requests",
                column: "ReviewedById");

            migrationBuilder.CreateIndex(
                name: "IX_form_configurations_CreatedById",
                table: "form_configurations",
                column: "CreatedById");

            migrationBuilder.CreateIndex(
                name: "IX_form_configurations_Type_Version",
                table: "form_configurations",
                columns: new[] { "Type", "Version" });

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_request_items_ContainerId",
                table: "repositioning_request_items",
                column: "ContainerId");

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_request_items_RepositioningRequestId_Container~",
                table: "repositioning_request_items",
                columns: new[] { "RepositioningRequestId", "ContainerId" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_requests_DestinationTerminalId",
                table: "repositioning_requests",
                column: "DestinationTerminalId");

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_requests_RequestedById",
                table: "repositioning_requests",
                column: "RequestedById");

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_requests_RequestNumber",
                table: "repositioning_requests",
                column: "RequestNumber",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_requests_ReviewedById",
                table: "repositioning_requests",
                column: "ReviewedById");

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_requests_ShippingLineId",
                table: "repositioning_requests",
                column: "ShippingLineId");

            migrationBuilder.CreateIndex(
                name: "IX_repositioning_requests_SourceTerminalId",
                table: "repositioning_requests",
                column: "SourceTerminalId");

            migrationBuilder.CreateIndex(
                name: "IX_suspension_appeals_ReviewedById",
                table: "suspension_appeals",
                column: "ReviewedById");

            migrationBuilder.CreateIndex(
                name: "IX_suspension_appeals_UserId",
                table: "suspension_appeals",
                column: "UserId");

            migrationBuilder.CreateIndex(
                name: "IX_welcome_contents_Audience_IsActive",
                table: "welcome_contents",
                columns: new[] { "Audience", "IsActive" });
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "accreditation_submissions");

            migrationBuilder.DropTable(
                name: "broker_transfer_requests");

            migrationBuilder.DropTable(
                name: "repositioning_request_items");

            migrationBuilder.DropTable(
                name: "suspension_appeals");

            migrationBuilder.DropTable(
                name: "welcome_contents");

            migrationBuilder.DropTable(
                name: "form_configurations");

            migrationBuilder.DropTable(
                name: "repositioning_requests");

            migrationBuilder.DropColumn(
                name: "OnboardingCompletedStepsJson",
                table: "users");
        }
    }
}
