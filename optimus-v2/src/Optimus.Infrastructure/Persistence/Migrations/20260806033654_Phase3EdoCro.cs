using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace Optimus.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class Phase3EdoCro : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "document_verifications",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    VerificationToken = table.Column<string>(type: "varchar(64)", maxLength: 64, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    DocumentType = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SubjectType = table.Column<string>(type: "varchar(64)", maxLength: 64, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SubjectId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    DocumentNumber = table.Column<string>(type: "varchar(100)", maxLength: 100, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SummaryJson = table.Column<string>(type: "json", nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_document_verifications", x => x.Id);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "edo_generation_sessions",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    SessionId = table.Column<string>(type: "varchar(36)", maxLength: 36, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ManifestId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    InitiatedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Status = table.Column<string>(type: "varchar(30)", maxLength: 30, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    TotalItems = table.Column<int>(type: "int", nullable: false),
                    CompletedItems = table.Column<int>(type: "int", nullable: false),
                    FailedItems = table.Column<int>(type: "int", nullable: false),
                    CurrentItem = table.Column<string>(type: "varchar(80)", maxLength: 80, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ExpirationDate = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    FailuresJson = table.Column<string>(type: "longtext", nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    StartedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    CompletedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_edo_generation_sessions", x => x.Id);
                    table.ForeignKey(
                        name: "FK_edo_generation_sessions_manifests_ManifestId",
                        column: x => x.ManifestId,
                        principalTable: "manifests",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_edo_generation_sessions_users_InitiatedById",
                        column: x => x.InitiatedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "electronic_delivery_orders",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    EdoNumber = table.Column<string>(type: "varchar(100)", maxLength: 100, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ManifestId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ShippingLineId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ContainerNumber = table.Column<string>(type: "varchar(50)", maxLength: 50, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    FeeAmount = table.Column<decimal>(type: "decimal(18,2)", precision: 18, scale: 2, nullable: true),
                    PdfPath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    QrPayload = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    QrImagePath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Status = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    GeneratedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    GeneratedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    ReleasedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ReleasedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    ExpiresAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    ExpiredDays = table.Column<int>(type: "int", nullable: true),
                    CyLocation = table.Column<string>(type: "varchar(100)", maxLength: 100, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    AdditionalNotes = table.Column<string>(type: "varchar(2000)", maxLength: 2000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    RejectionReason = table.Column<string>(type: "varchar(1000)", maxLength: 1000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Version = table.Column<int>(type: "int", nullable: false),
                    PreviousVersionId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    VerificationToken = table.Column<string>(type: "varchar(64)", maxLength: 64, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_electronic_delivery_orders", x => x.Id);
                    table.ForeignKey(
                        name: "FK_electronic_delivery_orders_electronic_delivery_orders_Previo~",
                        column: x => x.PreviousVersionId,
                        principalTable: "electronic_delivery_orders",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_electronic_delivery_orders_manifests_ManifestId",
                        column: x => x.ManifestId,
                        principalTable: "manifests",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_electronic_delivery_orders_shipping_lines_ShippingLineId",
                        column: x => x.ShippingLineId,
                        principalTable: "shipping_lines",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_electronic_delivery_orders_users_GeneratedById",
                        column: x => x.GeneratedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_electronic_delivery_orders_users_ReleasedById",
                        column: x => x.ReleasedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "edo_access_logs",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    EdoId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    AccessedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    AccessedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    IpAddress = table.Column<string>(type: "varchar(45)", maxLength: 45, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    AccessResult = table.Column<string>(type: "varchar(20)", maxLength: 20, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_edo_access_logs", x => x.Id);
                    table.ForeignKey(
                        name: "FK_edo_access_logs_electronic_delivery_orders_EdoId",
                        column: x => x.EdoId,
                        principalTable: "electronic_delivery_orders",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_edo_access_logs_users_AccessedById",
                        column: x => x.AccessedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "edo_payments",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ManifestId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    EdoId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ShippingLineId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    Amount = table.Column<decimal>(type: "decimal(18,2)", precision: 18, scale: 2, nullable: false),
                    Currency = table.Column<string>(type: "varchar(10)", maxLength: 10, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ReceiptFilePath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    OfficialReceiptPath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Status = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    SubmittedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ValidatedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ValidatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    RejectionReason = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Version = table.Column<int>(type: "int", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_edo_payments", x => x.Id);
                    table.ForeignKey(
                        name: "FK_edo_payments_electronic_delivery_orders_EdoId",
                        column: x => x.EdoId,
                        principalTable: "electronic_delivery_orders",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_edo_payments_manifests_ManifestId",
                        column: x => x.ManifestId,
                        principalTable: "manifests",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_edo_payments_shipping_lines_ShippingLineId",
                        column: x => x.ShippingLineId,
                        principalTable: "shipping_lines",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_edo_payments_users_SubmittedById",
                        column: x => x.SubmittedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_edo_payments_users_ValidatedById",
                        column: x => x.ValidatedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "edo_release_history",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    EdoId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    FromStatus = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ToStatus = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    ActorId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    RejectionReason = table.Column<string>(type: "varchar(1000)", maxLength: 1000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_edo_release_history", x => x.Id);
                    table.ForeignKey(
                        name: "FK_edo_release_history_electronic_delivery_orders_EdoId",
                        column: x => x.EdoId,
                        principalTable: "electronic_delivery_orders",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_edo_release_history_users_ActorId",
                        column: x => x.ActorId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "edo_renewal_requests",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    ExpiredEdoId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    NewEdoId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    RequestedById = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    RequestedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    EmptyContainerReturnDate = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    OverdueDays = table.Column<int>(type: "int", nullable: false),
                    DetentionChargeAmount = table.Column<decimal>(type: "decimal(18,2)", precision: 18, scale: 2, nullable: false),
                    Status = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    DetentionBillingId = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    PaymentVerified = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    PaymentVerifiedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    PaymentVerifiedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    AdditionalNotes = table.Column<string>(type: "varchar(2000)", maxLength: 2000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CompletedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_edo_renewal_requests", x => x.Id);
                    table.ForeignKey(
                        name: "FK_edo_renewal_requests_billings_DetentionBillingId",
                        column: x => x.DetentionBillingId,
                        principalTable: "billings",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_edo_renewal_requests_electronic_delivery_orders_ExpiredEdoId",
                        column: x => x.ExpiredEdoId,
                        principalTable: "electronic_delivery_orders",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_edo_renewal_requests_electronic_delivery_orders_NewEdoId",
                        column: x => x.NewEdoId,
                        principalTable: "electronic_delivery_orders",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_edo_renewal_requests_users_PaymentVerifiedById",
                        column: x => x.PaymentVerifiedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_edo_renewal_requests_users_RequestedById",
                        column: x => x.RequestedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateTable(
                name: "edo_versions",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    EdoId = table.Column<Guid>(type: "char(36)", nullable: false, collation: "ascii_general_ci"),
                    VersionNumber = table.Column<int>(type: "int", nullable: false),
                    PdfPath = table.Column<string>(type: "varchar(500)", maxLength: 500, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    EdoNumber = table.Column<string>(type: "varchar(100)", maxLength: 100, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Status = table.Column<string>(type: "varchar(40)", maxLength: 40, nullable: false)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    CreatedById = table.Column<Guid>(type: "char(36)", nullable: true, collation: "ascii_general_ci"),
                    ExpiresAt = table.Column<DateTime>(type: "datetime(6)", nullable: true),
                    CyLocation = table.Column<string>(type: "varchar(100)", maxLength: 100, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    Notes = table.Column<string>(type: "varchar(2000)", maxLength: 2000, nullable: true)
                        .Annotation("MySql:CharSet", "utf8mb4"),
                    IsCurrent = table.Column<bool>(type: "tinyint(1)", nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: false),
                    UpdatedAt = table.Column<DateTime>(type: "datetime(6)", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_edo_versions", x => x.Id);
                    table.ForeignKey(
                        name: "FK_edo_versions_electronic_delivery_orders_EdoId",
                        column: x => x.EdoId,
                        principalTable: "electronic_delivery_orders",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_edo_versions_users_CreatedById",
                        column: x => x.CreatedById,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                })
                .Annotation("MySql:CharSet", "utf8mb4");

            migrationBuilder.CreateIndex(
                name: "IX_document_verifications_DocumentType_SubjectType_SubjectId",
                table: "document_verifications",
                columns: new[] { "DocumentType", "SubjectType", "SubjectId" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_document_verifications_VerificationToken",
                table: "document_verifications",
                column: "VerificationToken",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_edo_access_logs_AccessedById",
                table: "edo_access_logs",
                column: "AccessedById");

            migrationBuilder.CreateIndex(
                name: "IX_edo_access_logs_EdoId",
                table: "edo_access_logs",
                column: "EdoId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_generation_sessions_InitiatedById",
                table: "edo_generation_sessions",
                column: "InitiatedById");

            migrationBuilder.CreateIndex(
                name: "IX_edo_generation_sessions_ManifestId",
                table: "edo_generation_sessions",
                column: "ManifestId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_generation_sessions_SessionId",
                table: "edo_generation_sessions",
                column: "SessionId",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_edo_payments_EdoId",
                table: "edo_payments",
                column: "EdoId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_payments_ManifestId",
                table: "edo_payments",
                column: "ManifestId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_payments_ShippingLineId",
                table: "edo_payments",
                column: "ShippingLineId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_payments_SubmittedById",
                table: "edo_payments",
                column: "SubmittedById");

            migrationBuilder.CreateIndex(
                name: "IX_edo_payments_ValidatedById",
                table: "edo_payments",
                column: "ValidatedById");

            migrationBuilder.CreateIndex(
                name: "IX_edo_release_history_ActorId",
                table: "edo_release_history",
                column: "ActorId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_release_history_EdoId",
                table: "edo_release_history",
                column: "EdoId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_renewal_requests_DetentionBillingId",
                table: "edo_renewal_requests",
                column: "DetentionBillingId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_renewal_requests_ExpiredEdoId",
                table: "edo_renewal_requests",
                column: "ExpiredEdoId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_renewal_requests_NewEdoId",
                table: "edo_renewal_requests",
                column: "NewEdoId");

            migrationBuilder.CreateIndex(
                name: "IX_edo_renewal_requests_PaymentVerifiedById",
                table: "edo_renewal_requests",
                column: "PaymentVerifiedById");

            migrationBuilder.CreateIndex(
                name: "IX_edo_renewal_requests_RequestedById",
                table: "edo_renewal_requests",
                column: "RequestedById");

            migrationBuilder.CreateIndex(
                name: "IX_edo_versions_CreatedById",
                table: "edo_versions",
                column: "CreatedById");

            migrationBuilder.CreateIndex(
                name: "IX_edo_versions_EdoId_VersionNumber",
                table: "edo_versions",
                columns: new[] { "EdoId", "VersionNumber" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_EdoNumber",
                table: "electronic_delivery_orders",
                column: "EdoNumber",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_GeneratedById",
                table: "electronic_delivery_orders",
                column: "GeneratedById");

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_ManifestId",
                table: "electronic_delivery_orders",
                column: "ManifestId");

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_PreviousVersionId",
                table: "electronic_delivery_orders",
                column: "PreviousVersionId");

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_ReleasedById",
                table: "electronic_delivery_orders",
                column: "ReleasedById");

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_ShippingLineId",
                table: "electronic_delivery_orders",
                column: "ShippingLineId");

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_VerificationToken",
                table: "electronic_delivery_orders",
                column: "VerificationToken");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "document_verifications");

            migrationBuilder.DropTable(
                name: "edo_access_logs");

            migrationBuilder.DropTable(
                name: "edo_generation_sessions");

            migrationBuilder.DropTable(
                name: "edo_payments");

            migrationBuilder.DropTable(
                name: "edo_release_history");

            migrationBuilder.DropTable(
                name: "edo_renewal_requests");

            migrationBuilder.DropTable(
                name: "edo_versions");

            migrationBuilder.DropTable(
                name: "electronic_delivery_orders");
        }
    }
}
